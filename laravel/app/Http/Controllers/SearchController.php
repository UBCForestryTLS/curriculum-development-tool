<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class SearchController extends Controller

{
    public function index(Request $request){
        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:200'],
            'view' => ['nullable', 'in:courses,programs'],
        ]); // The query is optional, and the result view must be one of the supported options.

        $searchTerm = $validated['query'] ?? '';
        $searchTerm = trim($searchTerm);
        $searchTerm = preg_replace('/\s+/', ' ', $searchTerm); #for normalizing internal whitepace
        $selectedView = $validated['view'] ?? 'courses';

        $results = collect();
        $programMatches = collect();
        $programResults = collect();
        $stats =  [
            'courses' => 0,
            'programs' => 0,
            'topics' => 0,
            'learning_outcomes' => 0,
            'assessments' => 0,
            'descriptions' => 0,
            'materials' => 0,
        ];

        if($searchTerm !== ''){
            $resultsAndStats = $this->searchCourses($searchTerm);
            $results = $resultsAndStats['results'];
            $stats = $resultsAndStats['stats'];
            $programMatches = $this->searchProgramNames($searchTerm);
            $programResults = $this->groupCourseResultsByProgram($results, $programMatches);
            $stats['programs'] = $programResults->count();

            if ($selectedView === 'programs') {
                $stats['courses'] = $programResults
                    ->flatMap(fn ($program) => $program->courses)
                    ->pluck('course_id')
                    ->unique()
                    ->count();
            }

        }

        $results = $this->paginateResults($results, $request); //so if many results/courses are returned they can 
        // show up as multiple pages in the UI
        $programResults = $this->paginateResults($programResults, $request);

        return view('search.index', [
            'searchTerm' => $searchTerm,
            'results' => $results,
            'stats' => $stats,
            'selectedView' => $selectedView,
            'programMatches' => $programMatches,
            'programResults' => $programResults,
        ]);
}

    public function searchCourses(string $searchTerm){
        $searchResults = collect()
            ->merge($this->searchCourseNames($searchTerm))
            ->merge($this->searchTopics($searchTerm))
            ->merge($this->searchLearningObjectives($searchTerm))
            ->merge($this->searchDescriptions($searchTerm))
            ->merge($this->searchMaterials($searchTerm))
            ->merge($this->searchAssessments($searchTerm));

        $results = $this->combineMatchesByCourse($searchResults);
        $results = $this->attachProgramsToCourseResults($results);
        $stats = $this->calculateSearchStats($searchResults, $results);

        return [
            'results' => $results,
            'stats' => $stats,
        ];
    }

    private function paginateResults(Collection $results, Request $request): LengthAwarePaginator
    {
        $perPage = 10;
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $currentPageResults = $results->forPage($currentPage, $perPage)->values();

        return new LengthAwarePaginator(
            $currentPageResults,
            $results->count(),
            $perPage,
            $currentPage,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }

    private function attachProgramsToCourseResults(Collection $results): Collection
    {
        $courseIds = $results->pluck('course_id');

        $programsByCourse = DB::table('course_programs')
            ->join('programs', 'programs.program_id', '=', 'course_programs.program_id')
            ->whereIn('course_programs.course_id', $courseIds)
            ->select(
                'course_programs.course_id',
                'programs.program_id',
                'programs.program'
            )
            ->distinct()
            ->get()
            ->groupBy('course_id');

        foreach ($results as $result) {
            $result->programs = $programsByCourse->get($result->course_id, collect());
        }

        return $results;
    }

    public function searchTopics(string $searchTerm){
        $results = DB::table('course_topics')
            ->join('courses', 'courses.course_id', '=', 'course_topics.course_id')
            ->whereRaw( //need to use raw SQL to support SQL functions like to_tsvector and ts_headline
                "to_tsvector('english', course_topics.topic) @@ websearch_to_tsquery('english', ?)",
                [$searchTerm])
            ->selectRaw("
                courses.course_id,
                courses.course_code,
                courses.course_num,
                courses.course_title,
                'topic' as property,
                course_topics.topic as matched_text,
                ts_headline(
                    'english',
                    course_topics.topic,
                    websearch_to_tsquery('english', ?),
                    'StartSel=<mark>, StopSel=</mark>, MaxFragments=2, MinWords=4, MaxWords=20'
                ) as snippet
            ", [$searchTerm])->get();

            return $results;
    }

    public function searchLearningObjectives(string $searchTerm){
        $results = DB::table('learning_outcomes')
        ->join('courses', 'courses.course_id', '=', 'learning_outcomes.course_id')
        ->whereRaw(
            "to_tsvector('english', learning_outcomes.l_outcome) @@ websearch_to_tsquery('english', ?)",
            [$searchTerm])
        ->selectRaw("
            courses.course_id,
            courses.course_code,
            courses.course_num,
            courses.course_title,
            'learning outcome' as property,
            learning_outcomes.l_outcome as matched_text,
            ts_headline(
                'english',
                learning_outcomes.l_outcome,
                websearch_to_tsquery('english', ?),
                'StartSel=<mark>, StopSel=</mark>, MaxFragments=2, MinWords=4, MaxWords=20'
            ) as snippet
        ", [$searchTerm])->get();

        return $results;
    }

    public function searchDescriptions(string $searchTerm){
        $results = DB::table('course_description')
        ->join('courses', 'courses.course_id', '=', 'course_description.course_id')
        ->whereRaw(
            "to_tsvector('english', course_description.description) @@ websearch_to_tsquery('english', ?)",
            [$searchTerm])
        ->selectRaw("
            courses.course_id,
            courses.course_code,
            courses.course_num,
            courses.course_title,
            'description' as property,
            course_description.description as matched_text,
            ts_headline(
                'english',
                course_description.description,
                websearch_to_tsquery('english', ?),
                'StartSel=<mark>, StopSel=</mark>, MaxFragments=2, MinWords=4, MaxWords=20'
            ) as snippet
        ", [$searchTerm])->get();

        return $results;
    }

    public function searchMaterials(string $searchTerm){
        $results = DB::table('course_materials')
        ->join('courses', 'courses.course_id', '=', 'course_materials.course_id')
        ->whereRaw(
            "to_tsvector('english', concat_ws(' ', course_materials.name, course_materials.type, course_materials.description)) @@ websearch_to_tsquery('english', ?)",
            [$searchTerm])
        ->selectRaw("
            courses.course_id,
            courses.course_code,
            courses.course_num,
            courses.course_title,
            'material' as property,
            concat_ws(' ', course_materials.name, course_materials.type, course_materials.description) as matched_text,
            ts_headline(
                'english',
                concat_ws(' ', course_materials.name, course_materials.type, course_materials.description),
                websearch_to_tsquery('english', ?),
                'StartSel=<mark>, StopSel=</mark>, MaxFragments=2, MinWords=4, MaxWords=20'
            ) as snippet
        ", [$searchTerm])->get();

        return $results;
    }

    public function searchAssessments(string $searchTerm){
        $results = DB::table('assessment_methods')
        ->join('courses', 'courses.course_id', '=', 'assessment_methods.course_id')
        ->whereRaw(
            "to_tsvector('english', assessment_methods.a_method) @@ websearch_to_tsquery('english', ?)",
            [$searchTerm])
        ->selectRaw("
            courses.course_id,
            courses.course_code,
            courses.course_num,
            courses.course_title,
            'assessment' as property,
            assessment_methods.a_method as matched_text,
            ts_headline(
                'english',
                assessment_methods.a_method,
                websearch_to_tsquery('english', ?),
                'StartSel=<mark>, StopSel=</mark>, MaxFragments=2, MinWords=4, MaxWords=20'
            ) as snippet
        ", [$searchTerm])->get();

        return $results;
    }

    public function searchCourseNames(string $searchTerm){
        $searchText = "concat_ws(' ', courses.course_code, courses.course_num, courses.course_title)";
        $normalizedSearchTerm = preg_replace('/^([A-Za-z]+)\s*(\d+)$/', '$1 $2', $searchTerm); //normalize course code/nums for better search

        $results = DB::table('courses')
            ->whereRaw(
                "to_tsvector('english', {$searchText}) @@ websearch_to_tsquery('english', ?)",
                [$normalizedSearchTerm]
            )
            ->selectRaw("
                courses.course_id,
                courses.course_code,
                courses.course_num,
                courses.course_title,
                'course' as property,
                {$searchText} as matched_text,
                ts_headline(
                    'english',
                    {$searchText},
                    websearch_to_tsquery('english', ?),
                    'StartSel=<mark>, StopSel=</mark>, MaxFragments=2, MinWords=4, MaxWords=20'
                ) as snippet
            ", [$normalizedSearchTerm])
            ->get();

        return $results;
    }

    public function searchProgramNames(string $searchTerm){
        $results = DB::table('programs')
            ->whereRaw(
                "to_tsvector('english', programs.program) @@ websearch_to_tsquery('english', ?)",
                [$searchTerm]
            )
            ->selectRaw("
                programs.program_id,
                programs.program,
                'program' as property,
                programs.program as matched_text,
                ts_headline(
                    'english',
                    programs.program,
                    websearch_to_tsquery('english', ?),
                    'StartSel=<mark>, StopSel=</mark>, MaxFragments=2, MinWords=1, MaxWords=20'
                ) as snippet
            ", [$searchTerm])
            ->get();

        return $results;
    }

    public function groupCourseResultsByProgram(Collection $courseResults, Collection $programMatches): Collection
    {
        $programResults = collect();

        foreach ($programMatches as $match) {
            $programResults[$match->program_id] = (object) [
                'program_id' => $match->program_id,
                'program' => $match->program,
                'program_match_snippet' => $match->snippet,
                'is_program_match' => true,
                'courses' => collect(),
            ];
        }

        foreach ($courseResults as $course) {
            foreach ($course->programs as $program) {
                if (!$programResults->has($program->program_id)) {
                    $programResults[$program->program_id] = (object) [
                        'program_id' => $program->program_id,
                        'program' => $program->program,
                        'program_match_snippet' => null,
                        'is_program_match' => false,
                        'courses' => collect(),
                    ];
                }

                $programResults[$program->program_id]->courses->push($course);
            }
        }

        return $programResults
            ->sortByDesc('is_program_match')
            ->values();
    }

    public function combineMatchesByCourse(Collection $matches): Collection{

        $propertyWeights = [
            'course' => 70,
            'topic' => 50,
            'learning outcome' => 40,
            'assessment' => 30,
            'description' => 20,
            'material' => 10,
            //these weights determine the score added to each match so courses with higher priority property matches
            //show up first - the priority order, from highest to lowest is: Topics, LOs, assesments, description, material.
        ];

        $propertyStatKeys = [
            'topic' => 'topics',
            'learning outcome' => 'learning_outcomes',
            'assessment' => 'assessments',
            'description' => 'descriptions',
            'material' => 'materials',

            // Maps each raw match property name to the matching per-course stats key
            // Search matches use singular property names, while match_stats uses plural keys for display counts.
        ];

        $combinedResults = collect();

        foreach($matches as $match){
            $courseId = $match->course_id;

            if(!$combinedResults->has($courseId)){
                //if there isn't a course created for this, create one
                $combinedResults[$courseId] = (object) [
                    'course_id' => $courseId,
                    'course_code' => $match->course_code,
                    'course_num' => $match->course_num,
                    'course_title' => $match->course_title,
                    'course_match_snippet' => null,
                    'is_course_match' => false,
                    'score' => 0,
                    'match_stats' => [
                        'topics' => 0,
                        'learning_outcomes' => 0,
                        'assessments' => 0,
                        'descriptions' => 0,
                        'materials' => 0,
                    ],
                    'matches' => collect(),
                ];
            }

            if($match->property === 'course'){
                //special case: if match is a direct course name match
                $combinedResults[$courseId]->course_match_snippet = $match->snippet;
                $combinedResults[$courseId]->is_course_match = true;
                $combinedResults[$courseId]->score += $propertyWeights['course'];
                continue;
            }

            $combinedResults[$courseId]->score += $propertyWeights[$match->property] ?? 0;

            $statKey = $propertyStatKeys[$match->property] ?? null;
            if($statKey){
                $combinedResults[$courseId]->match_stats[$statKey]++;
                //adds one to course every time for course-search-statistics for each property match

            }

            $combinedResults[$courseId]->matches->push((object) [
                'property' => $match->property,
                'matched_text' => $match->matched_text,
                'snippet' => $match->snippet,
            ]);

        }

        return $combinedResults
            ->sortByDesc('score')//sorts results by given course score based on matches
            ->sortByDesc('is_course_match')//sorts true before false so direct courses matches appear first
            ->values(); //removes course_id as index for the blade view to cleanly loop through results, 
        

    }

    public function calculateSearchStats(Collection $matches, Collection $results): array{
        return [
            'courses' => $matches->pluck('course_id')->unique()->count(),
            'programs' => $results->pluck('programs')->flatten()->pluck('program_id')->unique()->count(),
            'topics' => $matches->where('property', 'topic')->count(),
            'learning_outcomes' => $matches->where('property', 'learning outcome')->count(),
            'assessments' => $matches->where('property', 'assessment')->count(),
            'descriptions' => $matches->where('property', 'description')->count(),
            'materials' => $matches->where('property', 'material')->count(),];
    }   




}

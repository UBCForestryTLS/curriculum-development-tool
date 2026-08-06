<?php

namespace App\Http\Controllers;

use App\Helpers\SearchFilterOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder; //Builder is for a DB query that is still being constructed

class SearchController extends Controller

{
    /**
     * Validates and normalizes the search request, collects matching course and program results,
     * and displays the selected search results view.
     *
     * @param Request $request The incoming request containing the search query and selected result view
     *
     * @return view - the search page with its query, results, statistics, and selected view
     */
    public function index(Request $request){

        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:200'],
            'saved_filter_id' => ['nullable', 'integer'],
            'view' => ['nullable', 'in:courses,programs'],
            'property_filters_applied' => ['nullable', 'boolean'],
            'properties' => ['nullable', 'array'],
            'properties.*' => [
                SearchFilterOptions::propertyValidationRule(),
            ],
            'course_filters_applied' => ['nullable', 'boolean'],
            'course_codes' => ['nullable', 'array'],
            'course_codes.*' => ['nullable', 'string', 'max:10'],
            'course_levels' => ['nullable', 'array'],
            'course_levels.*' => ['nullable', 'in:100,200,300,400,500,600'],
            'program_filters_applied' => ['nullable', 'boolean'],
            'program_ids' => ['nullable', 'array'],
            'program_ids.*' => ['nullable', 'integer'],
        ]);
        // The query is optional, and the result view must be one of the supported options
        // we also validate property filters applied

        $searchTerm = $validated['query'] ?? '';
        $searchTerm = trim($searchTerm);
        $searchTerm = preg_replace('/\s+/', ' ', $searchTerm); #for normalizing internal whitepace
        $selectedView = $validated['view'] ?? 'courses';

        $propertyOptions = SearchFilterOptions::properties();
        $availableProperties = SearchFilterOptions::propertyKeys();

        $propertyFiltersApplied = (bool) ($validated['property_filters_applied'] ?? false);
        $selectedProperties = $propertyFiltersApplied ? ($validated['properties'] ?? []) : $availableProperties;

        $availableCourseCodes = DB::table('courses')
            //instead of having fixed course codes, we take avaialble courses from what is already in the DB,
            //since only those need to be searchable
            ->whereNotNull('course_code')
            ->where('course_code', '!=', '')
            ->distinct()
            ->orderBy('course_code')
            ->pluck('course_code')
            ->map(fn ($code) => strtoupper(trim($code)))
            ->unique()
            ->values()
            ->all();

        $courseFiltersApplied = (bool) ($validated['course_filters_applied'] ?? false);
        $selectedCourseCodes = $courseFiltersApplied
            ? collect($validated['course_codes'] ?? [])
                ->filter(fn ($code) => is_string($code) && trim($code) !== '')
                ->map(fn ($code) => strtoupper(trim($code)))
                ->unique()
                ->values()
                ->all()

            : [];

        $selectedCourseLevels = $courseFiltersApplied
            ? collect($validated['course_levels'] ?? [])
                ->filter(fn ($level) => is_string($level) && trim($level) !== '')
                ->unique()
                ->values()
                ->all()
            : [];

        $availablePrograms = DB::table('programs')
            ->select('program_id', 'program')
            ->orderBy('program')
            ->get();

        $programFiltersApplied = (bool) ($validated['program_filters_applied'] ?? false);
        $selectedProgramIds = $programFiltersApplied
            ? collect($validated['program_ids'] ?? [])
                ->filter(fn ($programId) => is_numeric($programId))
                ->map(fn ($programId) => (int) $programId)
                ->unique()
                ->values()
                ->all()
            : [];
        $selectedProgramNames = $availablePrograms
            ->whereIn('program_id', $selectedProgramIds)
            ->pluck('program')
            ->values()
            ->all();

        $results = collect();
        $programMatches = collect();
        $programResults = collect();
        $courseQuickLinks = collect();
        $programQuickLinks = collect();
        $stats =  [//search statistics
            'courses' => 0,
            'programs' => 0,
            'topics' => 0,
            'learning_outcomes' => 0,
            'assessments' => 0,
            'descriptions' => 0,
            'materials' => 0,
        ];

        $presetApplied = (bool) $request->session()->get('preset_applied', false);
        $searchPerformed = $searchTerm !== '' && ! $presetApplied;

        if($searchPerformed){
            $resultsAndStats = $this->searchCourses(
                $searchTerm,
                $selectedProperties,
                $selectedCourseCodes,
                $selectedCourseLevels,
                $selectedProgramIds,
            );
            $results = $resultsAndStats['results'];
            $stats = $resultsAndStats['stats'];
            $programMatches = $this->searchProgramNames($searchTerm, $selectedCourseCodes, $selectedCourseLevels);
            $programResults = $this->groupCourseResultsByProgram($results,$programMatches,$selectedProgramIds);

            $stats['programs'] = $programResults->count();
            $courseQuickLinks = $results;
            $programQuickLinks = $programResults;

            if ($selectedView === 'programs') {
                $stats['courses'] = $programResults
                    ->flatMap(fn ($program) => $program->courses)
                    ->pluck('course_id')
                    ->unique()
                    ->count();
                    //for the program view, only courses assigned to a program are counted in the statistics

                $courseQuickLinks = $programResults
                    ->flatMap(fn ($program) => $program->courses)
                    ->unique('course_id')
                    ->values();
            }

        }

        $results = $this->paginateResults($results, $request); //handle many restults via pagination
        $programResults = $this->paginateResults($programResults, $request);
        $savedSearchFilters = $request->user()
            ? $request->user()->savedSearchFilters()->latest()->get()
            : collect();
        $currentSavedFilter = isset($validated['saved_filter_id'])
            ? $savedSearchFilters->firstWhere('id', $validated['saved_filter_id'])
            : null;

        return view('search.index', [
            'searchTerm' => $searchTerm,
            'results' => $results,
            'stats' => $stats,
            'selectedView' => $selectedView,
            'programMatches' => $programMatches,
            'programResults' => $programResults,
            'courseQuickLinks' => $courseQuickLinks,
            'programQuickLinks' => $programQuickLinks,
            'selectedProperties' => $selectedProperties,
            'propertyOptions' => $propertyOptions,
            'availableCourseCodes' => $availableCourseCodes,
            'selectedCourseCodes' => $selectedCourseCodes,
            'selectedCourseLevels' => $selectedCourseLevels,
            'availablePrograms' => $availablePrograms,
            'selectedProgramIds' => $selectedProgramIds,
            'selectedProgramNames' => $selectedProgramNames,
            'savedSearchFilters' => $savedSearchFilters,
            'currentSavedFilter' => $currentSavedFilter,
            'searchPerformed' => $searchPerformed,
        ]);
        
}

    /**
     * searches the selected course properties (if selected) and prepares combined results and statistics
     *
     * @param string $searchTerm The normalized text to search for.
     * @param array $selectedProperties The course properties included in the search.
     * @param array $selectedCourseCodes The course codes included in the search.
     * @param array $selectedCourseLevels The course number levels included in the search.
     * @param array $selectedProgramIds The program IDs included in the search.
     *
     * @return array The combined course results and overall search statistics.
     */
    public function searchCourses(
        string $searchTerm,
        array $selectedProperties,
        array $selectedCourseCodes,
        array $selectedCourseLevels,
        array $selectedProgramIds,
    ){
        $searchResults = collect(); 

        //if the property is selected in filters, only then call search and merge
        if (in_array('course', $selectedProperties)) {
            $searchResults = $searchResults->merge(
                $this->searchCourseNames($searchTerm, $selectedCourseCodes, $selectedCourseLevels, $selectedProgramIds)
            );
        }

        if (in_array('topics', $selectedProperties)) {
            $searchResults = $searchResults->merge(
                $this->searchTopics($searchTerm, $selectedCourseCodes, $selectedCourseLevels, $selectedProgramIds)
            );
        }

        if (in_array('learning_outcomes', $selectedProperties)) {
            $searchResults = $searchResults->merge(
                $this->searchLearningObjectives($searchTerm, $selectedCourseCodes, $selectedCourseLevels, $selectedProgramIds)
            );
        }

        if (in_array('assessments', $selectedProperties)) {
            $searchResults = $searchResults->merge(
                $this->searchAssessments($searchTerm, $selectedCourseCodes, $selectedCourseLevels, $selectedProgramIds)
            );
        }

        if (in_array('descriptions', $selectedProperties)) {
            $searchResults = $searchResults->merge(
                $this->searchDescriptions($searchTerm, $selectedCourseCodes, $selectedCourseLevels, $selectedProgramIds)
            );
        }

        if (in_array('materials', $selectedProperties)) {
            $searchResults = $searchResults->merge(
                $this->searchMaterials($searchTerm, $selectedCourseCodes, $selectedCourseLevels, $selectedProgramIds)
            );
        }

        $results = $this->combineMatchesByCourse($searchResults);
        $results = $this->attachProgramsToCourseResults($results);
        $stats = $this->calculateSearchStats($searchResults, $results);

        return [
            'results' => $results,
            'stats' => $stats,
        ];
    }

    /**
     * Restricts a course-backed search query to the selected course codes and number levels.
     *
     * @param Builder $query The search query containing a join to the courses table.
     * @param array $courseCodes The selected course codes, such as CONS or FRST.
     * @param array $courseLevels The selected hundred-level ranges, such as 100 or 300.
     *
     * @return Builder The query with the selected course restrictions applied.
     */
    private function applyCourseFilters(
        Builder $query,
        array $courseCodes,
        array $courseLevels
    ): Builder {
        if (!empty($courseCodes)) {
            $query->whereIn('courses.course_code', $courseCodes);
            //this is the same things as adding "courses.course code IN ('...')"
        }

        if (!empty($courseLevels)) {
            $query->where(function (Builder $levelQuery) use ($courseLevels) {
                foreach ($courseLevels as $level) {
                    $minimum = (int) $level;

                    $levelQuery->orWhereBetween(
                        'courses.course_num',
                        [$minimum, $minimum + 99] //in the query, levels become ranges (300 -> 300-399)
                    );
                }
            });
        }

        return $query;
    }

    /**
     * Paginates a result collection while preserving the current search query parameters.
     *
     * @param Collection $results The complete collection of results to paginate.
     * @param Request $request The current request used to build pagination URLs.
     *
     * @return LengthAwarePaginator The current page of results and its pagination metadata.
     */
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

    /**
     * Attaches each matching course's associated programs to its result object.
     *
     * @param Collection $results The combined course results to enrich with program information.
     *
     * @return Collection The course results with a programs collection attached to each course.
     */
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

    /**
     * Finds course topics matching the search term and creates highlighted result snippets.
     *
     * @param string $searchTerm The normalized text to search for.
     * @param array $courseCodes The selected course codes.
     * @param array $courseLevels The selected course number levels.
     * @param array $selectedProgramIds The selected program IDs used to restrict matching courses.
     *
     * @return Collection The matching topic records with their course details and snippets.
     */
    public function searchTopics(string $searchTerm, array $courseCodes, array $courseLevels, array $selectedProgramIds){
        $query = DB::table('course_topics')
            ->join('courses', 'courses.course_id', '=', 'course_topics.course_id');

        $query = $this->applyCourseFilters($query, $courseCodes, $courseLevels);
        $query = $this->applyProgramFilters($query, $selectedProgramIds);

        $results = $query->whereRaw( //need to use raw SQL to support PostgreSQL full-text search functions
                "course_topics.search_vector @@ websearch_to_tsquery('english', ?)",
                //this would match against the already generated vector so PostgreSQL can use its GIN index
                //so no need to call to_tsvector() directly
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

    /**
     * Finds learning outcomes matching the search term and creates highlighted result snippets.
     *
     * @param string $searchTerm The normalized text to search for.
     * @param array $courseCodes The selected course codes.
     * @param array $courseLevels The selected course number levels.
     * @param array $selectedProgramIds The selected program IDs used to restrict matching courses.
     *
     * @return Collection The matching learning outcomes with their course details and snippets.
     */
    public function searchLearningObjectives(string $searchTerm, array $courseCodes, array $courseLevels, array $selectedProgramIds){
        $query = DB::table('learning_outcomes')
            ->join('courses', 'courses.course_id', '=', 'learning_outcomes.course_id');

        $query = $this->applyCourseFilters($query, $courseCodes, $courseLevels);
        $query = $this->applyProgramFilters($query, $selectedProgramIds);

        $results = $query->whereRaw(
            "learning_outcomes.search_vector @@ websearch_to_tsquery('english', ?)",
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

    /**
     * Finds course descriptions matching the search term and creates highlighted result snippets.
     *
     * @param string $searchTerm The normalized text to search for.
     * @param array $courseCodes The selected course codes.
     * @param array $courseLevels The selected course number levels.
     * @param array $selectedProgramIds The selected program IDs used to restrict matching courses.
     *
     * @return Collection The matching descriptions with their course details and snippets.
     */
    public function searchDescriptions(string $searchTerm, array $courseCodes, array $courseLevels, array $selectedProgramIds){
        $query = DB::table('course_description')
            ->join('courses', 'courses.course_id', '=', 'course_description.course_id');

        $query = $this->applyCourseFilters($query, $courseCodes, $courseLevels);
        $query = $this->applyProgramFilters($query, $selectedProgramIds);

        $results = $query->whereRaw(
            "course_description.search_vector @@ websearch_to_tsquery('english', ?)",
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

    /**
     * Searches material names, types, and descriptions and creates highlighted result snippets.
     *
     * @param string $searchTerm The normalized text to search for.
     * @param array $courseCodes The selected course codes.
     * @param array $courseLevels The selected course number levels.
     * @param array $selectedProgramIds The selected program IDs used to restrict matching courses.
     *
     * @return Collection The matching materials with their course details and snippets.
     */
    public function searchMaterials(string $searchTerm, array $courseCodes, array $courseLevels, array $selectedProgramIds){
        $query = DB::table('course_materials')
            ->join('courses', 'courses.course_id', '=', 'course_materials.course_id');

        $query = $this->applyCourseFilters($query, $courseCodes, $courseLevels);
        $query = $this->applyProgramFilters($query, $selectedProgramIds);

        $results = $query->whereRaw(
            "course_materials.search_vector @@ websearch_to_tsquery('english', ?)",
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

    /**
     * Finds assessment methods matching the search term and creates highlighted result snippets.
     *
     * @param string $searchTerm The normalized text to search for.
     * @param array $courseCodes The selected course codes.
     * @param array $courseLevels The selected course number levels.
     * @param array $selectedProgramIds The selected program IDs used to restrict matching courses.
     *
     * @return Collection The matching assessments with their course details and snippets.
     */
    public function searchAssessments(string $searchTerm, array $courseCodes, array $courseLevels, array $selectedProgramIds){
        $query = DB::table('assessment_methods')
            ->join('courses', 'courses.course_id', '=', 'assessment_methods.course_id');

        $query = $this->applyCourseFilters($query, $courseCodes, $courseLevels);
        $query = $this->applyProgramFilters($query, $selectedProgramIds);

        $results = $query->whereRaw(
            "assessment_methods.search_vector @@ websearch_to_tsquery('english', ?)",
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

    /**
     * Searches course codes, numbers, and titles for direct course matches.
     *
     * @param string $searchTerm The normalized text to search for.
     * @param array $courseCodes The selected course codes.
     * @param array $courseLevels The selected course number levels.
     * @param array $selectedProgramIds The selected program IDs used to restrict matching courses.
     *
     * @return Collection The matching courses with highlighted course snippets.
     */
    public function searchCourseNames(string $searchTerm, array $courseCodes, array $courseLevels, array $selectedProgramIds){
        $searchText = "concat_ws(' ', courses.course_code, courses.course_num, courses.course_title)";
        $normalizedSearchTerm = preg_replace('/^([A-Za-z]+(?:_[A-Za-z]+)?)\s*(\d+)\b/', '$1 $2', $searchTerm); //normalize compact course codes at the start of a query

        $query = DB::table('courses');

        $query = $this->applyCourseFilters($query, $courseCodes, $courseLevels);
        $query = $this->applyProgramFilters($query, $selectedProgramIds);

        $results = $query->whereRaw(
                "courses.search_vector @@ websearch_to_tsquery('english', ?)",
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

    /**
     * Searches program names for direct program matches.
     *
     * @param string $searchTerm The normalized text to search for.
     *
     * @return Collection The matching programs with highlighted name snippets.
     */
    public function searchProgramNames(string $searchTerm, array $selectedCourseCodes, array $selectedCourseLevels){
        $query = DB::table('programs')
            ->whereRaw(
                "programs.search_vector @@ websearch_to_tsquery('english', ?)",
                [$searchTerm]
            );

        // If course filters are active, a direct program-name match should only remain
        // if the program has at least one course that matches those selected course filters.
        if (!empty($selectedCourseCodes) || !empty($selectedCourseLevels)) {
            $query->whereExists(function (Builder $courseFilterQuery) use ($selectedCourseCodes, $selectedCourseLevels) {
                $courseFilterQuery->select(DB::raw(1))
                    ->from('course_programs')
                    ->join('courses', 'courses.course_id', '=', 'course_programs.course_id')
                    ->whereColumn('course_programs.program_id', 'programs.program_id');

                $this->applyCourseFilters($courseFilterQuery, $selectedCourseCodes, $selectedCourseLevels);
            });
        }

        $results = $query
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

    /**
     * Applies the selected program filters from the search request, filtering only selected programs by building a sub query
     * 
     * @param Builder $query The query to be built on using SQL to apply program filters
     * @param array $programIds User selected program Ids for filtering
     * 
     * @return Builder Query with slected program restrictions applied.
     */
    private function applyProgramFilters(Builder $query, array $programIds): Builder{
        if(empty($programIds)){
            return $query;
        }

        // Program membership lives in the course_programs pivot table, so we use EXISTS to filter
        // courses by selected programs (preventing duplicate course rows when course belongs to multiple programs)- using subquery
        return $query->whereExists(function ($subquery) use ($programIds){
            $subquery->select(DB::raw(1))
                ->from('course_programs')
                ->whereColumn('course_programs.course_id', 'courses.course_id')
                ->whereIn('course_programs.program_id', $programIds);
        });
    }

    /**
     * Groups matching courses under their programs and merges them with direct program matches
     *
     * @param Collection $courseResults The combined matching course results and their programs
     * @param Collection $programMatches Programs whose names directly matched the search term
     *
     * @param array $selectedProgramIds Program IDs used to limit Program view groups.
     *
     * @return Collection One result per program containing its matching courses
     */
    public function groupCourseResultsByProgram(Collection $courseResults, Collection $programMatches, array $selectedProgramIds): Collection {
        $programResults = collect();

        foreach ($programMatches as $match) {
            if (! empty($selectedProgramIds) && ! in_array($match->program_id, $selectedProgramIds)) {
                continue;
                //skip programs that were not selected by user filters (so the program view does not return non-selected programs)
                //filters direct program-name matches
            }

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
                if (! empty($selectedProgramIds) && ! in_array($program->program_id, $selectedProgramIds)) {
                    continue;
                    //similar skip as above: filters programs associated with matching courses
                    //both checks are necessary because either source can independently add a program to results
                }

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

    /**
     * Combines raw property matches by course and calculates scores and per-course statistics.
     *
     * @param Collection $matches Raw matches returned by all course property searches.
     *
     * @return Collection One ranked result per matching course.
     */
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

    /**
     * Calculates distinct course and program totals and match counts for each course property.
     *
     * @param Collection $matches Raw matches returned by all course property searches.
     * @param Collection $results Combined course results with their associated programs.
     *
     * @return array The overall course, program, and property match totals.
     */
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

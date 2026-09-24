<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use App\Models\StandardCategory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardCourseProgressTest extends TestCase
{
    use DatabaseTransactions;

    public function test_basic_progress_queries_are_batched_for_the_current_page(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $courses = Course::factory()->count(16)->create(['updated_at' => now()]);
        $user->courses()->attach($courses->modelKeys(), ['permission' => 3]);
        $this->actingAs($user);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $response = $this->get(route('home'))->assertOk();
            $queryLog = collect(DB::getQueryLog());
            $queries = $queryLog->pluck('query');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $basicQueries = $queries->filter(fn ($sql) => str_contains($sql, '"course_description"')
            || str_contains($sql, '"learning_outcomes_count"') || str_contains($sql, '"course_topics"')
            || str_contains($sql, '"course_materials"'));
        $this->assertCount(3, $basicQueries);
        $visibleIds = $response->viewData('myCourses')->modelKeys();
        $this->assertCount(15, $visibleIds);
        foreach ($basicQueries as $sql) {
            $this->assertSame(1, preg_match('/ in \(([\d, ]+)\)/', $sql, $matches));
            $this->assertEqualsCanonicalizing($visibleIds, array_map('intval', explode(',', $matches[1])));
        }
        $alignmentQueries = $queryLog->filter(fn ($entry) => str_contains($entry['query'], '"outcome_assessments"')
            || str_contains($entry['query'], '"outcome_activities"'));
        $this->assertCount(3, $alignmentQueries);
        foreach ($alignmentQueries as $entry) {
            $this->assertSame($visibleIds, $entry['bindings']);
        }
        $mappingQueries = $queryLog->filter(fn ($entry) => str_contains($entry['query'], '"outcome_maps"')
            || str_contains($entry['query'], '"standards_outcome_maps"'));
        $this->assertCount(2, $mappingQueries);
        foreach ($mappingQueries as $entry) {
            $this->assertSame($visibleIds, $entry['bindings']);
        }
        $this->assertCount(1, $queries->filter(fn ($sql) => str_contains($sql, 'from "standards"')));
        $this->assertCount(1, $queries->filter(fn ($sql) => str_contains($sql, 'from "program_learning_outcomes"') && ! str_contains($sql, ' join ')));
        $this->assertCount(0, $queries->filter(fn ($sql) => str_starts_with($sql, 'select * from "courses" where "courses"."course_id" =')));
        // Simple per-course counts were replaced by the batched facts.
        $oldCounts = $queries->filter(fn ($sql) => str_starts_with($sql, 'select count(*) as aggregate from ')
            && ! str_contains($sql, ' join ') && preg_match('/"(learning_outcomes|assessment_methods|learning_activities)"/', $sql));
        $this->assertCount(0, $oldCounts);
    }

    public function test_basic_progress_preserves_percentages_and_remaining_tasks(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $category = StandardCategory::has('standards')->firstOrFail();
        $courses = Course::factory()->count(3)->create(['standard_category_id' => $category->standard_category_id]);
        $user->courses()->attach($courses->modelKeys(), ['permission' => 3]);
        [$empty, $partial, $complete] = $courses->all();
        $partial->courseDescription()->create(['description' => '']);
        foreach ([$partial, $complete] as $course) {
            $course->courseTopics()->create(['topic' => 'Test topic', 'position' => 0]);
            $course->courseMaterials()->create(['name' => 'Reading', 'type' => 'reading', 'position' => 0]);
        }
        // "0" is a non-empty description under the existing progress rules.
        $complete->courseDescription()->create(['description' => '0']);
        $complete->learningOutcomes()->create(['l_outcome' => 'Explain the topic']);
        $complete->assessmentMethods()->create(['a_method' => 'Exam', 'weight' => 100]);
        $complete->learningActivities()->create(['l_activity' => 'Discussion']);

        $response = $this->actingAs($user)->get(route('home'))->assertOk();
        $basicTasks = [
            'Course Description (Step 1)', 'Course Topics (Step 2)', 'Course Materials (Step 3)',
            'Course Learning Outcomes (Step 4)', 'Student Assessment Methods (Step 5)',
            'Teaching and Learning Activities (Step 6)',
        ];
        $remainingTasks = [
            'Assessment Methods - Course Alignment (Step 7)',
            'Learning Activities - Course Alignment (Step 7)', 'Program Outcome Mapping (Step 8)', 'Standards (Step 9)',
        ];
        foreach ([[$empty, 0, $basicTasks], [$partial, 20, array_diff_key($basicTasks, [1 => true, 2 => true])], [$complete, 60, []]] as [$course, $percentage, $tasks]) {
            $this->assertSame($percentage, $response->viewData('progressBar')[$course->course_id]);
            $expected = '<b>Remaining Tasks</b> <ol>';
            foreach (array_merge($tasks, $remainingTasks) as $task) {
                $expected .= '<li>'.$task.'</li>';
            }
            $this->assertSame($expected.'</ol>', $response->viewData('progressBarMsg')[$course->course_id]['statusMsg']);
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\StandardCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DashboardCourseAlignmentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_alignment_preserves_progress_and_remaining_tasks(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $category = StandardCategory::has('standards')->firstOrFail();
        $cases = [
            'no CLOs' => [[], [], [], 20, 'none'],
            'unmapped' => [[0, 1], [], [], 30, 'none'],
            'assessment only' => [[0, 1], [0, 1], [], 40, 'partial'],
            'activity only' => [[0, 1], [], [0, 1], 40, 'partial'],
            'different CLOs aligned' => [[0, 1], [0], [1], 40, 'partial'],
            'one CLO complete' => [[0, 1], [0], [0], 40, 'partial'],
            'fully aligned' => [[0, 1], [0, 1], [0, 1], 50, 'complete'],
        ];
        $expected = [];
        foreach ($cases as $name => [$cloIndexes, $assessmentIndexes, $activityIndexes, $percentage, $state]) {
            $course = Course::factory()->create(['standard_category_id' => $category->standard_category_id]);
            $user->courses()->attach($course->course_id, ['permission' => 3]);
            $assessment = $course->assessmentMethods()->create(['a_method' => 'Exam', 'weight' => 100]);
            $activity = $course->learningActivities()->create(['l_activity' => 'Discussion']);
            foreach ($cloIndexes as $index) {
                $clo = $course->learningOutcomes()->create(['l_outcome' => 'Explain topic '.$index]);
                if (in_array($index, $assessmentIndexes, true)) {
                    $clo->assessmentMethods()->attach($assessment->a_method_id);
                }
                if (in_array($index, $activityIndexes, true)) {
                    $clo->learningActivities()->attach($activity->l_activity_id);
                }
            }
            $expected[$course->course_id] = [$name, $percentage, $state];
        }

        $response = $this->actingAs($user)->get(route('home'))->assertOk();
        foreach ($expected as $courseId => [$name, $percentage, $state]) {
            $this->assertSame($percentage, $response->viewData('progressBar')[$courseId], $name);
            $message = $response->viewData('progressBarMsg')[$courseId]['statusMsg'];
            preg_match_all('/<li>([^<]*Course Alignment[^<]*)<\/li>/', $message, $matches);
            $this->assertSame(match ($state) {
                'none' => ['Assessment Methods - Course Alignment (Step 7)', 'Learning Activities - Course Alignment (Step 7)'],
                'partial' => ['Course Alignment (Step 7)'],
                'complete' => [],
            }, $matches[1], $name);
        }
    }
}

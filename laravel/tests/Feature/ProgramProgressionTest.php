<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProgramProgressionTest extends TestCase
{
    use DatabaseTransactions;

    private function createProgram(): Program
    {
        return Program::create([
            'program' => 'Progression Test Program',
            'level' => 'Bachelors',
            'status' => 1,
        ]);
    }

    private function signInAsViewer(Program $program): void
    {
        $user = User::factory()->create();
        $program->users()->attach($user->id, ['permission' => 3]);
        $this->actingAs($user);
    }

    public function test_viewer_can_get_an_empty_program(): void
    {
        $program = $this->createProgram();
        $this->signInAsViewer($program);

        $this->getJson(route('programWizard.progression', $program->program_id))
            ->assertOk()
            ->assertExactJson([
                'program_id' => $program->program_id,
                'program_totals' => ['course_count' => 0, 'clo_count' => 0],
                'courses' => [],
            ]);
    }

    public function test_it_returns_current_program_courses_and_all_their_clos(): void
    {
        $program = $this->createProgram();
        $otherProgram = $this->createProgram();
        $this->signInAsViewer($program);

        $required = Course::factory()->create([
            'course_code' => 'ENVD',
            'course_num' => '101',
            'course_title' => 'Environmental Data',
        ]);
        $optional = Course::factory()->create();
        $unspecified = Course::factory()->create(['course_num' => null]);
        $outside = Course::factory()->create();
        $removed = Course::factory()->create();

        $program->courses()->attach([
            $required->course_id => ['course_required' => 1],
            $optional->course_id => ['course_required' => 0],
            $unspecified->course_id => ['course_required' => null],
            $removed->course_id => ['course_required' => 1],
        ]);
        $program->courses()->detach($removed->course_id);
        $otherProgram->courses()->attach([
            $required->course_id => ['course_required' => 0],
            $outside->course_id => ['course_required' => 1],
        ]);

        $firstClo = LearningOutcome::create([
            'course_id' => $required->course_id,
            'l_outcome' => 'Analyze environmental data and explain the results.',
            'clo_shortphrase' => 'Data analysis',
        ]);
        $secondClo = LearningOutcome::create([
            'course_id' => $required->course_id,
            'l_outcome' => 'Describe research methods.',
        ]);
        // Identical text still represents a separate CLO when its ID is different.
        $thirdClo = LearningOutcome::create([
            'course_id' => $optional->course_id,
            'l_outcome' => $secondClo->l_outcome,
        ]);
        foreach ([$outside, $removed] as $course) {
            LearningOutcome::create([
                'course_id' => $course->course_id,
                'l_outcome' => 'An outcome outside the current program.',
            ]);
        }

        $this->getJson(route('programWizard.progression', $program->program_id))
            ->assertOk()
            ->assertJsonPath('program_totals', ['course_count' => 3, 'clo_count' => 3])
            ->assertJsonCount(3, 'courses')
            ->assertJsonPath('courses.0.course_id', $required->course_id)
            ->assertJsonPath('courses.0.course_code', 'ENVD')
            ->assertJsonPath('courses.0.course_num', '101')
            ->assertJsonPath('courses.0.course_title', 'Environmental Data')
            ->assertJsonPath('courses.0.course_required', true)
            ->assertJsonPath('courses.0.clos', [
                ['l_outcome_id' => $firstClo->l_outcome_id, 'l_outcome' => $firstClo->l_outcome, 'clo_shortphrase' => 'Data analysis'],
                ['l_outcome_id' => $secondClo->l_outcome_id, 'l_outcome' => $secondClo->l_outcome, 'clo_shortphrase' => null],
            ])
            ->assertJsonPath('courses.1.course_id', $optional->course_id)
            ->assertJsonPath('courses.1.course_required', false)
            ->assertJsonPath('courses.1.clos.0.l_outcome_id', $thirdClo->l_outcome_id)
            ->assertJsonPath('courses.2.course_id', $unspecified->course_id)
            ->assertJsonPath('courses.2.course_num', null)
            ->assertJsonPath('courses.2.course_required', null)
            ->assertJsonPath('courses.2.clos', []);
    }

    public function test_user_without_program_access_cannot_get_progression_data(): void
    {
        $program = $this->createProgram();

        $this->actingAs(User::factory()->create())
            ->getJson(route('programWizard.progression', $program->program_id))
            ->assertRedirect(route('home'));
    }

    public function test_guest_cannot_get_progression_data(): void
    {
        $program = $this->createProgram();

        $this->getJson(route('programWizard.progression', $program->program_id))
            ->assertUnauthorized();
    }
}

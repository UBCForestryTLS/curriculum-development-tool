<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProgramAvailableCoursesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_only_accessible_courses_outside_the_program_are_returned(): void
    {
        $user = User::factory()->create();
        $program = Program::create(['program' => 'Available courses test', 'level' => 'Undergraduate', 'status' => 0]);
        $user->programs()->attach($program->program_id, ['permission' => 2]);
        $available = Course::factory()->create();
        $existing = Course::factory()->create();
        Course::factory()->create();
        $user->courses()->attach($available->course_id, ['permission' => 3]);
        $user->courses()->attach($existing->course_id, ['permission' => 1]);
        $program->courses()->attach($existing->course_id);

        $this->actingAs($user)->getJson(route('programWizard.availableCourses', $program->program_id))
            ->assertOk()->assertExactJson(['courses' => [$available->fresh()->only([
                'course_id', 'course_title', 'course_code', 'course_num', 'year', 'semester',
            ])]]);
    }

    public function test_program_access_is_required_and_empty_results_are_supported(): void
    {
        $user = User::factory()->create();
        $program = Program::create(['program' => 'Available courses access', 'level' => 'Undergraduate', 'status' => 0]);
        $url = route('programWizard.availableCourses', $program->program_id);
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($user)->getJson($url)->assertForbidden();
        $user->programs()->attach($program->program_id, ['permission' => 3]);
        $this->getJson($url)->assertForbidden();
        $user->programs()->updateExistingPivot($program->program_id, ['permission' => 1]);
        $this->getJson($url)->assertOk()->assertExactJson(['courses' => []]);
        $this->getJson(route('programWizard.availableCourses', 0))->assertNotFound();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProgramCourseSubmissionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_submission_requires_verified_program_edit_access(): void
    {
        $user = User::factory()->create();
        $program = Program::create(['program' => 'Submission access', 'level' => 'Undergraduate', 'status' => 0]);
        $course = Course::factory()->create();
        $url = route('courseProgram.addCoursesToProgram', $program->program_id);
        $data = ['program_id' => $program->program_id, 'selectedCourses' => [$course->course_id]];

        $this->postJson($url, $data)->assertUnauthorized();
        $this->actingAs($user)->postJson($url, $data)->assertForbidden();
        $user->programs()->attach($program->program_id, ['permission' => 3]);
        $this->postJson($url, $data)->assertForbidden();
        $user->programs()->updateExistingPivot($program->program_id, ['permission' => 1]);
        $user->email_verified_at = null;
        $user->save();
        $this->postJson($url, $data)->assertForbidden();
        $this->assertSame(0, $program->courses()->count());
    }

    public function test_invalid_selections_and_mismatched_programs_do_not_save_any_courses(): void
    {
        $user = User::factory()->create();
        $program = Program::create(['program' => 'Selection validation', 'level' => 'Undergraduate', 'status' => 0]);
        $user->programs()->attach($program->program_id, ['permission' => 1]);
        $allowed = Course::factory()->create();
        $other = Course::factory()->create();
        $user->courses()->attach($allowed->course_id, ['permission' => 3]);
        $url = route('courseProgram.addCoursesToProgram', $program->program_id);
        $this->actingAs($user);

        foreach (['invalid', [$allowed->course_id, $allowed->course_id], ['not-an-id']] as $selection) {
            $this->postJson($url, ['program_id' => $program->program_id, 'selectedCourses' => $selection])->assertUnprocessable();
        }
        $this->postJson($url, ['program_id' => 0, 'selectedCourses' => [$allowed->course_id]])->assertUnprocessable();
        foreach ([$other->course_id, 0] as $inaccessibleId) {
            $this->postJson($url, ['program_id' => $program->program_id, 'selectedCourses' => [$allowed->course_id, $inaccessibleId]])->assertForbidden();
        }
        $this->assertSame(0, $program->courses()->count());
    }

    public function test_program_director_can_add_courses_with_only_role_based_program_access(): void
    {
        $user = User::factory()->create();
        $program = Program::create(['program' => 'Role-based submission', 'level' => 'Undergraduate', 'status' => 0]);
        $role = Role::where('role', 'program director')->firstOrFail();
        $user->roles()->attach($role->id);
        $user->programsWithElevatedRoleAccess()->attach($program->program_id, ['role_id' => $role->id]);
        $course = Course::factory()->create();
        $user->courses()->attach($course->course_id, ['permission' => 3]);

        // Program access comes from the director role, without a direct permission.
        $this->assertFalse($user->programs()->where('programs.program_id', $program->program_id)->exists());
        $this->assertSame(1, $user->effectivePermissionForProgram($program->program_id));

        $this->actingAs($user)->post(route('courseProgram.addCoursesToProgram', $program->program_id), [
            'program_id' => $program->program_id,
            'selectedCourses' => [$course->course_id],
            'require'.$course->course_id => 'on',
        ])->assertRedirect(route('programWizard.step3', $program->program_id))
            ->assertSessionHas('success', 'Successfully added 1 course(s) to this program.');

        $this->assertDatabaseHas('course_programs', [
            'program_id' => $program->program_id,
            'course_id' => $course->course_id,
            'course_required' => 1,
        ]);
    }

    public function test_program_director_cannot_add_courses_to_another_program(): void
    {
        $user = User::factory()->create();
        $program = Program::create(['program' => 'Directed program', 'level' => 'Undergraduate', 'status' => 0]);
        $otherProgram = Program::create(['program' => 'Other program', 'level' => 'Undergraduate', 'status' => 0]);
        $role = Role::where('role', 'program director')->firstOrFail();
        $user->roles()->attach($role->id);
        $user->programsWithElevatedRoleAccess()->attach($program->program_id, ['role_id' => $role->id]);
        $course = Course::factory()->create();
        // The course is accessible, so rejection must come from the program access check.
        $user->courses()->attach($course->course_id, ['permission' => 3]);

        $this->actingAs($user)->postJson(route('courseProgram.addCoursesToProgram', $otherProgram->program_id), [
            'program_id' => $otherProgram->program_id,
            'selectedCourses' => [$course->course_id],
        ])->assertForbidden();

        $this->assertSame(0, $otherProgram->courses()->count());
    }

    public function test_editor_can_add_accessible_courses_with_required_settings(): void
    {
        $user = User::factory()->create();
        $program = Program::create(['program' => 'Valid submission', 'level' => 'Undergraduate', 'status' => 0]);
        $user->programs()->attach($program->program_id, ['permission' => 2]);
        $courses = Course::factory()->count(2)->create();
        foreach ($courses as $course) {
            $user->courses()->attach($course->course_id, ['permission' => 3]);
        }
        $this->actingAs($user)->post(route('courseProgram.addCoursesToProgram', $program->program_id), [
            'program_id' => $program->program_id, 'selectedCourses' => $courses->modelKeys(),
            'require'.$courses[0]->course_id => 'on',
        ])->assertRedirect(route('programWizard.step3', $program->program_id));
        foreach ($courses as $index => $course) {
            $this->assertDatabaseHas('course_programs', ['program_id' => $program->program_id,
                'course_id' => $course->course_id, 'course_required' => $index === 0 ? 1 : 0]);
        }
    }
}

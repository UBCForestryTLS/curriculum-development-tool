<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CourseCollaboratorModalTest extends TestCase
{
    use DatabaseTransactions;

    #[DataProvider('accessCases')]
    public function test_modal_reuses_permissions_and_preserves_controls(?int $permission, ?string $roleName, bool $supplied): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        if ($permission !== null) {
            $user->courses()->attach($course->course_id, ['permission' => $permission]);
        }
        if ($roleName !== null) {
            $role = Role::where('role', $roleName)->firstOrFail();
            $user->roles()->attach($role->id);
            $user->coursesWithElevatedRoleAccess()->attach($course->course_id, ['role_id' => $role->id]);
        }
        // Multiple collaborators must not cause repeated access lookups.
        foreach ([1, 2, 3] as $collaboratorPermission) {
            $course->users()->attach(User::factory()->create()->id, ['permission' => $collaboratorPermission]);
        }
        $course->load('users');
        $effectivePermission = $user->effectivePermissionForCourse($course->course_id);
        $viewer = Mockery::mock(User::class)->makePartial();
        $viewer->setRawAttributes($user->getAttributes());
        $viewer->shouldNotReceive('allCourses');
        $data = ['course' => $course, 'user' => $viewer];
        if ($supplied) {
            $data['courseUserPermission'] = $effectivePermission;
            $viewer->shouldNotReceive('effectivePermissionForCourse');
        } else {
            $viewer->shouldReceive('effectivePermissionForCourse')->once()
                ->with($course->course_id)->passthru();
        }

        $html = explode('<script>', (string) $this->view('courses.courseCollabs', $data))[0];

        $this->assertSame($effectivePermission === 1, str_contains($html, 'class="addCourseCollabForm'));
        $this->assertSame($effectivePermission !== 1, str_contains($html, 'class="form-select" disabled required'));
        $this->assertSame($permission === 1, str_contains($html, 'onclick="deleteCourseCollab(this)"'));
        $this->assertSame($permission === 1, str_contains($html, 'data-bs-target="#transferCourseConfirmation'));
        $this->assertSame(in_array($permission, [2, 3], true), str_contains($html, 'data-bs-target="#leaveCourseConfirmation'));
    }

    public static function accessCases(): iterable
    {
        $cases = [
            'owner' => [1, null],
            'editor' => [2, null],
            'viewer' => [3, null],
            'administrator' => [null, 'administrator'],
            'program director' => [null, 'program director'],
            'department head' => [null, 'department head'],
            'elevated viewer' => [3, 'program director'],
            'elevated owner' => [1, 'program director'],
        ];
        foreach ($cases as $name => [$permission, $role]) {
            yield "$name supplied" => [$permission, $role, true];
            yield "$name fallback" => [$permission, $role, false];
        }
    }
}

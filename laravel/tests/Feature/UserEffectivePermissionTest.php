<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserEffectivePermissionTest extends TestCase
{
    use DatabaseTransactions;

    // Check both methods against explicit permissions, rather than dashboard results.
    // $roleAccess identifies where the role applies; $permission is direct access to the target.
    #[DataProvider('permissionCases')]
    public function test_effective_permissions(?int $permission, ?string $roleName, string $roleAccess, int $expected): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $program = Program::create(['program' => 'Permission test program', 'level' => 'Undergraduate', 'status' => 0]);

        if ($permission !== null) {
            $user->courses()->attach($course->course_id, ['permission' => $permission]);
            $user->programs()->attach($program->program_id, ['permission' => $permission]);
        }

        if ($roleName !== null) {
            $role = Role::where('role', $roleName)->firstOrFail();
            $roleUser = $roleAccess === 'other user' ? User::factory()->create() : $user;
            $roleUser->roles()->attach($role->id);
            $roleCourse = $course;
            $roleProgram = $program;

            if ($roleAccess === 'other records') {
                $roleCourse = Course::factory()->create();
                $roleProgram = Program::create(['program' => 'Other program', 'level' => 'Undergraduate', 'status' => 0]);
                $user->courses()->attach($roleCourse->course_id, ['permission' => 1]);
                $user->programs()->attach($roleProgram->program_id, ['permission' => 1]);
            }

            // A global role alone has no course/program access row.
            if ($roleAccess !== 'global only') {
                $roleUser->coursesWithElevatedRoleAccess()->attach($roleCourse->course_id, ['role_id' => $role->id]);
                $roleUser->programsWithElevatedRoleAccess()->attach($roleProgram->program_id, ['role_id' => $role->id]);
            }
        }

        $this->assertSame($expected, $user->effectivePermissionForCourse($course->course_id));
        $this->assertSame($expected, $user->effectivePermissionForProgram($program->program_id));
    }

    public static function permissionCases(): iterable
    {
        yield 'no access' => [null, null, 'none', 0];
        yield 'direct zero permission' => [0, null, 'none', 0];
        yield 'direct owner' => [1, null, 'none', 1];
        yield 'direct editor' => [2, null, 'none', 2];
        yield 'direct viewer' => [3, null, 'none', 3];

        foreach (['administrator', 'program director', 'department head'] as $role) {
            yield "$role access" => [null, $role, 'target', 1];
            yield "$role overrides editor" => [2, $role, 'target', 1];
            yield "$role overrides viewer" => [3, $role, 'target', 1];
            yield "$role global only" => [null, $role, 'global only', 0];
            yield "$role on other records" => [null, $role, 'other records', 0];
            yield "$role for another user" => [null, $role, 'other user', 0];
        }

        yield 'non-elevated role gives no access' => [null, 'user', 'target', 0];
        yield 'non-elevated role preserves direct access' => [3, 'user', 'target', 3];
    }
}

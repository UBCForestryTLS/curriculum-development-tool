<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProgramCollaboratorModalTest extends TestCase
{
    use DatabaseTransactions;

    #[DataProvider('accessCases')]
    public function test_modal_reuses_permissions_and_preserves_controls(?int $permission, ?string $roleName, bool $supplied): void
    {
        $user = User::factory()->create();
        $program = Program::create(['program' => 'Dashboard test program', 'level' => 'Undergraduate', 'status' => 0]);
        if ($permission !== null) {
            $user->programs()->attach($program->program_id, ['permission' => $permission]);
        }
        if ($roleName !== null) {
            $role = Role::where('role', $roleName)->firstOrFail();
            $user->roles()->attach($role->id);
            $user->programsWithElevatedRoleAccess()->attach($program->program_id, ['role_id' => $role->id]);
        }
        // Multiple collaborators must not cause repeated access lookups.
        foreach ([1, 2, 3] as $collaboratorPermission) {
            $program->users()->attach(User::factory()->create()->id, ['permission' => $collaboratorPermission]);
        }
        $program->load('users');
        $effectivePermission = $user->effectivePermissionForProgram($program->program_id);
        $viewer = Mockery::mock(User::class)->makePartial();
        $viewer->setRawAttributes($user->getAttributes());
        $viewer->shouldNotReceive('allPrograms');
        $data = ['program' => $program, 'user' => $viewer];
        if ($supplied) {
            $data['programUserPermission'] = $effectivePermission;
            $viewer->shouldNotReceive('effectivePermissionForProgram');
        } else {
            $viewer->shouldReceive('effectivePermissionForProgram')->once()
                ->with($program->program_id)->passthru();
        }

        $html = explode('<script>', (string) $this->view('programs.programCollabs', $data))[0];

        $this->assertSame($effectivePermission === 1, str_contains($html, 'class="addProgramCollabForm'));
        $this->assertSame($effectivePermission !== 1, str_contains($html, 'class="form-select" disabled required'));
        $this->assertSame($effectivePermission === 1, str_contains($html, 'onclick="deleteProgramCollab(this)"'));
        $this->assertSame($permission === 1, str_contains($html, 'data-bs-target="#transferProgramConfirmation'));
        $this->assertSame(in_array($permission, [2, 3], true), str_contains($html, 'data-bs-target="#leaveProgramConfirmation'));
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

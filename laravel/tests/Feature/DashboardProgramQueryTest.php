<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardProgramQueryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_direct_owners_editors_and_viewers_match_existing_dashboard_access(): void
    {
        $user = User::factory()->create();
        $expected = [];
        foreach ([1, 2, 3, 0] as $permission) {
            $program = $this->createProgram();
            $user->programs()->attach($program->program_id, ['permission' => $permission]);
            if ($permission !== 0) {
                $expected[$program->program_id] = $permission;
            }
        }
        $otherUser = User::factory()->create();
        $otherUser->programs()->attach($this->createProgram()->program_id, ['permission' => 1]);
        $course = Course::factory()->create();
        $user->courses()->attach($course->course_id, ['permission' => 1]);
        $course->programs()->attach($this->createProgram()->program_id);

        $actual = $user->dashboardProgramsQuery()->get()->pluck('userPermission', 'program_id')->sortKeys()->all();
        $this->assertSame($expected, $actual);
        $this->assertSame($this->existingDashboardPermissions($user), $actual);
    }

    public function test_elevated_access_overrides_direct_access_without_duplicate_programs(): void
    {
        $user = User::factory()->create();
        $shared = $this->createProgram();
        $user->programs()->attach($shared->program_id, ['permission' => 3]);
        foreach (['administrator', 'program director', 'department head'] as $name) {
            $role = Role::where('role', $name)->firstOrFail();
            $user->roles()->attach($role->id);
            foreach ([$shared, $this->createProgram()] as $program) {
                $user->programsWithElevatedRoleAccess()->attach($program->program_id, ['role_id' => $role->id]);
            }
        }
        // A global role alone does not add programs without stored dashboard access.
        $this->createProgram();

        $programs = $user->dashboardProgramsQuery()->get();
        $this->assertCount(4, $programs);
        $this->assertSame([1], $programs->pluck('userPermission')->unique()->values()->all());
        $this->assertSame($this->existingDashboardPermissions($user), $programs->pluck('userPermission', 'program_id')->sortKeys()->all());
    }

    public function test_non_elevated_role_rows_do_not_grant_dashboard_access(): void
    {
        $user = User::factory()->create();
        $role = Role::where('role', 'user')->firstOrFail();
        $user->roles()->attach($role->id);
        $program = $this->createProgram();
        $user->programsWithElevatedRoleAccess()->attach($program->program_id, ['role_id' => $role->id]);

        $this->assertSame([], $this->existingDashboardPermissions($user));
        $this->assertSame(0, $user->dashboardProgramsQuery()->count());
        $user->programs()->attach($program->program_id, ['permission' => 3]);
        $this->assertSame(3, $user->dashboardProgramsQuery()->firstOrFail()->userPermission);
    }

    public function test_query_can_paginate_without_loading_all_accessible_programs(): void
    {
        $user = User::factory()->create();
        $programs = collect(range(1, 16))->map(fn () => $this->createProgram());
        foreach ($programs as $program) {
            $user->programs()->attach($program->program_id, ['permission' => 3]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $query = $user->dashboardProgramsQuery();
            $this->assertSame([], DB::getQueryLog());
            $page = $query->orderBy('programs.program_id')->paginate(15, ['*'], 'programs_page', 2);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame(16, $page->total());
        $this->assertCount(1, $page->items());
        $this->assertSame($programs->last()->program_id, $page->items()[0]->program_id);
        $this->assertSame(3, $page->items()[0]->userPermission);
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('limit 15 offset 15', strtolower($queries[1]['query']));
    }

    private function createProgram(): Program
    {
        return Program::create(['program' => 'Dashboard test program', 'level' => 'Undergraduate', 'status' => 0]);
    }

    private function existingDashboardPermissions(User $user): array
    {
        return $user->allPrograms()
            ->mapWithKeys(fn ($program) => [$program->program_id => $user->effectivePermissionForProgram($program->program_id)])
            ->filter(fn ($permission) => in_array($permission, [1, 2, 3], true))
            ->sortKeys()->all();
    }
}

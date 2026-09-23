<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardCourseQueryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_direct_owners_editors_and_viewers_match_existing_dashboard_access(): void
    {
        $user = User::factory()->create();
        $expected = [];
        foreach ([1, 2, 3, 0] as $permission) {
            $course = Course::factory()->create();
            $user->courses()->attach($course->course_id, ['permission' => $permission]);
            if ($permission !== 0) {
                $expected[$course->course_id] = $permission;
            }
        }
        $otherUser = User::factory()->create();
        $otherUser->courses()->attach(Course::factory()->create()->course_id, ['permission' => 1]);
        Course::factory()->create();

        $actual = $user->dashboardCoursesQuery()->get()->pluck('userPermission', 'course_id')->sortKeys()->all();
        $this->assertSame($expected, $actual);
        $this->assertSame($this->existingDashboardPermissions($user), $actual);
    }

    public function test_elevated_access_overrides_direct_access_without_duplicate_courses(): void
    {
        $user = User::factory()->create();
        $shared = Course::factory()->create();
        $user->courses()->attach($shared->course_id, ['permission' => 3]);
        foreach (['administrator', 'program director', 'department head'] as $name) {
            $role = Role::where('role', $name)->firstOrFail();
            $user->roles()->attach($role->id);
            foreach ([$shared, Course::factory()->create()] as $course) {
                $user->coursesWithElevatedRoleAccess()->attach($course->course_id, ['role_id' => $role->id]);
            }
        }
        // A global role alone does not add courses without stored dashboard access.
        Course::factory()->create();

        $courses = $user->dashboardCoursesQuery()->get();
        $this->assertCount(4, $courses);
        $this->assertSame([1], $courses->pluck('userPermission')->unique()->values()->all());
        $this->assertSame($this->existingDashboardPermissions($user), $courses->pluck('userPermission', 'course_id')->sortKeys()->all());
    }

    public function test_non_elevated_role_rows_do_not_grant_dashboard_access(): void
    {
        $user = User::factory()->create();
        $role = Role::where('role', 'user')->firstOrFail();
        $user->roles()->attach($role->id);
        $course = Course::factory()->create();
        $user->coursesWithElevatedRoleAccess()->attach($course->course_id, ['role_id' => $role->id]);

        $this->assertSame([], $this->existingDashboardPermissions($user));
        $this->assertSame(0, $user->dashboardCoursesQuery()->count());
        $user->courses()->attach($course->course_id, ['permission' => 3]);
        $this->assertSame(3, $user->dashboardCoursesQuery()->firstOrFail()->userPermission);
    }

    public function test_query_can_paginate_without_loading_all_accessible_courses(): void
    {
        $user = User::factory()->create();
        $courses = Course::factory()->count(16)->create();
        foreach ($courses as $course) {
            $user->courses()->attach($course->course_id, ['permission' => 3]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $query = $user->dashboardCoursesQuery();
            $this->assertSame([], DB::getQueryLog());
            $page = $query->orderBy('courses.course_id')->paginate(15, ['*'], 'courses_page', 2);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame(16, $page->total());
        $this->assertCount(1, $page->items());
        $this->assertSame($courses->last()->course_id, $page->items()[0]->course_id);
        $this->assertSame(3, $page->items()[0]->userPermission);
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('limit 15 offset 15', strtolower($queries[1]['query']));
    }

    private function existingDashboardPermissions(User $user): array
    {
        return $user->allCourses()
            ->mapWithKeys(fn ($course) => [$course->course_id => $user->effectivePermissionForCourse($course->course_id)])
            ->filter(fn ($permission) => in_array($permission, [1, 2, 3], true))
            ->sortKeys()->all();
    }
}

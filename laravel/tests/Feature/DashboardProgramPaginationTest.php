<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardProgramPaginationTest extends TestCase
{
    use DatabaseTransactions;

    #[DataProvider('singlePageCounts')]
    public function test_empty_and_full_single_pages(int $count): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        for ($i = 0; $i < $count; $i++) {
            $user->programs()->attach($this->createProgram()->program_id, ['permission' => 3]);
        }

        $response = $this->actingAs($user)->get(route('home'))->assertOk();
        $this->assertCount($count, $response->viewData('myPrograms'));
        $this->assertSame($count, $response->viewData('programsPaginator')->total());
        $this->assertFalse($response->viewData('programsPaginator')->hasPages());
        if ($count > 0) {
            $response->assertSee('Showing 1–15 of 15 programs');
        }
    }

    public static function singlePageCounts(): array
    {
        return [[0], [15]];
    }

    public function test_program_and_course_pages_navigate_independently(): void
    {
        $this->withoutVite();
        $this->freezeTime();
        $user = User::factory()->create();
        $programs = collect(range(1, 16))->map(fn () => $this->createProgram());
        foreach ($programs as $index => $program) {
            $user->programs()->attach($program->program_id, ['permission' => ($index % 3) + 1]);
        }
        $programs->last()->forceFill(['updated_at' => now()->subDay()])->save();
        $role = Role::where('role', 'program director')->firstOrFail();
        $user->roles()->attach($role->id);
        $user->programsWithElevatedRoleAccess()->attach($programs[2]->program_id, ['role_id' => $role->id]);
        $hidden = $this->createProgram();
        $courses = Course::factory()->count(16)->create();
        $user->courses()->attach($courses->modelKeys(), ['permission' => 3]);

        $first = $this->actingAs($user)->get(route('home', ['courses_page' => 2]))->assertOk();
        $expectedIds = $programs->take(15)->reverse()->pluck('program_id')->values()->all();
        $this->assertSame($expectedIds, $first->viewData('myPrograms')->modelKeys());
        $this->assertSame($expectedIds, array_keys($first->viewData('programUsers')));
        $this->assertSame(16, $first->viewData('programsPaginator')->total());
        $this->assertSame(1, $first->viewData('myPrograms')->find($programs[2]->program_id)->userPermission);
        $this->assertTrue($first->viewData('myPrograms')->every(fn ($program) => $program->relationLoaded('users')));
        $first->assertSee('Showing 1–15 of 16 programs')
            ->assertDontSee('id="addProgramCollaboratorsModal'.$hidden->program_id.'"', false)
            ->assertDontSee('id="addProgramCollaboratorsModal'.$programs->last()->program_id.'"', false);
        $nextUrl = $first->viewData('programsPaginator')->nextPageUrl();
        $this->assertStringContainsString('courses_page=2', $nextUrl);
        $this->assertStringContainsString('programs_page=2#dashboard-programs', $nextUrl);
        $first->assertSee('href="'.e($nextUrl).'"', false);

        $second = $this->get($nextUrl)->assertOk()->assertSee('Showing 16–16 of 16 programs');
        $this->assertSame([$programs->last()->program_id], $second->viewData('myPrograms')->modelKeys());
        $this->assertSame($first->viewData('myCourses')->modelKeys(), $second->viewData('myCourses')->modelKeys());
        $this->assertSame(1, substr_count($second->getContent(), '<div id="addProgramCollaboratorsModal'));
        $back = $this->get($second->viewData('programsPaginator')->previousPageUrl())->assertOk();
        $this->assertSame($expectedIds, $back->viewData('myPrograms')->modelKeys());

        $courseUrl = $second->viewData('coursesPaginator')->previousPageUrl();
        $this->assertStringContainsString('programs_page=2', $courseUrl);
        $this->assertStringContainsString('courses_page=1#dashboard-courses', $courseUrl);
        $second->assertSee('href="'.e($courseUrl).'"', false);
        $coursePage = $this->get($courseUrl)->assertOk();
        $this->assertSame([$programs->last()->program_id], $coursePage->viewData('myPrograms')->modelKeys());
        $this->assertSame($courses->reverse()->take(15)->values()->modelKeys(), $coursePage->viewData('myCourses')->modelKeys());
    }

    private function createProgram(): Program
    {
        return Program::create(['program' => 'Dashboard test program', 'level' => 'Undergraduate', 'status' => 0]);
    }
}

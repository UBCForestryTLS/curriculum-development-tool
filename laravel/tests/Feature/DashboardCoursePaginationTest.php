<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardCoursePaginationTest extends TestCase
{
    use DatabaseTransactions;

    #[DataProvider('singlePageCounts')]
    public function test_empty_and_full_single_pages(int $count): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $courses = Course::factory()->count($count)->create();
        $user->courses()->attach($courses->modelKeys(), ['permission' => 3]);

        $response = $this->actingAs($user)->get(route('home'))->assertOk();

        $this->assertCount($count, $response->viewData('myCourses'));
        $this->assertCount($count, $response->viewData('progressBar'));
        $this->assertSame($count, $response->viewData('coursesPaginator')->total());
        $this->assertFalse($response->viewData('coursesPaginator')->hasPages());
        if ($count > 0) {
            $response->assertSee('Showing 1–15 of 15 courses');
        }
    }

    public static function singlePageCounts(): array
    {
        return [[0], [15]];
    }

    public function test_next_and_previous_pages_preserve_order_permissions_and_query_parameters(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $courses = Course::factory()->count(16)->create(['updated_at' => now()->startOfDay()]);
        foreach ($courses as $index => $course) {
            $user->courses()->attach($course->course_id, ['permission' => ($index % 3) + 1]);
        }
        // An older update sorts after the tied courses, even with a higher ID.
        $courses->last()->forceFill(['updated_at' => now()->subDay()])->save();
        $expectedIds = $courses->take(15)->reverse()->values()->modelKeys();
        Course::factory()->create(['course_title' => 'Inaccessible dashboard course']);

        $first = $this->actingAs($user)->get(route('home', ['programs_page' => 1]))->assertOk();
        $this->assertSame($expectedIds, $first->viewData('myCourses')->modelKeys());
        $this->assertSame($expectedIds, array_keys($first->viewData('progressBar')));
        $this->assertSame(16, $first->viewData('coursesPaginator')->total());
        foreach ($first->viewData('myCourses') as $course) {
            $this->assertTrue($course->relationLoaded('users') && $course->relationLoaded('programs'));
            $this->assertSame($course->users->find($user->id)->pivot->permission, $course->userPermission);
        }
        $first->assertSee('Showing 1–15 of 16 courses')
            ->assertDontSee('Inaccessible dashboard course')
            ->assertDontSee('id="addCourseCollaboratorsModal'.$courses->last()->course_id.'"', false);
        $nextUrl = $first->viewData('coursesPaginator')->nextPageUrl();
        $this->assertStringContainsString('programs_page=1', $nextUrl);
        $this->assertStringContainsString('courses_page=2#dashboard-courses', $nextUrl);
        $first->assertSee('href="'.e($nextUrl).'"', false);

        $second = $this->get($nextUrl)->assertOk()->assertSee('Showing 16–16 of 16 courses');
        $this->assertSame([$courses->last()->course_id], $second->viewData('myCourses')->modelKeys());
        $this->assertSame([$courses->last()->course_id], array_keys($second->viewData('progressBar')));
        $this->assertSame(1, substr_count($second->getContent(), '<div id="addCourseCollaboratorsModal'));
        $previousUrl = $second->viewData('coursesPaginator')->previousPageUrl();
        $second->assertSee('href="'.e($previousUrl).'"', false);
        $back = $this->get($previousUrl)->assertOk();
        $this->assertSame($expectedIds, $back->viewData('myCourses')->modelKeys());
    }
}

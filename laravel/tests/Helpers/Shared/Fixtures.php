<?php

use App\Models\Course;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Pest\Browser\Api\PendingAwaitablePage;

function makeTestUser(string $email): User
{
    $user = User::where('email', $email)->first();
    if ($user) return $user;

    DB::table('users')->insert([
        'name' => 'E2E Test User',
        'email' => $email,
        'email_verified_at' => Carbon::now(),
        'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    ]);

    return User::where('email', $email)->first();
}

function makeTestCourse(string $title): Course
{
    return Course::create([
        'course_code' => 'E2E',
        'course_num' => 101,
        'course_title' => $title,
        'delivery_modality' => 'O',
        'year' => 2026,
        'semester' => 'W1',
        'assigned' => 1,
        'type' => 'unassigned',
        'scale_category_id' => 1,
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);
}

function linkUserToCourse(User $user, Course $course, int $permission = 1): void
{
    DB::table('course_users')->insert([
        'course_id' => $course->course_id,
        'user_id' => $user->id,
        'permission' => $permission,
    ]);
}

/**
 * Wraps Pest's visit with viewport dimensions.
 * Seems to reduce the flakiness by preventing elements from going off-screen.
 * Make sure to set the resolution to something SMALLER than your monitor's resolution
 */
function visit_v(string $url) : PendingAwaitablePage
{
    $width = env('PLAYWRIGHT_VIEWPORT_WIDTH');
    $height = env('PLAYWRIGHT_VIEWPORT_HEIGHT');

    if ($width && $height) {
        $page = visit($url, [
            'viewport' => ['width' => (int) $width, 'height' => (int) $height],
            'deviceScaleFactor' => 1,
        ]);
    } else {
        $page = visit($url);
    }

    return $page;
}

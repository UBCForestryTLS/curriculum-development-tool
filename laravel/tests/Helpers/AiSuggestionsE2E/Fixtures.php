<?php

use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\Program;
use App\Models\ProgramLearningOutcome;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Api\PendingAwaitablePage;

function makeTestUser(string $email): User
{
    DB::table('users')->insert([
        'name' => 'Fixture User',
        'email' => $email,
        'email_verified_at' => Carbon::now(),
        'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    ]);

    return User::where('email', $email)->first();
}

function makeTestCourse(string $title = 'E2E Fixture Course'): Course
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
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);
}

function makeTestProgram(string $name = 'E2E Fixture Program'): Program
{
    return Program::create([
        'program' => $name,
        'faculty' => 'Irving K. Barber Faculty of Science',
        'department' => 'Computer Science',
        'level' => 'Bachelors',
        'status' => 1,
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

function linkUserToProgram(User $user, Program $program, int $permission = 1): void
{
    DB::table('program_users')->insert([
        'program_id' => $program->program_id,
        'user_id' => $user->id,
        'permission' => $permission,
    ]);
}

function linkCourseToProgram(Course $course, Program $program): void
{
    DB::table('course_programs')->insert([
        'course_id' => $course->course_id,
        'program_id' => $program->program_id,
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);
}

function addCloToCourse(Course $course, string $text, string $shortphrase = 'Test CLO'): LearningOutcome
{
    return LearningOutcome::create([
        'course_id' => $course->course_id,
        'l_outcome' => $text,
        'clo_shortphrase' => $shortphrase,
    ]);
}

function addPloToProgram(Program $program, string $text, string $shortphrase = 'Test PLO'): ProgramLearningOutcome
{
    DB::table('program_learning_outcomes')->insert([
        'program_id' => $program->program_id,
        'pl_outcome' => $text,
        'plo_shortphrase' => $shortphrase,
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);

    return ProgramLearningOutcome::where('program_id', $program->program_id)
        ->where('pl_outcome', $text)
        ->first();
}

function attachMappingScalesToProgram(Program $program, array $mapScaleIds = [1, 2, 3]): void
{
    foreach ($mapScaleIds as $id) {
        DB::table('mapping_scale_programs')->insert([
            'map_scale_id' => $id,
            'program_id' => $program->program_id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }
}

// Test helper functions used to simulate the SageMaker Lambda's operations

function queryLOMappingService(int $courseId, int $programId, string $endpoint, bool $useCIDPID = true, array $body = []): array
{
    $baseUrl = getenv('LO_MAPPING_SERVICE_URL') ?: 'http://127.0.0.1:8002';
    $response = Http::post(
        rtrim($baseUrl, '/') . ($useCIDPID ? "/test/{$endpoint}/{$courseId}/{$programId}"
            : "/test/{$endpoint}"),
        $body
    );
    if (!$response->successful()) {
        throw new RuntimeException(
            "Failed to query {$endpoint}. " .
            "Make sure FastAPI is running in test mode (python -m app.test). " .
            "Status: {$response->status()}, Body: {$response->body()}"
        );
    }
    return $response->json();
}


function putPendingRecord(int $courseId, int $programId): string
{
    return queryLOMappingService($courseId, $programId, 'put-pending-record')['request_id'];
}

function markRecordInProgress(int $courseId, int $programId): void
{
    queryLOMappingService($courseId, $programId, 'mark-record-in-progress');
}

function deleteAiRecords(int $courseId, int $programId): void
{
    queryLOMappingService($courseId, $programId, 'delete-records');
}

function getDynamoDBRecords(int $courseId, int $programId): array
{
    return queryLOMappingService($courseId, $programId, 'get-records')['records'];
}

function clearDynamoDb(): void
{
    // 0, 0 just dummy values for CID, PID here
    queryLOMappingService(0, 0, 'clear-dynamodb-aisuggestions', useCIDPID: false);
}

/**
 * Writes mock SageMaker output to S3,
 * and moves record state from IN_PROGRESS to AWAITING_COMPLETION in DynamoDB
 */
function setAwaitingCompletion(int $courseId, int $programId, array $suggestions): void
{
    queryLOMappingService($courseId, $programId, 'set-awaiting-completion', body: ['suggestions' => $suggestions]);
}

// CSS selector helpers for various browser tests

function aiIconForCloPlo(int $cloId, int $ploId, int $scale): string
{
    // The int $scale refers to the INDEX of the intended mapping scale column
    return "input[name=\"map[{$cloId}][{$ploId}][]\"][value=\"{$scale}\"]"
        . ' ~ img[src*="AISuggestionPurple.png"]';
}

function anyAiIconForClo(int $cloId): string
{
    // Checks that any AI suggestion exists, used with negative assertions to verify none exist
    return "input[name^=\"map[{$cloId}][\"] ~ img[src*=\"AISuggestionPurple.png\"]";
}

function anyAiIconForPlo(int $ploId): string
{
    // Checks that any AI suggestion exists, used with negative assertions to verify none exist
    return "input[name*=\"][{$ploId}][\"] ~ img[src*=\"AISuggestionPurple.png\"]";
}

function manualMapCheckbox(int $cloId, int $ploId, int $scale): string
{
    return "input[name=\"map[{$cloId}][{$ploId}][]\"][value=\"{$scale}\"]";
}

function manualMapField(int $cloId, int $ploId): string
{
    return "map[{$cloId}][{$ploId}][]";
}

function programAccordionToggle(int $programId): string
{
    return "button[data-bs-target=\"#collapseProgramAccordion{$programId}\"]";
}

function cloAccordionToggle(int $programId, int $cloId): string
{
    return "button[data-bs-target=\"#collapse{$programId}-{$cloId}\"]";
}

function createManuallyButton(int $courseId, int $programId): string
{
    return "button[onclick=\"showManualMapDiv({$courseId}, {$programId})\"]";
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


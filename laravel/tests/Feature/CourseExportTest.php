<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class CourseExportTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_course_exports_generate_valid_files(): void
    {
        $user = User::factory()->create();
        $course = $this->createAccessibleCourse($user);

        $pdfPath = 'course-'.$course->course_id.'.pdf';
        $spreadsheetPath = 'spreadsheets/data-summary-course-'.$course->course_id.'.xlsx';

        $this->actingAs($user)->get(route('courses.pdf', $course->course_id))->assertOk();

        Storage::disk('public')->assertExists($pdfPath);
        $this->assertStringStartsWith('%PDF', Storage::disk('public')->get($pdfPath));
        $this->assertDirectoryDoesNotExist(Storage::disk('public')->path('spreadsheets'));

        $this->get(route('courses.dataSpreadsheet', $course->course_id))->assertOk();

        Storage::disk('public')->assertExists($spreadsheetPath);
        $this->assertSame('Xlsx', IOFactory::identify(Storage::disk('public')->path($spreadsheetPath)));
    }

    public function test_user_cannot_export_data_for_an_inaccessible_course(): void
    {
        $user = User::factory()->create();
        $course = $this->createCourse();
        $spreadsheetPath = 'spreadsheets/data-summary-course-'.$course->course_id.'.xlsx';

        $this->actingAs($user)
            ->get(route('courses.dataSpreadsheet', $course->course_id))
            ->assertRedirect('/courses');

        Storage::disk('public')->assertMissing($spreadsheetPath);
    }

    private function createAccessibleCourse(User $user): Course
    {
        $course = $this->createCourse();
        $course->users()->attach($user->id, ['permission' => 1]);

        return $course;
    }

    private function createCourse(): Course
    {
        return Course::factory()->create([
            'standard_category_id' => DB::table('standard_categories')->value('standard_category_id'),
            'scale_category_id' => DB::table('standards_scale_categories')->value('scale_category_id'),
        ]);
    }
}

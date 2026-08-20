<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\CourseMaterialChunk;
use App\Models\CourseMaterialFile;
use App\Models\CourseTopic;
use App\Models\SuggestedTopic;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SuggestedTopicTest extends TestCase
{
    public function test_suggested_topic_confirmation(): void
    {
        DB::table('users')->insert([
            'name' => 'Test Suggested Topic',
            'email' => 'test-suggested-topic@ubc.ca',
            'email_verified_at' => Carbon::now(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);

        $user = User::where('email', 'test-suggested-topic@ubc.ca')->first();

        $this->actingAs($user)->post(route('courses.store'), [
            'course_code' => 'TEST',
            'course_num' => 101,
            'course_year' => 2026,
            'course_semester' => 'W1',
            'course_title' => 'Suggested Topic Test Course',
            'delivery_modality' => 'O',
            'assigned' => 1,
            'type' => 'unassigned',
            'standard_category_id' => 1,
            'scale_category_id' => 1,
            'user_id' => $user->id,
        ]);

        $course = Course::where('course_title', 'Suggested Topic Test Course')->orderBy('course_id', 'DESC')->first();

        $material = CourseMaterial::create([
            'course_id' => $course->course_id,
            'name' => 'Test Material',
            'type' => 'article',
        ]);

        $file = CourseMaterialFile::create([
            'course_material_id' => $material->course_material_id,
            'course_id' => $course->course_id,
            'uploaded_by' => Auth::id(),
            'file_name' => 'test.pdf',
            'file_path' => 'course-materials/' . $course->course_id . '/test.pdf',
            'file_size' => 1024,
            'status' => CourseMaterialFile::STATUS_INDEXED,
            'extraction_engine' => 'tesseract',
            'ocr_enabled' => false,
            'ocr_threshold' => 0,
        ]);

        $suggested = SuggestedTopic::create([
            'course_material_file_id' => $file->course_material_file_id,
            'topic' => 'Forest Ecology',
            'score' => 0.75,
            'source' => 'keyword',
            'status' => SuggestedTopic::STATUS_PENDING,
        ]);

        $this->actingAs($user);

        $response = $this->post(route('course.material.files.topics.review', [
            $course->course_id,
            $material->course_material_id,
            $file->course_material_file_id,
        ]), [
            'decisions' => [
                ['id' => $suggested->suggested_topic_id, 'action' => 'confirm'],
            ],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('course_topics', [
            'course_id' => $course->course_id,
            'topic' => 'Forest Ecology',
        ]);

        $this->assertDatabaseMissing('suggested_topics', [
            'course_material_file_id' => $file->course_material_file_id,
            'topic' => 'Forest Ecology',
        ]);
    }

    public function test_confirm_topic_no_duplication(): void
    {
        $user = User::where('email', 'test-suggested-topic@ubc.ca')->first();
        $course = Course::where('course_title', 'Suggested Topic Test Course')->orderBy('course_id', 'DESC')->first();

        $material = CourseMaterial::create([
            'course_id' => $course->course_id,
            'name' => 'Test Material 2',
            'type' => 'article',
        ]);

        $file = CourseMaterialFile::create([
            'course_material_id' => $material->course_material_id,
            'course_id' => $course->course_id,
            'uploaded_by' => Auth::id(),
            'file_name' => 'test2.pdf',
            'file_path' => 'course-materials/' . $course->course_id . '/test2.pdf',
            'file_size' => 1024,
            'status' => CourseMaterialFile::STATUS_INDEXED,
            'extraction_engine' => 'tesseract',
            'ocr_enabled' => false,
            'ocr_threshold' => 0,
        ]);

        CourseTopic::create([
            'course_id' => $course->course_id,
            'topic' => 'Climate Change',
            'position' => 1,
        ]);

        $suggested = SuggestedTopic::create([
            'course_material_file_id' => $file->course_material_file_id,
            'topic' => 'Climate Change',
            'score' => 0.75,
            'source' => 'keyword',
            'status' => SuggestedTopic::STATUS_PENDING,
        ]);

        $this->actingAs($user);

        $this->post(route('course.material.files.topics.review', [
            $course->course_id,
            $material->course_material_id,
            $file->course_material_file_id,
        ]), [
            'decisions' => [
                ['id' => $suggested->suggested_topic_id, 'action' => 'confirm'],
            ],
        ]);

        $topicCount = CourseTopic::where('course_id', $course->course_id)
            ->where('topic', 'Climate Change')
            ->count();

        $this->assertEquals(1, $topicCount);
    }

    public function test_confirms_a_topic_case_insensitively_without_duplicating(): void
    {
        $user = User::where('email', 'test-suggested-topic@ubc.ca')->first();
        $course = Course::where('course_title', 'Suggested Topic Test Course')->orderBy('course_id', 'DESC')->first();

        $material = CourseMaterial::create([
            'course_id' => $course->course_id,
            'name' => 'Test Material 3',
            'type' => 'article',
        ]);

        $file = CourseMaterialFile::create([
            'course_material_id' => $material->course_material_id,
            'course_id' => $course->course_id,
            'uploaded_by' => Auth::id(),
            'file_name' => 'test3.pdf',
            'file_path' => 'course-materials/' . $course->course_id . '/test3.pdf',
            'file_size' => 1024,
            'status' => CourseMaterialFile::STATUS_INDEXED,
            'extraction_engine' => 'tesseract',
            'ocr_enabled' => false,
            'ocr_threshold' => 0,
        ]);

        CourseTopic::create([
            'course_id' => $course->course_id,
            'topic' => 'mycology basics',
            'position' => 2,
        ]);

        $suggested = SuggestedTopic::create([
            'course_material_file_id' => $file->course_material_file_id,
            'topic' => 'Mycology Basics',
            'score' => 0.75,
            'source' => 'keyword',
            'status' => SuggestedTopic::STATUS_PENDING,
        ]);

        $this->actingAs($user);

        $this->post(route('course.material.files.topics.review', [
            $course->course_id,
            $material->course_material_id,
            $file->course_material_file_id,
        ]), [
            'decisions' => [
                ['id' => $suggested->suggested_topic_id, 'action' => 'confirm'],
            ],
        ]);

        $topicCount = CourseTopic::where('course_id', $course->course_id)
            ->whereRaw('LOWER(topic) = ?', ['mycology basics'])
            ->count();

        $this->assertEquals(1, $topicCount);
    }

    public function test_reject_suggested_topic(): void
    {
        $user = User::where('email', 'test-suggested-topic@ubc.ca')->first();
        $course = Course::where('course_title', 'Suggested Topic Test Course')->orderBy('course_id', 'DESC')->first();

        $material = CourseMaterial::create([
            'course_id' => $course->course_id,
            'name' => 'Test Material 4',
            'type' => 'article',
        ]);

        $file = CourseMaterialFile::create([
            'course_material_id' => $material->course_material_id,
            'course_id' => $course->course_id,
            'uploaded_by' => Auth::id(),
            'file_name' => 'test4.pdf',
            'file_path' => 'course-materials/' . $course->course_id . '/test4.pdf',
            'file_size' => 1024,
            'status' => CourseMaterialFile::STATUS_INDEXED,
            'extraction_engine' => 'tesseract',
            'ocr_enabled' => false,
            'ocr_threshold' => 0,
        ]);

        $suggested = SuggestedTopic::create([
            'course_material_file_id' => $file->course_material_file_id,
            'topic' => 'Irrelevant Topic',
            'score' => 0.75,
            'source' => 'keyword',
            'status' => SuggestedTopic::STATUS_PENDING,
        ]);

        $this->actingAs($user);

        $this->post(route('course.material.files.topics.review', [
            $course->course_id,
            $material->course_material_id,
            $file->course_material_file_id,
        ]), [
            'decisions' => [
                ['id' => $suggested->suggested_topic_id, 'action' => 'reject'],
            ],
        ]);

        $suggested->refresh();
        $this->assertEquals(SuggestedTopic::STATUS_REJECTED, $suggested->status);

        $this->assertDatabaseMissing('course_topics', [
            'course_id' => $course->course_id,
            'topic' => 'Irrelevant Topic',
        ]);
    }

    public function test_accept_all_suggested_topics(): void
    {
        $user = User::where('email', 'test-suggested-topic@ubc.ca')->first();
        $course = Course::where('course_title', 'Suggested Topic Test Course')->orderBy('course_id', 'DESC')->first();

        $material = CourseMaterial::create([
            'course_id' => $course->course_id,
            'name' => 'Test Material 5',
            'type' => 'article',
        ]);

        $file = CourseMaterialFile::create([
            'course_material_id' => $material->course_material_id,
            'course_id' => $course->course_id,
            'uploaded_by' => Auth::id(),
            'file_name' => 'test5.pdf',
            'file_path' => 'course-materials/' . $course->course_id . '/test5.pdf',
            'file_size' => 1024,
            'status' => CourseMaterialFile::STATUS_INDEXED,
            'extraction_engine' => 'tesseract',
            'ocr_enabled' => false,
            'ocr_threshold' => 0,
        ]);

        SuggestedTopic::create([
            'course_material_file_id' => $file->course_material_file_id,
            'topic' => 'Topic A',
            'score' => 0.75,
            'source' => 'keyword',
            'status' => SuggestedTopic::STATUS_PENDING,
        ]);

        SuggestedTopic::create([
            'course_material_file_id' => $file->course_material_file_id,
            'topic' => 'Topic B',
            'score' => 0.75,
            'source' => 'keyword',
            'status' => SuggestedTopic::STATUS_PENDING,
        ]);

        $this->actingAs($user);

        $this->post(route('course.material.files.topics.accept-all', [
            $course->course_id,
            $material->course_material_id,
            $file->course_material_file_id,
        ]));

        $this->assertDatabaseHas('course_topics', [
            'course_id' => $course->course_id,
            'topic' => 'Topic A',
        ]);
        $this->assertDatabaseHas('course_topics', [
            'course_id' => $course->course_id,
            'topic' => 'Topic B',
        ]);

        $this->assertDatabaseMissing('suggested_topics', [
            'course_material_file_id' => $file->course_material_file_id,
            'status' => SuggestedTopic::STATUS_PENDING,
        ]);
    }

    public function test_reject_all_suggested_topics(): void
    {
        $user = User::where('email', 'test-suggested-topic@ubc.ca')->first();
        $course = Course::where('course_title', 'Suggested Topic Test Course')->orderBy('course_id', 'DESC')->first();

        $material = CourseMaterial::create([
            'course_id' => $course->course_id,
            'name' => 'Test Material 6',
            'type' => 'article',
        ]);

        $file = CourseMaterialFile::create([
            'course_material_id' => $material->course_material_id,
            'course_id' => $course->course_id,
            'uploaded_by' => Auth::id(),
            'file_name' => 'test6.pdf',
            'file_path' => 'course-materials/' . $course->course_id . '/test6.pdf',
            'file_size' => 1024,
            'status' => CourseMaterialFile::STATUS_INDEXED,
            'extraction_engine' => 'tesseract',
            'ocr_enabled' => false,
            'ocr_threshold' => 0,
        ]);

        SuggestedTopic::create([
            'course_material_file_id' => $file->course_material_file_id,
            'topic' => 'Reject All Topic A',
            'score' => 0.75,
            'source' => 'keyword',
            'status' => SuggestedTopic::STATUS_PENDING,
        ]);

        SuggestedTopic::create([
            'course_material_file_id' => $file->course_material_file_id,
            'topic' => 'Reject All Topic B',
            'score' => 0.75,
            'source' => 'keyword',
            'status' => SuggestedTopic::STATUS_PENDING,
        ]);

        $this->actingAs($user);

        $this->post(route('course.material.files.topics.reject-all', [
            $course->course_id,
            $material->course_material_id,
            $file->course_material_file_id,
        ]));

        $this->assertDatabaseHas('suggested_topics', [
            'course_material_file_id' => $file->course_material_file_id,
            'topic' => 'Reject All Topic A',
            'status' => SuggestedTopic::STATUS_REJECTED,
        ]);
        $this->assertDatabaseHas('suggested_topics', [
            'course_material_file_id' => $file->course_material_file_id,
            'topic' => 'Reject All Topic B',
            'status' => SuggestedTopic::STATUS_REJECTED,
        ]);

        $this->assertDatabaseMissing('course_topics', [
            'course_id' => $course->course_id,
            'topic' => 'Reject All Topic A',
        ]);
    }

    public function test_preserve_rejected_topics_after_refresh(): void
    {
        $user = User::where('email', 'test-suggested-topic@ubc.ca')->first();
        $course = Course::where('course_title', 'Suggested Topic Test Course')->orderBy('course_id', 'DESC')->first();

        $material = CourseMaterial::create([
            'course_id' => $course->course_id,
            'name' => 'Test Material 7',
            'type' => 'article',
        ]);

        $file = CourseMaterialFile::create([
            'course_material_id' => $material->course_material_id,
            'course_id' => $course->course_id,
            'uploaded_by' => Auth::id(),
            'file_name' => 'test7.pdf',
            'file_path' => 'course-materials/' . $course->course_id . '/test7.pdf',
            'file_size' => 1024,
            'status' => CourseMaterialFile::STATUS_INDEXED,
            'extraction_engine' => 'tesseract',
            'ocr_enabled' => false,
            'ocr_threshold' => 0,
        ]);

        SuggestedTopic::create([
            'course_material_file_id' => $file->course_material_file_id,
            'topic' => 'Rejected Topic',
            'score' => 0.75,
            'source' => 'keyword',
            'status' => SuggestedTopic::STATUS_REJECTED,
        ]);

        SuggestedTopic::create([
            'course_material_file_id' => $file->course_material_file_id,
            'topic' => 'Pending Topic',
            'score' => 0.75,
            'source' => 'match',
            'status' => SuggestedTopic::STATUS_PENDING,
        ]);

        CourseMaterialChunk::create([
            'course_material_file_id' => $file->course_material_file_id,
            'page_number' => 1,
            'chunk_index' => 0,
            'content' => 'Some extracted text.',
        ]);

        $this->actingAs($user);

        $this->post(route('course.material.files.refresh', [
            $course->course_id,
            $material->course_material_id,
            $file->course_material_file_id,
        ]));

        $this->assertDatabaseHas('suggested_topics', [
            'course_material_file_id' => $file->course_material_file_id,
            'topic' => 'Rejected Topic',
            'status' => SuggestedTopic::STATUS_REJECTED,
        ]);
    }

    public function test_reviews_with_empty_decisions_does_nothing(): void
    {
        // In case user saves without changes
        $user = User::where('email', 'test-suggested-topic@ubc.ca')->first();
        $course = Course::where('course_title', 'Suggested Topic Test Course')->orderBy('course_id', 'DESC')->first();

        $material = CourseMaterial::create([
            'course_id' => $course->course_id,
            'name' => 'Test Material 9',
            'type' => 'article',
        ]);

        $file = CourseMaterialFile::create([
            'course_material_id' => $material->course_material_id,
            'course_id' => $course->course_id,
            'uploaded_by' => Auth::id(),
            'file_name' => 'test9.pdf',
            'file_path' => 'course-materials/' . $course->course_id . '/test9.pdf',
            'file_size' => 1024,
            'status' => CourseMaterialFile::STATUS_INDEXED,
            'extraction_engine' => 'tesseract',
            'ocr_enabled' => false,
            'ocr_threshold' => 0,
        ]);

        $suggested = SuggestedTopic::create([
            'course_material_file_id' => $file->course_material_file_id,
            'topic' => 'Test Topic',
            'score' => 0.75,
            'source' => 'keyword',
            'status' => SuggestedTopic::STATUS_PENDING,
        ]);

        $this->actingAs($user);

        $this->post(route('course.material.files.topics.review', [
            $course->course_id,
            $material->course_material_id,
            $file->course_material_file_id,
        ]), [
            'decisions' => [],
        ]);

        $suggested->refresh();
        $this->assertEquals(SuggestedTopic::STATUS_PENDING, $suggested->status);
    }

    public function test_material_file_must_belong_to_the_accessible_course(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $accessibleCourse = Course::factory()->create();
        $accessibleCourse->users()->attach($user->id, ['permission' => 1]);

        $otherCourse = Course::factory()->create();
        $material = CourseMaterial::create([
            'course_id' => $otherCourse->course_id,
            'name' => 'Private Material',
            'type' => 'article',
        ]);
        $path = 'course-materials/' . $otherCourse->course_id . '/private.pdf';
        Storage::disk('local')->put($path, 'private content');

        $file = CourseMaterialFile::create([
            'course_material_id' => $material->course_material_id,
            'uploaded_by' => $user->id,
            'file_name' => 'private.pdf',
            'file_path' => $path,
            'file_size' => 15,
            'status' => CourseMaterialFile::STATUS_INDEXED,
        ]);

        $this->actingAs($user)
            ->get(route('course.material.files.view', [
                $accessibleCourse->course_id,
                $material->course_material_id,
                $file->course_material_file_id,
            ]))
            ->assertNotFound();
    }
}

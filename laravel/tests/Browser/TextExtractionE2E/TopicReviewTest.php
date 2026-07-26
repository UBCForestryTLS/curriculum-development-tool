<?php

use App\Models\CourseMaterialFile;
use App\Models\CourseTopic;
use App\Models\SuggestedTopic;

/**
 * End-to-end test for uploading a material file and reviewing its suggested topics.
 *
 * Uploads slides.pdf, waits for indexing, then reviews suggested topics
 * (confirm individually, accept all remaining) through the browser.
 * The text extraction service must be running on http://127.0.0.1:8003.
 *
 * Note: File upload is done via $this->post() as Pest runs in to some issues with UI file uploads on Windows
 */
it('uploads a file and reviews suggested topics through the full workflow', function () {
    $user = makeTestUser('text-e2e-review@ubc.ca');
    $course = makeTestCourse('Text E2E Review Test');
    $material = makeTestMaterial($course, 'Lecture Slides');
    linkUserToCourse($user, $course);

    $this->actingAs($user);

    $fixturePath = realpath(__DIR__ . '/fixtures/slides.pdf');
    $this->assertNotEmpty($fixturePath, 'Fixture file must exist: slides.pdf');

    // --- Upload the file (real POST to the real endpoint with the real PDF) ---
    $uploadResponse = $this->call('POST', route('course.material.files.store', [
        $course->course_id, $material->course_material_id,
    ]), [
        '_token' => csrf_token(),
        'ocr_enabled' => '0',
        'extraction_engine' => 'tesseract',
    ], [], [
        'file' => new \Symfony\Component\HttpFoundation\File\UploadedFile(
            $fixturePath, 'slides.pdf', 'application/pdf', null, true
        ),
    ]);

    $this->assertTrue(
        $uploadResponse->isRedirect() || $uploadResponse->isOk(),
        'Upload should succeed. Status: '.$uploadResponse->getStatusCode()
    );

    // IndexCourseMaterial runs synchronously (QUEUE_CONNECTION=sync)
    $file = CourseMaterialFile::where('course_material_id', $material->course_material_id)
        ->where('file_name', 'slides.pdf')
        ->first();
    $this->assertNotNull($file, 'File record should exist after upload');
    $this->assertEquals(CourseMaterialFile::STATUS_INDEXED, $file->status);

    // --- Navigate to the file review page through the browser ---
    $page = visit_v('/courseWizard/' . $course->course_id . '/step10');

    $page->pressAndWaitFor("button[data-bs-target=\"#filesRow-{$material->course_material_id}\"]", 1);
    $page->press("a[href*=\"files/{$file->course_material_file_id}\"]");
    $page->wait(2);

    $page->assertSee('Suggested Topics');

    // Suggested topics should have been extracted by the real service
    $suggested = SuggestedTopic::where('course_material_file_id', $file->course_material_file_id)
        ->where('status', SuggestedTopic::STATUS_PENDING)
        ->get();
    $this->assertNotEmpty($suggested, 'Text extraction service should have produced suggested topics');

    // --- Confirm the first suggested topic individually ---
    $firstSuggested = $suggested->first();
    $page->press(".suggested-topic-row[data-id=\"{$firstSuggested->suggested_topic_id}\"] .review-confirm");
    $page->wait(0.5);

    // Click Save to submit the review decision via the dynamically created form
    $page->pressAndWaitFor('#save-review-btn', 3);

    // A course topic was created and linked to the file
    $topicText = $firstSuggested->topic;
    $this->assertDatabaseHas('course_topics', [
        'course_id' => $course->course_id,
        'topic' => $topicText,
    ]);

    $courseTopic = CourseTopic::where('course_id', $course->course_id)
        ->where('topic', $topicText)
        ->first();
    $this->assertDatabaseHas('course_material_file_topic', [
        'course_material_file_id' => $file->course_material_file_id,
        'course_topic_id' => $courseTopic->course_topic_id,
    ]);

    // The confirmed suggested topic should have been deleted
    $this->assertDatabaseMissing('suggested_topics', [
        'suggested_topic_id' => $firstSuggested->suggested_topic_id,
    ]);

    // --- Accept all remaining via the Accept All button ---
    $page->script('window.confirm = () => true');
    $page->pressAndWaitFor('button:has-text("Accept All")', 3);

    // All remaining pending topics should now be confirmed
    $remainingTopics = SuggestedTopic::where('course_material_file_id', $file->course_material_file_id)
        ->where('status', SuggestedTopic::STATUS_PENDING)
        ->get();
    $this->assertTrue($remainingTopics->isEmpty(), 'All suggested topics should have been accepted');

    // Verify each accepted topic became a course topic linked to the file
    $allSuggested = SuggestedTopic::where('course_material_file_id', $file->course_material_file_id)->get();
    foreach ($allSuggested as $s) {
        $this->assertDatabaseHas('course_topics', [
            'course_id' => $course->course_id,
            'topic' => $s->topic,
        ]);
    }

    // The Topics card on the page should now list the confirmed topics
    $page->assertSee($topicText);
});

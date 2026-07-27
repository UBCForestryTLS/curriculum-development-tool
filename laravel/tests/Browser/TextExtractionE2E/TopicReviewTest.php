<?php

use App\Models\CourseMaterialFile;
use App\Models\CourseTopic;
use App\Models\SuggestedTopic;

/**
 * End-to-end test for uploading a material file and reviewing its suggested topics.
 *
 * Uploads slides.pdf through the browser, waits for indexing, then reviews suggested topics
 * (confirm individually, accept all remaining) through the browser.
 * The text extraction service must be running on http://127.0.0.1:8003.
 */
it('uploads a file and reviews suggested topics through the full workflow', function () {
    $user = makeTestUser('text-e2e-review@ubc.ca');
    $course = makeTestCourse('Text E2E Review Test');
    $material = makeTestMaterial($course, 'Lecture Slides');
    linkUserToCourse($user, $course);

    $this->actingAs($user);

    $sourcePath = realpath(__DIR__ . '/fixtures/slides.pdf');
    $this->assertNotEmpty($sourcePath, 'Fixture file must exist: slides.pdf');

    $page = visit_v('/courseWizard/' . $course->course_id . '/step10');
    $page->pressAndWaitFor('button:has-text("Show Files")', 1);
    $page->pressAndWaitFor('button:has-text("Add File")', 1);
    $page->attach('#uploadFileInput', $sourcePath);
    $page->pressAndWaitFor('#uploadFileForm button[type="submit"]', 10);

    $file = CourseMaterialFile::where('course_material_id', $material->course_material_id)
        ->where('file_name', 'slides.pdf')
        ->first();
    $this->assertNotNull($file, 'File record should exist after upload');
    $this->assertEquals(CourseMaterialFile::STATUS_INDEXED, $file->status);

    // Review the uploaded file's suggested topics
    $page->refresh()
        ->wait(4);

    $page->pressAndWaitFor("button[data-bs-target=\"#filesRow-{$material->course_material_id}\"]", 1);
    $page->press('a[href*=\"files/{$file->course_material_file_id}\"]');
    $page->wait(2);

    $page->assertSee('Suggested Topics');

    $suggested = SuggestedTopic::where('course_material_file_id', $file->course_material_file_id)
        ->where('status', SuggestedTopic::STATUS_PENDING)
        ->get();
    $this->assertNotEmpty($suggested, 'Text extraction service should have produced suggested topics');

    $firstSuggested = $suggested->first();
    $page->press('.suggested-topic-row[data-id=\"{$firstSuggested->suggested_topic_id}\"] .review-confirm');
    $page->wait(0.5);

    $page->pressAndWaitFor('#save-review-btn', 3);

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

    $this->assertDatabaseMissing('suggested_topics', [
        'suggested_topic_id' => $firstSuggested->suggested_topic_id,
    ]);

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

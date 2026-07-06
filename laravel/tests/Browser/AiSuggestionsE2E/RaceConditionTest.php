<?php

/**
 * If user A submits a request,
 * user B - whose page was rendered before A submitted and so
 * still shows the "AI Suggestions" button -
 * must not be able to create a second duplicate request.
 */
it('stops a second user from creating a duplicate request for the same course/program', function () {
    $userA = makeTestUser('e2e-race-a@ubc.ca');
    $userB = makeTestUser('e2e-race-b@ubc.ca');
    $course = makeTestCourse('Formula 1 Race Course');
    $program = makeTestProgram('Formula Race Program');

    linkUserToCourse($userA, $course);
    linkUserToCourse($userB, $course);
    linkCourseToProgram($course, $program);
    attachMappingScalesToProgram($program);

    addCloToCourse($course, 'CLO 1: Apply concepts');
    addPloToProgram($program, 'PLO 1: Demonstrate mastery');

    $accordion = "[data-bs-target=\"#collapseProgramAccordion{$program->program_id}\"]";

    // User B opens step 5 while nothing is in flight so the button is available
    $this->actingAs($userB);
    $page = visit_v("/courseWizard/{$course->course_id}/step5");
    $page->assertSee('Program Outcome Mapping')
        ->click($accordion)
        ->assertSee('AI Suggestions');

    // Meanwhile, mock user A submits a request from their own session
    putPendingRecord($course->course_id, $program->program_id);

    // User B's page is now stale. B clicks AI Suggestions -> Yes. The pre-submit
    // guard catches A's in-flight request and shows the waiting state rather than
    // submitting a duplicate.
    $page->click("#buttonAISuggestionCenter-{$course->course_id}-{$program->program_id}")
        ->click("#AiSuggestionConfirmation{$course->course_id}{$program->program_id} .btn-success")
        ->assertSee('already submitted (possibly by another user)')
        ->assertSee('Waiting for AI suggestions...');

    $page->wait(2);
    // Even DynamoDB should only have one req entry
    expect(getDynamoDBRecords($course->course_id, $program->program_id))->toHaveCount(1);
});

<?php

/**
 * When a request is already in flight (a PENDING record exists, possibly
 * started by another user or session), opening step 5 should render the
 * waiting state.
 *
 * Somewhat redundant with PendingInProgress, but this specifically tests the case where the
 * user didn't just click "Yes" to start the request, it may have been started by another user or this user in an earlier session.
 */
it('renders the waiting state on step 5 when a request is already in flight', function () {
    $user = makeTestUser('e2e-inflight-render@ubc.ca');
    $course = makeTestCourse('E2E In-Flight Render Course');
    $program = makeTestProgram('E2E In-Flight Render Program');

    linkUserToCourse($user, $course);
    linkCourseToProgram($course, $program);
    attachMappingScalesToProgram($program);

    addCloToCourse($course, 'CLO 1: Apply concepts');
    addPloToProgram($program, 'PLO 1: Demonstrate mastery');

    putPendingRecord($course->course_id, $program->program_id);

    $this->actingAs($user);

    $page = visit_v("/courseWizard/{$course->course_id}/step5");
    $page->click("[data-bs-target=\"#collapseProgramAccordion{$program->program_id}\"]");

    $page->assertSee('Waiting for AI suggestions...');

    $optionsSelector = "#mappingOptions-{$course->course_id}-{$program->program_id}";
    $page->assertAttribute($optionsSelector, 'data-course-id', (string) $course->course_id);
    $page->assertAttribute($optionsSelector, 'data-program-id', (string) $program->program_id);
    $page->assertAttribute($optionsSelector, 'data-poll-on-load', 'true'); // This is the 'real' test here
});

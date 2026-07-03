<?php

it('renders waiting state after the user clicks AI Suggestions and confirms', function () {
    $user = makeTestUser('e2e-pending@ubc.ca');
    $course = makeTestCourse('E2E Pending Course');
    $program = makeTestProgram('E2E Pending Program');

    linkUserToCourse($user, $course);
    linkCourseToProgram($course, $program);
    attachMappingScalesToProgram($program);

    addCloToCourse($course, 'CLO 1: Apply concepts');
    addPloToProgram($program, 'PLO 1: Demonstrate mastery');

    $this->actingAs($user);

    $page = visit_v("/courseWizard/{$course->course_id}/step5");
    $page->click("[data-bs-target=\"#collapseProgramAccordion{$program->program_id}\"]");
    $page->click("#buttonAISuggestionCenter-{$course->course_id}-{$program->program_id}");

    $yesButtonSelector = "#AiSuggestionConfirmation{$course->course_id}{$program->program_id} .btn-success";
    $page->click($yesButtonSelector);

    // record in PENDING state
    $page = visit_v("/courseWizard/{$course->course_id}/step5");
    $page->click("[data-bs-target=\"#collapseProgramAccordion{$program->program_id}\"]");
    $page->assertSee('Waiting for AI suggestions...');

    // Simulates the PENDING record moving to IN_PROGRESS (i.e. accepted by SageMaker)
    // because we can't test with SageMaker in LocalStack
    markRecordInProgress($course->course_id, $program->program_id);

    // record in IN_PROGRESS state
    $page = visit_v("/courseWizard/{$course->course_id}/step5");
    $page->click("[data-bs-target=\"#collapseProgramAccordion{$program->program_id}\"]");
    $page->assertSee('Waiting for AI suggestions...');
});

<?php

/**
 * Tests that when AI suggestions arrive, already manually mapped rows are untouched,
 * and previously unmapped rows have manual maps set to the AI Suggestions.
 * If there are unsaved manual changes, test that the warning appears
 * and the manual changes when saved aren't overwritten by the AI Suggestions
 */
it('shows unsaved-changes message when AI arrives, then applies manual save and AI defaults correctly', function () {
    $user = makeTestUser('e2e-manual-defaults@ubc.ca');
    $this->actingAs($user);

    $course  = makeTestCourse('E2E Manual-Defaults Course');
    $program = makeTestProgram('E2E Manual-Defaults Program');
    $clo     = addCloToCourse($course, 'Students synthesize and evaluate domain knowledge.');
    $plo1    = addPloToProgram($program, 'PLO 1: Apply foundational methods.');
    $plo2    = addPloToProgram($program, 'PLO 2: Communicate findings clearly.');
    $plo3    = addPloToProgram($program, 'PLO 3: Integrate ideas across disciplines.');

    linkUserToCourse($user, $course);
    linkUserToProgram($user, $program);
    linkCourseToProgram($course, $program);
    attachMappingScalesToProgram($program);

    $I  = 1;
    $D  = 2;
    $A  = 3;
    $NA = 0;

    // Manually map plo1 to A via the UI before AI suggestions arrive
    $page = visit_v("/courseWizard/{$course->course_id}/step5");
    $page->pressAndWaitFor(programAccordionToggle($program->program_id), 0.5)
        ->pressAndWaitFor('Create Manually', 1)
        ->pressAndWaitFor(cloAccordionToggle($program->program_id, $clo->l_outcome_id), 0.5)
        ->check(manualMapField($clo->l_outcome_id, $plo1->pl_outcome_id), (string) $A)
        ->pressAndWaitFor('button.btn.btn-success:text-is("Save")', 3);

    // AI suggests: plo1:I, plo2:N/A, plo3:I+D
    putPendingRecord($course->course_id, $program->program_id);

    $page->navigate("/courseWizard/{$course->course_id}/step5");
    $page->pressAndWaitFor(programAccordionToggle($program->program_id), 0.5)
        ->pressAndWaitFor(cloAccordionToggle($program->program_id, $clo->l_outcome_id), 0.5);

    // Make an Unsaved change
    $page->check(manualMapField($clo->l_outcome_id, $plo3->pl_outcome_id), (string) $D);

    // Mock results ready
    setAwaitingCompletion($course->course_id, $program->program_id, [
        ['clo_id' => $clo->l_outcome_id, 'plo_id' => $plo1->pl_outcome_id, 'labels' => ['I']],
        ['clo_id' => $clo->l_outcome_id, 'plo_id' => $plo2->pl_outcome_id, 'labels' => []],
        ['clo_id' => $clo->l_outcome_id, 'plo_id' => $plo3->pl_outcome_id, 'labels' => ['I', 'D']],
    ]);

    $page->wait(20);

    // Check that page didn't auto-reload
    $page->assertSee('AI suggestions are ready - reload to see. Save your unsaved changes if you wish to keep them.')
        ->assertPresent("#aiRefreshCenter-{$course->course_id}-{$program->program_id}");

    $page->pressAndWaitFor('button.btn.btn-success:text-is("Save")', 3);

    $page->wait(1)
        ->pressAndWaitFor(programAccordionToggle($program->program_id), 0.5)
        ->pressAndWaitFor(cloAccordionToggle($program->program_id, $clo->l_outcome_id), 0.5);

    // plo1: checkbox still shows manual A, but AI icon shows I
    $page->assertPresent(manualMapCheckbox($clo->l_outcome_id, $plo1->pl_outcome_id, $A) . ':checked')
        ->assertMissing(manualMapCheckbox($clo->l_outcome_id, $plo1->pl_outcome_id, $I) . ':checked')
        ->assertPresent(aiIconForCloPlo($clo->l_outcome_id, $plo1->pl_outcome_id, $I))
        ->assertMissing(aiIconForCloPlo($clo->l_outcome_id, $plo1->pl_outcome_id, $A));

    // plo2: checkbox shows N/A (AI-filled), AI icon shows N/A
    $page->assertPresent(manualMapCheckbox($clo->l_outcome_id, $plo2->pl_outcome_id, $NA) . ':checked')
        ->assertPresent(aiIconForCloPlo($clo->l_outcome_id, $plo2->pl_outcome_id, $NA));

    // plo3: user manually saved D, which isn't overwritten by AI's I+D suggestion;
    // AI icons still show I+D (the suggestions), only D is checked manually
    $page->assertPresent(manualMapCheckbox($clo->l_outcome_id, $plo3->pl_outcome_id, $D) . ':checked')
        ->assertMissing(manualMapCheckbox($clo->l_outcome_id, $plo3->pl_outcome_id, $I) . ':checked')
        ->assertPresent(aiIconForCloPlo($clo->l_outcome_id, $plo3->pl_outcome_id, $I))
        ->assertPresent(aiIconForCloPlo($clo->l_outcome_id, $plo3->pl_outcome_id, $D));
});

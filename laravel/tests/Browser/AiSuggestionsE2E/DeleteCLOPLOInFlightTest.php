<?php

use App\Models\LearningOutcome;
use App\Models\ProgramLearningOutcome;

/**
 * Tests that when a CLO and/or PLO are deleted while waiting on an AI suggestions request,
 * the results for the remaining (clo, plo) pairs are still stored correctly
 */
it('stores remaining AI suggestions when a CLO and PLO are deleted in-flight', function () {
    $user = makeTestUser('e2e-delete-in-flight@ubc.ca');
    $this->actingAs($user);

    $course = makeTestCourse('E2E Delete-In-Flight Course');
    $program = makeTestProgram('E2E Delete-In-Flight Program');

    $clo1 = addCloToCourse($course, 'CLO 1: Will be deleted while in-flight.');
    $clo2 = addCloToCourse($course, 'CLO 2: Remains through the test.');
    $plo1 = addPloToProgram($program, 'PLO 1: Will be deleted while in-flight.');
    $plo2 = addPloToProgram($program, 'PLO 2: Remains through the test.');

    linkUserToCourse($user, $course);
    linkUserToProgram($user, $program);
    linkCourseToProgram($course, $program);
    attachMappingScalesToProgram($program);

    $I = 1;

    // Send AI Suggestions Request for (clo1, plo1), (clo1, plo2), (clo2, plo1), (clo2, plo2)
    putPendingRecord($course->course_id, $program->program_id);

    // Delete CLO1
    $page = visit_v("/courseWizard/{$course->course_id}/step1");
    $page->pressAndWaitFor('CLO', 1) // the + CLO button
        ->pressAndWaitFor(':nth-match(i.bi-x-circle-fill[onclick="deleteCLO(this)"], 1)', 0.5) // removes clo1's row from the modal table
        ->hover('#saveCLOChanges button[type="submit"]')
        ->pressAndWaitFor('#saveCLOChanges button[type="submit"]', 2);
    expect(LearningOutcome::find($clo1->l_outcome_id))->toBeNull();

    // Delete PLO1
    $page->navigate("/programWizard/{$program->program_id}/step1")->wait(1)
        ->pressAndWaitFor('[data-bs-toggle="modal"][data-bs-target="#deletePLO' . $plo1->pl_outcome_id . '"]', 0.5)
        ->pressAndWaitFor('#deletePLO' . $plo1->pl_outcome_id . ' button[type="submit"]', 2);
    expect(ProgramLearningOutcome::find($plo1->pl_outcome_id))->toBeNull();

    // Return mock results for all 4 original pairs including the now-deleted CLO/PLO.
    setAwaitingCompletion($course->course_id, $program->program_id, [
        ['clo_id' => $clo1->l_outcome_id, 'plo_id' => $plo1->pl_outcome_id, 'labels' => ['I']],
        ['clo_id' => $clo1->l_outcome_id, 'plo_id' => $plo2->pl_outcome_id, 'labels' => ['I']],
        ['clo_id' => $clo2->l_outcome_id, 'plo_id' => $plo1->pl_outcome_id, 'labels' => ['I']],
        ['clo_id' => $clo2->l_outcome_id, 'plo_id' => $plo2->pl_outcome_id, 'labels' => ['I']],
    ]);

    $page->navigate("/courseWizard/{$course->course_id}/step5")
        ->wait(20);

    // AI Suggestion for the remaining pair (clo2, plo2) should be stored in db
    $this->assertDatabaseHas('outcome_map_ai_suggestions', [
        'l_outcome_id' => $clo2->l_outcome_id,
        'pl_outcome_id' => $plo2->pl_outcome_id,
        'map_scale_id' => $I,
    ]);

    // AI Suggestions should not have stored the deleted CLO/PLO pairs in db
    $this->assertDatabaseMissing('outcome_map_ai_suggestions', ['l_outcome_id' => $clo1->l_outcome_id]);
    $this->assertDatabaseMissing('outcome_map_ai_suggestions', ['pl_outcome_id' => $plo1->pl_outcome_id]);

    // Should also have set the manual map to AI Suggestions
    $this->assertDatabaseHas('outcome_maps', [
        'l_outcome_id' => $clo2->l_outcome_id,
        'pl_outcome_id' => $plo2->pl_outcome_id,
        'map_scale_id' => $I,
    ]);

    // Deleted outcomes should not be re-added to database
    $this->assertDatabaseMissing('outcome_maps', ['l_outcome_id' => $clo1->l_outcome_id]);
    $this->assertDatabaseMissing('outcome_maps', ['pl_outcome_id' => $plo1->pl_outcome_id]);

    // Only clo2 should appear in step5.
    // clo2/plo2 should have AI suggestion icon and checkbox checked on I column
    $page->pressAndWaitFor(programAccordionToggle($program->program_id), 0.5)
        ->pressAndWaitFor(cloAccordionToggle($program->program_id, $clo2->l_outcome_id), 0.5);

    $page->assertPresent(aiIconForCloPlo($clo2->l_outcome_id, $plo2->pl_outcome_id, $I))
        ->assertPresent(manualMapCheckbox($clo2->l_outcome_id, $plo2->pl_outcome_id, $I) . ':checked');
});

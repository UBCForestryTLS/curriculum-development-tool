<?php

use App\Models\LearningOutcome;
use App\Models\ProgramLearningOutcome;

/**
 * Checks a CLO or PLO added when the AI suggestions request is in-flight,
 * or after the results have returned, is unaffected, and doesn't affect the existing suggestions.
 */
it('does not render icons for CLOs/PLOs added in-flight or after results return', function () {
    $user = makeTestUser('e2e-late-outcomes@ubc.ca');
    $course = makeTestCourse('E2E Late Outcomes Course');
    $program = makeTestProgram('E2E Late Outcomes Program');

    linkUserToCourse($user, $course);
    linkUserToProgram($user, $program);
    linkCourseToProgram($course, $program);
    attachMappingScalesToProgram($program);

    $clo1 = addCloToCourse($course, 'CLO 1: existed at request time');
    $plo1 = addPloToProgram($program, 'PLO 1: existed at request time');

    $this->actingAs($user);
    $page = visit_v("/courseWizard/{$course->course_id}/step5");

    $addCloViaUi = function (string $text) use ($page, $course) {
        $page->navigate("/courseWizard/{$course->course_id}/step1")
            ->click('CLO') // the + CLO button
            ->type('#l_outcome', $text)
            ->type('#title', substr($text, 0, 20))
            ->pressAndWaitFor('#addCLOBtn', 3)
            ->hover('#saveCLOChanges button:has-text("Save Changes")')
            ->pressAndWaitFor('#saveCLOChanges button:has-text("Save Changes")', 1);
    };
    $addPloViaUi = function (string $text) use ($page, $program) {
        $page->navigate("/programWizard/{$program->program_id}/step1")
            ->wait(3)
            ->pressAndWaitFor('[data-bs-target="#addPLOModal"]', 1)
            ->type('#pl_outcome', $text)
            ->type('#ploShortphrase', substr($text, 0, 20))
            ->pressAndWaitFor('#addPLOBtn', 1)
            ->press('#savePLOChanges button[type="submit"]');
    };

    // Puts AI suggestions request in flight, only clo1 and plo1 at this point
    putPendingRecord($course->course_id, $program->program_id);

    $addCloViaUi('CLO 2: added while waiting');
    $addPloViaUi('PLO 2: added while waiting');

    $clo2 = LearningOutcome::where('l_outcome', 'CLO 2: added while waiting')->firstOrFail();
    $plo2 = ProgramLearningOutcome::where('pl_outcome', 'PLO 2: added while waiting')->firstOrFail();

    // Request Mock Sagemaker to deliver result 'I'.
    setAwaitingCompletion($course->course_id, $program->program_id, [
        ['clo_id' => $clo1->l_outcome_id, 'plo_id' => $plo1->pl_outcome_id, 'labels' => ['I']],
    ]);
    $page->navigate("/courseWizard/{$course->course_id}/step5")
        ->wait(20) // Wait for frontend to poll and FastAPI to deliver results
        ->assertPresent(aiIconForCloPlo($clo1->l_outcome_id, $plo1->pl_outcome_id, 1));

    $addCloViaUi('CLO 3: added after AI suggestions received');
    $addPloViaUi('PLO 3: added after AI suggestions received');

    $clo3 = LearningOutcome::where('l_outcome', 'CLO 3: added after AI suggestions received')->firstOrFail();
    $plo3 = ProgramLearningOutcome::where('pl_outcome', 'PLO 3: added after AI suggestions received')->firstOrFail();

    $I = 1; // scale value for 'I' mapping

    // Check only CLO1-PLO1 has an AI suggestions icon.
    // The later added LOs should have none
    $page->navigate("/courseWizard/{$course->course_id}/step5");
    $page->assertPresent(aiIconForCloPlo($clo1->l_outcome_id, $plo1->pl_outcome_id, $I)) // original pair correctly mapped to I
        ->assertMissing(anyAiIconForClo($clo2->l_outcome_id))
        ->assertMissing(anyAiIconForClo($clo3->l_outcome_id))
        ->assertMissing(anyAiIconForPlo($plo2->pl_outcome_id))
        ->assertMissing(anyAiIconForPlo($plo3->pl_outcome_id));
});

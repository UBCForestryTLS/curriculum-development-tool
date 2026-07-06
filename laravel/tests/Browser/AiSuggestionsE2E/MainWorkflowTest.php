<?php

use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\Program;
use App\Models\ProgramLearningOutcome;

/**
 * Tests main workflow without 'complications'
 * Tests that mapping works when CLO has categories
 */
it('creates a course and program via the UI and renders AI suggestion icons end-to-end', function () {
    $user = makeTestUser('e2e-normal-workflow@ubc.ca');
    $this->actingAs($user);

    // Create course
    $page = visit_v('/home');
    $page->pressAndWaitFor('[data-bs-target="#methodToCreateNewCourse"]', 2)
        ->pressAndWaitFor('[data-bs-target="#createCourseModal"]', 2)
        ->type('#course_code', 'HAPP')
        ->type('#course_num', '101')
        ->type('#course_title', 'E2E Normal workflow Course')
        ->select('#course_semester', 'W1')
        ->select('#course_year', '2026')
        ->select('#delivery_modality', 'O')
        ->select('#standard_category_id', '1')
        ->hover('#createCourse #submit')
        ->pressAndWaitFor('#createCourse #submit', 3); // POSTs and redirects to /courseWizard/{id}/step8

    $course = Course::where('course_title', 'E2E Normal workflow Course')->latest('course_id')->firstOrFail();
    $page->assertPathIs("/courseWizard/{$course->course_id}/step8");

    // Add CLO
    $page->click('a.btn.btn-secondary[href$="/courseWizard/' . $course->course_id . '/step1"]')
        ->click('CLO') // the + CLO button
        ->type('#l_outcome', 'Students apply core concepts to novel problems.')
        ->type('#title', 'Apply Concepts')
        ->pressAndWaitFor('Add', 0.5)
        ->pressAndWaitFor('#saveCLOChanges button:has-text("Save Changes")', 1);

    $clo = LearningOutcome::where('course_id', $course->course_id)->firstOrFail();

    // Create program
    $page->click('a[href$="/home"]')
        ->pressAndWaitFor('[data-bs-target="#createProgramModal"]', 0.5)
        ->type('#program', 'E2E Normal workflow Program')
        ->check('input[name="level"][value="Bachelors"]')
        ->pressAndWaitFor('Add', 1); // redirects to /programWizard/{id}/step1

    $program = Program::where('program', 'E2E Normal workflow Program')->latest('program_id')->firstOrFail();

    // Add 3 PLOs
    $page->press('[data-bs-target="#addPLOModal"]')
        ->type('#pl_outcome', 'PLO 1: Demonstrate mastery of the discipline.')
        ->type('#ploShortphrase', 'Demonstrate Mastery')
        ->press('#addPLOBtn')
        ->type('#pl_outcome', 'PLO 2: Synthesize ideas across domains.')
        ->type('#ploShortphrase', 'Synthesize Ideas')
        ->press('#addPLOBtn')
        ->type('#pl_outcome', 'PLO 3: Evaluate competing approaches.')
        ->type('#ploShortphrase', 'Evaluate Approaches')
        ->pressAndWaitFor('#addPLOBtn', 1)
        ->pressAndWaitFor('#savePLOChanges button[type="submit"]', 3)
        ->assertPathIs("/programWizard/{$program->program_id}/step1");

    // These also function as assertions that the PLOs were stored correctly in the db
    $plo1 = ProgramLearningOutcome::where('pl_outcome', 'PLO 1: Demonstrate mastery of the discipline.')->firstOrFail();
    $plo2 = ProgramLearningOutcome::where('pl_outcome', 'PLO 2: Synthesize ideas across domains.')->firstOrFail();
    $plo3 = ProgramLearningOutcome::where('pl_outcome', 'PLO 3: Evaluate competing approaches.')->firstOrFail();

    // Assign default I/D/A mapping scales to the program
    $page->pressAndWaitFor('a.btn.btn-secondary[href$="/programWizard/' . $program->program_id . '/step2"]', 2)
        ->assertPathIs("/programWizard/{$program->program_id}/step2")
        ->pressAndWaitFor('[data-bs-target=".mapping-scales"]', 1)
        ->pressAndWaitFor('form[action*="addDefaultMappingScale"]:has(input[name="mapping_scale_categories_id"][value="1"]) button[type="submit"]', 2);

    // Map the course to the program
    $page->click('a.btn.btn-secondary[href$="/programWizard/' . $program->program_id . '/step3"]')
        ->click('[data-bs-target="#addCourseModal"]')
        ->check('input[name="selectedCourses[]"][value="' . $course->course_id . '"]')
        ->hover('button[form="addExistCourse"]')
        ->pressAndWaitFor('button[form="addExistCourse"]', 3);

    $page->navigate("/courseWizard/{$course->course_id}/step5")
        ->assertNoJavascriptErrors()
        ->pressAndWaitFor(programAccordionToggle($program->program_id), 0.5)
        ->assertSee('AI Suggestions')
        ->wait(2);

    // If we submit the job via UI, it will fail because that calls the lambda that invokes SageMaker
    // So instead, we directly create the pending record and write Mock SageMaker output
    putPendingRecord($course->course_id, $program->program_id);
    // Request mock sagemaker to return these values
    setAwaitingCompletion($course->course_id, $program->program_id, [
        ['clo_id' => $clo->l_outcome_id, 'plo_id' => $plo1->pl_outcome_id, 'labels' => ['I']],      // single map
        ['clo_id' => $clo->l_outcome_id, 'plo_id' => $plo2->pl_outcome_id, 'labels' => []],         // no map, should show as N/A
        ['clo_id' => $clo->l_outcome_id, 'plo_id' => $plo3->pl_outcome_id, 'labels' => ['I', 'D']], // multi map
    ]);

    // Reload the page. Now that we have a record awaiting completion, it should auto-poll and
    // FastAPI should get the results from LocalStack, and send them to Laravel
    $page->refresh();

    // Scale values: I, D, A, N/A
    $I = 1;
    $D = 2;
    $A = 3;
    $NA = 0;

    // May take some time for it to poll FastAPI and get the results into Laravel
    $page->wait(20);

    $page->assertPresent(aiIconForCloPlo($clo->l_outcome_id, $plo1->pl_outcome_id, $I))
        ->assertMissing(aiIconForCloPlo($clo->l_outcome_id, $plo1->pl_outcome_id, $D))
        ->assertMissing(aiIconForCloPlo($clo->l_outcome_id, $plo1->pl_outcome_id, $A))
        ->assertMissing(aiIconForCloPlo($clo->l_outcome_id, $plo1->pl_outcome_id, $NA));

    $page->assertPresent(aiIconForCloPlo($clo->l_outcome_id, $plo2->pl_outcome_id, $NA))
        ->assertMissing(aiIconForCloPlo($clo->l_outcome_id, $plo2->pl_outcome_id, $D))
        ->assertMissing(aiIconForCloPlo($clo->l_outcome_id, $plo2->pl_outcome_id, $A))
        ->assertMissing(aiIconForCloPlo($clo->l_outcome_id, $plo2->pl_outcome_id, $I));

    $page->assertPresent(aiIconForCloPlo($clo->l_outcome_id, $plo3->pl_outcome_id, $I))
        ->assertPresent(aiIconForCloPlo($clo->l_outcome_id, $plo3->pl_outcome_id, $D))
        ->assertMissing(aiIconForCloPlo($clo->l_outcome_id, $plo3->pl_outcome_id, $A))
        ->assertMissing(aiIconForCloPlo($clo->l_outcome_id, $plo3->pl_outcome_id, $NA));

    $this->assertDatabaseHas('course_programs', [
        'course_id' => $course->course_id,
        'program_id' => $program->program_id,
        'ai_suggestion_status' => true,
    ]);
    $this->assertDatabaseHas('outcome_map_ai_suggestions', [
        'l_outcome_id' => $clo->l_outcome_id,
        'pl_outcome_id' => $plo1->pl_outcome_id,
        'map_scale_id' => $I, // 'I'
    ]);
    $this->assertDatabaseHas('outcome_map_ai_suggestions', [
        'l_outcome_id' => $clo->l_outcome_id,
        'pl_outcome_id' => $plo2->pl_outcome_id,
        'map_scale_id' => $NA, // N/A
    ]);
    $this->assertDatabaseHas('outcome_map_ai_suggestions', [
        'l_outcome_id' => $clo->l_outcome_id,
        'pl_outcome_id' => $plo3->pl_outcome_id,
        'map_scale_id' => $I, // multi 'I'
    ]);
    $this->assertDatabaseHas('outcome_map_ai_suggestions', [
        'l_outcome_id' => $clo->l_outcome_id,
        'pl_outcome_id' => $plo3->pl_outcome_id,
        'map_scale_id' => $D, // multi 'D'
    ]);

    // AI suggestions should have been auto-applied to outcome_maps (setManualMapsToAiSuggestions).
    // Verify via checkboxes: the UI reflects the AI-defaulted manual maps.
    $page->pressAndWaitFor(programAccordionToggle($program->program_id), 0.5)
        ->pressAndWaitFor(cloAccordionToggle($program->program_id, $clo->l_outcome_id), 0.5)
        ->assertPresent(manualMapCheckbox($clo->l_outcome_id, $plo1->pl_outcome_id, $I) . ':checked')   // plo1: I
        ->assertPresent(manualMapCheckbox($clo->l_outcome_id, $plo2->pl_outcome_id, $NA) . ':checked')  // plo2: N/A
        ->assertPresent(manualMapCheckbox($clo->l_outcome_id, $plo3->pl_outcome_id, $I) . ':checked')   // plo3: I
        ->assertPresent(manualMapCheckbox($clo->l_outcome_id, $plo3->pl_outcome_id, $D) . ':checked');  // plo3: D

    // Uncheck all AI-defaulted boxes to reset, then apply manual maps
    $page->uncheck(manualMapField($clo->l_outcome_id, $plo1->pl_outcome_id), (string) $I)
        ->uncheck(manualMapField($clo->l_outcome_id, $plo2->pl_outcome_id), (string) $NA)
        ->uncheck(manualMapField($clo->l_outcome_id, $plo3->pl_outcome_id), (string) $I)
        ->uncheck(manualMapField($clo->l_outcome_id, $plo3->pl_outcome_id), (string) $D)
        ->pressAndWaitFor('button.btn.btn-success:text-is("Save")', 3);

    // Add new manual maps: plo1 matching AI (I), plo3 different from AI (A instead of I+D)
    $page->click(programAccordionToggle($program->program_id))
        ->click(cloAccordionToggle($program->program_id, $clo->l_outcome_id))
        ->check(manualMapField($clo->l_outcome_id, $plo1->pl_outcome_id), (string) $I)
        ->check(manualMapField($clo->l_outcome_id, $plo3->pl_outcome_id), (string) $A)
        ->wait(0.5)
        ->pressAndWaitFor('button.btn.btn-success:text-is("Save")', 3);

    // Check checkboxes reflect the manual choices; the purple icons still reflect the AI suggestions
    $page->refresh();
    $page->wait(1)
        ->assertPresent(manualMapCheckbox($clo->l_outcome_id, $plo1->pl_outcome_id, $I) . ':checked')
        ->assertPresent(aiIconForCloPlo($clo->l_outcome_id, $plo1->pl_outcome_id, $I))            // manual + AI on I
        ->assertPresent(manualMapCheckbox($clo->l_outcome_id, $plo3->pl_outcome_id, $A) . ':checked')
        ->assertMissing(aiIconForCloPlo($clo->l_outcome_id, $plo3->pl_outcome_id, $A))            // manual A, no AI on A
        ->assertMissing(manualMapCheckbox($clo->l_outcome_id, $plo3->pl_outcome_id, $I) . ':checked')
        ->assertPresent(aiIconForCloPlo($clo->l_outcome_id, $plo3->pl_outcome_id, $I))            // AI on I still there
        ->assertPresent(aiIconForCloPlo($clo->l_outcome_id, $plo3->pl_outcome_id, $D))            // AI on D still there
        ->assertPresent(aiIconForCloPlo($clo->l_outcome_id, $plo2->pl_outcome_id, $NA));          // PLO 2 AI N/A untouched

    $this->assertDatabaseHas('outcome_maps', [
        'l_outcome_id' => $clo->l_outcome_id,
        'pl_outcome_id' => $plo1->pl_outcome_id,
        'map_scale_id' => $I,
    ]);
    $this->assertDatabaseHas('outcome_maps', [
        'l_outcome_id' => $clo->l_outcome_id,
        'pl_outcome_id' => $plo3->pl_outcome_id,
        'map_scale_id' => $A,
    ]);
});

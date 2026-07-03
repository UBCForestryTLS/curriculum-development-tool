<?php

/**
 * Verifies that changing a program's mapping scale keeps the mapping data consistent.
 * Dropping a column should not drop other columns' mappings.
 * Swapping to a different scale category should wipe existing mappings
 */
it('keeps manual maps and AI suggestions consistent when the scale is dropped or swapped', function () {

    // Scale id values for default category
    $I = 1;
    $D = 2;
    $A = 3;

    // Scale id value for category 2
    $P = \App\Models\MappingScale::where('mapping_scale_categories_id', 2)->orderBy('map_scale_id')->value('map_scale_id');

    $user = makeTestUser('e2e-scale-change@ubc.ca');
    $course = makeTestCourse('E2E Scale Change Course');
    $programManual = makeTestProgram('E2E Scale Change Manual Program');
    $programAi = makeTestProgram('E2E Scale Change AI Program');

    linkUserToCourse($user, $course);
    linkUserToProgram($user, $programManual);
    linkUserToProgram($user, $programAi);
    linkCourseToProgram($course, $programManual);
    linkCourseToProgram($course, $programAi);
    attachMappingScalesToProgram($programManual);
    attachMappingScalesToProgram($programAi);

    $clo = addCloToCourse($course, 'CLO: applies concepts');
    $ploM = addPloToProgram($programManual, 'PLO M: manual-only outcome');
    $ploAi1 = addPloToProgram($programAi, 'PLO AI 1: has manual + AI');
    $ploAi2 = addPloToProgram($programAi, 'PLO AI 2: AI only');

    $this->actingAs($user);

    // Mock SageMaker request submission and completion
    putPendingRecord($course->course_id, $programAi->program_id);
    setAwaitingCompletion($course->course_id, $programAi->program_id, [
        ['clo_id' => $clo->l_outcome_id, 'plo_id' => $ploAi1->pl_outcome_id, 'labels' => ['I']], // icon on I
        ['clo_id' => $clo->l_outcome_id, 'plo_id' => $ploAi2->pl_outcome_id, 'labels' => ['A']], // icon on A
    ]);

    $page = visit_v("/courseWizard/{$course->course_id}/step5");
    $page->wait(20)
        ->assertPresent(aiIconForCloPlo($clo->l_outcome_id, $ploAi1->pl_outcome_id, $I));

    // Manual Mapping

    $page->navigate("/courseWizard/{$course->course_id}/step5")
        ->wait(2)
        ->pressAndWaitFor(programAccordionToggle($programManual->program_id), 1)
        ->pressAndWaitFor(createManuallyButton($course->course_id, $programManual->program_id), 2)
        ->pressAndWaitFor(cloAccordionToggle($programManual->program_id, $clo->l_outcome_id), 1)
        ->check(manualMapField($clo->l_outcome_id, $ploM->pl_outcome_id), (string) $I);

    $page->click(programAccordionToggle($programAi->program_id))
        ->click(cloAccordionToggle($programAi->program_id, $clo->l_outcome_id))
        ->check(manualMapField($clo->l_outcome_id, $ploAi1->pl_outcome_id), (string) $D);

    $page->click('button.btn-success[type="submit"]:text-is("Save")');

    $page->refresh()
        ->wait(3)
        ->assertPresent(manualMapCheckbox($clo->l_outcome_id, $ploM->pl_outcome_id, $I) . ':checked')
        ->assertMissing(aiIconForCloPlo($clo->l_outcome_id, $ploM->pl_outcome_id, $I))
        ->assertPresent(aiIconForCloPlo($clo->l_outcome_id, $ploAi1->pl_outcome_id, $I))           // AI on I
        ->assertPresent(manualMapCheckbox($clo->l_outcome_id, $ploAi1->pl_outcome_id, $D) . ':checked') // manual on D
        ->assertMissing(manualMapCheckbox($clo->l_outcome_id, $ploAi1->pl_outcome_id, $I) . ':checked')
        ->assertPresent(aiIconForCloPlo($clo->l_outcome_id, $ploAi2->pl_outcome_id, $A));

    $this->assertDatabaseHas('outcome_maps', [
        'l_outcome_id' => $clo->l_outcome_id,
        'pl_outcome_id' => $ploM->pl_outcome_id,
        'map_scale_id' => $I,
    ]);
    $this->assertDatabaseHas('outcome_maps', [
        'l_outcome_id' => $clo->l_outcome_id,
        'pl_outcome_id' => $ploAi1->pl_outcome_id,
        'map_scale_id' => $D,
    ]);
    $this->assertDatabaseHas('outcome_map_ai_suggestions', [
        'l_outcome_id' => $clo->l_outcome_id,
        'pl_outcome_id' => $ploAi1->pl_outcome_id,
        'map_scale_id' => $I,
    ]);

    // Drop the D column from both programs
    $page->navigate("/programWizard/{$programManual->program_id}/step2")
        ->wait(1)
        ->click("form[action*=\"mappingScale/{$D}/delete\"] button")
        ->wait(1)
        ->click('#deleteMSConfirmBtn')
        ->wait(1);
    $page->navigate("/programWizard/{$programAi->program_id}/step2")
        ->wait(1)
        ->click("form[action*=\"mappingScale/{$D}/delete\"] button")
        ->wait(1)
        ->click('#deleteMSConfirmBtn')
        ->wait(1);

    $page->navigate("/courseWizard/{$course->course_id}/step5")
        ->wait(2);
    $page->assertMissing(manualMapCheckbox($clo->l_outcome_id, $ploM->pl_outcome_id, $D))          // D column gone
        ->assertMissing(manualMapCheckbox($clo->l_outcome_id, $ploAi1->pl_outcome_id, $D))
        ->assertPresent(manualMapCheckbox($clo->l_outcome_id, $ploM->pl_outcome_id, $I) . ':checked') // I survives
        ->assertPresent(aiIconForCloPlo($clo->l_outcome_id, $ploAi1->pl_outcome_id, $I))             // AI on I survives
        ->assertPresent(aiIconForCloPlo($clo->l_outcome_id, $ploAi2->pl_outcome_id, $A));            // AI on A survives

    // The manual D map for PLO AI 1 referenced the dropped scale, so it is gone.
    $this->assertDatabaseMissing('outcome_maps', [
        'l_outcome_id' => $clo->l_outcome_id,
        'pl_outcome_id' => $ploAi1->pl_outcome_id,
        'map_scale_id' => $D,
    ]);

    // Swap both programs to a different scale category
    $page->navigate("/programWizard/{$programManual->program_id}/step2")
        ->wait(1)
        ->click('[data-bs-target=".mapping-scales"]')
        ->wait(1)
        ->click('form[action*="addDefaultMappingScale"]:has(input[name="mapping_scale_categories_id"][value="2"]) button[type="submit"]')
        ->wait(1);
    $page->navigate("/programWizard/{$programAi->program_id}/step2")
        ->wait(1)
        ->click('[data-bs-target=".mapping-scales"]')
        ->wait(1)
        ->click('form[action*="addDefaultMappingScale"]:has(input[name="mapping_scale_categories_id"][value="2"]) button[type="submit"]')
        ->wait(1);

    $page->navigate("/courseWizard/{$course->course_id}/step5")
        ->wait(2);
    $page->assertPresent(manualMapCheckbox($clo->l_outcome_id, $ploM->pl_outcome_id, $P))   // new category columns exist
        ->assertPresent(manualMapCheckbox($clo->l_outcome_id, $ploAi1->pl_outcome_id, $P))
        ->assertMissing("input[name=\"map[{$clo->l_outcome_id}][{$ploM->pl_outcome_id}][]\"]:checked")    // nothing checked
        ->assertMissing("input[name=\"map[{$clo->l_outcome_id}][{$ploAi1->pl_outcome_id}][]\"]:checked")
        ->assertMissing(anyAiIconForPlo($ploAi1->pl_outcome_id))   // AI suggestions wiped
        ->assertMissing(anyAiIconForPlo($ploAi2->pl_outcome_id))
        // The AI Suggestions button should be available again after clearing
        ->assertPresent("#mappingOptions-{$course->course_id}-{$programAi->program_id}.d-flex")
        ->assertPresent("#buttonAISuggestionCenter-{$course->course_id}-{$programAi->program_id}:has-text(\"AI Suggestions\")");

    // Check status flags reset and AI suggestions cleared
    $this->assertDatabaseHas('course_programs', [
        'course_id' => $course->course_id,
        'program_id' => $programManual->program_id,
        'manual_map_status' => false,
    ]);
    $this->assertDatabaseHas('course_programs', [
        'course_id' => $course->course_id,
        'program_id' => $programAi->program_id,
        'manual_map_status' => false,
        'ai_suggestion_status' => false,
    ]);
    $this->assertDatabaseMissing('outcome_maps', [
        'l_outcome_id' => $clo->l_outcome_id,
        'pl_outcome_id' => $ploM->pl_outcome_id,
    ]);
    $this->assertDatabaseMissing('outcome_map_ai_suggestions', [
        'l_outcome_id' => $clo->l_outcome_id,
        'pl_outcome_id' => $ploAi1->pl_outcome_id,
    ]);
    $this->assertDatabaseMissing('outcome_map_ai_suggestions', [
        'l_outcome_id' => $clo->l_outcome_id,
        'pl_outcome_id' => $ploAi2->pl_outcome_id,
    ]);
});

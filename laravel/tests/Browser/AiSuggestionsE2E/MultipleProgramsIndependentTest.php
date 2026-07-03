<?php

/**
 * Tests that requests are tracked independently for 1 course with 3 programs:
 *   - puts requests in flight for program 1 and 2
 *   - delivers result for program 1 only
 *   - adds 3rd program to the course
 *   - navigates away from the page and back
 *   - checks that program 1 has results, program 2 is still waiting, and program 3 is empty
 */
it('tracks AI-suggestion requests independently across multiple programs', function () {
    $user = makeTestUser('e2e-multiprogram@ubc.ca');
    $course = makeTestCourse('E2E Multi-Program Course');
    $program1 = makeTestProgram('E2E Program One');
    $program2 = makeTestProgram('E2E Program Two');

    linkUserToCourse($user, $course);
    linkCourseToProgram($course, $program1);
    linkCourseToProgram($course, $program2);
    attachMappingScalesToProgram($program1);
    attachMappingScalesToProgram($program2);

    $clo = addCloToCourse($course, 'CLO 1: Apply concepts');
    $plo1 = addPloToProgram($program1, 'PLO 1: Demonstrate mastery');
    addPloToProgram($program2, 'PLO 1: Communicate effectively');

    // Two AI-suggestion requests in flight, one per program.
    putPendingRecord($course->course_id, $program1->program_id);
    putPendingRecord($course->course_id, $program2->program_id);

    $this->actingAs($user);

    $options1 = "#mappingOptions-{$course->course_id}-{$program1->program_id}";
    $options2 = "#mappingOptions-{$course->course_id}-{$program2->program_id}";

    // Check that both programs show the waiting state
    $page = visit_v("/courseWizard/{$course->course_id}/step5");
    $page->assertSee('Program Outcome Mapping')
         ->click("[data-bs-target=\"#collapseProgramAccordion{$program1->program_id}\"]")
         ->assertSee('Waiting for AI suggestions...')
         ->assertAttribute($options1, 'data-poll-on-load', 'true')
         ->click("[data-bs-target=\"#collapseProgramAccordion{$program1->program_id}\"]")
         ->click("[data-bs-target=\"#collapseProgramAccordion{$program2->program_id}\"]")
         ->assertSee('Waiting for AI suggestions...')
         ->assertAttribute($options2, 'data-poll-on-load', 'true')
         ->click("[data-bs-target=\"#collapseProgramAccordion{$program2->program_id}\"]");

    // Mock SageMaker response for program 1
    $this->postJson('/api/microservices/lo-mapping/ai-suggestions/store', [
        'course_id'  => $course->course_id,
        'program_id' => $program1->program_id,
        'status'     => 'AWAITING_COMPLETION',
        'results'    => [
            ['clo_id' => $clo->l_outcome_id, 'plo_id' => $plo1->pl_outcome_id, 'is_mapped' => true, 'map_labels' => ['I']],
        ],
    ])->assertOk();
    // Mock EventBridge record cleanup that follows SageMaker response
    deleteAiRecords($course->course_id, $program1->program_id);

    $this->assertDatabaseHas('course_programs', [
        'course_id'            => $course->course_id,
        'program_id'           => $program1->program_id,
        'ai_suggestion_status' => true,
    ]);
    $this->assertDatabaseHas('outcome_map_ai_suggestions', [
        'l_outcome_id'  => $clo->l_outcome_id,
        'pl_outcome_id' => $plo1->pl_outcome_id,
        'map_scale_id'  => 1, // 'I'
    ]);

    // Now add a third program to the same course,
    // It should neither have manual mappings, nor AI suggestions, nor be waiting for any

    $program3 = makeTestProgram('E2E Program Three');
    linkCourseToProgram($course, $program3);
    attachMappingScalesToProgram($program3);
    $plo3 = addPloToProgram($program3, 'PLO 1: Evaluate evidence');

    $this->assertDatabaseHas('course_programs', [
        'course_id'  => $course->course_id,
        'program_id' => $program3->program_id,
    ]);
    $this->assertDatabaseMissing('outcome_map_ai_suggestions', [
        'pl_outcome_id' => $plo3->pl_outcome_id,
    ]);

    // As if the user navigated away and back
    $page->click('a[href$="/step1"]')
         ->click('a[href$="/step5"]');

    // Program 2's request should still be waiting. No other should be waiting.
    $page->click("[data-bs-target=\"#collapseProgramAccordion{$program2->program_id}\"]")
         ->assertSee('Waiting for AI suggestions...')
         ->assertAttribute($options2, 'data-poll-on-load', 'true')
         ->wait(1)
         ->click("[data-bs-target=\"#collapseProgramAccordion{$program2->program_id}\"]") // Close P2 Modal
         ->wait(1)
         ->click("[data-bs-target=\"#collapseProgramAccordion{$program1->program_id}\"]") // Open P1 Modal
         ->wait(1)
         ->click("[data-bs-target=\"#collapseProgramAccordion{$program3->program_id}\"]") // Open P3 Modal
         ->wait(1)
         ->assertDontSee('Waiting for AI suggestions...');
});

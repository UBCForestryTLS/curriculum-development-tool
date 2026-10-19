<?php

use Tests\Browser\ProgramReports\ReportFixtures;

beforeEach(function () {
    $this->report = ReportFixtures::create();
    $this->actingAs($this->report['user']);
});

it('follows the comparison report flow from program totals to scoped CLO details', function () {
    $page = visit('/programWizard/'.$this->report['program']->program_id.'/step4');

    // Start with the whole program. Two of the three 100-level CLOs match Remember,
    // so that bar should show 66.67%. The 300-level course has no CLOs to chart.
    $page->click('#nav-progression-tab')
        ->assertSeeIn('#progression-clo-count', '4')
        ->assertSeeIn('#progression-course-count', '3')
        ->assertSeeIn('#progression-matched-count', '3')
        ->assertSeeIn('#progression-unmatched-count', '1')
        ->assertVisible('#progression-chart .highcharts-root')
        ->assertScript("Highcharts.charts.find(chart => chart?.renderTo.id === 'progression-chart').series[0].points.map(point => point.y)", [66.67, 0, null]);

    // Switch the chart to counts. The exact-values table still shows both units,
    // and the course group without CLOs should be highlighted.
    $page->select('#progression-units', 'counts')
        ->assertScript("Highcharts.charts.find(chart => chart?.renderTo.id === 'progression-chart').series[0].points.map(point => point.y)", [2, 0, null])
        ->click('#progression-table-section summary')
        ->assertSeeIn('#progression-table tbody tr:nth-child(1)', '2 (66.67%)')
        ->assertSeeIn('#progression-table tbody tr.table-warning', '300-level');

    // Open a course group and try the CLO filters. Check what is included and
    // excluded so a filter that leaves every CLO visible cannot pass this test.
    $page->click('#progression-group-buttons button:text-is("100-level details")')
        ->assertVisible('#progression-details-modal')
        ->assertSeeIn('#progression-details-counts', 'Showing 3 of 3 CLOs')
        ->select('#progression-details-filter', 'unmatched')
        ->assertSeeIn('#progression-course-list', 'Work together.')
        ->assertDontSeeIn('#progression-course-list', 'Remember the main ideas.')
        ->assertSeeIn('#progression-details-counts', 'Showing 1 of 3 CLOs')
        ->select('#progression-details-filter', (string) $this->report['levels']['Analyze']->id)
        ->assertSeeIn('#progression-course-list', 'Remember, analyze and create a solution.')
        ->assertDontSeeIn('#progression-course-list', 'Work together.')
        ->click('#progression-details-modal .modal-footer button')
        ->assertMissing('#progression-details-modal');

    // Beta only has one CLO from the 200-level course. Changing scope should
    // update the totals, table and chart together, without keeping Alpha's courses.
    $page->select('#progression-scope', (string) $this->report['beta']->pl_outcome_id)
        ->assertSeeIn('#progression-plo-description', 'Investigate new questions.')
        ->assertSeeIn('#progression-course-count', '1')
        ->assertSeeIn('#progression-clo-count', '1')
        ->assertVisible('#progression-incomplete')
        ->assertSeeIn('#progression-table tbody', '200-level')
        ->assertDontSeeIn('#progression-table tbody', '100-level')
        ->assertDisabled('#progression-review-details')
        ->assertScript("Highcharts.charts.find(chart => chart?.renderTo.id === 'progression-chart').xAxis[0].categories", ['200-level']);

    // The details should use the same scope. Beta's CLO has a Bloom match, so
    // selecting No Bloom match should show the empty message instead of stale results.
    $page->click('#progression-group-buttons button:text-is("200-level details")')
        ->assertSeeIn('#progression-course-list', 'Advanced topics')
        ->assertDontSeeIn('#progression-course-list', 'Foundations')
        ->select('#progression-details-filter', 'unmatched')
        ->assertVisible('#progression-details-empty')
        ->assertSeeIn('#progression-details-counts', 'Showing 0 of 1 CLOs')
        ->click('#progression-details-modal .modal-footer button')
        ->assertMissing('#progression-details-modal');

    // Returning to the whole program should restore the original totals and chart.
    $page->select('#progression-scope', '')
        ->assertSeeIn('#progression-clo-count', '4')
        ->assertMissing('#progression-incomplete')
        ->select('#progression-units', 'percentages')
        ->assertScript("Highcharts.charts.find(chart => chart?.renderTo.id === 'progression-chart').series[0].points.map(point => point.y)", [66.67, 0, null])
        ->assertNoJavaScriptErrors();
});

it('opens the CLOs suggested for review with a visible review badge', function () {
    $page = visit('/programWizard/'.$this->report['program']->program_id.'/step4');

    // Only one CLO matches three cognitive levels. The review button should open
    // that CLO directly, with the review filter selected and the badge visible.
    $page->click('#nav-progression-tab')
        ->assertSeeIn('#progression-review-count', '1')
        ->click('#progression-review-details')
        ->assertVisible('#progression-details-modal')
        ->assertValue('#progression-details-filter', 'review')
        ->assertSeeIn('#progression-course-list .badge.bg-warning', 'Review suggested')
        ->assertSeeIn('#progression-course-list', 'Remember, analyze and create a solution.')
        ->assertDontSeeIn('#progression-course-list', 'Remember the main ideas.')
        ->assertSeeIn('#progression-details-counts', 'Showing 1 of 4 CLOs')
        ->click('#progression-details-modal .modal-footer button')
        ->assertMissing('#progression-details-modal')
        ->assertNoJavaScriptErrors();
});

it('shows an empty PLO scope and restores the comparison report when returning to the program', function () {
    // This PLO exists in the selector but has no mapped CLOs.
    $plo = $this->report['program']->programLearningOutcomes()->create([
        'plo_shortphrase' => 'Unmapped', 'pl_outcome' => 'An outcome without contributing CLOs.',
    ]);
    $page = visit('/programWizard/'.$this->report['program']->program_id.'/step4');

    $page->click('#nav-progression-tab')
        ->assertVisible('#progression-chart .highcharts-root')
        ->select('#progression-scope', (string) $plo->pl_outcome_id)
        ->assertVisible('#progression-no-mappings')
        ->assertSeeIn('#progression-clo-count', '0')
        ->assertMissing('#progression-chart-section')
        ->assertMissing('#progression-table-section')
        ->assertMissing('#progression-groups');

    // The empty scope should not stop the user from going back to the full report.
    $page->select('#progression-scope', '')
        ->assertMissing('#progression-no-mappings')
        ->assertVisible('#progression-chart .highcharts-root')
        ->assertSeeIn('#progression-clo-count', '4')
        ->assertNoJavaScriptErrors();
});

it('keeps course and CLO details available when the Bloom reference is missing', function () {
    // Delete the verbs before their levels because the database prevents the reverse.
    // The courses and CLOs stay in place so we can still check their details.
    foreach ($this->report['levels'] as $level) {
        $level->verbs()->delete();
        $level->delete();
    }
    $page = visit('/programWizard/'.$this->report['program']->program_id.'/step4');

    $page->click('#nav-progression-tab')
        ->assertVisible('#progression-no-reference')
        ->assertMissing('#progression-chart-section')
        ->assertDisabled('#progression-review-details')
        ->click('#progression-table-section summary')
        ->assertSeeIn('#progression-table tbody tr:first-child td:last-child', 'N/A')
        ->click('#progression-group-buttons button:text-is("100-level details")')
        ->assertVisible('#progression-details-no-reference')
        ->assertDisabled('#progression-details-filter')
        ->assertSeeIn('#progression-course-list', 'Remember the main ideas.')
        ->assertSeeIn('#progression-course-list', 'Work together.')
        ->assertNoJavaScriptErrors();
});

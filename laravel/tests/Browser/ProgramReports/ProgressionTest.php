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

it('follows the cognitive-level report flow through units, tooltips, scope and comparison', function () {
    $page = visit('/programWizard/'.$this->report['program']->program_id.'/step4');
    $chart = "Highcharts.charts.find(chart => chart?.renderTo.id === 'progression-chart')";
    $analyzeBar = '#progression-chart .highcharts-series-0 .highcharts-point:nth-child(2)';

    // There are four CLOs, including one with no Bloom match. Two match Analyze,
    // so the chart and table should both show 50%, with each contributing course once.
    $page->click('#nav-progression-tab')
        ->assertVisible('#progression-chart .highcharts-root')
        ->select('#progression-view', 'cognitive-levels')
        ->assertSeeIn('#progression-chart-heading', 'CLO distribution by cognitive level')
        ->assertScript($chart.'.xAxis[0].categories', ['Remember', 'Analyze', 'Create'])
        ->assertScript($chart.'.series[0].points.map(point => point.y)', [50, 50, 25])
        ->click('#progression-table-section summary')
        ->assertSeeIn('#progression-table-section summary', 'Exact values by cognitive level')
        ->assertSeeIn('#progression-table tbody tr:nth-child(2)', '50%')
        ->assertSeeIn('#progression-table tbody tr:nth-child(2)', 'TEST 101: Foundations')
        ->assertSeeIn('#progression-table tbody tr:nth-child(2)', 'TEST 201: Advanced topics')
        ->assertScript("document.querySelectorAll('#progression-table tbody tr:first-child td:last-child div').length", 1)
        ->hover($analyzeBar)
        ->assertSeeIn('.highcharts-tooltip', 'Analyze: 50%')
        ->assertSeeIn('.highcharts-tooltip', '2 of 4 CLOs in the selected scope')
        ->assertSeeIn('.highcharts-tooltip', 'TEST 101: Foundations')
        ->assertSeeIn('.highcharts-tooltip', 'TEST 201: Advanced topics')
        ->assertDontSeeIn('.highcharts-tooltip', 'Future course');

    // Counts change the bars and tooltip, while the exact-values table keeps both units.
    $page->select('#progression-units', 'counts')
        ->assertScript($chart.'.series[0].points.map(point => point.y)', [2, 2, 1])
        ->hover($analyzeBar)
        ->assertSeeIn('.highcharts-tooltip', 'Analyze: 2 CLOs')
        ->assertSeeIn('#progression-table tbody tr:nth-child(2) td:first-of-type', '2')
        ->assertSeeIn('#progression-table tbody tr:nth-child(2)', '50%');

    // Beta only includes the Advanced topics CLO. The other course must disappear
    // from both the table and tooltip, and levels with no matches should stay at zero.
    $page->select('#progression-scope', (string) $this->report['beta']->pl_outcome_id)
        ->assertSeeIn('#progression-clo-count', '1')
        ->assertScript($chart.'.series[0].points.map(point => point.y)', [0, 1, 0])
        ->assertSeeIn('#progression-table tbody tr:first-child', 'No matching courses')
        ->assertSeeIn('#progression-table tbody tr:first-child', '0%')
        ->assertDontSeeIn('#progression-table tbody', 'Foundations')
        ->select('#progression-units', 'percentages')
        ->assertScript($chart.'.series[0].points.map(point => point.y)', [0, 100, 0])
        ->hover($analyzeBar)
        ->assertSeeIn('.highcharts-tooltip', 'Analyze: 100%')
        ->assertSeeIn('.highcharts-tooltip', '1 of 1 CLOs in the selected scope')
        ->assertSeeIn('.highcharts-tooltip', 'TEST 201: Advanced topics')
        ->assertDontSeeIn('.highcharts-tooltip', 'Foundations');

    // Going back should restore the whole program, then the original Comparison view.
    $page->select('#progression-scope', '')
        ->assertSeeIn('#progression-clo-count', '4')
        ->assertScript($chart.'.series[0].points.map(point => point.y)', [50, 50, 25])
        ->assertSeeIn('#progression-table tbody tr:first-child', 'Foundations')
        ->select('#progression-view', 'comparison')
        ->assertSeeIn('#progression-chart-heading', 'Cognitive levels by course group')
        ->assertSeeIn('#progression-table-section summary', 'Exact values by course group')
        ->assertSeeIn('#progression-table tbody', '2 (66.67%)')
        ->assertScript($chart.'.series[0].points.map(point => point.y)', [66.67, 0, null])
        ->assertNoJavaScriptErrors();
});

it('shows cognitive-level exact values when the chart library is unavailable', function () {
    $page = visit('/programWizard/'.$this->report['program']->program_id.'/step4');
    $page->click('#nav-progression-tab')
        ->assertVisible('#progression-chart .highcharts-root');

    // Simulate the library being unavailable on the next render. The report data
    // still comes from the real endpoint, and the table should open automatically.
    $page->script('window.Highcharts = undefined');
    $page->select('#progression-view', 'cognitive-levels')
        ->assertVisible('#progression-chart-unavailable')
        ->assertMissing('#progression-chart')
        ->assertDisabled('#progression-units')
        ->assertVisible('#progression-table tbody')
        ->assertSeeIn('#progression-table tbody tr:nth-child(2)', '50%')
        ->assertSeeIn('#progression-table tbody tr:nth-child(2)', 'TEST 201: Advanced topics')
        ->select('#progression-scope', (string) $this->report['beta']->pl_outcome_id)
        ->assertSeeIn('#progression-table tbody tr:nth-child(2)', '100%')
        ->assertDontSeeIn('#progression-table tbody', 'Foundations')
        ->assertNoJavaScriptErrors();
});

it('clears cognitive-level results if the Bloom reference becomes unavailable', function () {
    $page = visit('/programWizard/'.$this->report['program']->program_id.'/step4');
    $page->click('#nav-progression-tab')
        ->assertVisible('#progression-chart .highcharts-root')
        ->select('#progression-view', 'cognitive-levels')
        ->click('#progression-table-section summary')
        ->assertSeeIn('#progression-table tbody tr:first-child', 'Foundations');

    // Keep the selected view while the reference changes. Refreshing the scope
    // must clear the old results rather than presenting unavailable data as zero.
    foreach ($this->report['levels'] as $level) {
        $level->verbs()->delete();
        $level->delete();
    }
    $page->select('#progression-scope', (string) $this->report['beta']->pl_outcome_id)
        ->assertVisible('#progression-no-reference')
        ->assertMissing('#progression-chart-section')
        ->assertSeeIn('#progression-table tbody', 'The Bloom’s cognitive reference is unavailable.')
        ->assertDontSeeIn('#progression-table tbody', 'Foundations')
        ->assertDontSeeIn('#progression-table tbody', '0%')
        ->assertVisible('#progression-groups')
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

it('shows an empty PLO scope and restores the selected view when returning to the program', function (string $view) {
    // This PLO exists in the selector but has no mapped CLOs.
    $plo = $this->report['program']->programLearningOutcomes()->create([
        'plo_shortphrase' => 'Unmapped', 'pl_outcome' => 'An outcome without contributing CLOs.',
    ]);
    $page = visit('/programWizard/'.$this->report['program']->program_id.'/step4');

    $page->click('#nav-progression-tab')
        ->assertVisible('#progression-chart .highcharts-root')
        ->select('#progression-view', $view)
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
        ->assertValue('#progression-view', $view)
        ->assertSeeIn('#progression-clo-count', '4')
        ->assertScript("Highcharts.charts.find(chart => chart?.renderTo.id === 'progression-chart').series[0].points.map(point => point.y)",
            $view === 'cognitive-levels' ? [50, 50, 25] : [66.67, 0, null])
        ->assertNoJavaScriptErrors();
})->with(['comparison', 'cognitive-levels']);

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

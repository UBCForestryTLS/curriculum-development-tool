<?php

use Tests\Browser\ProgramReports\ReportFixtures;

beforeEach(function () {
    $this->report = ReportFixtures::create();
    $this->actingAs($this->report['user']);
});

it('follows the gap report flow from setting expectations to reviewing and editing the results', function () {
    $scaleId = $this->report['scale']->map_scale_id;
    $min = "#gap-coverage-mapped_clo_count-levels-{$scaleId}-min";
    $max = "#gap-coverage-mapped_clo_count-levels-{$scaleId}-max";
    $page = visit('/programWizard/'.$this->report['program']->program_id.'/step4');

    // Start with both concerns selected. Alpha has 75% coverage and Beta has 25%,
    // so a 50–60% range should flag Alpha as a redundancy and Beta as a gap.
    $page->click('#nav-gap-coverage-tab')
        ->assertVisible('#gap-coverage-expectations')
        ->assertMissing('#gap-coverage-report')
        ->assertVisible('#gap-coverage-incomplete')
        ->check('#gap-coverage-mapped_clo_count-enabled')
        ->check('#gap-coverage-review-redundancies')
        ->fill($min, '50')
        ->fill($max, '60');

    // Apply the ranges and check the actual chart values as well as the table.
    $page->click('#gap-coverage-expectations-form button[type="submit"]')
        ->assertMissing('#gap-coverage-expectations')
        ->assertVisible('#gap-coverage-report')
        ->assertSeeIn('#gap-coverage-expectations-summary', 'Potential gaps')
        ->assertSeeIn('#gap-coverage-expectations-summary', 'Potential redundancies')
        ->assertSeeIn('#gap-coverage-rows tr:nth-child(1) td:first-of-type strong', '75%')
        ->assertSeeIn('#gap-coverage-rows tr:nth-child(1)', 'Potential redundancy')
        ->assertSeeIn('#gap-coverage-rows tr:nth-child(2)', '25%')
        ->assertSeeIn('#gap-coverage-rows tr:nth-child(2)', 'Potential gap')
        ->assertVisible('#gap-coverage-chart .highcharts-root')
        ->assertScript("Highcharts.charts.find(chart => chart?.renderTo.id === 'gap-coverage-chart').series[0].points.map(point => point.y)", [75, 25]);

    // Counts change how coverage is displayed. The concern flags still use percentages.
    $page->select('#gap-coverage-units', 'counts')
        ->assertSeeIn('#gap-coverage-rows tr:nth-child(1) td:first-of-type strong', '3')
        ->assertSeeIn('#gap-coverage-rows tr:nth-child(1)', 'Potential redundancy')
        ->assertScript("Highcharts.charts.find(chart => chart?.renderTo.id === 'gap-coverage-chart').series[0].points.map(point => point.y)", [3, 1]);

    // Open Alpha's details and make sure courses from Beta do not appear there.
    $page->click('#gap-coverage-details-button-'.$this->report['alpha']->pl_outcome_id)
        ->assertVisible('#gap-coverage-details-modal')
        ->assertSeeIn('#gap-coverage-details-content', 'Foundations')
        ->assertSeeIn('#gap-coverage-details-content', 'Remember the main ideas.')
        ->assertDontSeeIn('#gap-coverage-details-content', 'Advanced topics')
        ->click('#gap-coverage-details-modal .modal-footer button')
        ->assertMissing('#gap-coverage-details-modal');

    // Leaving the tab and coming back should keep the applied settings and results.
    $page->click('#nav-progression-tab')
        ->assertVisible('#progression-results')
        ->click('#nav-gap-coverage-tab')
        ->assertVisible('#gap-coverage-report')
        ->assertValue('#gap-coverage-units', 'counts')
        ->assertSeeIn('#gap-coverage-rows tr:nth-child(1)', 'Potential redundancy');

    // Go back and turn off redundancies. The maximum stays saved, but only Beta's
    // gap should appear when filtering the updated report to PLOs with concerns.
    $page->click('#gap-coverage-back')
        ->assertValue($min, '50')
        ->assertValue($max, '60')
        ->uncheck('#gap-coverage-review-redundancies')
        ->assertDisabled($max)
        ->click('#gap-coverage-expectations-form button[type="submit"]')
        ->select('#gap-coverage-filter', 'concerns')
        ->assertDontSeeIn('#gap-coverage-rows', 'Alpha')
        ->assertSeeIn('#gap-coverage-rows', 'Beta')
        ->assertScript("Highcharts.charts.find(chart => chart?.renderTo.id === 'gap-coverage-chart').xAxis[0].categories", ['Beta']);

    // Statistics-only mode should bring both PLOs back and remove the concern flags.
    $page->click('#gap-coverage-back')
        ->assertValue($max, '60')
        ->click('#gap-coverage-view-report')
        ->assertSeeIn('#gap-coverage-expectations-summary', 'Showing statistics only')
        ->assertSeeIn('#gap-coverage-rows', 'Alpha')
        ->assertSeeIn('#gap-coverage-rows', 'Beta')
        ->assertDontSeeIn('#gap-coverage-rows', 'Potential gap')
        ->assertDontSeeIn('#gap-coverage-rows', 'Potential redundancy')
        ->assertNoJavaScriptErrors();
});

it('blocks invalid expectations and keeps the slider and text input in sync', function () {
    $scaleId = $this->report['scale']->map_scale_id;
    $min = "#gap-coverage-mapped_clo_count-levels-{$scaleId}-min";
    $max = "#gap-coverage-mapped_clo_count-levels-{$scaleId}-max";
    $page = visit('/programWizard/'.$this->report['program']->program_id.'/step4');

    // Maximums should only be editable when redundancies are selected.
    $page->click('#nav-gap-coverage-tab')
        ->check('#gap-coverage-mapped_clo_count-enabled')
        ->assertEnabled($min)
        ->assertDisabled($max)
        ->check('#gap-coverage-review-redundancies')
        ->assertEnabled($max);

    // An invalid percentage should keep the user on the form and show the error there.
    $page->fill($min, '101')
        ->click('#gap-coverage-expectations-form button[type="submit"]')
        ->assertVisible($min.'-error')
        ->assertAttribute($min, 'aria-invalid', 'true')
        ->assertMissing('#gap-coverage-report');

    // Correct it through the text input, then move the slider by one. Both controls
    // should agree, and the corrected form should now let the user view the report.
    $page->fill($min, '49')
        ->assertValue($min.'-slider', '49')
        ->keys($min.'-slider', 'ArrowRight')
        ->assertValue($min, '50')
        ->fill($max, '60')
        ->assertMissing($min.'-error')
        ->click('#gap-coverage-expectations-form button[type="submit"]')
        ->assertVisible('#gap-coverage-report')
        ->assertNoJavaScriptErrors();
});

it('filters concerns using the selected coverage metric', function () {
    $scaleId = $this->report['scale']->map_scale_id;
    $page = visit('/programWizard/'.$this->report['program']->program_id.'/step4');

    // Both PLOs have CLO concerns, but their course coverage is within 20–40%.
    // This catches PLOs being included because of concerns in a different metric.
    $page->click('#nav-gap-coverage-tab')
        ->check('#gap-coverage-review-redundancies')
        ->check('#gap-coverage-mapped_clo_count-enabled')
        ->fill("#gap-coverage-mapped_clo_count-levels-{$scaleId}-min", '50')
        ->fill("#gap-coverage-mapped_clo_count-levels-{$scaleId}-max", '60')
        ->check('#gap-coverage-covering_course_count-enabled')
        ->fill("#gap-coverage-covering_course_count-levels-{$scaleId}-min", '20')
        ->fill("#gap-coverage-covering_course_count-levels-{$scaleId}-max", '40')
        ->click('#gap-coverage-expectations-form button[type="submit"]');

    // Neither PLO should appear for the course metric when the concern filter is on.
    $page->select('#gap-coverage-filter', 'concerns')
        ->select('#gap-coverage-metric', 'covering_course_count')
        ->assertSeeIn('#gap-coverage-rows', 'No PLOs match the concern filter for this coverage metric.')
        ->assertMissing('#gap-coverage-chart')
        ->assertVisible('#gap-coverage-chart-message')
        ->assertSeeIn('#gap-coverage-display-metric', 'Metric: Covering courses');

    // Switching back to CLOs should restore both PLOs in the table and chart.
    $page->select('#gap-coverage-metric', 'mapped_clo_count')
        ->assertSeeIn('#gap-coverage-rows', 'Alpha')
        ->assertSeeIn('#gap-coverage-rows', 'Beta')
        ->assertVisible('#gap-coverage-chart .highcharts-root')
        ->assertScript("Highcharts.charts.find(chart => chart?.renderTo.id === 'gap-coverage-chart').xAxis[0].categories", ['Alpha', 'Beta'])
        ->assertNoJavaScriptErrors();
});

it('allows statistics and PLO details without configured mapping levels', function () {
    $this->report['program']->mappingScaleLevels()->detach();
    $page = visit('/programWizard/'.$this->report['program']->program_id.'/step4');

    // No levels means no ranges or chart, but the PLO statistics should still be usable.
    $page->click('#nav-gap-coverage-tab')
        ->assertVisible('#gap-coverage-no-levels')
        ->assertDisabled('#gap-coverage-mapped_clo_count-enabled')
        ->click('#gap-coverage-view-report')
        ->assertVisible('#gap-coverage-report-no-levels')
        ->assertMissing('#gap-coverage-chart')
        // The report already explains the missing levels, so do not show a duplicate warning.
        ->assertMissing('#gap-coverage-chart-message')
        ->assertSeeIn('#gap-coverage-rows', 'Alpha')
        ->click('#gap-coverage-details-button-'.$this->report['alpha']->pl_outcome_id)
        ->assertVisible('#gap-coverage-details-modal')
        ->assertSeeIn('#gap-coverage-details-title', 'Alpha')
        ->assertSeeIn('#gap-coverage-details-content', 'Use foundational knowledge.')
        ->assertNoJavaScriptErrors();
});

it('explains why a program without PLOs cannot generate a gap report', function () {
    $this->report['program']->programLearningOutcomes()->delete();
    $page = visit('/programWizard/'.$this->report['program']->program_id.'/step4');

    $page->click('#nav-gap-coverage-tab')
        ->assertVisible('#gap-coverage-empty')
        ->assertDisabled('#gap-coverage-view-report')
        ->assertMissing('#gap-coverage-results')
        ->assertMissing('#gap-coverage-incomplete')
        ->assertNoJavaScriptErrors();
});

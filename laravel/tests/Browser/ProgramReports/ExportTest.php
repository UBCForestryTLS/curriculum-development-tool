<?php

use Illuminate\Support\Facades\Http;
use Smalot\PdfParser\Parser;
use Tests\Browser\ProgramReports\PreserveStreamedDownloads;
use Tests\Browser\ProgramReports\ReportDownloads;
use Tests\Browser\ProgramReports\ReportFixtures;

beforeEach(function () {
    $this->report = ReportFixtures::create();
    $this->actingAs($this->report['user']);
    app(\Illuminate\Contracts\Http\Kernel::class)->prependMiddleware(PreserveStreamedDownloads::class);
    Http::preventStrayRequests();
    // Only replace the external image service. The report endpoints still generate real files.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAIAAAACUFjqAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADUlEQVQYlWNgGAWkAwABNgABxYufBwAAAABJRU5ErkJggg==');
    Http::fake(['export.highcharts.com/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
});

it('downloads applied gap expectations and clears them when returning to statistics only', function (string $format) {
    $scaleId = $this->report['scale']->map_scale_id;
    $page = visit('/programWizard/'.$this->report['program']->program_id.'/step4');
    ReportDownloads::watch($page);
    $page->click('#nav-gap-coverage-tab')
        ->check('#gap-coverage-mapped_clo_count-enabled')
        ->check('#gap-coverage-review-redundancies')
        ->fill("#gap-coverage-mapped_clo_count-levels-{$scaleId}-min", '50')
        ->fill("#gap-coverage-mapped_clo_count-levels-{$scaleId}-max", '60')
        ->click('#gap-coverage-expectations-form button[type="submit"]');

    // This display filter hides both PLOs, but the download should still include all PLOs and metrics.
    $page->select('#gap-coverage-metric', 'covering_course_count')
        ->select('#gap-coverage-units', 'counts')
        ->select('#gap-coverage-filter', 'concerns')
        ->assertSeeIn('#gap-coverage-rows', 'No PLOs match the concern filter');
    $file = ReportDownloads::download($page, 'gap-coverage', $format);
    expect($file['filename'])->toBe('browser-report-program-'.$this->report['program']->program_id
        .'-gap-and-redundancy-'.now()->format('Y-m-d').'.'.$format);
    if ($format === 'pdf') {
        $document = (new Parser)->parseContent($file['content']);
        $text = $document->getText();
        expect($text)->toContain('Use foundational knowledge.', 'Investigate new questions.', 'Potential gap', 'Potential redundancy');
        $this->assertMatchesRegularExpression('/3 of 4\s*75%\s*50%\s*60%\s*Potential redundancy/', $text);
        expect($document->getObjectsByType('XObject', 'Image'))->not->toBeEmpty();
        Http::assertSent(function ($request) {
            $chart = json_decode($request['options'], true);

            return $chart['title']['text'] === 'Covering courses'
                && $chart['yAxis']['title']['text'] === 'Number of courses'
                && $chart['series'][0]['data'] === [1, 1];
        });
    } else {
        $sheets = ReportDownloads::spreadsheetRows($file['content']);
        $cloRows = collect($sheets['Coverage comparisons'])->filter(fn ($row) => $row[3] === 'Mapped CLOs');
        expect($cloRows->map(fn ($row) => array_slice($row, 5))->values()->all())->toEqual([
            [3, 4, 75, 50, 60, 'Potential redundancy'],
            [1, 4, 25, 50, 60, 'Potential gap'],
        ]);
        expect(json_encode($sheets['Supporting mappings']))->toContain('Foundations', 'Advanced topics');
    }

    // A second download must use the newly applied settings, not the earlier range.
    $page->click('#gap-coverage-back')->click('#gap-coverage-view-report');
    $statistics = ReportDownloads::download($page, 'gap-coverage', $format);
    if ($format === 'pdf') {
        expect((new Parser)->parseContent($statistics['content'])->getText())->toContain('Statistics only', 'No expectation applied');
    } else {
        $sheets = ReportDownloads::spreadsheetRows($statistics['content']);
        expect($sheets['Summary and expectations'][7][1])->toBe('No');
        expect($sheets['Summary and expectations'][8][1])->toBe('No');
        expect(array_unique(array_column(array_slice($sheets['Coverage comparisons'], 1), 10)))->toBe(['No expectation applied']);
    }
})->with(['pdf', 'xlsx']);

it('downloads progression data after changing the view units and PLO scope', function (string $format, string $view) {
    $page = visit('/programWizard/'.$this->report['program']->program_id.'/step4');
    ReportDownloads::watch($page);
    $page->click('#nav-progression-tab')->assertVisible('#progression-results')->select('#progression-view', $view);
    $program = ReportDownloads::download($page, 'progression', $format);

    // Whole-program percentages use all four CLOs, including the unmatched one.
    if ($format === 'pdf') {
        $text = (new Parser)->parseContent($program['content'])->getText();
        $this->assertMatchesRegularExpression('/Analyze\s*2\s*50%/', $text);
        expect($text)->toContain('Scope: Entire program', 'Foundations', 'Advanced topics');
        $expected = $view === 'cognitive-levels'
            ? [[50, 50, 25]]
            : [[66.67, 0, null], [33.33, 100, null], [33.33, 0, null]];
        Http::assertSent(fn ($request) => array_column(json_decode($request['options'], true)['series'], 'data') === $expected);
    } else {
        $sheets = ReportDownloads::spreadsheetRows($program['content']);
        expect($sheets['Summary'][11][1])->toBe(4);
        expect(array_column(array_slice($sheets['Cognitive-level distribution'], 1), 2))->toEqual([50, 50, 25]);
    }

    // Beta has one Analyze CLO. Its export must exclude Alpha's courses and use a denominator of one.
    $page->select('#progression-scope', (string) $this->report['beta']->pl_outcome_id)
        ->assertSeeIn('#progression-plo-description', 'Investigate new questions.')
        ->select('#progression-units', 'counts');
    $scoped = ReportDownloads::download($page, 'progression', $format);
    if ($format === 'pdf') {
        $text = (new Parser)->parseContent($scoped['content'])->getText();
        expect($text)->toContain('Scope: Selected PLO', 'Advanced topics')->not->toContain('Foundations');
        $this->assertMatchesRegularExpression('/Analyze\s*1\s*100%/', $text);
        $expected = $view === 'cognitive-levels' ? [[0, 1, 0]] : [[0], [1], [0]];
        Http::assertSent(function ($request) use ($expected) {
            $chart = json_decode($request['options'], true);

            return $chart['yAxis']['title']['text'] === 'Number of CLOs'
                && array_column($chart['series'], 'data') === $expected;
        });
    } else {
        $sheets = ReportDownloads::spreadsheetRows($scoped['content']);
        expect($sheets['Summary'][11][1])->toBe(1);
        expect(array_column(array_slice($sheets['Cognitive-level distribution'], 1), 2))->toEqual([0, 100, 0]);
        expect(json_encode($sheets['CLO classifications']))->toContain('Analyze new questions.')->not->toContain('Remember the main ideas.');
        expect($sheets['Cognitive-level distribution'][2][3])->toBe('TEST 201 — Advanced topics');
    }
})->with(['pdf', 'xlsx'])->with(['comparison', 'cognitive-levels']);

it('allows another download after program access is restored', function (string $report) {
    $page = visit('/programWizard/'.$this->report['program']->program_id.'/step4');
    ReportDownloads::watch($page);
    $page->click('#nav-'.$report.'-tab');
    if ($report === 'gap-coverage') {
        $page->click('#gap-coverage-view-report');
    }
    $page->assertEnabled('#'.$report.'-download');

    // Access can change after the page loads. A failed download should leave the controls usable.
    $this->report['program']->users()->detach($this->report['user']->id);
    $attribute = $report === 'gap-coverage' ? 'gap' : 'progression';
    $page->click('#'.$report.'-download')->click('[data-'.$attribute.'-export="pdf"]')
        ->assertSeeIn('#'.$report.'-export-error', 'You no longer have permission')
        ->assertEnabled('#'.$report.'-download')->assertScript('window.reportDownloads.length', 0);
    $this->report['program']->users()->attach($this->report['user']->id, ['permission' => 3]);
    $file = ReportDownloads::download($page, $report, 'pdf');
    expect((new Parser)->parseContent($file['content'])->getText())->toContain('Browser report program');
})->with(['gap-coverage', 'progression']);

it('allows retrying when a removed mapping level has been restored', function () {
    $scaleId = $this->report['scale']->map_scale_id;
    $page = visit('/programWizard/'.$this->report['program']->program_id.'/step4');
    ReportDownloads::watch($page);
    $page->click('#nav-gap-coverage-tab')->check('#gap-coverage-mapped_clo_count-enabled')
        ->fill("#gap-coverage-mapped_clo_count-levels-{$scaleId}-min", '50')
        ->click('#gap-coverage-expectations-form button[type="submit"]');
    $this->report['program']->mappingScaleLevels()->detach($scaleId);
    $page->click('#gap-coverage-download')->click('[data-gap-export="xlsx"]')
        ->assertSeeIn('#gap-coverage-export-error', 'These report settings are no longer valid.')
        ->assertEnabled('#gap-coverage-download')->assertScript('window.reportDownloads.length', 0);

    $this->report['program']->mappingScaleLevels()->attach($scaleId, ['position' => 1]);
    $file = ReportDownloads::download($page, 'gap-coverage', 'xlsx');
    expect(ReportDownloads::spreadsheetRows($file['content']))->toHaveKey('Coverage comparisons');
});

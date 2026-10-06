<?php

namespace Tests\Unit;

use App\Helpers\GapCoverageReport;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GapCoverageReportTest extends TestCase
{
    private function settings($min = 40, $max = 40): array
    {
        return ['concerns' => ['gaps' => true, 'redundancies' => true], 'metrics' => [
            'covering_course_count' => ['levels' => [90 => compact('min', 'max')]],
        ]];
    }

    public function test_normalization_preserves_zero_and_ignores_inactive_bounds(): void
    {
        $this->assertNull(GapCoverageReport::validateExpectations(null, []));
        $settings = $this->settings('0', '');
        $normalized = GapCoverageReport::validateExpectations($settings, [90]);
        $this->assertSame(['min' => 0, 'max' => null], $normalized['metrics']['covering_course_count']['levels'][90]);
        $settings['concerns']['redundancies'] = false;
        $settings['metrics']['covering_course_count']['levels'][90]['max'] = 'ignored';
        $this->assertNull(GapCoverageReport::validateExpectations($settings, [90])['metrics']['covering_course_count']['levels'][90]['max']);
        $settings['concerns']['gaps'] = false;
        $this->assertSame([], GapCoverageReport::validateExpectations($settings, [])['metrics']);
    }

    public function test_invalid_settings_are_rejected(): void
    {
        $unknownMetric = $this->settings();
        $unknownMetric['metrics']['unknown'] = ['levels' => []];
        foreach ([[$this->settings(60, 40), [90]], [$this->settings(-1, 40), [90]],
            [$this->settings(0, 101), [90]], [$this->settings('1.5', 40), [90]],
            [$this->settings([], 40), [90]], [$this->settings(), [0]],
            [$this->settings(), [91]], [$unknownMetric, [90]]] as [$settings, $ids]) {
            try {
                GapCoverageReport::validateExpectations($settings, $ids);
                $this->fail('Invalid export settings were accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_findings_use_inclusive_unrounded_bounds_and_distinct_plo_summaries(): void
    {
        $data = ['program_totals' => ['course_count' => 100000, 'clo_count' => 0], 'coverage' => []];
        foreach ([39999, 40000, 40001] as $id => $count) {
            $data['coverage'][] = ['pl_outcome_id' => $id, 'mapping_scale_histogram' => [[
                'map_scale_id' => 90, 'mapped_clo_count' => 0, 'covering_course_count' => $count,
                'required_course_count' => $count, 'non_required_course_count' => 0,
            ]]];
        }
        $settings = GapCoverageReport::validateExpectations($this->settings(), [90]);
        $settings['metrics']['required_course_count'] = ['levels' => [90 => ['min' => 0, 'max' => 0]]];
        $result = GapCoverageReport::evaluate($data, $settings);
        $this->assertSame(['gap', 'within_expectations', 'redundancy'], array_map(fn ($plo) => $plo['comparisons'][1]['status'], $result['plos']));
        $this->assertEquals([39.999, 40, 40.001], array_map(fn ($plo) => $plo['comparisons'][1]['percentage'], $result['plos']));
        $this->assertSame('not_applicable', $result['plos'][0]['comparisons'][0]['status']);
        $this->assertSame(100000, $result['plos'][0]['comparisons'][2]['denominator']);
        $this->assertSame(['evaluated_plo_count' => 3, 'concern_plo_count' => 3, 'gap_plo_count' => 1, 'redundancy_plo_count' => 3], $result['summary']);
        $this->assertSame('no_expectation', GapCoverageReport::evaluate($data)['plos'][0]['comparisons'][1]['status']);
        $data['program_totals']['course_count'] = 0;
        $this->assertSame(0, GapCoverageReport::evaluate($data, $settings)['summary']['concern_plo_count']);
        $data['coverage'] = [];
        $this->assertSame([], GapCoverageReport::evaluate($data)['plos']);
    }
    public function test_spreadsheet_preserves_comparisons_mappings_and_literal_text(): void
    {
        $settings = $this->settings(0, 0);
        $data = ['program_id' => 1, 'expectations' => $settings,
            'program_totals' => ['course_count' => 2, 'clo_count' => 1],
            'mapping_completeness' => ['has_incomplete_mappings' => true],
            'coverage' => [['pl_outcome_id' => 1, 'plo_shortphrase' => '=1+1', 'pl_outcome' => 'Analyze data',
                'mapping_scale_histogram' => [['map_scale_id' => 90, 'title' => 'Introduced',
                    'mapped_clo_count' => 1, 'covering_course_count' => 1, 'required_course_count' => 0, 'non_required_course_count' => 0]],
                'courses' => [['course_id' => 1, 'course_code' => 'TEST', 'course_num' => '001',
                    'course_title' => 'Course', 'course_required' => null,
                    'learning_outcomes' => [['l_outcome_id' => 1, 'clo_shortphrase' => 'Analyze',
                        'l_outcome' => '=HYPERLINK("https://example.test")', 'map_scale_title' => 'Introduced']]]]]]];
        $data['results'] = GapCoverageReport::evaluate($data, $settings);
        $book = (new \App\Exports\GapCoverageSpreadsheet)->build([
            'program_name' => 'Test', 'generated_at' => '2026-10-06', 'report' => $data,
        ]);
        try {
            $coverage = $book->getSheetByName('Coverage comparisons');
            $this->assertSame(5, $coverage->getHighestRow());
            $this->assertEquals(50, $coverage->getCell('H3')->getValue());
            $this->assertSame(0, $coverage->getCell('I3')->getValue());
            $this->assertSame('Potential redundancy', $coverage->getCell('K3')->getValue());
            $this->assertNull($coverage->getCell('I2')->getValue());
            $this->assertSame('s', $coverage->getCell('B2')->getDataType());
            $mappings = $book->getSheetByName('Supporting mappings');
            $this->assertSame('001', $mappings->getCell('E2')->getValue());
            $this->assertSame('Unspecified', $mappings->getCell('G2')->getValue());
            $this->assertSame('s', $mappings->getCell('J2')->getDataType());
            $this->assertStringContainsString('provisional', $book->getSheet(0)->getCell('B10')->getValue());
        } finally {
            $book->disconnectWorksheets();
        }
    }

}

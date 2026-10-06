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
}

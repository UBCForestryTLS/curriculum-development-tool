<?php

namespace Tests\Unit;

use App\Helpers\ProgramReportChart;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProgramReportChartTest extends TestCase
{
    public function test_comparison_chart_keeps_unknown_groups_and_missing_values(): void
    {
        $group = ['course_level' => 100, 'clo_count' => 2, 'blooms_levels' => [['level_id' => 1, 'clo_count' => 1, 'percentage' => 50]]];
        $report = ['bloom_reference_available' => true, 'bloom_levels' => [['id' => 1, 'name' => 'Apply']],
            'course_groups' => [$group, [
                'course_level' => 200, 'clo_count' => 0,
                'blooms_levels' => [['level_id' => 1, 'clo_count' => 0, 'percentage' => null]],
            ], array_replace($group, ['course_level' => 'other'])]];
        $chart = ProgramReportChart::progression($report, ['units' => 'counts', 'view' => 'comparison']);
        $this->assertSame('column', $chart['chart']['type']);
        $this->assertSame(['100-level', '200-level (no CLOs)', 'Other/unknown'], $chart['xAxis']['categories']);
        $this->assertSame([1, null, 1], $chart['series'][0]['data']);
    }

    public function test_cognitive_level_chart_uses_scoped_counts_and_percentages(): void
    {
        $report = [
            'bloom_reference_available' => true,
            'cognitive_distribution' => ['clo_count' => 4, 'levels' => [
                ['name' => 'Remember', 'clo_count' => 2, 'percentage' => 50.0],
                ['name' => 'Analyze', 'clo_count' => 1, 'percentage' => 25.0],
                ['name' => 'Create', 'clo_count' => 0, 'percentage' => 0.0],
            ]],
        ];
        foreach (['counts' => [2, 1, 0], 'percentages' => [50.0, 25.0, 0.0]] as $units => $values) {
            $chart = ProgramReportChart::progression($report, ['units' => $units, 'view' => 'cognitive-levels']);
            $this->assertSame('column', $chart['chart']['type']);
            $this->assertSame(['Remember', 'Analyze', 'Create'], $chart['xAxis']['categories']);
            $this->assertSame($values, $chart['series'][0]['data']);
        }
        $report['bloom_reference_available'] = false;
        $this->assertSame([], ProgramReportChart::progression($report, ['units' => 'counts', 'view' => 'cognitive-levels'])['series']);
        $report['bloom_reference_available'] = true;
        $report['cognitive_distribution']['clo_count'] = 0;
        $this->assertSame([], ProgramReportChart::progression($report, ['units' => 'counts', 'view' => 'cognitive-levels'])['series']);
    }

    public function test_chart_image_accepts_png_and_falls_back_on_service_failures(): void
    {
        $config = ['xAxis' => ['categories' => ['PLO 1']], 'series' => [['data' => [1]]]];
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAIAAAACUFjqAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADUlEQVQYlWNgGAWkAwABNgABxYufBwAAAABJRU5ErkJggg==');
        Http::fakeSequence()->push($png)->push('<html>Unavailable</html>')->push('', 503)
            ->whenEmpty(fn () => throw new ConnectionException('Timeout'));
        $image = ProgramReportChart::image($config);
        $this->assertSame('data:image/png;base64,'.base64_encode($png), $image);
        $pdf = \PDF::loadView('programs.exports.chart', ['chartImage' => $image])->output();
        $document = (new \Smalot\PdfParser\Parser)->parseContent($pdf);
        $this->assertNotEmpty($document->getObjectsByType('XObject', 'Image'));
        $this->assertNull(ProgramReportChart::image($config));
        $this->assertNull(ProgramReportChart::image($config));
        $this->assertNull(ProgramReportChart::image($config));
    }
}

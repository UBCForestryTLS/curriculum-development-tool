<?php

namespace Tests\Unit;

use App\Helpers\ProgramReportChart;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function test_chart_image_logs_encoding_failures_and_falls_back_without_sending_a_request(): void
    {
        Http::fake();
        Log::spy();
        // JSON cannot represent infinity, so this fails before contacting the image service.
        $config = ['xAxis' => ['categories' => ['PLO 1']], 'series' => [['data' => [INF]]]];

        $image = ProgramReportChart::image($config);

        $this->assertNull($image);
        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->with(
            'Could not generate the report chart because its data could not be converted to JSON. The PDF export will continue without the chart.',
            ['error' => 'Inf and NaN cannot be JSON encoded'],
        );
        $pdf = \PDF::loadView('programs.exports.chart', ['chartImage' => $image])->output();
        $text = (new \Smalot\PdfParser\Parser)->parseContent($pdf)->getText();
        $this->assertStringContainsString('The chart is unavailable. Exact values remain available in the tables below.', $text);
    }

    public function test_chart_image_accepts_png_and_falls_back_on_service_failures(): void
    {
        Log::spy();
        $config = ['xAxis' => ['categories' => ['PLO 1']], 'series' => [['data' => [1]]]];
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAIAAAACUFjqAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADUlEQVQYlWNgGAWkAwABNgABxYufBwAAAABJRU5ErkJggg==');
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        Http::fakeSequence()->push($png)
            ->push('<html>Unavailable</html>', 200, ['Content-Type' => 'text/html'])
            ->push($gif, 200, ['Content-Type' => 'image/png'])
            ->push('', 403, ['Content-Type' => 'text/html'])
            ->whenEmpty(fn () => throw new ConnectionException('Timeout'));
        $image = ProgramReportChart::image($config);
        $this->assertSame('data:image/png;base64,'.base64_encode($png), $image);
        Log::shouldNotHaveReceived('warning');
        $pdf = \PDF::loadView('programs.exports.chart', ['chartImage' => $image])->output();
        $document = (new \Smalot\PdfParser\Parser)->parseContent($pdf);
        $this->assertNotEmpty($document->getObjectsByType('XObject', 'Image'));

        // A successful HTTP response can still contain an error page instead of an image.
        $this->assertNull(ProgramReportChart::image($config));
        Log::shouldHaveReceived('warning')->with(
            'Highcharts did not return a valid PNG image for the report chart. The PDF export will continue without the chart.',
            ['http_status' => 200, 'content_type' => 'text/html', 'detected_image_type' => null],
        )->once();

        // Check the image itself, even when its response header claims it is a PNG.
        $this->assertNull(ProgramReportChart::image($config));
        Log::shouldHaveReceived('warning')->with(
            'Highcharts did not return a valid PNG image for the report chart. The PDF export will continue without the chart.',
            ['http_status' => 200, 'content_type' => 'image/png', 'detected_image_type' => 'image/gif'],
        )->once();

        // A blocked request should leave its HTTP status in the log.
        $this->assertNull(ProgramReportChart::image($config));
        Log::shouldHaveReceived('warning')->with(
            'Highcharts returned an unsuccessful response while generating the report chart. The PDF export will continue without the chart.',
            ['http_status' => 403, 'content_type' => 'text/html'],
        )->once();

        $this->assertNull(ProgramReportChart::image($config));
        Log::shouldHaveReceived('warning')->with(
            'Could not connect to Highcharts or the chart request timed out. The PDF export will continue without the chart.',
            ['error' => 'Timeout'],
        )->once();
    }

    public function test_chart_image_logs_responses_over_the_size_limit_and_falls_back(): void
    {
        Log::spy();
        $config = ['xAxis' => ['categories' => ['PLO 1']], 'series' => [['data' => [1]]]];
        $maxBytes = 5 * 1024 * 1024;
        Http::fake(['export.highcharts.com/*' => Http::response(str_repeat('x', $maxBytes + 1))]);

        $this->assertNull(ProgramReportChart::image($config));
        Log::shouldHaveReceived('warning')->with(
            'The report chart image returned by Highcharts exceeded the 5 MB size limit. The PDF export will continue without the chart.',
            ['response_bytes' => $maxBytes + 1, 'max_bytes' => $maxBytes],
        )->once();
    }
}

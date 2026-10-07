<?php

namespace Tests\Unit;

use App\Helpers\ProgramReportChart;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProgramReportChartTest extends TestCase
{
    public function test_progression_chart_separates_unknown_groups_and_preserves_missing_values(): void
    {
        $group = ['course_level' => 100, 'clo_count' => 2, 'levels' => [['level_id' => 1, 'clo_count' => 1, 'percentage' => 50]]];
        $report = ['bloom_reference_available' => true, 'bloom_levels' => [['id' => 1, 'name' => 'Apply']],
            'course_groups' => [$group, array_replace($group, ['course_level' => 'other'])]];
        $chart = ProgramReportChart::progression($report, ['units' => 'counts', 'view' => 'progression']);
        $this->assertSame('line', $chart['chart']['type']);
        $this->assertSame(['100-level', '', 'Other/unknown'], $chart['xAxis']['categories']);
        $this->assertSame([1, null, 1], $chart['series'][0]['data']);
        $this->assertFalse($chart['plotOptions']['line']['connectNulls']);
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

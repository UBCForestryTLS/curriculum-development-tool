<?php

namespace App\Helpers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

class ProgramReportChart
{
    public static function gapCoverage(array $report, array $options): array
    {
        $metric = $options['metric'];
        $counts = $options['units'] === 'counts';
        $labels = GapCoverageReport::METRIC_LABELS;
        $plos = $report['coverage'];
        $results = array_column($report['results']['plos'], null, 'pl_outcome_id');
        // Each mapping level is a series, with one value per PLO for the selected metric.
        $series = [];
        foreach ($plos[0]['mapping_scale_histogram'] ?? [] as $level) {
            if ((int) $level['map_scale_id'] === 0) {
                continue;
            }
            $series[] = [
                'name' => e($level['title']), 'color' => $level['colour'],
                'data' => array_map(function ($plo) use ($results, $level, $metric, $counts) {
                    $row = collect($results[$plo['pl_outcome_id']]['comparisons'])
                        ->where('metric', $metric)->firstWhere('map_scale_id', $level['map_scale_id']);

                    if ($row['percentage'] === null) {
                        return null;
                    }

                    return $counts ? $row['count'] : $row['percentage'];
                }, $plos),
            ];
        }

        $axisTitle = 'Coverage (%)';
        if ($counts) {
            $axisTitle = $metric === 'mapped_clo_count' ? 'Number of CLOs' : 'Number of courses';
        }

        // Array keys supply the positions used to label PLOs without a short phrase.
        return self::config('column', $labels[$metric], array_map(
            fn ($plo, $index) => e($plo['plo_shortphrase'] ?: 'PLO '.($index + 1)), $plos, array_keys($plos),
        ), $series, $counts, $axisTitle);
    }

    public static function progression(array $report, array $options): array
    {
        $counts = $options['units'] === 'counts';
        // The cognitive-level view combines all CLOs in the selected scope.
        if ($options['view'] === 'cognitive-levels') {
            $distribution = $report['cognitive_distribution'];
            $series = [];
            if ($report['bloom_reference_available'] && $distribution['clo_count'] > 0) {
                $series[] = [
                    'name' => 'CLOs',
                    'data' => array_column($distribution['levels'], $counts ? 'clo_count' : 'percentage'),
                ];
            }

            $chart = self::config('column', 'CLO distribution by cognitive level',
                array_map(fn ($level) => e($level['name']), $distribution['levels']),
                $series, $counts, $counts ? 'Number of CLOs' : 'CLOs (%)');
            $chart['legend'] = ['enabled' => false];

            return $chart;
        }

        // The comparison view shows each cognitive level across the course groups.
        $groups = $report['course_groups'];
        $series = [];
        if ($report['bloom_reference_available']) {
            foreach ($report['bloom_levels'] as $level) {
                $series[] = ['name' => e($level['name']), 'data' => array_map(function ($group) use ($level, $counts) {
                    $row = collect($group['blooms_levels'])->firstWhere('level_id', $level['id']);

                    if ($row['percentage'] === null) {
                        return null;
                    }

                    return $counts ? $row['clo_count'] : $row['percentage'];
                }, $groups)];
            }
        }

        return self::config('column', 'Cognitive levels by course group',
            array_map(fn ($group) => ($group['course_level'] === 'other' ? 'Other/unknown' : $group['course_level'].'-level')
                .($group['clo_count'] === 0 ? ' (no CLOs)' : ''), $groups),
            $series, $counts, $counts ? 'Number of CLOs' : 'CLOs (%)');
    }

    private static function config(string $type, string $title, array $categories, array $series, bool $counts, string $axis): array
    {
        return [
            'chart' => ['type' => $type, 'animation' => false, 'width' => 1000, 'height' => 500],
            'title' => ['text' => $title],
            'xAxis' => ['categories' => $categories],
            'yAxis' => ['min' => 0, 'max' => $counts ? null : 100, 'allowDecimals' => ! $counts, 'title' => ['text' => $axis]],
            'plotOptions' => ['series' => ['animation' => false]],
            'credits' => ['enabled' => false], 'exporting' => ['enabled' => false],
            'series' => $series,
        ];
    }

    /**
     * Reuse the existing Highcharts export service; embed the image without shared temporary files.
     * Return null if the chart is unavailable so the PDF can still be downloaded with its tables.
     */
    public static function image(array $config): ?string
    {
        if ($config['xAxis']['categories'] === [] || $config['series'] === []) {
            return null;
        }
        try {
            $response = Http::asForm()->withHeaders([
                'Referer' => config('app.url'),
                'User-Agent' => 'CurriculumDevelopmentTool/1.0 (report-export)',
            ])->connectTimeout(2)->timeout(5)->post('https://export.highcharts.com/', [
                'type' => 'image/png', 'width' => 1000, 'options' => json_encode($config, JSON_THROW_ON_ERROR),
            ]);
            $image = $response->body();
            if (! $response->successful()) {
                Log::warning('Highcharts returned an unsuccessful response while generating the report chart. The PDF export will continue without the chart.', [
                    'http_status' => $response->status(),
                    'content_type' => $response->header('Content-Type'),
                ]);

                return null;
            }
            if (strlen($image) > 5 * 1024 * 1024) {
                Log::warning('The report chart image returned by Highcharts exceeded the 5 MB size limit. The PDF export will continue without the chart.', [
                    'response_bytes' => strlen($image),
                    'max_bytes' => 5 * 1024 * 1024,
                ]);

                return null;
            }
            $info = @getimagesizefromstring($image);
            if (! $info || $info[2] !== IMAGETYPE_PNG) {
                Log::warning('Highcharts did not return a valid PNG image for the report chart. The PDF export will continue without the chart.', [
                    'http_status' => $response->status(),
                    'content_type' => $response->header('Content-Type'),
                    'detected_image_type' => $info['mime'] ?? null,
                ]);

                return null;
            }

            return 'data:image/png;base64,'.base64_encode($image);
        } catch (JsonException $exception) {
            Log::warning('Could not generate the report chart because its data could not be converted to JSON. The PDF export will continue without the chart.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        } catch (ConnectionException $exception) {
            Log::warning('Could not connect to Highcharts or the chart request timed out. The PDF export will continue without the chart.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}

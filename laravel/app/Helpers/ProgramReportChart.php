<?php

namespace App\Helpers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ProgramReportChart
{
    public static function gapCoverage(array $report, array $options): array
    {
        $metric = $options['metric'];
        $counts = $options['units'] === 'counts';
        $labels = ['mapped_clo_count' => 'Mapped CLOs', 'covering_course_count' => 'Covering courses',
            'required_course_count' => 'Required covering courses', 'non_required_course_count' => 'Non-required covering courses'];
        $plos = $report['coverage'];
        $results = array_column($report['results']['plos'], null, 'pl_outcome_id');
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

                    return $row['percentage'] === null ? null : ($counts ? $row['count'] : $row['percentage']);
                }, $plos),
            ];
        }

        return self::config('column', $labels[$metric], array_map(
            fn ($plo, $index) => e($plo['plo_shortphrase'] ?: 'PLO '.($index + 1)), $plos, array_keys($plos),
        ), $series, $counts, $counts ? ($metric === 'mapped_clo_count' ? 'Number of CLOs' : 'Number of courses') : 'Coverage (%)');
    }

    public static function progression(array $report, array $options): array
    {
        $counts = $options['units'] === 'counts';
        $trend = $options['view'] === 'progression';
        $groups = $report['course_groups'];
        // Match the page's break before Other/unknown in the progression view.
        foreach ($groups as $index => $group) {
            if ($trend && $index > 0 && $group['course_level'] === 'other') {
                array_splice($groups, $index, 0, [null]);
                break;
            }
        }
        $series = [];
        if ($report['bloom_reference_available']) {
            foreach ($report['bloom_levels'] as $level) {
                $series[] = ['name' => e($level['name']), 'data' => array_map(function ($group) use ($level, $counts) {
                    if ($group === null) {
                        return null;
                    }
                    $row = collect($group['levels'])->firstWhere('level_id', $level['id']);

                    return $row['percentage'] === null ? null : ($counts ? $row['clo_count'] : $row['percentage']);
                }, $groups)];
            }
        }

        return self::config($trend ? 'line' : 'column', $trend ? 'Cognitive progression' : 'Cognitive level comparison',
            array_map(fn ($group) => $group === null ? '' :
                ($group['course_level'] === 'other' ? 'Other/unknown' : $group['course_level'].'-level')
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
            'plotOptions' => ['series' => ['animation' => false], 'line' => ['connectNulls' => false, 'marker' => ['enabled' => true]]],
            'credits' => ['enabled' => false], 'exporting' => ['enabled' => false],
            'series' => $series,
        ];
    }

    /** Reuse the existing Highcharts export service; embed the image without shared temporary files. */
    public static function image(array $config): ?string
    {
        if ($config['xAxis']['categories'] === [] || $config['series'] === []) {
            return null;
        }
        try {
            $response = Http::asForm()->connectTimeout(2)->timeout(5)->post('https://export.highcharts.com/', [
                'type' => 'image/png', 'width' => 1000, 'options' => json_encode($config, JSON_THROW_ON_ERROR),
            ]);
            $image = $response->body();
            if (! $response->successful() || strlen($image) > 5 * 1024 * 1024) {
                return null;
            }
            $info = @getimagesizefromstring($image);
            if (! $info || $info[2] !== IMAGETYPE_PNG) {
                return null;
            }

            return 'data:image/png;base64,'.base64_encode($image);
        } catch (ConnectionException $exception) {
            return null;
        }
    }
}

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Gap and Redundancy Report</title>
    <style>
        @page { margin: 32px; }
        body { font-family: DejaVu Sans, sans-serif; color: #002145; font-size: 10px; line-height: 1.4; }
        h1 { font-size: 20px; margin-bottom: 4px; }
        h2 { font-size: 14px; margin: 18px 0 6px; page-break-after: avoid; }
        h3 { font-size: 11px; margin: 10px 0 4px; page-break-after: avoid; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; table-layout: fixed; }
        th, td { border: 1px solid #c8d0d9; padding: 5px; text-align: left; vertical-align: top; overflow-wrap: break-word; }
        th { background: #e9ecef; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .muted { color: #555; }
        .warning, .gap { background: #fff3cd; }
        .warning { padding: 8px; }
        .redundancy { background: #fce4d6; }
    </style>
</head>
<body>
@php
    $metrics = [
        'mapped_clo_count' => 'Mapped CLOs',
        'covering_course_count' => 'Covering courses',
        'required_course_count' => 'Required covering courses',
        'non_required_course_count' => 'Non-required covering courses',
    ];
    $statuses = [
        'gap' => 'Potential gap', 'redundancy' => 'Potential redundancy',
        'within_expectations' => 'Within expectations', 'no_expectation' => 'No expectation applied',
        'not_applicable' => 'N/A (zero denominator)',
    ];
    $settings = $report['expectations'];
    $summary = $report['results']['summary'];
    $results = array_column($report['results']['plos'], null, 'pl_outcome_id');
    $levels = [];
    foreach ($report['coverage'] as $plo) {
        foreach ($plo['mapping_scale_histogram'] as $level) {
            $levels[$level['map_scale_id']] = $level['title'];
        }
    }
    $percent = fn ($value) => $value === null ? 'N/A' : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').'%';
    $bound = fn ($value) => $value === null ? 'Not set' : $value.'%';
@endphp
<h1>Gap and Redundancy Report</h1>
<h2>{{ $program_name }}</h2>
<p class="muted">Program ID: {{ $report['program_id'] }} · Generated: {{ $generated_at }}</p>
<p>{{ $report['program_totals']['course_count'] }} courses · {{ $report['program_totals']['clo_count'] }} CLOs · {{ count($report['coverage']) }} PLOs</p>
@if ($report['mapping_completeness']['has_incomplete_mappings'])
    <p class="warning">Program mappings are incomplete. Potential gap and redundancy findings are provisional.</p>
@endif
<h2>Applied expectations</h2>
<p>Check potential gaps: {{ ($settings['concerns']['gaps'] ?? false) ? 'Yes' : 'No' }} ·
    Check potential redundancies: {{ ($settings['concerns']['redundancies'] ?? false) ? 'Yes' : 'No' }}</p>
@if (empty($settings['metrics']))
    <p>Statistics only — no coverage ranges applied.</p>
@else
    <table>
        <thead><tr><th>Metric</th><th>Mapping level</th><th>Minimum</th><th>Maximum</th></tr></thead>
        <tbody>
        @foreach ($settings['metrics'] as $metric => $selection)
            @foreach ($selection['levels'] as $id => $bounds)
                <tr><td>{{ $metrics[$metric] }}</td><td>{{ $levels[$id] ?? "Level ID {$id}" }}</td>
                    <td>{{ $bound($bounds['min']) }}</td><td>{{ $bound($bounds['max']) }}</td></tr>
            @endforeach
        @endforeach
        </tbody>
    </table>
@endif
<p>Coverage below the minimum is a potential gap; coverage above the maximum is a potential redundancy.
    Values equal to either boundary are within the range. Findings use unrounded percentages.</p>
<p>Mapped CLO percentages use all program CLOs. All course percentages, including required and non-required courses,
    use all program courses. Coverage can overlap across levels; percentages do not need to total 100%. N/A mappings do not count as coverage.</p>
<h2>Findings summary</h2>
<p>{{ $summary['evaluated_plo_count'] }} PLOs evaluated against ranges · {{ $summary['concern_plo_count'] }} with any concern ·
    {{ $summary['gap_plo_count'] }} with potential gaps · {{ $summary['redundancy_plo_count'] }} with potential redundancies</p>
<p class="muted">A PLO can have both types of concern. Download Excel for the contributing courses and CLO mappings.</p>
<h2>PLO coverage comparisons</h2>
@forelse ($report['coverage'] as $plo)
    <h2>{{ $plo['plo_shortphrase'] ?: 'PLO '.$loop->iteration }}</h2>
    <p>{{ $plo['pl_outcome'] }}</p>
    @php $comparisons = collect($results[$plo['pl_outcome_id']]['comparisons'])->groupBy('metric'); @endphp
    @forelse ($comparisons as $metric => $rows)
        <h3>{{ $metrics[$metric] }}</h3>
        <table>
            <thead><tr><th>Mapping level</th><th>Actual coverage</th><th>Minimum</th><th>Maximum</th><th>Result</th></tr></thead>
            <tbody>
            @foreach ($rows as $row)
                <tr class="{{ in_array($row['status'], ['gap', 'redundancy']) ? $row['status'] : '' }}">
                    <td>{{ $levels[$row['map_scale_id']] }}</td>
                    <td>{{ $row['count'] }} of {{ $row['denominator'] }}<br>{{ $percent($row['percentage']) }}</td>
                    <td>{{ $bound($row['min']) }}</td><td>{{ $bound($row['max']) }}</td>
                    <td>{{ $statuses[$row['status']] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @empty
        <p>No applicable mapping levels are configured.</p>
    @endforelse
@empty
    <p>No PLOs have been added to this program.</p>
@endforelse
</body>
</html>

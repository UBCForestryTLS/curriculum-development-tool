<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Curriculum Progression Report</title>
    @include('programs.exports.pdf-styles')
</head>
<body>
@php
    $available = $report['bloom_reference_available'];
    $plo = $report['selected_plo'];
    $clos = collect($report['courses'])->flatMap(fn ($course) => $course['clos'])->unique('l_outcome_id');
    $matched = $available ? $clos->filter(fn ($clo) => ! empty($clo['bloom_levels']))->count() : null;
    $needsReview = fn ($clo) => collect($clo['bloom_levels'])->unique('level_id')->count() >= 3;
    $reviewCount = $available ? $clos->filter($needsReview)->count() : null;
    $percent = fn ($value) => $value === null ? 'N/A' : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').'%';
@endphp
<h1>Curriculum Progression Report</h1>
<h2>{{ $program_name }}</h2>
<p class="muted">Program ID: {{ $report['program_id'] }} · Generated: {{ $generated_at }}</p>
@if ($plo === null)
    <p><strong>Scope: Entire program</strong></p>
@else
    <p><strong>Scope: Selected PLO — {{ $plo['plo_shortphrase'] ?: 'PLO '.$plo['pl_outcome_id'] }}</strong></p>
    <p>{{ $plo['pl_outcome'] }}</p>
@endif
@if ($report['has_incomplete_mappings'])
    <p class="warning">Program mappings are incomplete. PLO scope may be incomplete.</p>
@endif
@if (! $available)
    <p class="warning">The cognitive Bloom reference is unavailable. Classification counts and percentages cannot be calculated.</p>
@endif
<h2>Summary</h2>
<p>Program: {{ $report['program_totals']['course_count'] }} courses · {{ $report['program_totals']['clo_count'] }} CLOs</p>
<p>In scope: {{ $report['scope_totals']['course_count'] }} courses · {{ $report['scope_totals']['clo_count'] }} CLOs</p>
<p>Matched CLOs: {{ $matched ?? 'N/A' }} · Unmatched CLOs: {{ $available ? $clos->count() - $matched : 'N/A' }} ·
    Review suggested: {{ $reviewCount ?? 'N/A' }}</p>
<p>Classification uses cognitive Bloom reference terms found in CLO text and retains all matching levels.
    Unmatched means no Bloom level matched the text, not that the CLO is unmapped to a PLO.</p>
<p>Percentages use all CLOs in each course group, including unmatched CLOs. A CLO can match multiple levels,
    so percentages can total above 100%. N/A means there are no CLOs in the group or the reference is unavailable.</p>
<h2>Course-group distributions</h2>
@forelse ($report['course_groups'] as $group)
    <h3>{{ $group['course_level'] === 'other' ? 'Other/unknown' : $group['course_level'].'-level' }}</h3>
    <p>{{ $group['course_count'] }} courses · {{ $group['clo_count'] }} CLOs</p>
    <table>
        <thead><tr><th>Cognitive level</th><th>CLO count</th><th>Percentage</th></tr></thead>
        <tbody>
        @foreach ($group['levels'] as $level)
            <tr><td>{{ $level['name'] }}</td><td>{{ $level['clo_count'] ?? 'N/A' }}</td><td>{{ $percent($level['percentage']) }}</td></tr>
        @endforeach
        <tr><td>Unmatched</td><td>{{ $group['unmatched_clo_count'] ?? 'N/A' }}</td>
            <td>{{ $percent($available && $group['clo_count'] > 0 ? 100 * $group['unmatched_clo_count'] / $group['clo_count'] : null) }}</td></tr>
        </tbody>
    </table>
@empty
    <p>No courses are included in this scope.</p>
@endforelse
<h2>CLOs suggested for review</h2>
<p>CLOs matching three or more distinct cognitive levels are suggested for review. This is a prompt to check the classification.</p>
@if (! $available)
    <p>Review suggestions are unavailable without the cognitive Bloom reference.</p>
@elseif ($reviewCount === 0)
    <p>No CLOs in this scope are suggested for review.</p>
@else
    @foreach ($report['courses'] as $course)
        @foreach ($course['clos'] as $clo)
            @if ($needsReview($clo))
                <h3>{{ $course['course_code'] }} {{ $course['course_num'] }} — {{ $course['course_title'] }}</h3>
                <p><strong>{{ $clo['clo_shortphrase'] ?: 'CLO '.$clo['l_outcome_id'] }}</strong> (CLO ID: {{ $clo['l_outcome_id'] }})<br>{{ $clo['l_outcome'] }}</p>
                <table>
                    <thead><tr><th>Matched level</th><th>Matched terms</th></tr></thead>
                    <tbody>
                    @foreach ($clo['bloom_levels'] as $level)
                        <tr><td>{{ $level['name'] }}</td><td>{{ implode(', ', $level['matched_terms']) }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        @endforeach
    @endforeach
@endif
<p class="muted">Download Excel for all supporting CLO classifications in this scope.</p>
</body>
</html>

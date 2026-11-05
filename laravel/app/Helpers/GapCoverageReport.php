<?php

namespace App\Helpers;

use App\Models\Program;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GapCoverageReport
{
    public const METRIC_LABELS = [
        'mapped_clo_count' => 'Mapped CLOs',
        'covering_course_count' => 'Covering courses',
        'required_course_count' => 'Required covering courses',
        'non_required_course_count' => 'Non-required covering courses',
    ];

    private const DENOMINATORS = [
        'mapped_clo_count' => 'clo_count',
        'covering_course_count' => 'course_count',
        'required_course_count' => 'course_count',
        'non_required_course_count' => 'course_count',
    ];

    /** Prepare export data after the caller has authorized program access. */
    public static function report(Program $program, ?array $expectations = null): array
    {
        $levelIds = $program->mappingScaleLevels()->where('mapping_scales.map_scale_id', '<>', 0)
            ->pluck('mapping_scales.map_scale_id')->all();
        $settings = self::validateExpectations($expectations, $levelIds);
        $data = [
            'program_id' => (int) $program->program_id,
            'program_totals' => ProgramGapCoverage::programTotals($program),
            'mapping_completeness' => ProgramGapCoverage::mappingCompleteness($program),
            'coverage' => ProgramGapCoverage::analyze($program)->all(),
        ];

        return $data + ['expectations' => $settings, 'results' => self::evaluate($data, $settings)];
    }

    /** Accept applied settings (no draft enabled flags); null means statistics only. */
    public static function validateExpectations(?array $settings, array $levelIds): ?array
    {
        if ($settings === null) {
            return null;
        }
        Validator::make($settings, [
            'concerns' => 'required|array:gaps,redundancies',
            'concerns.gaps' => 'required|boolean',
            'concerns.redundancies' => 'required|boolean',
            'metrics' => 'present|array:'.implode(',', array_keys(self::DENOMINATORS)),
            'metrics.*' => 'array:levels',
            'metrics.*.levels' => 'present|array',
            'metrics.*.levels.*' => 'array:min,max',
        ])->validate();
        $concerns = array_map(fn ($value) => (bool) $value, $settings['concerns']);
        $normalized = ['concerns' => $concerns, 'metrics' => []];
        if (! $concerns['gaps'] && ! $concerns['redundancies']) {
            return $normalized;
        }
        $levelIds = array_filter(array_map('strval', $levelIds), fn ($id) => $id !== '0');
        $errors = [];
        foreach ($settings['metrics'] as $metric => $selection) {
            foreach ($selection['levels'] as $id => $input) {
                $path = "metrics.{$metric}.levels.{$id}";
                if (! in_array((string) $id, $levelIds, true)) {
                    $errors[$path] = 'Only current, non-N/A mapping levels can have expectations.';
                    continue;
                }
                $bounds = ['min' => null, 'max' => null];
                foreach (['min' => 'gaps', 'max' => 'redundancies'] as $bound => $concern) {
                    if (! $concerns[$concern]) {
                        continue;
                    }
                    $value = $input[$bound] ?? null;
                    if ($value === null || (is_string($value) && trim($value) === '')) {
                        continue;
                    }
                    if ((! is_string($value) && ! is_int($value) && ! is_float($value))
                        || ! preg_match('/^\d+$/D', trim((string) $value)) || (float) $value > 100) {
                        $errors["{$path}.{$bound}"] = 'Enter a whole percentage from 0 to 100, or leave blank.';
                    } else {
                        $bounds[$bound] = (int) $value;
                    }
                }
                if ($bounds['min'] !== null && $bounds['max'] !== null && $bounds['min'] > $bounds['max']) {
                    $errors["{$path}.max"] = 'Maximum must be greater than or equal to minimum.';
                }
                if ($bounds['min'] !== null || $bounds['max'] !== null) {
                    $normalized['metrics'][$metric]['levels'][$id] = $bounds;
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /** Match coverage-report.js using validated settings and unrounded comparisons. */
    public static function evaluate(array $data, ?array $settings = null): array
    {
        $summary = [
            'evaluated_plo_count' => 0,
            'concern_plo_count' => 0,
            'gap_plo_count' => 0,
            'redundancy_plo_count' => 0,
        ];
        $plos = collect($data['coverage'])->map(function ($plo) use ($data, $settings, &$summary) {
            $comparisons = [];
            $hasGap = false;
            $hasRedundancy = false;
            $hasEvaluatedComparison = false;
            foreach ($plo['mapping_scale_histogram'] as $level) {
                if ((int) $level['map_scale_id'] === 0) {
                    continue;
                }
                foreach (self::DENOMINATORS as $metric => $denominatorKey) {
                    $count = $level[$metric];
                    $denominator = $data['program_totals'][$denominatorKey];
                    $bounds = $settings['metrics'][$metric]['levels'][$level['map_scale_id']] ?? [];
                    $min = ($settings['concerns']['gaps'] ?? false) ? ($bounds['min'] ?? null) : null;
                    $max = ($settings['concerns']['redundancies'] ?? false) ? ($bounds['max'] ?? null) : null;
                    $percentage = $denominator === 0 ? null : 100 * $count / $denominator;
                    $status = match (true) {
                        $denominator === 0 => 'not_applicable',
                        $min !== null && 100 * $count < $min * $denominator => 'gap',
                        $max !== null && 100 * $count > $max * $denominator => 'redundancy',
                        $min !== null || $max !== null => 'within_expectations',
                        default => 'no_expectation',
                    };
                    if ($status === 'gap') {
                        $hasGap = true;
                    } elseif ($status === 'redundancy') {
                        $hasRedundancy = true;
                    }
                    if (in_array($status, ['gap', 'redundancy', 'within_expectations'], true)) {
                        $hasEvaluatedComparison = true;
                    }
                    $comparisons[] = compact('metric', 'count', 'denominator', 'percentage', 'min', 'max', 'status')
                        + ['map_scale_id' => $level['map_scale_id']];
                }
            }

            // Count each PLO once, even when several comparisons flag the same concern.
            if ($hasEvaluatedComparison) {
                $summary['evaluated_plo_count']++;
            }
            if ($hasGap || $hasRedundancy) {
                $summary['concern_plo_count']++;
            }
            if ($hasGap) {
                $summary['gap_plo_count']++;
            }
            if ($hasRedundancy) {
                $summary['redundancy_plo_count']++;
            }

            return [
                'pl_outcome_id' => $plo['pl_outcome_id'],
                'comparisons' => $comparisons,
                'has_gap' => $hasGap,
                'has_redundancy' => $hasRedundancy,
            ];
        });

        return [
            'plos' => $plos->values()->all(),
            'summary' => $summary,
        ];
    }
}

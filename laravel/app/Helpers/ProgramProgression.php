<?php

namespace App\Helpers;

use App\Models\Program;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProgramProgression
{
    /**
     * Prepare progression data for the report view and exports.
     * The caller must authorize program access before calling this method.
     */
    public static function report(Program $program, ?int $ploId = null): array
    {
        $plos = $program->programLearningOutcomes()
            ->select('pl_outcome_id', 'plo_shortphrase', 'pl_outcome')->orderBy('pl_outcome_id')->get();
        $selectedPlo = $ploId !== null ? $plos->find($ploId) : null;
        abort_if($ploId !== null && $selectedPlo === null, 404);
        $courses = $program->courses()
            ->select('courses.course_id', 'course_code', 'course_num', 'course_title')
            ->with(['learningOutcomes' => fn ($query) => $query
                ->select('l_outcome_id', 'course_id', 'l_outcome', 'clo_shortphrase')
                ->orderBy('l_outcome_id')])
            ->orderBy('courses.course_id')
            ->get()
            ->unique('course_id')
            ->values();

        $programTotals = [
            'course_count' => $courses->count(),
            'clo_count' => $courses->sum(fn ($course) => $course->learningOutcomes->count()),
        ];
        if ($selectedPlo !== null) {
            // Use the same applicable scale levels as gap coverage, excluding N/A and removed levels.
            $scaleIds = $program->mappingScaleLevels()->where('mapping_scales.map_scale_id', '<>', 0)
                ->pluck('mapping_scales.map_scale_id');
            $cloIds = DB::table('outcome_maps')->where('pl_outcome_id', $selectedPlo->pl_outcome_id)
                ->whereIn('map_scale_id', $scaleIds)->distinct()->pluck('l_outcome_id');
            $courses = $courses->filter(function ($course) use ($cloIds) {
                $course->setRelation('learningOutcomes', $course->learningOutcomes
                    ->whereIn('l_outcome_id', $cloIds)->values());

                return $course->learningOutcomes->isNotEmpty();
            })->values();
        }

        $classification = BloomClassifier::classifyClos($courses
            ->flatMap(fn ($course) => $course->learningOutcomes)
            ->mapWithKeys(fn ($clo) => [$clo->l_outcome_id => (string) $clo->l_outcome])
            ->all());

        $courses = $courses->map(fn ($course) => [
            'course_id' => (int) $course->course_id,
            'course_code' => $course->course_code,
            'course_num' => $course->course_num,
            'course_title' => $course->course_title,
            'course_required' => $course->pivot->course_required === null
                ? null : (bool) $course->pivot->course_required,
            'clos' => $course->learningOutcomes->map(fn ($clo) => [
                'l_outcome_id' => (int) $clo->l_outcome_id,
                'l_outcome' => $clo->l_outcome,
                'clo_shortphrase' => $clo->clo_shortphrase,
                'bloom_levels' => $classification['classifications'][$clo->l_outcome_id] ?? null,
            ]),
        ]);

        return [
            'program_id' => (int) $program->program_id,
            'selected_plo' => $selectedPlo?->only(['pl_outcome_id', 'plo_shortphrase', 'pl_outcome']),
            'plos' => $plos,
            'has_incomplete_mappings' => $selectedPlo !== null
                && ProgramGapCoverage::mappingCompleteness($program)['has_incomplete_mappings'],
            'bloom_reference_available' => $classification['reference_available'],
            'bloom_levels' => $classification['levels'],
            'course_groups' => self::distributions(
                $courses, $classification['levels'], $classification['reference_available'],
            ),
            'program_totals' => $programTotals,
            'scope_totals' => [
                'course_count' => $courses->count(),
                'clo_count' => $courses->sum(fn ($course) => $course['clos']->count()),
            ],
            'courses' => $courses,
        ];
    }

    /**
     * Group loaded courses by hundred-level, keeping unrecognized numbers last.
     * Numeric keys identify levels; "other" represents Other/unknown.
     */
    public static function groupCourses(Collection $courses): Collection
    {
        return $courses->groupBy(function ($course) {
            $number = trim((string) ($course['course_num'] ?? ''));

            // Accept three-digit course numbers with optional letter suffixes.
            if (preg_match('/^[1-9][0-9]{2}[a-z]*$/iD', $number) !== 1) {
                return 'other';
            }

            return (int) $number[0] * 100;
        })->sortKeys(SORT_NATURAL);
    }

    /**
     * Summarize course arrays containing CLO IDs and their bloom_levels matches.
     * $levels is the full reference list (id, name, position), including unmatched levels.
     */
    public static function distributions(Collection $courses, array $levels, bool $referenceAvailable): array
    {
        $levels = collect($levels)->sortBy('position')->values();

        return self::groupCourses($courses)->map(function ($group, $courseLevel) use ($levels, $referenceAvailable) {
            $group = $group->unique('course_id');
            $clos = $group->flatMap(fn ($course) => $course['clos'])->unique('l_outcome_id');
            $total = $clos->count();
            $matched = $referenceAvailable
                ? $clos->filter(fn ($clo) => ! empty($clo['bloom_levels']))->count()
                : null;

            return [
                'course_level' => $courseLevel,
                'course_count' => $group->count(),
                'clo_count' => $total,
                'matched_clo_count' => $matched,
                'unmatched_clo_count' => $referenceAvailable ? $total - $matched : null,
                'levels' => $levels->map(function ($level) use ($clos, $total, $referenceAvailable) {
                    // A CLO counts once per level, even when it contains several matching terms.
                    $count = $referenceAvailable
                        ? $clos->filter(fn ($clo) => collect($clo['bloom_levels'])->contains('level_id', $level['id']))->count()
                        : null;

                    return [
                        'level_id' => $level['id'],
                        'name' => $level['name'],
                        'position' => $level['position'],
                        'clo_count' => $count,
                        // Unmatched CLOs stay in the denominator; overlapping levels can exceed 100% in total.
                        'percentage' => $referenceAvailable && $total > 0 ? round($count / $total * 100, 2) : null,
                    ];
                })->all(),
            ];
        })->values()->all();
    }
}

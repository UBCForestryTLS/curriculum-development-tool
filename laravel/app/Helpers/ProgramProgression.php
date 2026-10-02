<?php

namespace App\Helpers;

use Illuminate\Support\Collection;

class ProgramProgression
{
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

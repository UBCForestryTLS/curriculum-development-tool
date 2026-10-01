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
}

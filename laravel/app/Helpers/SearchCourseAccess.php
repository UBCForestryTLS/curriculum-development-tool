<?php

namespace App\Helpers;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SearchCourseAccess
{
    /**
     * Applies the first search access rule: admins can search all courses, while
     * regular users can only search courses they have direct access to.
     */
    public static function applyDirectCourseAccess(Builder $query, User $user): Builder
    {
        if ($user->hasRole('administrator')) {
            return $query;
        }

        return $query->whereExists(function (Builder $accessQuery) use ($user) {
            $accessQuery->select(DB::raw(1))
                ->from('course_users')
                ->whereColumn('course_users.course_id', 'courses.course_id')
                ->where('course_users.user_id', $user->id);
        });
    }

    /**
     * Limits program lists to programs that contain at least one directly accessible course.
     */
    public static function applyDirectProgramAccess(Builder $query, User $user): Builder
    {
        if ($user->hasRole('administrator')) {
            return $query;
        }

        return $query->whereExists(function (Builder $programAccessQuery) use ($user) {
            $programAccessQuery->select(DB::raw(1))
                ->from('course_programs')
                ->join('courses', 'courses.course_id', '=', 'course_programs.course_id')
                ->whereColumn('course_programs.program_id', 'programs.program_id');

            self::applyDirectCourseAccess($programAccessQuery, $user);
        });
    }
}

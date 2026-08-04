<?php

namespace App\Helpers;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SearchCourseAccess
{
    /**
     * Applies the same stored course access used by the dashboard.
     */
    public static function applyCourseAccess(Builder $query, User $user): Builder
    {
        if ($user->hasRole('administrator')) {
            return $query;
        }

        return $query->where(function (Builder $accessQuery) use ($user) {
            self::whereHasDirectCourseAccess($accessQuery, $user);
            self::orWhereHasRoleCourseAccess($accessQuery, $user);
        });
    }

    /**
     * Limits program lists to programs that contain at least one accessible course.
     */
    public static function applyProgramAccess(Builder $query, User $user): Builder
    {
        if ($user->hasRole('administrator')) {
            return $query;
        }

        return $query->whereExists(function (Builder $programAccessQuery) use ($user) {
            $programAccessQuery->select(DB::raw(1))
                ->from('course_programs as access_program_courses')
                ->join('courses', 'courses.course_id', '=', 'access_program_courses.course_id')
                ->whereColumn('access_program_courses.program_id', 'programs.program_id');

            self::applyCourseAccess($programAccessQuery, $user);
        });
    }

    /**
     * Adds the direct course access check from course_users.
     */
    private static function whereHasDirectCourseAccess(Builder $query, User $user): Builder
    {
        return $query->whereExists(function (Builder $accessQuery) use ($user) {
            $accessQuery->select(DB::raw(1))
                ->from('course_users')
                ->whereColumn('course_users.course_id', 'courses.course_id')
                ->where('course_users.user_id', $user->id);
        });
    }

    /**
     * Adds role-based course access already materialized in course_user_role.
     */
    private static function orWhereHasRoleCourseAccess(Builder $query, User $user): Builder
    {
        return $query->orWhereExists(function (Builder $accessQuery) use ($user) {
            $accessQuery->select(DB::raw(1))
                ->from('course_user_role')
                ->whereColumn('course_user_role.course_id', 'courses.course_id')
                ->where('course_user_role.user_id', $user->id);
        });
    }
}

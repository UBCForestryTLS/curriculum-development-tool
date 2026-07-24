<?php

namespace App\Helpers;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SearchCourseAccess
{
    /**
     * Applies search access rules: admins can search all courses, while other
     * users need either direct course access or program director access.
     */
    public static function applyCourseAccess(Builder $query, User $user): Builder
    {
        if ($user->hasRole('administrator')) {
            return $query;
        }

        return $query->where(function (Builder $accessQuery) use ($user) {
            self::whereHasDirectCourseAccess($accessQuery, $user);
            self::orWhereHasProgramDirectorCourseAccess($accessQuery, $user);
        });
    }

    /**
     * Applies only the direct course access rule.
     */
    public static function applyDirectCourseAccess(Builder $query, User $user): Builder
    {
        if ($user->hasRole('administrator')) {
            return $query;
        }

        return self::whereHasDirectCourseAccess($query, $user);
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
     * Limits program lists to programs that contain at least one directly accessible course.
     * (Keeping direct only access for now as it may be useful for strict direect-only checks;
     * can be removed during a later cleanup commit)
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
     * Adds the program director course access check from program_user_role.
     */
    private static function orWhereHasProgramDirectorCourseAccess(Builder $query, User $user): Builder
    {
        return $query->orWhereExists(function (Builder $programDirectorQuery) use ($user) {
            //Program director access comes from the role table and the program-course pivot
            $programDirectorQuery->select(DB::raw(1))
                ->from('course_programs as access_course_programs')
                ->join('program_user_role as access_program_user_role', 'access_program_user_role.program_id', '=', 'access_course_programs.program_id')
                ->join('roles as access_roles', 'access_roles.id', '=', 'access_program_user_role.role_id')
                ->whereColumn('access_course_programs.course_id', 'courses.course_id')
                ->where('access_program_user_role.user_id', $user->id)
                ->where('access_roles.role', 'program director');
        });
    }
}

<?php

namespace App\Helpers;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SearchCourseAccess
{
    /**
     * Applies search access rules: admins can search all courses, while other
     * users need direct course access, program director access, or department head access.
     */
    public static function applyCourseAccess(Builder $query, User $user): Builder
    {
        if ($user->hasRole('administrator')) {
            return $query;
        }

        return $query->where(function (Builder $accessQuery) use ($user) {
            self::whereHasDirectCourseAccess($accessQuery, $user);
            self::orWhereHasProgramDirectorCourseAccess($accessQuery, $user);
            self::orWhereHasProgramDirectorFacultyCourseAccess($accessQuery, $user);
            self::orWhereHasDepartmentHeadCourseAccess($accessQuery, $user);
            self::orWhereHasDepartmentHeadFacultyCourseAccess($accessQuery, $user);
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

    /**
     * Adds the program director all-faculty access check.
     */
    private static function orWhereHasProgramDirectorFacultyCourseAccess(Builder $query, User $user): Builder
    {
        return $query->orWhereExists(function (Builder $programDirectorQuery) use ($user) {
            // The program role gives the user's faculty context for the all-faculty flag.
            $programDirectorQuery->select(DB::raw(1))
                ->from('program_user_role as access_program_user_role')
                ->join('roles as access_roles', 'access_roles.id', '=', 'access_program_user_role.role_id')
                ->join('programs as access_programs', 'access_programs.program_id', '=', 'access_program_user_role.program_id')
                ->join('campuses as access_campuses', 'access_campuses.campus', '=', 'access_programs.campus')
                ->join('faculties as access_faculties', function ($join) {
                    $join->on('access_faculties.faculty', '=', 'access_programs.faculty')
                        ->on('access_faculties.campus_id', '=', 'access_campuses.campus_id');
                })
                ->where('access_program_user_role.user_id', $user->id)
                ->where('access_roles.role', 'program director')
                ->where('access_program_user_role.has_access_to_all_courses_in_faculty', true)
                ->where(function (Builder $facultyCourseQuery) {
                    self::whereCourseCodeBelongsToFaculty($facultyCourseQuery, 'access_faculties.faculty_id');
                });
        });
    }

    /**
     * Adds the department head course access check from course_user_role.
     */
    private static function orWhereHasDepartmentHeadCourseAccess(Builder $query, User $user): Builder
    {
        return $query->orWhereExists(function (Builder $departmentHeadQuery) use ($user) {
            // Department head access is already materialized into course_user_role by the role flows.
            $departmentHeadQuery->select(DB::raw(1))
                ->from('course_user_role as access_course_user_role')
                ->join('roles as access_department_roles', 'access_department_roles.id', '=', 'access_course_user_role.role_id')
                ->whereColumn('access_course_user_role.course_id', 'courses.course_id')
                ->where('access_course_user_role.user_id', $user->id)
                ->where('access_department_roles.role', 'department head');
        });
    }

    /**
     * Adds the department head all-faculty access check.
     */
    private static function orWhereHasDepartmentHeadFacultyCourseAccess(Builder $query, User $user): Builder
    {
        return $query->orWhereExists(function (Builder $departmentHeadQuery) use ($user) {
            // This covers department heads with the faculty-wide flag even if course_user_role is not materialized yet.
            $departmentHeadQuery->select(DB::raw(1))
                ->from('department_head as access_department_head')
                ->join('role_user as access_department_role_user', 'access_department_role_user.user_id', '=', 'access_department_head.user_id')
                ->join('roles as access_department_roles', 'access_department_roles.id', '=', 'access_department_role_user.role_id')
                ->join('departments as access_departments', 'access_departments.department_id', '=', 'access_department_head.department_id')
                ->join('faculties as access_faculties', 'access_faculties.faculty_id', '=', 'access_departments.faculty_id')
                ->join('campuses as access_campuses', 'access_campuses.campus_id', '=', 'access_faculties.campus_id')
                ->where('access_department_head.user_id', $user->id)
                ->where('access_department_roles.role', 'department head')
                ->where('access_department_head.has_access_to_all_courses_in_faculty', true)
                ->where(function (Builder $facultyCourseQuery) {
                    self::whereCourseCodeBelongsToFaculty($facultyCourseQuery, 'access_faculties.faculty_id');
                });
        });
    }

    /**
     * Checks faculty course-code ownership for the all-faculty access flag.
     */
    private static function whereCourseCodeBelongsToFaculty(Builder $query, string $facultyIdColumn): Builder
    {
        return $query->whereExists(function (Builder $facultyCourseCodeQuery) use ($facultyIdColumn) {
            $facultyCourseCodeQuery->select(DB::raw(1))
                ->from('faculty_course_codes')
                ->whereColumn('faculty_course_codes.faculty_id', $facultyIdColumn)
                ->whereColumn('faculty_course_codes.course_code', 'courses.course_code');
        });
    }
}

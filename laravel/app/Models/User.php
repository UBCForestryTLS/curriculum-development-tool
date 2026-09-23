<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function roles()
    {
        return $this->belongsToMany(\App\Models\Role::class);
    }

    public function courses()
    {
        return $this->belongsToMany(\App\Models\Course::class, 'course_users', 'user_id', 'course_id')->withPivot('permission');
    }

    public function coursesWithElevatedRoleAccess()
    {
        return $this->belongsToMany(\App\Models\Course::class, 'course_user_role', 'user_id', 'course_id')
            ->withPivot('role_id', 'program_id');
    }

    public function allCourses()
    {
        $permissionCourses = $this->courses()->get();
        $roleCourses = $this->coursesWithElevatedRoleAccess()->get();

        return $roleCourses->merge($permissionCourses)->unique('course_id')->values();
    }

    /**
     * Query visible dashboard courses without loading the full access lists.
     */
    public function dashboardCoursesQuery(): Builder
    {
        $directAccess = DB::table('course_users')
            ->select('course_id', 'permission')->where('user_id', $this->id);
        $elevatedAccess = DB::table('course_user_role')
            ->join('roles', 'roles.id', '=', 'course_user_role.role_id')
            ->where('course_user_role.user_id', $this->id)
            ->whereIn('roles.role', ['administrator', 'program director', 'department head'])
            ->select('course_user_role.course_id')->distinct();

        return Course::query()
            ->select('courses.*')
            ->leftJoinSub($directAccess, 'direct_access', 'direct_access.course_id', '=', 'courses.course_id')
            ->leftJoinSub($elevatedAccess, 'elevated_access', 'elevated_access.course_id', '=', 'courses.course_id')
            ->where(function (Builder $query) {
                $query->whereNotNull('elevated_access.course_id')
                    ->orWhereIn('direct_access.permission', [1, 2, 3]);
            })
            ->selectRaw('CASE WHEN elevated_access.course_id IS NOT NULL THEN 1 ELSE direct_access.permission END AS "userPermission"')
            ->withCasts(['userPermission' => 'integer']);
    }

    public function effectivePermissionForCourse($courseId)
    {
        $elevatedRoleIds = Role::whereIn('role', ['administrator', 'program director', 'department head'])
            ->pluck('id')->toArray();

        $hasElevatedRole = $this->coursesWithElevatedRoleAccess()
            ->where('course_user_role.course_id', $courseId)
            ->wherePivotIn('role_id', $elevatedRoleIds)
            ->exists();

        if ($hasElevatedRole) {
            return 1; // Elevated roles are treated as owners
        }

        $pivot = $this->courses()
            ->where('courses.course_id', $courseId)
            ->first()?->pivot;

        return $pivot->permission ?? 0;
    }

    public function programs()
    {
        return $this->belongsToMany(\App\Models\Program::class, 'program_users', 'user_id', 'program_id')->withPivot('permission');
    }

    public function savedSearchFilters()
    {
        return $this->hasMany(SavedSearchFilter::class);
    }

    public function programsWithElevatedRoleAccess()
    {
        return $this->belongsToMany(\App\Models\Program::class, 'program_user_role', 'user_id', 'program_id')
            ->withPivot('role_id');
    }

    public function allPrograms()
    {
        $permissionPrograms = $this->programs()->get();
        $rolePrograms = $this->programsWithElevatedRoleAccess()->get();

        return $rolePrograms->merge($permissionPrograms)->unique('program_id')->values();
    }

    public function effectivePermissionForProgram($programId)
    {
        $elevatedRoleIds = Role::whereIn('role', ['administrator', 'program director', 'department head'])
            ->pluck('id')->toArray();

        $hasElevatedRole = $this->programsWithElevatedRoleAccess()
            ->where('program_user_role.program_id', $programId)
            ->wherePivotIn('role_id', $elevatedRoleIds)
            ->exists();

        if ($hasElevatedRole) {
            return 1; // Elevated roles are treated as owners
        }

        $pivot = $this->programs()
            ->where('programs.program_id', $programId)
            ->first()?->pivot;

        return $pivot->permission ?? 0;
    }

    public function syllabi()
    {
        return $this->belongsToMany(\App\Models\syllabus\Syllabus::class, 'syllabi_users', 'user_id', 'syllabus_id')->withPivot('permission');
    }

    public function headedDepartments()
    {
        return $this->belongsToMany(\App\Models\Department::class, 'department_head', 'user_id','department_id')->withPivot('has_access_to_all_courses_in_faculty');
    }

    public function directedPrograms()
    {
        $directorRoleId = Role::where('role', 'program director')->first()->id;

        return $this->programsWithElevatedRoleAccess()
                    ->withPivot('role_id')
                    ->wherePivot('role_id', $directorRoleId);
    }

    public function hasAnyRoles($roles)
    {
        if ($this->roles()->whereIn('role', $roles)->first()) {
            return true;
        }

        return false;
    }

    public function hasRole($role)
    {
        if ($this->roles()->where('role', $role)->first()) {
            return true;
        }

        return false;
    }
}

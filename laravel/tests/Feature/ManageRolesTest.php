<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManageRolesTest extends TestCase
{
    public function test_assigning_new_admin_role(){


        $delivery_modalities = ['O', 'B', 'I'];
        $semesters = ['W1', 'W2', 'S1', 'S2'];

        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();

        $adminUser->email_verified_at = Carbon::now();
        $adminUser->save();

        $user->email_verified_at = Carbon::now();
        $user->save();

        $response = $this->actingAs($adminUser)->post(route('courses.store'), [
            'course_code' => 'TEST',
            'course_num' => '111',
            'delivery_modality' => $delivery_modalities[array_rand($delivery_modalities)],
            'course_year' => 2022,
            'course_semester' => $semesters[array_rand($semesters)],
            'course_title' => 'Intro to Unit Testing',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
            'assigned' => 1,
            'type' => 'unassigned',
            'standard_category_id' => 1,
            'scale_category_id' => 1,
            'user_id' => $adminUser->id,
        ]);

        $course = Course::where('course_title', 'Intro to Unit Testing')->orderBy('course_id', 'DESC')->first();

        $response->assertRedirect('/courseWizard/'.($course->course_id).'/step8');

        $response = $this->actingAs($adminUser)->post(route('programs.store'), [
            'program' => 'Bachelor of Testing',
            'campus' => 'Vancouver',
            'faculty' => 'Faculty of Forestry',
            'level' => 'Bachelors',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
            'user_id' => $adminUser->id,
        ]);

        $program = Program::where('program', 'Bachelor of Testing')->orderBy('program_id', 'DESC')->first();

        $response->assertRedirect('/programWizard/'.($program->program_id).'/step1');

        $adminRoleId = Role::where('role', 'administrator')->first()->id;

        $response = $this->actingAs($adminUser)->post(route('admin.assignRole'), [
            'email' => 'usertest@gmail.com',
            'role' => 'admin'
        ]);

        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $adminRoleId,
        ]);

        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $adminRoleId
        ]);

        $this->assertDatabaseHas('program_user_role', [
            'program_id' => $program->program_id,
            'user_id' => $user->id,
            'role_id' => $adminRoleId
        ]);

    }

    public function test_admin_access_for_new_course_and_program(){

        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where('role', 'administrator')->first();

        $response = $this->actingAs($adminUser)->post(route('programs.store'), [
            'program' => 'Test Program',
            'campus' => 'Vancouver',
            'faculty' => 'Extended Learning',
            'level' => 'Bachelors',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
            'user_id' => $adminUser->id,
        ]);

        $program = Program::where('program', 'Test Program')->orderBy('program_id', 'DESC')->first();

        $this->assertDatabaseHas('program_user_role', [
            'program_id' => $program->program_id,
            'user_id' => $user->id,
            'role_id' => $role->id
        ]);

        $this->assertDatabaseHas('program_users', [
            'user_id' => $adminUser->id,
            'program_id' => $program->program_id,
            'permission' => 1
        ]);

        $delivery_modalities = ['O', 'B', 'I'];
        $semesters = ['W1', 'W2', 'S1', 'S2'];

        $response = $this->actingAs($adminUser)->post(route('courses.store'), [
            'course_code' => 'TEST',
            'course_num' => '678',
            'delivery_modality' => $delivery_modalities[array_rand($delivery_modalities)],
            'course_year' => 2022,
            'course_semester' => $semesters[array_rand($semesters)],
            'course_title' => 'Test Course',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
            'assigned' => 1,
            'type' => 'unassigned',
            'standard_category_id' => 1,
            'scale_category_id' => 1,
            'user_id' => $adminUser->id,
        ]);

        $course = Course::where('course_title', 'Test Course')->orderBy('course_id', 'DESC')->first();

        $response->assertRedirect('/courseWizard/'.($course->course_id).'/step8');


        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        $this->assertDatabaseHas('course_users', [
            'user_id' => $adminUser->id,
            'course_id' => $course->course_id,
            'permission' => 1
        ]);
    }

    public function test_removing_admin_role(){

        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where(['role' => 'administrator'])->first();

        $response = $this->actingAs($adminUser)->delete(route('admin.assignRole.deleteAdminRole', [
            'user' => $user->id,
            'role' => $role->id]
        ));

        $this->assertDatabaseMissing('role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        $course = Course::where('course_title', 'Intro to Unit Testing')->orderBy('course_id', 'DESC')->first();

        $program = Program::where('program', 'Bachelor of Testing')->orderBy('program_id', 'DESC')->first();

        $this->assertDatabaseMissing('program_user_role', [
            'program_id' => $program->program_id,
            'user_id' => $user->id,
            'role_id' => $role->id
        ]);

        $this->assertDatabaseMissing('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id
        ]);
    }

    public function test_assigning_new_program_director_role(){

        $delivery_modalities = ['O', 'B', 'I'];
        $semesters = ['W1', 'W2', 'S1', 'S2'];

        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();

        $response = $this->actingAs($adminUser)->post(route('courses.store'), [
            'course_code' => 'FOPR',
            'course_num' => '111',
            'delivery_modality' => $delivery_modalities[array_rand($delivery_modalities)],
            'course_year' => 2025,
            'course_semester' => $semesters[array_rand($semesters)],
            'course_title' => 'Forestry Testing Course',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
            'assigned' => 1,
            'type' => 'unassigned',
            'standard_category_id' => 1,
            'scale_category_id' => 1,
            'user_id' => $adminUser->id,
        ]);

        $course = Course::where('course_title', 'Forestry Testing Course')->orderBy('course_id', 'DESC')->first();


        $response->assertRedirect('/courseWizard/'.($course->course_id).'/step8');

        $programDirectorRoleId = Role::where('role', 'program director')->first()->id;

        $response = $this->actingAs($adminUser)->post(route('admin.assignRole'), [
            'email' => 'usertest@gmail.com',
            'role' => 'program-director',
            'program' => 'Bachelor of Testing',
            'accessToAllCoursesInFaculty' => '1'
        ]);

        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $programDirectorRoleId,
        ]);

        $program = Program::where('program', 'Bachelor of Testing')->first();

        $this->assertDatabaseHas('program_user_role', [
            'program_id' => $program->program_id,
            'user_id' => $user->id,
            'role_id' => $programDirectorRoleId,
            'has_access_to_all_courses_in_faculty' => true

        ]);

        // Program directors in faculty of forestry have access to all forestry courses
        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $programDirectorRoleId,
            'program_id' => $program->program_id
        ]);
    }

    public function test_removing_program_director_role(){

        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where(['role' => 'program director'])->first();
        $program = Program::where('program', 'Bachelor of Testing')->first();

        $response = $this->actingAs($adminUser)->delete(route('admin.assignRole.deleteProgramDirectorRole', [
                'user' => $user->id,
                'program' => $program->program_id,
                'role' => $role->id]
        ));

        $this->assertDatabaseMissing('role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        $course = Course::where('course_title', 'Forestry Testing Course')->orderBy('course_id', 'DESC')->first();

        $program = Program::where('program', 'Bachelor of Testing')->orderBy('program_id', 'DESC')->first();

        $this->assertDatabaseMissing('program_user_role', [
            'program_id' => $program->program_id,
            'user_id' => $user->id,
            'role_id' => $role->id
        ]);

        $this->assertDatabaseMissing('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id
        ]);
    }

    public function test_assigning_new_department_head_role(){

        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where(['role' => 'department head'])->first();

        $program = Program::where('program', 'Bachelor of Testing')->first();
        $program->department = 'Department of Forest Resources Management';
        $program->save();

        $response = $this->actingAs($adminUser)->post(route('admin.assignRole'), [
            'email' => 'usertest@gmail.com',
            'role' => 'department-head',
            'campus' => 'Vancouver',
            'faculty' => 'Faculty of Forestry',
            'department' => 'Department of Forest Resources Management',
            'accessToAllCoursesInFaculty' => '1'
        ]);

        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        $campus = Campus::where('campus', $program->campus)->first();
        $faculty = Faculty::where(['faculty'=> $program->faculty,
            'campus_id' => $campus->campus_id])->first();
        $department = Department::where(['department'=> $program->department,
            'faculty_id' => $faculty->faculty_id])->first();

        $this->assertDatabaseHas('department_head', [
            'department_id' => $department->department_id,
            'user_id' => $user->id,
            'has_access_to_all_courses_in_faculty' => true
        ]);

        $course = Course::where('course_title', 'Forestry Testing Course')->orderBy('course_id', 'DESC')->first();

        $this->assertDatabaseHas('program_user_role', [
            'program_id' => $program->program_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'department_id' => $department->department_id

        ]);

        // Department Heads in faculty of forestry have access to all forestry courses
        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'department_id' => $department->department_id
        ]);

    }

    public function test_existing_department_head_role_ownership_of_new_course_in_program(){
        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where(['role' => 'department head'])->first();

        $program = Program::where('program', 'Bachelor of Testing')->first();

        $course = Course::where('course_title', 'Intro to Unit Testing')->first();

        $campus = Campus::where('campus', 'Vancouver')->first();
        $faculty = Faculty::where(['faculty'=> 'Faculty of Forestry',
            'campus_id' => $campus->campus_id])->first();
        $department = Department::where(['department'=> 'Department of Forest Resources Management',
            'faculty_id' => $faculty->faculty_id])->first();

        $response = $this->actingAs($user)->post(route('courseProgram.addCoursesToProgram', $program->program_id), [
            'selectedCourses' => [
                0 => $course->course_id],
            'program_id' => $program->program_id,
        ]);

        $this->assertDatabaseHas('course_programs', [
            'course_id' => $course->course_id,
            'program_id' => $program->program_id,
        ]);

        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'program_id' => $program->program_id,
            'department_id' => $department->department_id
        ]);
    }

    public function test_existing_department_head_with_accesss_to_all_faculty_courses_access_to_new_course_in_forestry(){
        //Test Existing Department head in Faculty of forestry access to new forestry course

        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where(['role' => 'department head'])->first();

        $delivery_modalities = ['O', 'B', 'I'];
        $semesters = ['W1', 'W2', 'S1', 'S2'];

        $response = $this->actingAs($adminUser)->post(route('courses.store'), [
            'course_code' => 'FRST',
            'course_num' => '111',
            'delivery_modality' => $delivery_modalities[array_rand($delivery_modalities)],
            'course_year' => 2025,
            'course_semester' => $semesters[array_rand($semesters)],
            'course_title' => 'Forestry New Test Course',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
            'assigned' => 1,
            'type' => 'unassigned',
            'standard_category_id' => 1,
            'scale_category_id' => 1,
            'user_id' => $adminUser->id,
        ]);

        $campus = Campus::where('campus', 'Vancouver')->first();
        $faculty = Faculty::where(['faculty'=> 'Faculty of Forestry',
            'campus_id' => $campus->campus_id])->first();
        $department = Department::where(['department'=> 'Department of Forest Resources Management',
            'faculty_id' => $faculty->faculty_id])->first();

        $course = Course::where('course_title', 'Forestry New Test Course')->orderBy('course_id', 'DESC')->first();

        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id
        ]);


    }

    public function test_existing_dept_head_ownership_of_new_program_in_department(){
        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where(['role' => 'department head'])->first();

        $response = $this->actingAs($adminUser)->post(route('programs.store'), [
            'program' => 'Bachelor of New Test Program',
            'campus' => 'Vancouver',
            'faculty' => 'Faculty of Forestry',
            'department' => 'Department of Forest Resources Management',
            'level' => 'Bachelors',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
            'user_id' => $adminUser->id,
        ]);

        $campus = Campus::where('campus', 'Vancouver')->first();
        $faculty = Faculty::where(['faculty'=> 'Faculty of Forestry',
            'campus_id' => $campus->campus_id])->first();
        $department = Department::where(['department'=> 'Department of Forest Resources Management',
            'faculty_id' => $faculty->faculty_id])->first();


        $program = Program::where('program', 'Bachelor of New Test Program')->orderBy('program_id', 'DESC')->first();

        $this->assertDatabaseHas('program_user_role', [
            'program_id' => $program->program_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'department_id' => $department->department_id
        ]);
    }

    public function test_assign_existing_dept_head_another_dept_in_faculty_as_head(){
        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where(['role' => 'department head'])->first();

        $response = $this->actingAs($adminUser)->post(route('admin.assignRole'), [
            'email' => 'usertest@gmail.com',
            'role' => 'department-head',
            'campus' => 'Vancouver',
            'faculty' => 'Faculty of Forestry',
            'department' => 'Department of Forest Sciences',
            'accessToAllCoursesInFaculty' => '1'
        ]);

        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        $campus = Campus::where('campus', 'Vancouver')->first();
        $faculty = Faculty::where(['faculty'=> 'Faculty of Forestry',
            'campus_id' => $campus->campus_id])->first();
        $department = Department::where(['department'=> 'Department of Forest Sciences',
            'faculty_id' => $faculty->faculty_id])->first();

        $this->assertDatabaseHas('department_head', [
            'department_id' => $department->department_id,
            'user_id' => $user->id,
            'has_access_to_all_courses_in_faculty' => true
        ]);
    }

    public function test_assign_another_elevated_role_to_user(){
        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $programDirectorRoleId = Role::where('role', 'program director')->first()->id;

        $response = $this->actingAs($adminUser)->post(route('admin.assignRole'), [
            'email' => 'usertest@gmail.com',
            'role' => 'program-director',
            'program' => 'Bachelor of Testing',
            'accessToAllCoursesInFaculty' => '1'
        ]);

        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $programDirectorRoleId,
        ]);

        $program = Program::where('program', 'Bachelor of Testing')->first();

        $this->assertDatabaseHas('program_user_role', [
            'program_id' => $program->program_id,
            'user_id' => $user->id,
            'role_id' => $programDirectorRoleId,
            'has_access_to_all_courses_in_faculty' => true
        ]);

        $course = Course::where('course_title', 'Forestry New Test Course')->orderBy('course_id', 'DESC')->first();

        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $programDirectorRoleId,
            'program_id' => $program->program_id,
        ]);

        $course = Course::where('course_title', 'Forestry Testing Course')->orderBy('course_id', 'DESC')->first();

        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $programDirectorRoleId,
            'program_id' => $program->program_id,
        ]);

        $course = Course::where('course_title', 'Intro to Unit Testing')->orderBy('course_id', 'DESC')->first();

        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $programDirectorRoleId,
            'program_id' => $program->program_id,
        ]);


    }

    public function test_delete_single_dept_head_role(){
        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where(['role' => 'department head'])->first();

        $course = Course::where('course_title', 'Forestry New Test Course')->orderBy('course_id', 'DESC')->first();
        $program = Program::where('program', 'Bachelor of Testing')->first();

        $response = $this->actingAs($user)->post(route('courseProgram.addCoursesToProgram', $program->program_id), [
            'selectedCourses' => [
                0 => $course->course_id],
            'program_id' => $program->program_id,
        ]);

        $this->assertDatabaseHas('course_programs', [
            'course_id' => $course->course_id,
            'program_id' => $program->program_id,
        ]);

        $campus = Campus::where('campus', 'Vancouver')->first();
        $faculty = Faculty::where(['faculty'=> 'Faculty of Forestry',
            'campus_id' => $campus->campus_id])->first();
        $department = Department::where(['department'=> $program->department,
            'faculty_id' => $faculty->faculty_id])->first();

        $response = $this->actingAs($adminUser)->delete(route('admin.assignRole.deleteDepartmentHeadRole', [
            'user'=>$user->id,
            'role'=>$role->id,
            'department'=>$department->department_id]
        ));

        $this->assertDatabaseMissing('department_head', [
            'department_id' => $department->department_id,
            'user_id' => $user->id
        ]);

        $campus = Campus::where('campus', 'Vancouver')->first();
        $faculty = Faculty::where(['faculty'=> 'Faculty of Forestry',
            'campus_id' => $campus->campus_id])->first();
        $department = Department::where(['department'=> 'Department of Forest Sciences',
            'faculty_id' => $faculty->faculty_id])->first();

        // User should still have access to all forestry courses

        $course = Course::where('course_title', 'Forestry New Test Course')->orderBy('course_id', 'DESC')->first();

        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'department_id' => $department->department_id
        ]);

        $course = Course::where('course_title', 'Forestry Testing Course')->orderBy('course_id', 'DESC')->first();

        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id
        ]);
    }

    public function test_remove_all_dept_head_roles_for_elevated_user(){
        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where(['role' => 'department head'])->first();

        $campus = Campus::where('campus', 'Vancouver')->first();
        $faculty = Faculty::where(['faculty'=> 'Faculty of Forestry',
            'campus_id' => $campus->campus_id])->first();
        $department = Department::where(['department'=> 'Department of Forest Sciences',
            'faculty_id' => $faculty->faculty_id])->first();

        $response = $this->actingAs($adminUser)->delete(route('admin.assignRole.deleteDepartmentHeadRole', [
                'user'=>$user->id,
                'role'=>$role->id,
                'department'=>$department->department_id]
        ));

        $this->assertDatabaseMissing('department_head', [
            'department_id' => $department->department_id,
            'user_id' => $user->id
        ]);

        $this->assertDatabaseMissing('role_user', [
            'role_id' => $role->id,
            'user_id' => $user->id
        ]);

        $role = Role::where(['role' => 'program director'])->first();
        $program = Program::where('program', 'Bachelor of Testing')->first();

        // User should still have access to all forestry courses as a program director
        $course = Course::where('course_title', 'Forestry New Test Course')->orderBy('course_id', 'DESC')->first();

        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'program_id' => $program->program_id
        ]);

        $course = Course::where('course_title', 'Forestry Testing Course')->orderBy('course_id', 'DESC')->first();

        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'program_id' => $program->program_id
        ]);
    }

    public function test_assign_existing_program_director_another_program_as_director(){
        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where(['role' => 'program director'])->first();
        $program = Program::where('program', 'Bachelor of New Test Program')->orderBy('program_id', 'DESC')->first();

        $response = $this->actingAs($adminUser)->post(route('admin.assignRole'), [
            'email' => 'usertest@gmail.com',
            'role' => 'program-director',
            'program' => 'Bachelor of New Test Program',
            'accessToAllCoursesInFaculty' => '1'
        ]);

        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        $this->assertDatabaseHas('program_user_role', [
            'program_id' => $program->program_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'has_access_to_all_courses_in_faculty' => true

        ]);

        $course = Course::where('course_title', 'Forestry New Test Course')->orderBy('course_id', 'DESC')->first();

        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'program_id' => $program->program_id,
        ]);

        $course = Course::where('course_title', 'Forestry Testing Course')->orderBy('course_id', 'DESC')->first();

        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'program_id' => $program->program_id,
        ]);
    }

    public function test_access_to_forestry_courses_after_remove_single_program_director_role(){
        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where(['role' => 'program director'])->first();

        $program = Program::where('program', 'Bachelor of Testing')->first();

        $response = $this->actingAs($adminUser)->delete(route('admin.assignRole.deleteProgramDirectorRole', [
                'user' => $user->id,
                'program' => $program->program_id,
                'role' => $role->id]
        ));

        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        $program1 = Program::where('program', 'Bachelor of Testing')->orderBy('program_id', 'DESC')->first();

        $this->assertDatabaseMissing('program_user_role', [
            'program_id' => $program1->program_id,
            'user_id' => $user->id,
            'role_id' => $role->id
        ]);

        $program2 = Program::where('program', 'Bachelor of New Test Program')->orderBy('program_id', 'DESC')->first();
        $course = Course::where('course_title', 'Forestry New Test Course')->orderBy('course_id', 'DESC')->first();
        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'program_id' => $program2->program_id,
        ]);

        $this->assertDatabaseMissing('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'program_id' => $program1->program_id,
        ]);

        $course = Course::where('course_title', 'Forestry Testing Course')->orderBy('course_id', 'DESC')->first();

        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'program_id' => $program2->program_id,
        ]);

        $this->assertDatabaseMissing('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'program_id' => $program1->program_id,
        ]);

        $course = Course::where('course_title', 'Intro to Unit Testing')->orderBy('course_id', 'DESC')->first();

        $this->assertDatabaseMissing('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'program_id' => $program1->program_id,
        ]);
    }

    public function test_assign_new_course_to_program_with_exiting_director(){
        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where(['role' => 'program director'])->first();
        $program = Program::where('program', 'Bachelor of New Test Program')->orderBy('program_id', 'DESC')->first();

        $course = Course::where('course_title', 'Intro to Unit Testing')->orderBy('course_id', 'DESC')->first();

        $response = $this->actingAs($user)->post(route('courseProgram.addCoursesToProgram', $program->program_id), [
            'selectedCourses' => [
                0 => $course->course_id],
            'program_id' => $program->program_id,
        ]);

        $this->assertDatabaseHas('course_programs', [
            'course_id' => $course->course_id,
        ]);

        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'program_id' => $program->program_id
        ]);
    }

    public function test_removing_non_forestry_course_from_forestry_program_removes_elevated_role_access(){
        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where(['role' => 'program director'])->first();
        $program = Program::where('program', 'Bachelor of New Test Program')->orderBy('program_id', 'DESC')->first();
        $course = Course::where('course_title', 'Intro to Unit Testing')->orderBy('course_id', 'DESC')->first();

        $response = $this->actingAs($user)->get(route('courses.remove', [
            'program_id' => $program->program_id,
            'course' => $course
        ]));

        $this->assertDatabaseMissing('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'program_id' => $program->program_id
        ]);

        $this->assertDatabaseMissing('course_programs', [
            'course_id' => $course->course_id,
            'program_id' => $program->program_id,
        ]);
    }

    public function test_removing_forestry_course_from_forestry_program_keeps_elevated_role_access(){
        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where(['role' => 'program director'])->first();
        $program = Program::where('program', 'Bachelor of New Test Program')->orderBy('program_id', 'DESC')->first();

        $response = $this->actingAs($adminUser)->post(route('admin.assignRole'), [
            'email' => 'usertest@gmail.com',
            'role' => 'department-head',
            'campus' => 'Vancouver',
            'faculty' => 'Faculty of Forestry',
            'department' => 'Department of Forest Resources Management',
            'accessToAllCoursesInFaculty' => '1'
        ]);

        $course = Course::where('course_title', 'Forestry Testing Course')->orderBy('course_id', 'DESC')->first();

        $response = $this->actingAs($user)->post(route('courseProgram.addCoursesToProgram', $program->program_id), [
            'selectedCourses' => [
                0 => $course->course_id],
            'program_id' => $program->program_id,
        ]);

        $this->assertDatabaseHas('course_programs', [
            'course_id' => $course->course_id,
            'program_id' => $program->program_id,
        ]);

        $response = $this->actingAs($user)->get(route('courses.remove', [
            'program_id' => $program->program_id,
            'course' => $course
        ]));

        $this->assertDatabaseMissing('course_programs', [
            'course_id' => $course->course_id,
            'program_id' => $program->program_id,
        ]);

        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'program_id' => $program->program_id
        ]);

        $campus = Campus::where('campus', 'Vancouver')->first();
        $faculty = Faculty::where(['faculty'=> 'Faculty of Forestry',
            'campus_id' => $campus->campus_id])->first();
        $department = Department::where(['department'=> 'Department of Forest Resources Management',
            'faculty_id' => $faculty->faculty_id])->first();

        $role = Role::where('role', 'department head')->first();

        $this->assertDatabaseHas('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'program_id' => null,
            'department_id' => $department->department_id
        ]);
    }

    public function test_update_course_to_non_forestry_removes_elevated_role_access(){
        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();

        $course = Course::where('course_title', 'Forestry Testing Course')->orderBy('course_id', 'DESC')->first();

        $response = $this->actingAs($adminUser)->put(action([\App\Http\Controllers\CourseController::class, 'update'],
            $course->course_id),
            [
                'course_code' => 'TEST',
                'course_title' => 'Changed To Non-Forestry Testing Course',
                'course_num' => $course->course_num,
                'delivery_modality' => $course->delivery_modality,
                'course_year' => $course->year,
                'course_semester' => $course->semester,
                'standard_category_id' => $course->standard_category_id,

            ]
        );

        $this->assertDatabaseHas('courses', [
            'course_id' => $course->course_id,
            'course_title' => 'Changed To Non-Forestry Testing Course',
            'course_num' => $course->course_num,
            'course_code' => 'TEST'
        ]);

        $this->assertDatabaseMissing('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id
        ]);
    }

    public function test_update_program_to_change_dept_removes_dept_head_access(){
        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $program = Program::where('program', 'Bachelor of New Test Program')->orderBy('program_id', 'DESC')->first();

        $response = $this->actingAs($adminUser)->post(route('programs.single.update', $program->program_id),[
            'program_id' => $program->program_id,
            'program' => 'Changed Name of Program',
            'level' => $program->level,
            'department' => 'other',
            'campus' => 'other',
            'faculty' => 'other',
        ]);

        $this->assertDatabaseHas('programs',[
            'program' =>'Changed Name of Program'
        ]);

        $role = Role::where('role', 'department head')->first();

        $this->assertDatabaseMissing('program_user_role',[
            'program_id' => $program->program_id,
            'role_id' => $role->id,
            'user_id' => $user->id,
        ]);

        $role = Role::where('role', 'program director')->first();

        $this->assertDatabaseHas('program_user_role',[
            'program_id' => $program->program_id,
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

    }

    public function test_assign_program_director_for_forestry_course_without_full_faculty_course_access(){
        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where(['role' => 'program director'])->first();

        $response = $this->actingAs($adminUser)->post(route('programs.store'), [
            'program' => 'Bachelor of Test Forestry Program',
            'campus' => 'Vancouver',
            'faculty' => 'Faculty of Forestry',
            'department' => 'Department of Forest Resources Management',
            'level' => 'Bachelors',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
            'user_id' => $adminUser->id,
        ]);

        $program = Program::where('program', 'Bachelor of Test Forestry Program')->first();

        $response = $this->actingAs($adminUser)->post(route('admin.assignRole'), [
            'email' => 'usertest@gmail.com',
            'role' => 'program-director',
            'program' => 'Bachelor of Test Forestry Program',
            'accessToAllCoursesInFaculty' => '0'
        ]);

        $this->assertDatabaseHas('program_user_role',[
            'program_id' => $program->program_id,
            'role_id' => $role->id,
            'user_id' => $user->id,
            'has_access_to_all_courses_in_faculty' => false
        ]);

        $course = Course::where('course_title', 'Forestry New Test Course')->orderBy('course_id', 'DESC')->first();

        $this->assertDatabaseMissing('course_user_role', [
            'role_id' => $role->id,
            'user_id' => $user->id,
            'course_id' => $course->course_id
        ]);
    }

    public function test_assign_department_head_without_full_faculty_course_access(){
        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where(['role' => 'department head'])->first();

        // remove existing department head access
        $campus = Campus::where('campus', 'Vancouver')->first();
        $faculty = Faculty::where(['faculty'=> 'Faculty of Forestry',
            'campus_id' => $campus->campus_id])->first();
        $department = Department::where(['department'=> 'Department of Forest Resources Management',
            'faculty_id' => $faculty->faculty_id])->first();

        $response = $this->actingAs($adminUser)->delete(route('admin.assignRole.deleteDepartmentHeadRole', [
                'user'=>$user->id,
                'role'=>$role->id,
                'department'=>$department->department_id]
        ));

        $this->assertDatabaseMissing('department_head', [
            'user_id'=>$user->id,
            'department_id'=>$department->department_id
        ]);

        $course = Course::where('course_title', 'Forestry New Test Course')->orderBy('course_id', 'DESC')->first();

        $this->assertDatabaseMissing('course_user_role', [
            'course_id' => $course->course_id,
            'user_id' => $user->id,
            'role_id' => $role->id
        ]);

        $response = $this->actingAs($adminUser)->post(route('admin.assignRole'), [
            'email' => 'usertest@gmail.com',
            'role' => 'department-head',
            'campus' => 'Vancouver',
            'faculty' => 'Faculty of Forestry',
            'department' => 'Department of Forest Sciences',
            'accessToAllCoursesInFaculty' => '0'
        ]);

        $campus = Campus::where('campus', 'Vancouver')->first();
        $faculty = Faculty::where(['faculty'=> 'Faculty of Forestry',
            'campus_id' => $campus->campus_id])->first();
        $department = Department::where(['department'=> 'Department of Forest Sciences',
            'faculty_id' => $faculty->faculty_id])->first();


        $this->assertDatabaseHas('department_head', [
            'user_id' => $user->id,
            'department_id' => $department->department_id,
            'has_access_to_all_courses_in_faculty' => false
        ]);

        $this->assertDatabaseMissing('course_user_role', [
            'role_id' => $role->id,
            'user_id' => $user->id,
            'course_id' => $course->course_id
        ]);

    }

    public function test_existing_dept_head_access_to_course_in_dept_stored_with_depart_info(){
        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where(['role' => 'department head'])->first();

        $delivery_modalities = ['O', 'B', 'I'];
        $semesters = ['W1', 'W2', 'S1', 'S2'];


        $response = $this->actingAs($adminUser)->post(route('courses.store'), [
            'course_code' => 'TEST',
            'course_num' => '271',
            'delivery_modality' => $delivery_modalities[array_rand($delivery_modalities)],
            'course_year' => 2025,
            'course_semester' => $semesters[array_rand($semesters)],
            'course_title' => 'Another Forestry Test Course',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
            'assigned' => 1,
            'type' => 'unassigned',
            'standard_category_id' => 1,
            'scale_category_id' => 1,
            'user_id' => $adminUser->id,
            'campus' => 'Vancouver',
            'faculty' => 'Faculty of Forestry',
            'department' => 'Department of Forest Sciences',
        ]);

        $course = Course::where(['course_code' => 'TEST', 'course_num' => 271])->first();

        $campus = Campus::where('campus', 'Vancouver')->first();
        $faculty = Faculty::where(['faculty'=> 'Faculty of Forestry',
            'campus_id' => $campus->campus_id])->first();
        $department = Department::where(['department'=> 'Department of Forest Sciences',
            'faculty_id' => $faculty->faculty_id])->first();

        $this->assertDatabaseHas('course_user_role', [
            'user_id' => $user->id,
            'course_id' => $course->course_id,
            'role_id' => $role->id,
            'department_id' => $department->department_id
        ]);
    }

    public function test_program_director_with_full_faculty_course_access_update_on_program_faculty_update(){
        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where(['role' => 'program director'])->first();

        $response = $this->actingAs($adminUser)->post(route('programs.store'), [
            'program' => 'Bachelor of New Test Forestry Program',
            'campus' => 'Vancouver',
            'faculty' => 'Faculty of Forestry',
            'department' => 'Department of Forest Resources Management',
            'level' => 'Bachelors',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
            'user_id' => $adminUser->id,
        ]);

        $program = Program::where('program', 'Bachelor of New Test Forestry Program')->first();

        $response = $this->actingAs($adminUser)->post(route('admin.assignRole'), [
            'email' => 'usertest@gmail.com',
            'role' => 'program-director',
            'program' => 'Bachelor of New Test Forestry Program',
            'accessToAllCoursesInFaculty' => '1'
        ]);

        $this->assertDatabaseHas('program_user_role',[
            'program_id' => $program->program_id,
            'role_id' => $role->id,
            'user_id' => $user->id,
            'has_access_to_all_courses_in_faculty' => true
        ]);

        $course1 = Course::where('course_title', 'Forestry New Test Course')->orderBy('course_id', 'DESC')->first();

        $this->assertDatabaseHas('course_user_role', [
            'role_id' => $role->id,
            'user_id' => $user->id,
            'course_id' => $course1->course_id,
            'program_id' => $program->program_id,
        ]);

        $course2 = Course::where(['course_code' => 'TEST', 'course_num' => 271])->first();

        $this->assertDatabaseHas('course_user_role', [
            'role_id' => $role->id,
            'user_id' => $user->id,
            'course_id' => $course2->course_id,
            'program_id' => $program->program_id,
        ]);

        $delivery_modalities = ['O', 'B', 'I'];
        $semesters = ['W1', 'W2', 'S1', 'S2'];


        $response = $this->actingAs($adminUser)->post(route('courses.store'), [
            'course_code' => 'CPSC',
            'course_num' => '234',
            'delivery_modality' => $delivery_modalities[array_rand($delivery_modalities)],
            'course_year' => 2025,
            'course_semester' => $semesters[array_rand($semesters)],
            'course_title' => 'Different Faculty Test Course',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
            'assigned' => 1,
            'type' => 'unassigned',
            'standard_category_id' => 1,
            'scale_category_id' => 1,
            'user_id' => $adminUser->id,
            'campus' => 'Vancouver',
            'faculty' => 'Faculty of Science',
            'department' => 'Department of Computer Science',
        ]);

        $courseNew = Course::where(['course_code' => 'CPSC', 'course_num' => 234])->first();

        $response = $this->actingAs($adminUser)->post(route('programs.single.update', $program->program_id),[
            'program_id' => $program->program_id,
            'program' => 'Changed Faculty of Program',
            'level' => $program->level,
            'campus' => 'Vancouver',
            'faculty' => 'Faculty of Science',
            'department' => 'Department of Computer Science',
        ]);

        $this->assertDatabaseHas('course_user_role', [
            'role_id' => $role->id,
            'user_id' => $user->id,
            'course_id' => $courseNew->course_id,
            'program_id' => $program->program_id,
        ]);

        $this->assertDatabaseMissing('course_user_role', [
            'role_id' => $role->id,
            'user_id' => $user->id,
            'course_id' => $course1->course_id,
        ]);

        $this->assertDatabaseMissing('course_user_role', [
            'role_id' => $role->id,
            'user_id' => $user->id,
            'course_id' => $course2->course_id,
        ]);
    }

    public function test_dept_head_access_update_on_course_dept_change(){
        $adminUser = User::where('email', 'admintest@gmail.com')->first();
        $user = User::where('email', 'usertest@gmail.com')->first();
        $role = Role::where(['role' => 'department head'])->first();

        $course = Course::where(['course_code' => 'TEST', 'course_num' => 271])->first();

        $this->assertDatabaseHas('course_user_role', [
            'role_id' => $role->id,
            'user_id' => $user->id,
            'course_id' => $course->course_id,
        ]);

        $response = $this->actingAs($adminUser)->put(action([\App\Http\Controllers\CourseController::class, 'update'],
            $course->course_id),
            [
                'course_code' => 'TEST',
                'course_title' => 'Changed To Non-Forestry Testing Course',
                'course_num' => $course->course_num,
                'delivery_modality' => $course->delivery_modality,
                'course_year' => $course->year,
                'course_semester' => $course->semester,
                'standard_category_id' => $course->standard_category_id,
                'campus' => 'Vancouver',
                'faculty' => 'Faculty of Forestry',
                'department' => 'Department of Wood Science'

            ]
        );

        $this->assertDatabaseMissing('course_user_role', [
            'role_id' => $role->id,
            'user_id' => $user->id,
            'course_id' => $course->course_id,
        ]);
    }
}

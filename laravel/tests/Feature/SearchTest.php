<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\AssessmentMethod;
use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\CourseMaterialChunk;
use App\Models\CourseMaterialFile;
use App\Models\CourseTopic;
use App\Models\LearningOutcome;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PDF;

class SearchTest extends TestCase
{
    use DatabaseTransactions;

    private User $searchUser;

    protected function setUp(): void
    {
        parent::setUp();

        // After merging dev's package updates, tests need this so they do not look for built Vite files
        $this->withoutVite();

        // Existing search tests focus on ranking/filter behavior, so they use admin access.
        // The regular-user access rules are covered separately below.
        $this->searchUser = User::factory()->create();
        $this->assignRoleToUser($this->searchUser, 'administrator');
        $this->actingAs($this->searchUser);
    }

    /**
     * A basic feature test example.
     */
    public function test_example(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_search_page_loads_without_query(){
        $response = $this->get(route('search.index'));
        $response->assertStatus(200);
        $response->assertViewHas('selectedView', 'courses');
        $response->assertSee('Course Search');
        $response->assertSee('Search settings');
        $response->assertSee('Courses');
        $response->assertSee('Programs');
    }

    public function test_guest_user_is_redirected_from_search_page()
    {
        auth()->logout();

        $response = $this->get(route('search.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_regular_user_only_sees_directly_accessible_courses()
    {
        $this->createCourseScaleCategory();

        $regularUser = User::factory()->create();
        $this->actingAs($regularUser);

        $accessibleCourse = $this->createSearchCourse('OPEN', 101, 'Accessium Visible Course');
        $otherAccessibleCourse = $this->createSearchCourse('EDIT', 102, 'Accessium Editor Course');
        $this->createSearchCourse('HIDE', 202, 'Accessium Hidden Course');

        $this->giveUserDirectCourseAccess($regularUser, $accessibleCourse, 3);
        $this->giveUserDirectCourseAccess($regularUser, $otherAccessibleCourse, 2);

        $response = $this->get(route('search.index', [
            'query' => 'accessium',
        ]));

        $response->assertStatus(200);
        $this->assertSearchVisibility($response, [
            'Accessium Visible Course',
            'Accessium Editor Course',
        ], [
            'Accessium Hidden Course',
        ]);
        $response->assertSee('Courses: 2');
        $this->assertSame(['EDIT', 'OPEN'], $response->viewData('availableCourseCodes'));
    }

    public function test_regular_user_without_access_does_not_see_courses()
    {
        $this->createCourseScaleCategory();

        $regularUser = User::factory()->create();
        $this->actingAs($regularUser);

        $this->createSearchCourse('HIDE', 202, 'Noaccessium Hidden Course');

        $response = $this->get(route('search.index', [
            'query' => 'noaccessium',
        ]));

        $response->assertStatus(200);
        $response->assertDontSee('Noaccessium Hidden Course');
        $this->assertSame(0, $response->viewData('results')->total());
        $this->assertSame([], $response->viewData('availableCourseCodes'));
    }

    public function test_admin_can_search_courses_without_direct_access()
    {
        $this->createCourseScaleCategory();

        $this->createSearchCourse('ADMN', 303, 'Adminium Visible Course');

        $response = $this->get(route('search.index', [
            'query' => 'adminium',
        ]));

        $response->assertStatus(200);
        $response->assertSee('Adminium Visible Course');
    }

    public function test_program_director_can_search_courses_in_directed_program()
    {
        $this->createCourseScaleCategory();

        $programDirector = User::factory()->create();
        $this->assignRoleToUser($programDirector, 'program director');
        $this->actingAs($programDirector);

        $directedProgramId = $this->createProgram('Visible Director Program');
        $otherProgramId = $this->createProgram('Hidden Director Program');

        $visibleCourse = $this->createSearchCourse('PDIR', 301, 'Directorium Visible Course');
        $hiddenCourse = $this->createSearchCourse('HIDE', 302, 'Directorium Hidden Course');

        $this->attachCourseToProgram($visibleCourse, $directedProgramId);
        $this->attachCourseToProgram($hiddenCourse, $otherProgramId);

        $this->giveUserProgramDirectorAccess($programDirector, $directedProgramId);

        $response = $this->get(route('search.index', [
            'query' => 'directorium',
        ]));

        $response->assertStatus(200);
        $this->assertSearchVisibility($response, [
            'Directorium Visible Course',
        ], [
            'Directorium Hidden Course',
        ]);
        $response->assertSee('Courses: 1');
    }

    public function test_program_role_without_course_role_does_not_grant_search_access()
    {
        $this->createCourseScaleCategory();

        $programDirector = User::factory()->create();
        $this->actingAs($programDirector);

        $programId = $this->createProgram('Unmaterialized Director Program');
        $course = $this->createSearchCourse('MISS', 301, 'Unmaterialized Hidden Course');
        $this->attachCourseToProgram($course, $programId);

        $roleId = $this->assignRoleToUser($programDirector, 'program director');
        DB::table('program_user_role')->insert([
            'program_id' => $programId,
            'user_id' => $programDirector->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get(route('search.index', [
            'query' => 'unmaterialized',
        ]));

        $response->assertStatus(200);
        $response->assertDontSee('Unmaterialized Hidden Course');
        $this->assertSame(0, $response->viewData('results')->total());
    }

    public function test_non_admin_can_see_direct_program_match_without_course_access()
    {
        $regularUser = User::factory()->create();
        $this->actingAs($regularUser);

        $visibleProgramId = $this->createProgram('Directonly Visible Program');
        $this->createProgram('Directonly Hidden Program');
        $this->giveUserDirectProgramAccess($regularUser, $visibleProgramId, 3);

        $response = $this->get(route('search.index', [
            'query' => 'directonly',
            'view' => 'programs',
        ]));
        $programResults = $response->viewData('programResults');

        $response->assertStatus(200);
        $this->assertCount(1, $programResults);
        $this->assertSame($visibleProgramId, $programResults->first()->program_id);
        $this->assertCount(0, $programResults->first()->courses);
        $this->assertSearchVisibility($response, [
            'Directonly Visible Program',
        ], [
            'Directonly Hidden Program',
        ]);
    }

    public function test_program_director_can_search_courses_from_multiple_directed_programs()
    {
        $this->createCourseScaleCategory();

        $programDirector = User::factory()->create();
        $this->actingAs($programDirector);

        $firstProgramId = $this->createProgram('First Multi Director Program');
        $secondProgramId = $this->createProgram('Second Multi Director Program');
        $firstCourse = $this->createSearchCourse('MULT', 301, 'Multidirectorium First Course');
        $secondCourse = $this->createSearchCourse('MULT', 302, 'Multidirectorium Second Course');

        $this->attachCourseToProgram($firstCourse, $firstProgramId);
        $this->attachCourseToProgram($secondCourse, $secondProgramId);
        $this->giveUserProgramDirectorAccess($programDirector, $firstProgramId);
        $this->giveUserProgramDirectorAccess($programDirector, $secondProgramId);

        $response = $this->get(route('search.index', [
            'query' => 'multidirectorium',
            'view' => 'programs',
        ]));
        $programResults = collect($response->viewData('programResults')->items());
        $programIds = $programResults->pluck('program_id')->all();

        $response->assertStatus(200);
        $this->assertCount(2, $programIds);
        $this->assertContains($firstProgramId, $programIds);
        $this->assertContains($secondProgramId, $programIds);
        $response->assertSee('Multidirectorium First Course');
        $response->assertSee('Multidirectorium Second Course');
    }

    public function test_program_director_keeps_direct_course_access_outside_directed_program()
    {
        $this->createCourseScaleCategory();

        $programDirector = User::factory()->create();
        $this->assignRoleToUser($programDirector, 'program director');
        $this->actingAs($programDirector);

        $directedProgramId = $this->createProgram('Director Access Program');

        $programCourse = $this->createSearchCourse('PDIR', 401, 'Accessblend Program Course');
        $directCourse = $this->createSearchCourse('OPEN', 402, 'Accessblend Direct Course');
        $this->createSearchCourse('HIDE', 403, 'Accessblend Hidden Course');

        $this->attachCourseToProgram($programCourse, $directedProgramId);

        $this->giveUserProgramDirectorAccess($programDirector, $directedProgramId);
        $this->giveUserDirectCourseAccess($programDirector, $directCourse, 3);

        $response = $this->get(route('search.index', [
            'query' => 'accessblend',
        ]));

        $response->assertStatus(200);
        $this->assertSearchVisibility($response, [
            'Accessblend Program Course',
            'Accessblend Direct Course',
        ], [
            'Accessblend Hidden Course',
        ]);
        $response->assertSee('Courses: 2');
    }

    public function test_department_head_can_search_materialized_faculty_course_access()
    {
        $this->createCourseScaleCategory();

        $departmentHead = User::factory()->create();
        $this->actingAs($departmentHead);

        $campusId = DB::table('campuses')->max('campus_id') + 1;
        $facultyId = DB::table('faculties')->max('faculty_id') + 1;
        $departmentId = DB::table('departments')->max('department_id') + 1;

        DB::table('campuses')->insert([
            'campus_id' => $campusId,
            'campus' => 'Faculty Access Campus',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('faculties')->insert([
            'faculty_id' => $facultyId,
            'campus_id' => $campusId,
            'faculty' => 'Faculty Access Faculty',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('departments')->insert([
            'department_id' => $departmentId,
            'faculty_id' => $facultyId,
            'department' => 'Faculty Access Department',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('department_head')->insert([
            'department_id' => $departmentId,
            'user_id' => $departmentHead->id,
            'has_access_to_all_courses_in_faculty' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assignRoleToUser($departmentHead, 'department head');
        $facultyCourse = $this->createSearchCourse('FACC', 411, 'Facultium Faculty Course');
        $this->createSearchCourse('HIDE', 412, 'Facultium Hidden Course');

        DB::table('faculty_course_codes')->insert([
            'faculty_id' => $facultyId,
            'course_code' => 'FACC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The role assignment flow materializes faculty-wide access in course_user_role.
        $this->giveUserDepartmentHeadCourseAccess($departmentHead, $facultyCourse);

        $response = $this->get(route('search.index', [
            'query' => 'facultium',
        ]));

        $response->assertStatus(200);
        $this->assertSearchVisibility($response, [
            'Facultium Faculty Course',
        ], [
            'Facultium Hidden Course',
        ]);
    }

    public function test_program_director_filter_options_only_show_accessible_courses_and_programs()
    {
        $this->createCourseScaleCategory();

        $programDirector = User::factory()->create();
        $this->actingAs($programDirector);

        $directedProgramId = $this->createProgram('Visible Filter Program');
        $otherProgramId = $this->createProgram('Hidden Filter Program');

        $visibleCourse = $this->createSearchCourse('PDIR', 301, 'Filter Option Visible Course');
        $hiddenCourse = $this->createSearchCourse('HIDE', 302, 'Filter Option Hidden Course');

        $this->attachCourseToProgram($visibleCourse, $directedProgramId);
        $this->attachCourseToProgram($hiddenCourse, $otherProgramId);
        $this->giveUserProgramDirectorAccess($programDirector, $directedProgramId);

        $response = $this->get(route('search.index'));
        $availablePrograms = $response->viewData('availablePrograms')->pluck('program')->all();

        $response->assertStatus(200);
        $this->assertSame(['PDIR'], $response->viewData('availableCourseCodes'));
        $this->assertSame(['Visible Filter Program'], $availablePrograms);
    }

    public function test_program_director_filter_options_include_role_and_direct_program_access()
    {
        $programDirector = User::factory()->create();
        $this->actingAs($programDirector);

        $directedProgramId = $this->createProgram('Directed Filter Program');
        $directProgramId = $this->createProgram('Direct Filter Program');
        $this->createProgram('Hidden Filter Program');

        $this->giveUserProgramDirectorAccess($programDirector, $directedProgramId);
        $this->giveUserDirectProgramAccess($programDirector, $directProgramId, 3);

        $response = $this->get(route('search.index'));
        $availablePrograms = $response->viewData('availablePrograms')->pluck('program')->all();

        $response->assertStatus(200);
        $this->assertSame([
            'Direct Filter Program',
            'Directed Filter Program',
        ], $availablePrograms);
    }

    public function test_program_director_direct_program_match_requires_accessible_course()
    {
        $this->createCourseScaleCategory();

        $programDirector = User::factory()->create();
        $this->actingAs($programDirector);

        $directedProgramId = $this->createProgram('Terraria Visible Program');
        $otherProgramId = $this->createProgram('Terraria Hidden Program');

        $visibleCourse = $this->createSearchCourse('PDIR', 401, 'Program Match Visible Course');
        $hiddenCourse = $this->createSearchCourse('HIDE', 402, 'Program Match Hidden Course');

        $this->attachCourseToProgram($visibleCourse, $directedProgramId);
        $this->attachCourseToProgram($hiddenCourse, $otherProgramId);
        $this->giveUserProgramDirectorAccess($programDirector, $directedProgramId);

        $response = $this->get(route('search.index', [
            'query' => 'terraria',
            'view' => 'programs',
        ]));
        $programResults = $response->viewData('programResults');

        $response->assertStatus(200);
        $this->assertCount(1, $programResults);
        $this->assertSame($directedProgramId, $programResults->first()->program_id);
        $this->assertSearchVisibility($response, [
            'Terraria Visible Program',
        ], [
            'Terraria Hidden Program',
        ]);
        $response->assertSee(route('programWizard.step1', $directedProgramId));
    }

    public function test_department_head_can_search_courses_with_role_and_direct_access()
    {
        $this->createCourseScaleCategory();

        $departmentHead = User::factory()->create();
        $this->actingAs($departmentHead);

        $accessibleCourse = $this->createSearchCourse('DHED', 301, 'Headium Visible Course');
        $directCourse = $this->createSearchCourse('OPEN', 302, 'Headium Direct Course');
        $this->createSearchCourse('HIDE', 303, 'Headium Hidden Course');

        $this->giveUserDepartmentHeadCourseAccess($departmentHead, $accessibleCourse);
        $this->giveUserDirectCourseAccess($departmentHead, $directCourse, 3);

        $response = $this->get(route('search.index', [
            'query' => 'headium',
        ]));

        $response->assertStatus(200);
        $this->assertSearchVisibility($response, [
            'Headium Visible Course',
            'Headium Direct Course',
        ], [
            'Headium Hidden Course',
        ]);
        $response->assertSee('Courses: 2');
        $this->assertSame(['DHED', 'OPEN'], $response->viewData('availableCourseCodes'));
    }

    public function test_department_head_direct_program_match_requires_accessible_course()
    {
        $this->createCourseScaleCategory();

        $departmentHead = User::factory()->create();
        $this->actingAs($departmentHead);

        $visibleProgramId = $this->createProgram('Terraria Department Program');
        $hiddenProgramId = $this->createProgram('Terraria Hidden Department Program');

        $accessibleCourse = $this->createSearchCourse('DHED', 401, 'Department Program Visible Course');
        $hiddenCourse = $this->createSearchCourse('HIDE', 402, 'Department Program Hidden Course');

        $this->attachCourseToProgram($accessibleCourse, $visibleProgramId);
        $this->attachCourseToProgram($hiddenCourse, $hiddenProgramId);
        $this->giveUserDepartmentHeadCourseAccess($departmentHead, $accessibleCourse);

        $response = $this->get(route('search.index', [
            'query' => 'terraria',
            'view' => 'programs',
        ]));
        $programResults = $response->viewData('programResults');

        $response->assertStatus(200);
        $this->assertCount(1, $programResults);
        $this->assertSame($visibleProgramId, $programResults->first()->program_id);
        $this->assertSearchVisibility($response, [
            'Terraria Department Program',
        ], [
            'Terraria Hidden Department Program',
        ]);
        $response->assertDontSee(route('programWizard.step1', $visibleProgramId));
    }

    public function test_program_view_selection_is_preserved(){
        $response = $this->get(route('search.index', [
            'view' => 'programs',
        ]));

        $response->assertStatus(200);
        $response->assertViewHas('selectedView', 'programs');
        $response->assertSee('value="programs" checked', false);
    }

    public function test_invalid_search_view_is_rejected(){
        $response = $this->from(route('search.index'))->get(route('search.index', [
            'view' => 'invalid',
        ]));

        $response->assertRedirect(route('search.index'));
        $response->assertSessionHasErrors('view');
    }

    public function test_invalid_course_level_filter_is_rejected()
    {
        $response = $this->from(route('search.index'))->get(route('search.index', [
            'course_filters_applied' => 1,
            'course_levels' => ['700'],
        ]));

        $response->assertRedirect(route('search.index'));
        $response->assertSessionHasErrors('course_levels.0');
    }

    public function test_search_page_displays_query(){
        $response = $this->get(route('search.index', [
            'query' => 'climate change'
        ]));

        $response->assertStatus(200);
        $response->assertSee('climate change');
    }

    public function test_search_query_whitespace_gone(){
        $response = $this->get(route('search.index', [
            'query' => '   climate        change        '
        ]));

        $response->assertStatus(200);
        $response->assertSee('climate change');
    }

    public function test_oversized_search_query(){
        $response = $this->from(route('search.index'))->get(route('search.index', [
            'query' => str_repeat('a', 201),
        ]));

        $response->assertRedirect(route('search.index'));
        $response->assertSessionHasErrors('query');
    }

    public function test_empty_search_query_allowed(){
        $response = $this->get(route('search.index', [
            'query' => '',
        ]));

        $response->assertStatus(200);
        $response->assertSessionHasNoErrors();
    }

    public function test_course_search_results_can_be_exported_as_pdf(): void
    {
        $this->createCourseScaleCategory();
        $course = Course::factory()->create([
            'course_code' => 'FRST',
            'course_num' => 321,
            'course_title' => 'Zephyr Export Forestry',
        ]);

        PDF::shouldReceive('loadView')
            ->once()
            ->with('search.exports.course-results', \Mockery::on(fn ($data) =>
                $data['searchTerm'] === 'zephyr export'
                && $data['results']->contains('course_id', $course->course_id)
            ))
            ->andReturnSelf();
        PDF::shouldReceive('download')
            ->once()
            ->with('zephyr-export-course-search-results-'.now()->format('Y-m-d').'.pdf')
            ->andReturn(response('%PDF', 200, ['Content-Type' => 'application/pdf']));

        $response = $this->get(route('search.export.pdf', [
            'query' => 'zephyr export',
            'view' => 'courses',
        ]));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_program_search_results_can_be_exported_as_pdf(): void
    {
        $this->createCourseScaleCategory();
        $course = Course::factory()->create([
            'course_title' => 'Auralithpdfexport Course',
        ]);
        $programId = DB::table('programs')->insertGetId([
            'program' => 'Auralithpdfexport Program',
            'level' => 'Bachelors',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'program_id');
        DB::table('course_programs')->insert([
            'course_id' => $course->course_id,
            'program_id' => $programId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        PDF::shouldReceive('loadView')
            ->once()
            ->with('search.exports.program-results', \Mockery::on(fn ($data) =>
                $data['programResults']->contains('program_id', $programId)
            ))
            ->andReturnSelf();
        PDF::shouldReceive('download')
            ->once()
            ->with('auralithpdfexport-program-search-results-'.now()->format('Y-m-d').'.pdf')
            ->andReturn(response('%PDF', 200, ['Content-Type' => 'application/pdf']));

        $response = $this->get(route('search.export.pdf', [
            'query' => 'auralithpdfexport',
            'view' => 'programs',
        ]));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_course_search_results_can_be_exported_as_a_spreadsheet(): void
    {
        $this->createCourseScaleCategory();
        $course = $this->createSearchCourse('FRST', 322, 'Zephyr Spreadsheet Forestry');
        $this->createCourseTopic($course, 'Zephyr spreadsheet topic');

        $response = $this->get(route('search.export.spreadsheet', [
            'query' => 'zephyr',
            'view' => 'courses',
        ]));

        $response->assertOk()
            ->assertDownload('zephyr-course-search-results-'.now()->format('Y-m-d').'.xlsx');

        $spreadsheet = $this->loadDownloadedSpreadsheet($response);

        try {
            $this->assertSame([
                'Search Parameters',
                'Search Summary',
                'Course Identity',
                'Topics',
            ], $spreadsheet->getSheetNames());
            $this->assertSame('zephyr', $spreadsheet->getSheetByName('Search Parameters')->getCell('C2')->getValue());
            $this->assertSame(0, $spreadsheet->getSheetByName('Search Parameters')->getCell('C16')->getValue());
            $this->assertSame('Zephyr Spreadsheet Forestry', $spreadsheet->getSheetByName('Search Summary')->getCell('C2')->getValue());
            $this->assertSame('Zephyr spreadsheet topic', $spreadsheet->getSheetByName('Topics')->getCell('E2')->getValue());
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_program_search_results_can_be_exported_as_a_spreadsheet(): void
    {
        $this->createProgram('Auralith Spreadsheet Program');

        $response = $this->get(route('search.export.spreadsheet', [
            'query' => 'auralith spreadsheet',
            'view' => 'programs',
        ]));

        $response->assertOk()
            ->assertDownload('auralith-spreadsheet-program-search-results-'.now()->format('Y-m-d').'.xlsx');

        $spreadsheet = $this->loadDownloadedSpreadsheet($response);

        try {
            $this->assertSame([
                'Search Parameters',
                'Search Summary',
                'Program Names',
            ], $spreadsheet->getSheetNames());
            $this->assertSame('Auralith Spreadsheet Program', $spreadsheet->getSheetByName('Search Summary')->getCell('A2')->getValue());
            $this->assertSame('Auralith Spreadsheet Program', $spreadsheet->getSheetByName('Program Names')->getCell('A2')->getValue());
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_guest_user_cannot_export_search_results_as_a_spreadsheet(): void
    {
        auth()->logout();

        $response = $this->get(route('search.export.spreadsheet', [
            'query' => 'forestry',
        ]));

        $response->assertRedirect(route('login'));
    }

    public function test_search_finds_course_by_compact_course_code(){
        $this->createCourseScaleCategory();

        $this->createSearchCourse('CONS', 123, 'Compact Code Match Course');

        $response = $this->get(route('search.index', [
            'query' => 'CONS123',
        ]));

        $response->assertStatus(200);
        $response->assertSee('Compact Code Match Course');
        $response->assertSee('<mark>CONS</mark>', false);
        $response->assertDontSee('<strong>Course:</strong>', false);
    }

    public function test_compact_course_code_supports_new_notation_and_title_words(){
        $this->createCourseScaleCategory();

        Course::factory()->create([
            'course_code' => 'FRST_V',
            'course_num' => 100,
            'course_title' => 'Forest Management',
        ]);

        $response = $this->get(route('search.index', [
            'query' => 'FRST_V100 Forest',
        ]));

        $response->assertStatus(200);
        $response->assertSee('Forest Management');
    }

    public function test_search_finds_course_by_course_title(){
        $this->createCourseScaleCategory();

        $this->createSearchCourse('FRST', 321, 'Auralith Forest Policy');

        $response = $this->get(route('search.index', [
            'query' => 'auralith',
        ]));

        $response->assertStatus(200);
        $response->assertSee('Forest Policy');
        $response->assertSee('<mark>Auralith</mark>', false);
        $response->assertDontSee('<strong>Course:</strong>', false);
    }

    public function test_direct_course_matches_appear_before_content_only_matches(){
        $this->createCourseScaleCategory();

        $this->createSearchCourse('CONS', 123, 'Actual Course Match');

        $contentOnlyCourse = $this->createSearchCourse('FRST', 456, 'Description Mention Course');

        DB::table('course_description')->insert([
            'course_id' => $contentOnlyCourse->course_id,
            'description' => 'This course references CONS123 as a related prerequisite example.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get(route('search.index', [
            'query' => 'CONS123',
        ]));

        $response->assertStatus(200);
        $response->assertSeeInOrder([
            'Actual Course Match',
            'Description Mention Course',
        ]);
        $response->assertSee('Description:');
    }

    // This method creates the missing parent rows needed by the test courses.
    // updateOrInsert keeps it safe if the local database already has seeded data.
    private function createCourseScaleCategory(): void
    {
        DB::table('standard_categories')->updateOrInsert(
            ['standard_category_id' => 1],
            ['sc_name' => 'Test Standard Category']
        );

        DB::table('standards_scale_categories')->updateOrInsert(
            ['scale_category_id' => 1],
            ['name' => 'Test Scale Category']
        );
    }

    private function loadDownloadedSpreadsheet(TestResponse $response): Spreadsheet
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'search-export-');
        file_put_contents($temporaryPath, $response->streamedContent());

        try {
            $this->assertSame('Xlsx', IOFactory::identify($temporaryPath));

            return IOFactory::load($temporaryPath);
        } finally {
            unlink($temporaryPath);
        }
    }

    private function assignRoleToUser(User $user, string $roleName): int
    {
        $roleId = DB::table('roles')->where('role', $roleName)->value('id');

        if (!$roleId) {
            $roleId = DB::table('roles')->insertGetId([
                'role' => $roleName,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('role_user')->insertOrIgnore([
            'role_id' => $roleId,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $roleId;
    }

    private function giveUserDirectCourseAccess(User $user, Course $course, int $permission): void
    {
        DB::table('course_users')->updateOrInsert(
            [
                'course_id' => $course->course_id,
                'user_id' => $user->id,
            ],
            [
                'permission' => $permission,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function giveUserDirectProgramAccess(User $user, int $programId, int $permission): void
    {
        DB::table('program_users')->updateOrInsert(
            [
                'program_id' => $programId,
                'user_id' => $user->id,
            ],
            [
                'permission' => $permission,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function giveUserProgramDirectorAccess(User $user, int $programId): void
    {
        $programDirectorRoleId = $this->assignRoleToUser($user, 'program director');

        DB::table('program_user_role')->updateOrInsert(
            [
                'program_id' => $programId,
                'user_id' => $user->id,
                'role_id' => $programDirectorRoleId,
            ],
            [
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $courseIds = DB::table('course_programs')
            ->where('program_id', $programId)
            ->pluck('course_id');

        foreach ($courseIds as $courseId) {
            DB::table('course_user_role')->updateOrInsert(
                [
                    'course_id' => $courseId,
                    'user_id' => $user->id,
                    'role_id' => $programDirectorRoleId,
                    'program_id' => $programId,
                ],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function giveUserDepartmentHeadCourseAccess(User $user, Course $course): void
    {
        $departmentHeadRoleId = $this->assignRoleToUser($user, 'department head');

        DB::table('course_user_role')->updateOrInsert(
            [
                'course_id' => $course->course_id,
                'user_id' => $user->id,
                'role_id' => $departmentHeadRoleId,
            ],
            [
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function createSearchCourse(string $courseCode, int $courseNumber, string $courseTitle, array $attributes = []): Course
    {
        return Course::factory()->create(array_merge([
            'course_code' => $courseCode,
            'course_num' => $courseNumber,
            'course_title' => $courseTitle,
        ], $attributes));
    }

    private function createCourseTopic(Course $course, string $topic): CourseTopic
    {
        return CourseTopic::factory()->create([
            'course_id' => $course->course_id,
            'topic' => $topic,
        ]);
    }

    private function createProgram(string $programName, array $attributes = []): int
    {
        return DB::table('programs')->insertGetId(array_merge([
            'program' => $programName,
            'level' => 'Bachelors',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes), 'program_id');
    }

    private function attachCourseToProgram(Course $course, int $programId): void
    {
        DB::table('course_programs')->insert([
            'course_id' => $course->course_id,
            'program_id' => $programId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertSearchVisibility($response, array $visibleText, array $hiddenText): void
    {
        foreach ($visibleText as $text) {
            $response->assertSee($text);
        }

        foreach ($hiddenText as $text) {
            $response->assertDontSee($text);
        }
    }

    private function createIndexedMaterialContent(Course $course, string $content): CourseMaterialFile
    {
        $material = CourseMaterial::factory()->create([
            'course_id' => $course->course_id,
            'name' => 'Indexed lecture material',
            'type' => 'slides',
        ]);

        $file = CourseMaterialFile::create([
            'course_material_id' => $material->course_material_id,
            'uploaded_by' => $this->searchUser->id,
            'file_name' => 'lecture.pdf',
            'file_path' => "course-materials/{$course->course_id}/lecture.pdf",
            'file_size' => 1024,
            'status' => CourseMaterialFile::STATUS_INDEXED,
        ]);

        CourseMaterialChunk::create([
            'course_material_file_id' => $file->course_material_file_id,
            'page_number' => 1,
            'chunk_index' => 0,
            'content' => $content,
        ]);

        return $file;
    }


    //Search Topics Querying tests
    public function test_search_finds_course_by_topic(){
        $this->createCourseScaleCategory();

        $course = $this->createSearchCourse('TEST', 101, 'Test Course');

        $this->createCourseTopic($course, 'Climate change adaptaion of something something');

        $response = $this->get(route('search.index', [
            'query' => 'climate change',
        ]));

        $response->assertStatus(200);
        $response->assertSee('TEST');
        $response->assertSee('101');
        $response->assertSee('Test Course');
    }

    public function test_search_does_not_show_course_when_topic_does_not_match(){
    $this->createCourseScaleCategory();

    $course = $this->createSearchCourse('TEST', 101, 'Nonmatching Sentence');

    $this->createCourseTopic($course, 'Forest ecology and biodiversity');

    $response = $this->get(route('search.index', [
        'query' => 'climate change',
    ]));

    $response->assertStatus(200);
    $response->assertDontSee('Nonmatching Sentence');
}

public function test_search_only_returns_course_with_matching_topic()
{
    $this->createCourseScaleCategory();

    $matchingCourse = $this->createSearchCourse('TEST', 101, 'Matching Course');

    $nonMatchingCourse = $this->createSearchCourse('TEST', 202, 'Non Matching Course');

    $this->createCourseTopic($matchingCourse, 'Climate change adaptation strategies');

    $this->createCourseTopic($nonMatchingCourse, 'Forest inventory and timber supply');

    $response = $this->get(route('search.index', [
        'query' => 'climate change',
    ]));

    $response->assertStatus(200);
    $response->assertSee('Matching Course');
    $response->assertDontSee('Non Matching Course');
}

public function test_search_returns_multiple_matching_topics_for_same_course()
{
    $this->createCourseScaleCategory();

    $course = $this->createSearchCourse('TEST', 101, 'Climate Course');

    $this->createCourseTopic($course, 'Climate change adaptation strategies');

    $this->createCourseTopic($course, 'Climate change impacts on forests');

    $this->createCourseTopic($course, 'Soil classification methods');

    $response = $this->get(route('search.index', [
        'query' => 'climate change',
    ]));

    $response->assertStatus(200);
    $response->assertSee('Climate Course');
    $response->assertSee('adaptation strategies');
    $response->assertSee('impacts on forests');
    $response->assertDontSee('Soil classification methods');
}

    public function test_highlighted_snippet_escapes_stored_html_while_preserving_highlights()
    {
        $renderedSnippet = view('search.partials.highlighted-snippet', [
            'snippet' => '<mark>Climate</mark> <img src=x onerror="alert(\'unsafe\')">',
        ])->render();

        $this->assertStringContainsString('<mark>Climate</mark>', $renderedSnippet);
        $this->assertStringContainsString('&lt;img src=x onerror=', $renderedSnippet);
        $this->assertStringNotContainsString('<img src=x onerror=', $renderedSnippet);
    }

public function test_search_finds_course_by_description()
{
    $this->createCourseScaleCategory();

    $course = $this->createSearchCourse('TEST', 303, 'Description Match Course');

    DB::table('course_description')->insert([
        'course_id' => $course->course_id,
        'description' => 'This course studies zirconium watershed planning and applied environmental analysis.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->get(route('search.index', [
        'query' => 'zirconium',
    ]));

    $response->assertStatus(200);
    $response->assertSee('Description Match Course');
    $response->assertSee('Description:');
    $response->assertSee('zirconium', false);
}

public function test_search_only_returns_course_with_matching_learning_objective()
{
    $this->createCourseScaleCategory();

    $matchingCourse = $this->createSearchCourse('TEST', 606, 'Learning Objective Match Course');

    $nonMatchingCourse = $this->createSearchCourse('TEST', 607, 'Learning Objective Non Match Course');

    LearningOutcome::create([
        'course_id' => $matchingCourse->course_id,
        'l_outcome' => 'Evaluate aurorium restoration planning in urban landscapes.',
        'clo_shortphrase' => 'Evaluate aurorium restoration',
    ]);

    LearningOutcome::create([
        'course_id' => $nonMatchingCourse->course_id,
        'l_outcome' => 'Explain forest inventory sampling methods.',
        'clo_shortphrase' => 'Explain sampling',
    ]);

    $response = $this->get(route('search.index', [
        'query' => 'aurorium',
    ]));

    $response->assertStatus(200);
    $response->assertSee('Learning Objective Match Course');
    $response->assertSee('Learning Objective:');
    $response->assertSee('aurorium', false);
    $response->assertDontSee('Learning Objective Non Match Course');
}

public function test_search_only_returns_course_with_matching_description()
{
    $this->createCourseScaleCategory();

    $matchingCourse = $this->createSearchCourse('TEST', 707, 'Description Only Match Course');

    $nonMatchingCourse = $this->createSearchCourse('TEST', 708, 'Description Non Match Course');

    DB::table('course_description')->insert([
        [
            'course_id' => $matchingCourse->course_id,
            'description' => 'This course introduces solandria watershed governance and planning.',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'course_id' => $nonMatchingCourse->course_id,
            'description' => 'This course introduces silviculture and forest operations.',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $response = $this->get(route('search.index', [
        'query' => 'solandria',
    ]));

    $response->assertStatus(200);
    $response->assertSee('Description Only Match Course');
    $response->assertSee('Description:');
    $response->assertSee('solandria', false);
    $response->assertDontSee('Description Non Match Course');
}

public function test_search_finds_course_by_material()
{
    $this->createCourseScaleCategory();

    $course = $this->createSearchCourse('TEST', 404, 'Material Match Course');

    CourseMaterial::factory()->create([
        'course_id' => $course->course_id,
        'name' => 'Nebulagraph field guide',
        'type' => 'textbook',
        'description' => 'Required material for applied field methods',
    ]);

    $response = $this->get(route('search.index', [
        'query' => 'nebulagraph',
    ]));

    $response->assertStatus(200);
    $response->assertSee('Material Match Course');
    $response->assertSee('Material:');
    $response->assertSee('nebulagraph', false);
}

public function test_search_only_returns_course_with_matching_material()
{
    $this->createCourseScaleCategory();

    $matchingCourse = $this->createSearchCourse('TEST', 808, 'Material Only Match Course');

    $nonMatchingCourse = $this->createSearchCourse('TEST', 809, 'Material Non Match Course');

    CourseMaterial::factory()->create([
        'course_id' => $matchingCourse->course_id,
        'name' => 'Velorium case study collection',
        'type' => 'article',
        'description' => 'Recommended readings for environmental policy.',
    ]);

    CourseMaterial::factory()->create([
        'course_id' => $nonMatchingCourse->course_id,
        'name' => 'Forest measurements handbook',
        'type' => 'textbook',
        'description' => 'Required field methods reference.',
    ]);

    $response = $this->get(route('search.index', [
        'query' => 'velorium',
    ]));

    $response->assertStatus(200);
    $response->assertSee('Material Only Match Course');
    $response->assertSee('Material:');
    $response->assertSee('velorium', false);
    $response->assertDontSee('Material Non Match Course');
}

public function test_search_finds_course_by_indexed_material_content()
{
    $this->createCourseScaleCategory();

    $course = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 810,
        'course_title' => 'Material Content Match Course',
    ]);

    $file = $this->createIndexedMaterialContent($course, 'The lecture examines cryodendron restoration methods.');

    $response = $this->get(route('search.index', [
        'query' => 'cryodendron',
    ]));

    $response->assertStatus(200);
    $response->assertSee('Material Content Match Course');
    $response->assertSee('Material Content:');
    $response->assertSee('lecture.pdf, Page 1');
    $response->assertSee(route('course.material.files.view', [
        'course' => $course->course_id,
        'material' => $file->course_material_id,
        'file' => $file->course_material_file_id,
    ]) . '#page=1', false);
    $response->assertSee('<mark>cryodendron</mark>', false);
}

public function test_material_content_search_only_returns_accessible_courses()
{
    $this->createCourseScaleCategory();

    $regularUser = User::factory()->create();
    $this->actingAs($regularUser);

    $accessibleCourse = $this->createSearchCourse('OPEN', 813, 'Accessible Material Content Course');
    $inaccessibleCourse = $this->createSearchCourse('HIDE', 814, 'Inaccessible Material Content Course');

    $this->giveUserDirectCourseAccess($regularUser, $accessibleCourse, 3);
    $this->createIndexedMaterialContent($accessibleCourse, 'The lecture examines verdantaccessium restoration methods.');
    $this->createIndexedMaterialContent($inaccessibleCourse, 'The lecture examines verdantaccessium restoration methods.');

    $response = $this->get(route('search.index', [
        'query' => 'verdantaccessium',
        'property_filters_applied' => 1,
        'properties' => ['material_content'],
    ]));

    $response->assertStatus(200);
    $this->assertSearchVisibility($response, [
        'Accessible Material Content Course',
    ], [
        'Inaccessible Material Content Course',
    ]);
}

public function test_material_content_is_not_searched_when_property_is_not_selected()
{
    $this->createCourseScaleCategory();

    $course = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 811,
        'course_title' => 'Excluded Material Content Course',
    ]);

    $this->createIndexedMaterialContent($course, 'The lecture examines luminara restoration methods.');

    $response = $this->get(route('search.index', [
        'query' => 'luminara',
        'property_filters_applied' => 1,
        'properties' => ['materials'],
    ]));

    $response->assertStatus(200);
    $response->assertDontSee('Excluded Material Content Course');
}

public function test_material_content_from_pending_file_is_not_searched()
{
    $this->createCourseScaleCategory();

    $course = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 812,
        'course_title' => 'Pending Material Content Course',
    ]);

    $file = $this->createIndexedMaterialContent($course, 'The lecture examines solavine restoration methods.');
    $file->update(['status' => CourseMaterialFile::STATUS_PENDING]);

    $response = $this->get(route('search.index', [
        'query' => 'solavine',
    ]));

    $response->assertStatus(200);
    $response->assertDontSee('Pending Material Content Course');
}

public function test_search_finds_course_by_assessment()
{
    $this->createCourseScaleCategory();

    $course = $this->createSearchCourse('TEST', 505, 'Assessment Match Course');

    AssessmentMethod::create([
        'course_id' => $course->course_id,
        'a_method' => 'Capstone xenolith analysis presentation',
        'weight' => 35,
        'pos_in_alignment' => 0,
    ]);

    $response = $this->get(route('search.index', [
        'query' => 'xenolith',
    ]));

    $response->assertStatus(200);
    $response->assertSee('Assessment Match Course');
    $response->assertSee('Assessment:');
    $response->assertSee('xenolith', false);
}

public function test_search_only_returns_course_with_matching_assessment()
{
    $this->createCourseScaleCategory();

    $matchingCourse = $this->createSearchCourse('TEST', 909, 'Assessment Only Match Course');

    $nonMatchingCourse = $this->createSearchCourse('TEST', 910, 'Assessment Non Match Course');

    AssessmentMethod::create([
        'course_id' => $matchingCourse->course_id,
        'a_method' => 'Mycelion policy memo and oral presentation',
        'weight' => 25,
        'pos_in_alignment' => 0,
    ]);

    AssessmentMethod::create([
        'course_id' => $nonMatchingCourse->course_id,
        'a_method' => 'Final exam and weekly participation',
        'weight' => 40,
        'pos_in_alignment' => 0,
    ]);

    $response = $this->get(route('search.index', [
        'query' => 'mycelion',
    ]));

    $response->assertStatus(200);
    $response->assertSee('Assessment Only Match Course');
    $response->assertSee('Assessment:');
    $response->assertSee('mycelion', false);
    $response->assertDontSee('Assessment Non Match Course');
}

public function test_topic_match_ranks_above_material_match()
{
    $this->createCourseScaleCategory();

    $topicCourse = $this->createSearchCourse('TEST', 111, 'Topic Match Course');

    $materialCourse = $this->createSearchCourse('TEST', 222, 'Material Match Course');

    $this->createCourseTopic($topicCourse, 'Zenthos climate adaptation');

    CourseMaterial::factory()->create([
        'course_id' => $materialCourse->course_id,
        'name' => 'Zenthos climate adaptation reading',
        'type' => 'article',
        'description' => 'Required material',
    ]);

    $response = $this->get(route('search.index', [
        'query' => 'zenthos',
    ]));

    $response->assertStatus(200);
    $response->assertSeeInOrder([
        'Topic Match Course',
        'Material Match Course',
    ]);
}



public function test_multiple_lower_weight_matches_can_outrank_single_topic_match()
{
    $this->createCourseScaleCategory();

    $topicCourse = $this->createSearchCourse('TEST', 333, 'Single Topic Course');

    $materialCourse = $this->createSearchCourse('TEST', 444, 'Multiple Material Matches Course');

    $this->createCourseTopic($topicCourse, 'Vorlan sustainability systems');

    for ($index = 0; $index < 6; $index++) {
        CourseMaterial::factory()->create([
            'course_id' => $materialCourse->course_id,
            'name' => "Vorlan reading package {$index}",
            'type' => 'article',
            'description' => 'Recommended material',
        ]);
    }

    $response = $this->get(route('search.index', [
        'query' => 'vorlan',
    ]));

    $response->assertStatus(200);
    $response->assertSeeInOrder([
        'Multiple Material Matches Course',
        'Single Topic Course',
    ]);
}

public function test_direct_course_match_ranks_above_higher_content_score()
{
    $this->createCourseScaleCategory();

    $this->createSearchCourse('CONS', 123, 'Direct Course Match');

    $contentCourse = $this->createSearchCourse('TEST', 555, 'High Content Match Course');

    $this->createCourseTopic($contentCourse, 'CONS123 policy topic');

    $this->createCourseTopic($contentCourse, 'CONS123 advanced topic');

    CourseMaterial::factory()->create([
        'course_id' => $contentCourse->course_id,
        'name' => 'CONS123 reading package',
        'type' => 'article',
        'description' => 'Required reading',
    ]);

    $response = $this->get(route('search.index', [
        'query' => 'CONS123',
    ]));

    $response->assertStatus(200);
    $response->assertSeeInOrder([
        'Direct Course Match',
        'High Content Match Course',
    ]);
}

public function test_search_stats_show_total_matches_by_property()
{
    $this->createCourseScaleCategory();

    $course = $this->createSearchCourse('TEST', 111, 'Stats Match Course');

    $this->createCourseTopic($course, 'Glacier adaptation topic one');

    $this->createCourseTopic($course, 'Glacier adaptation topic two');

    LearningOutcome::create([
        'course_id' => $course->course_id,
        'l_outcome' => 'Explain glacier adaptation planning.',
        'clo_shortphrase' => 'Explain glacier adaptation',
    ]);

    AssessmentMethod::create([
        'course_id' => $course->course_id,
        'a_method' => 'Glacier adaptation presentation',
        'weight' => 25,
        'pos_in_alignment' => 0,
    ]);

    DB::table('course_description')->insert([
        'course_id' => $course->course_id,
        'description' => 'This course covers glacier adaptation examples.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    CourseMaterial::factory()->create([
        'course_id' => $course->course_id,
        'name' => 'Glacier adaptation reading',
        'type' => 'article',
        'description' => 'Required material',
    ]);

    $response = $this->get(route('search.index', [
        'query' => 'glacier adaptation',
    ]));

    $response->assertStatus(200);
    $response->assertSee('Courses: 1');
    $response->assertSee('Topics: 2');
    $response->assertSee('Learning Objectives: 1');
    $response->assertSee('Assessments: 1');
    $response->assertSee('Descriptions: 1');
    $response->assertSee('Materials: 1');
}

public function test_material_content_match_is_included_in_search_statistics()
{
    $this->createCourseScaleCategory();

    $course = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 112,
        'course_title' => 'Material Content Stats Course',
    ]);

    $this->createIndexedMaterialContent($course, 'The uploaded document discusses statstone restoration.');

    $response = $this->get(route('search.index', [
        'query' => 'statstone',
    ]));

    $stats = $response->viewData('stats');
    $result = $response->viewData('results')->first();

    $response->assertStatus(200);
    $this->assertSame(1, $stats['material_content']);
    $this->assertSame(1, $result->match_stats['material_content']);
    $response->assertSee('Material Content: 1');
}

public function test_search_stats_count_distinct_courses_and_total_topic_matches()
{
    $this->createCourseScaleCategory();

    $firstCourse = $this->createSearchCourse('TEST', 222, 'First Stats Course');

    $secondCourse = $this->createSearchCourse('TEST', 333, 'Second Stats Course');

    $this->createCourseTopic($firstCourse, 'Hydrology climate topic one');

    $this->createCourseTopic($firstCourse, 'Hydrology climate topic two');

    $this->createCourseTopic($secondCourse, 'Hydrology climate topic three');

    $response = $this->get(route('search.index', [
        'query' => 'hydrology climate',
    ]));

    $response->assertStatus(200);
    $response->assertSee('Courses: 2');
    $response->assertSee('Topics: 3');
    $response->assertDontSee('Learning Objectives:');
    $response->assertDontSee('Assessments:');
    $response->assertDontSee('Descriptions:');
    $response->assertDontSee('Materials:');
}

public function test_course_result_shows_per_course_match_statistics()
{
    $this->createCourseScaleCategory();

    $course = $this->createSearchCourse('TEST', 555, 'Per Course Stats Course');

    $this->createCourseTopic($course, 'Watershed resilience planning topic one');

    $this->createCourseTopic($course, 'Watershed resilience planning topic two');

    CourseMaterial::factory()->create([
        'course_id' => $course->course_id,
        'name' => 'Watershed resilience article',
        'type' => 'article',
        'description' => 'Required material',
    ]);

    $response = $this->get(route('search.index', [
        'query' => 'watershed resilience',
    ]));

    $response->assertStatus(200);
    $response->assertSee('Per Course Stats Course');
    $response->assertSee('Found in:');
    $response->assertSee('Topics: 2');
    $response->assertSee('Materials: 1');
    $response->assertDontSee('Learning Objectives: 0');
    $response->assertDontSee('Assessments: 0');
    $response->assertDontSee('Descriptions: 0');
}

public function test_search_stats_show_zero_when_query_has_no_results()
{
    $this->createCourseScaleCategory();

    $this->createSearchCourse('TEST', 444, 'No Match Course');

    $response = $this->get(route('search.index', [
        'query' => 'nonexistentsearchterm',
    ]));

    $response->assertStatus(200);
    $response->assertSee('No matches found.');
    $response->assertDontSee('Courses: 0');
    $response->assertDontSee('Topics: 0');
    $response->assertDontSee('Learning Objectives: 0');
    $response->assertDontSee('Assessments: 0');
    $response->assertDontSee('Descriptions: 0');
    $response->assertDontSee('Materials: 0');
}

public function test_search_results_are_paginated_with_ten_courses_per_page()
{
    $this->createCourseScaleCategory();

    for ($index = 1; $index <= 11; $index++) {
        $course = $this->createSearchCourse('PAGE', 100 + $index, "Pagination Course {$index}");

        $this->createCourseTopic($course, 'Pagination testing topic');
    }

    $firstPage = $this->get(route('search.index', [
        'query' => 'pagination',
    ]));

    $firstPageResults = $firstPage->viewData('results');

    $firstPage->assertStatus(200);
    $this->assertCount(10, $firstPageResults);
    $this->assertSame(11, $firstPageResults->total());
    $this->assertStringContainsString('query=pagination', $firstPageResults->url(2));

    $secondPage = $this->get(route('search.index', [
        'query' => 'pagination',
        'page' => 2,
    ]));

    $secondPageResults = $secondPage->viewData('results');

    $secondPage->assertStatus(200);
    $this->assertCount(1, $secondPageResults);
    $this->assertSame(2, $secondPageResults->currentPage());
}

public function test_search_result_shows_the_course_program()
{
    $this->createCourseScaleCategory();

    $course = $this->createSearchCourse('TEST', 901, 'Program Display Course');

    $this->createCourseTopic($course, 'Astronomy program search topic');

    $programId = $this->createProgram('Astronomy Program');
    $this->attachCourseToProgram($course, $programId);
    $this->searchUser->programs()->attach($programId, ['permission' => 3]);

    $response = $this->get(route('search.index', [
        'query' => 'astronomy',
    ]));

    $response->assertStatus(200);
    $this->assertCount(0, $response->viewData('programMatches'));
    $response->assertSee('Astronomy Program');
    $response->assertSee(route('programWizard.step1', $programId));
}

public function test_search_stats_count_distinct_programs()
{
    $this->createCourseScaleCategory();

    $firstCourse = $this->createSearchCourse('TEST', 902, 'First Program Stats Course');

    $secondCourse = $this->createSearchCourse('TEST', 903, 'Second Program Stats Course');

    foreach ([$firstCourse, $secondCourse] as $course) {
        $this->createCourseTopic($course, 'Geophysics program statistics topic');
    }

    $firstProgramId = $this->createProgram('First Geophysics Program');
    $secondProgramId = $this->createProgram('Second Geophysics Program');

    DB::table('course_programs')->insert([
        [
            'course_id' => $firstCourse->course_id,
            'program_id' => $firstProgramId,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'course_id' => $secondCourse->course_id,
            'program_id' => $firstProgramId,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'course_id' => $secondCourse->course_id,
            'program_id' => $secondProgramId,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $response = $this->get(route('search.index', [
        'query' => 'geophysics',
    ]));

    $response->assertStatus(200);
    $response->assertSee('Courses: 2');
    $response->assertSee('Programs: 2');
}

public function test_search_finds_program_directly_by_name()
{
    $matchingProgramId = $this->createProgram('Quasar Studies');
    $this->searchUser->programs()->attach($matchingProgramId, ['permission' => 3]);

    DB::table('programs')->insert([
        'program' => 'Marine Biology',
        'level' => 'Bachelors',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->get(route('search.index', [
        'query' => 'quasar',
        'view' => 'programs',
    ]));

    $programMatches = $response->viewData('programMatches');

    $response->assertStatus(200);
    $this->assertCount(1, $programMatches);
    $this->assertSame($matchingProgramId, $programMatches->first()->program_id);
    $this->assertSame('Quasar Studies', $programMatches->first()->matched_text);
    $this->assertStringContainsString('<mark>Quasar</mark>', $programMatches->first()->snippet);
    $response->assertSee('<mark>Quasar</mark>', false);
    $response->assertSee(route('programWizard.step1', $matchingProgramId));
}

public function test_search_groups_matching_courses_under_their_program()
{
    $this->createCourseScaleCategory();

    $programId = $this->createProgram('Quantum Studies');

    $course = $this->createSearchCourse('TEST', 904, 'Quantum Course');

    $this->createCourseTopic($course, 'Quantum systems and applications');

    $unassignedCourse = $this->createSearchCourse('TEST', 905, 'Unassigned Quantum Course');

    $this->createCourseTopic($unassignedCourse, 'Quantum theory without a program assignment');

    $this->attachCourseToProgram($course, $programId);

    $response = $this->get(route('search.index', [
        'query' => 'quantum',
        'view' => 'programs',
    ]));

    $programResults = $response->viewData('programResults');

    $response->assertStatus(200);
    $this->assertCount(1, $programResults);
    $this->assertTrue($programResults->first()->is_program_match);
    $this->assertCount(1, $programResults->first()->courses);
    $this->assertSame($course->course_id, $programResults->first()->courses->first()->course_id);
    $response->assertSee('Matching courses: 1');
    $response->assertSee('Courses: 1');
    $response->assertDontSee('Unassigned Quantum Course');
    $response->assertSee(route('courseWizard.step8', $course->course_id));
}

public function test_program_search_results_are_paginated_with_ten_programs_per_page()
{
    $programs = [];

    for ($index = 1; $index <= 11; $index++) {
        $programs[] = [
            'program' => "Aetherium Program {$index}",
            'level' => 'Bachelors',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    DB::table('programs')->insert($programs);

    $firstPage = $this->get(route('search.index', [
        'query' => 'aetherium',
        'view' => 'programs',
    ]));

    $firstPageResults = $firstPage->viewData('programResults');

    $firstPage->assertStatus(200);
    $this->assertSame(11, $firstPageResults->total());
    $this->assertCount(10, $firstPageResults);
    $this->assertTrue($firstPageResults->hasPages());

    $secondPage = $this->get(route('search.index', [
        'query' => 'aetherium',
        'view' => 'programs',
        'page' => 2,
    ]));

    $secondPage->assertStatus(200);
    $this->assertCount(1, $secondPage->viewData('programResults'));
}

public function test_search_defaults_to_all_properties()
{
    $this->createCourseScaleCategory();

    $topicCourse = $this->createSearchCourse('TEST', 910, 'Default Topic Filter Course');

    $descriptionCourse = $this->createSearchCourse('TEST', 911, 'Default Description Filter Course');

    $this->createCourseTopic($topicCourse, 'Filterium topic applications');

    DB::table('course_description')->insert([
        'course_id' => $descriptionCourse->course_id,
        'description' => 'This description examines filterium in curriculum design.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->get(route('search.index', [
        'query' => 'filterium',
    ]));

    $response->assertStatus(200);
    $response->assertSee('Default Topic Filter Course');
    $response->assertSee('Default Description Filter Course');
    $response->assertViewHas('selectedProperties', [
        'course',
        'topics',
        'learning_outcomes',
        'assessments',
        'descriptions',
        'materials',
        'material_content',
    ]);
}

public function test_topic_only_filter_excludes_description_matches()
{
    $this->createCourseScaleCategory();

    $topicCourse = $this->createSearchCourse('TEST', 912, 'Topic Only Filter Course');

    $descriptionCourse = $this->createSearchCourse('TEST', 913, 'Excluded Description Filter Course');

    $this->createCourseTopic($topicCourse, 'Filterium topic applications');

    DB::table('course_description')->insert([
        'course_id' => $descriptionCourse->course_id,
        'description' => 'This description examines filterium in curriculum design.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->get(route('search.index', [
        'query' => 'filterium',
        'property_filters_applied' => 1,
        'properties' => ['topics'],
    ]));

    $response->assertStatus(200);
    $response->assertSee('Topic Only Filter Course');
    $response->assertDontSee('Excluded Description Filter Course');
    $response->assertViewHas('selectedProperties', ['topics']);
}

public function test_multiple_property_filters_work_together()
{
    $this->createCourseScaleCategory();

    $topicCourse = $this->createSearchCourse('TEST', 914, 'Multiple Topic Filter Course');

    $descriptionCourse = $this->createSearchCourse('TEST', 915, 'Multiple Description Filter Course');

    $this->createCourseTopic($topicCourse, 'Filterium topic applications');

    DB::table('course_description')->insert([
        'course_id' => $descriptionCourse->course_id,
        'description' => 'This description examines filterium in curriculum design.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->get(route('search.index', [
        'query' => 'filterium',
        'property_filters_applied' => 1,
        'properties' => ['topics', 'descriptions'],
    ]));

    $response->assertStatus(200);
    $response->assertSee('Multiple Topic Filter Course');
    $response->assertSee('Multiple Description Filter Course');
    $response->assertViewHas('selectedProperties', ['topics', 'descriptions']);
}

public function test_course_code_filter_excludes_other_course_codes()
{
    $this->createCourseScaleCategory();

    $consCourse = $this->createSearchCourse('CONS', 221, 'Selected Code Course');

    $frstCourse = $this->createSearchCourse('FRST', 222, 'Excluded Code Course');

    $this->createCourseTopic($consCourse, 'Codefilterium conservation methods');

    $this->createCourseTopic($frstCourse, 'Codefilterium forestry methods');

    $response = $this->get(route('search.index', [
        'query' => 'codefilterium',
        'course_filters_applied' => 1,
        'course_codes' => ['CONS'],
    ]));

    $response->assertStatus(200);
    $response->assertSee('Selected Code Course');
    $response->assertDontSee('Excluded Code Course');
}

public function test_multiple_course_code_filters_return_selected_codes()
{
    $this->createCourseScaleCategory();

    $consCourse = $this->createSearchCourse('CONS', 321, 'Selected CONS Course');

    $frstCourse = $this->createSearchCourse('FRST', 322, 'Selected FRST Course');

    $bestCourse = $this->createSearchCourse('BEST', 323, 'Excluded BEST Course');

    foreach ([$consCourse, $frstCourse, $bestCourse] as $course) {
        $this->createCourseTopic($course, 'Multicodefilterium search topic');
    }

    $response = $this->get(route('search.index', [
        'query' => 'multicodefilterium',
        'course_filters_applied' => 1,
        'course_codes' => ['CONS', 'FRST'],
    ]));

    $response->assertStatus(200);
    $response->assertViewHas('selectedCourseCodes', ['CONS', 'FRST']);
    $response->assertSee('Selected CONS Course');
    $response->assertSee('Selected FRST Course');
    $response->assertDontSee('Excluded BEST Course');
}



public function test_course_level_filter_excludes_other_levels()
{
    $this->createCourseScaleCategory();

    $lowerLevelCourse = $this->createSearchCourse('FRST', 150, 'Excluded Lower Level Course');

    $selectedLevelCourse = $this->createSearchCourse('FRST', 350, 'Selected Upper Level Course');

    $this->createCourseTopic($lowerLevelCourse, 'Levelfilterium introductory methods');

    $this->createCourseTopic($selectedLevelCourse, 'Levelfilterium advanced methods');

    $response = $this->get(route('search.index', [
        'query' => 'levelfilterium',
        'course_filters_applied' => 1,
        'course_levels' => ['300'],
    ]));

    $response->assertStatus(200);
    $response->assertSee('Selected Upper Level Course');
    $response->assertDontSee('Excluded Lower Level Course');
}

public function test_all_course_levels_does_not_restrict_results()
{
    $this->createCourseScaleCategory();

    $lowerLevelCourse = $this->createSearchCourse('CONS', 151, 'All Levels Lower Course');

    $upperLevelCourse = $this->createSearchCourse('CONS', 351, 'All Levels Upper Course');

    $this->createCourseTopic($lowerLevelCourse, 'Alllevelium introductory methods');

    $this->createCourseTopic($upperLevelCourse, 'Alllevelium advanced methods');

    $response = $this->get(route('search.index', [
        'query' => 'alllevelium',
        'course_filters_applied' => 1,
        'course_levels' => [''],
    ]));

    $response->assertStatus(200);
    $response->assertSee('All Levels Lower Course');
    $response->assertSee('All Levels Upper Course');
}

public function test_course_code_and_level_filters_work_together()
{
    $this->createCourseScaleCategory();

    $lowerConsCourse = $this->createSearchCourse('CONS', 152, 'Excluded CONS Lower Course');

    $upperConsCourse = $this->createSearchCourse('CONS', 352, 'Selected CONS Upper Course');

    $upperFrstCourse = $this->createSearchCourse('FRST', 352, 'Excluded FRST Upper Course');

    foreach ([$lowerConsCourse, $upperConsCourse, $upperFrstCourse] as $course) {
        $this->createCourseTopic($course, 'Combinedfilterium course methods');
    }

    $response = $this->get(route('search.index', [
        'query' => 'combinedfilterium',
        'course_filters_applied' => 1,
        'course_codes' => ['CONS'],
        'course_levels' => ['300'],
    ]));

    $response->assertStatus(200);
    $response->assertSee('Selected CONS Upper Course');
    $response->assertDontSee('Excluded CONS Lower Course');
    $response->assertDontSee('Excluded FRST Upper Course');
}

public function test_selected_course_level_is_preserved_and_displayed()
{
    $response = $this->get(route('search.index', [
        'course_filters_applied' => 1,
        'course_levels' => ['300'],
    ]));

    $response->assertStatus(200);
    $response->assertViewHas('selectedCourseLevels', ['300']);
    $this->assertMatchesRegularExpression(
        '/<input[^>]+name="course_levels\[\]"[^>]+id="courseLevel-300"[^>]+value="300"[^>]+checked/s',
        $response->getContent()
    );
    $response->assertSee('Levels: 300');
}

public function test_program_filter_only_returns_courses_in_selected_program()
{
    $selectedProgramId = $this->createProgram('Forest Sciences');
    $otherProgramId = $this->createProgram('Bioeconomy Sciences');

    $matchingCourse = $this->createSearchCourse('FRST', 302, 'Forest Genetics');

    $excludedCourse = $this->createSearchCourse('BEST', 300, 'Climate Adaptation');

    $this->attachCourseToProgram($matchingCourse, $selectedProgramId);
    $this->attachCourseToProgram($excludedCourse, $otherProgramId);

    $this->createCourseTopic($matchingCourse, 'climate adaptation in forest ecosystems');

    $this->createCourseTopic($excludedCourse, 'climate adaptation in forest ecosystems');

    $response = $this->get(route('search.index', [
        'query' => 'climate adaptation',
        'program_filters_applied' => 1,
        'program_ids' => [$selectedProgramId],
    ]));

    $response->assertStatus(200);
    $response->assertSee('Forest Genetics');
    $response->assertDontSee('Climate Adaptation');


}

public function test_program_filter_only_groups_course_under_selected_program()
{
    $selectedProgramId = $this->createProgram('Selected Forest Program');
    $otherProgramId = $this->createProgram('Unselected Climate Program');

    $course = $this->createSearchCourse('FRST', 303, 'Climate Resilient Forests');

    $this->attachCourseToProgram($course, $selectedProgramId);
    $this->attachCourseToProgram($course, $otherProgramId);



    $this->createCourseTopic($course, 'Climate resilience in managed forests');

    $parameters = [
        'query' => 'climate',
        'program_filters_applied' => 1,
        'program_ids' => [$selectedProgramId],
    ];

    
    $courseResponse = $this->get(route('search.index', $parameters));
    $courseResult = $courseResponse->viewData('results')->first();

    $courseResponse->assertStatus(200);
    $this->assertCount(2, $courseResult->programs);

    $programResponse = $this->get(route('search.index', [
        ...$parameters,
        'view' => 'programs',
    ]));
    $programResults = $programResponse->viewData('programResults');

    $programResponse->assertStatus(200);
    $this->assertCount(1, $programResults);
    $this->assertSame($selectedProgramId, $programResults->first()->program_id);
    $this->assertCount(1, $programResults->first()->courses);
    $this->assertSame($course->course_id, $programResults->first()->courses->first()->course_id);
}

public function test_authenticated_user_can_save_search_filter(): void{
    $user = User::factory()->create();

    $response = $this->actingAs($user)->from(route('search.index'))->post(route('search.filters.store'), [
        'name' => 'Forestry Search',
        'view' => 'programs',
        'property_filters_applied' => 1,
        'properties' => ['topics', 'materials'],
        'course_codes' => ['frst', 'CONS'],
        'course_levels' => ['300'],
        'program_ids' => [],

    ]);

    $response->assertStatus(302);
    $this->assertStringStartsWith(route('search.index'), $response->headers->get('Location'));
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('saved_search_filters', [
        'user_id' => $user->id,
        'name' => 'Forestry Search',

    ]);

    $savedFilter = $user->savedSearchFilters()->where('name', 'Forestry Search')->firstOrFail();

    $this->assertEquals([
        'view' => 'programs',
        'properties' => ['topics', 'materials'],
        'course_codes' => ['FRST', 'CONS'],
        'course_levels' => ['300'],
        'program_ids' => [],
    ], $savedFilter->filters);
}

public function test_authenticated_user_can_apply_saved_search_filter(): void
{
    $user = User::factory()->create();
    $programId = $this->createProgram('Forest Sciences');

    $savedFilter = $user->savedSearchFilters()->create([
        'name' => 'Forest Program Preset',
        'filters' => [
            'view' => 'programs',
            'properties' => ['topics', 'materials'],
            'course_codes' => ['FRST'],
            'course_levels' => ['300'],
            'program_ids' => [$programId],
        ],
    ]);

    $response = $this->actingAs($user)->get(route('search.filters.apply', [
        'savedFilterId' => $savedFilter->id,
        'query' => 'climate adaptation',
    ]));

    $response->assertRedirect(route('search.index', [
        'query' => 'climate adaptation',
        'saved_filter_id' => $savedFilter->id,
        'view' => 'programs',
        'property_filters_applied' => 1,
        'properties' => ['topics', 'materials'],
        'course_filters_applied' => 1,
        'course_codes' => ['FRST'],
        'course_levels' => ['300'],
        'program_filters_applied' => 1,
        'program_ids' => [$programId],
    ]));
    $response->assertSessionHas('success', 'Filter preset applied.');
    $response->assertSessionHas('preset_applied', true);
}

public function test_authenticated_user_cannot_apply_another_users_saved_search_filter(): void
{
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $savedFilter = $owner->savedSearchFilters()->create([
        'name' => 'Private Preset',
        'filters' => [],
    ]);

    $response = $this->actingAs($otherUser)->get(route('search.filters.apply', [
        'savedFilterId' => $savedFilter->id,
    ]));

    $response->assertNotFound();
}

public function test_authenticated_user_can_delete_saved_search_filter(): void
{
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $savedFilter = $user->savedSearchFilters()->create([
        'name' => 'Delete Me',
        'filters' => [
            'view' => 'courses',
            'properties' => ['topics'],
            'course_codes' => [],
            'course_levels' => [],
            'program_ids' => [],
        ],
    ]);

    $otherUserFilter = $otherUser->savedSearchFilters()->create([
        'name' => 'Keep Me',
        'filters' => [
            'view' => 'programs',
            'properties' => ['materials'],
            'course_codes' => ['CONS'],
            'course_levels' => ['400'],
            'program_ids' => [],
        ],
    ]);

    $response = $this->actingAs($user)->from(route('search.index'))->delete(route('search.filters.destroy', [
        'savedFilterId' => $savedFilter->id,
    ]));

    $response->assertRedirect(route('search.index'));
    $response->assertSessionHas('success', 'Saved search filter deleted successfully.');

    $this->assertDatabaseMissing('saved_search_filters', [
        'id' => $savedFilter->id,
        'user_id' => $user->id,
    ]);
    $this->assertDatabaseHas('saved_search_filters', [
        'id' => $otherUserFilter->id,
        'user_id' => $otherUser->id,
    ]);
}

public function test_authenticated_user_cannot_delete_another_users_saved_search_filter(): void
{
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $savedFilter = $owner->savedSearchFilters()->create([
        'name' => 'Keep Private Preset',
        'filters' => [],
    ]);

    $response = $this->actingAs($otherUser)->delete(route('search.filters.destroy', [
        'savedFilterId' => $savedFilter->id,
    ]));

    $response->assertNotFound();
    $this->assertDatabaseHas('saved_search_filters', [
        'id' => $savedFilter->id,
        'user_id' => $owner->id,
    ]);
}

public function test_applied_saved_filter_is_shown_as_the_current_preset(): void
{
    $user = User::factory()->create();
    $this->createCourseScaleCategory();
    $course = $this->createSearchCourse('FRST', 300, 'Climate Forestry');
    $this->createCourseTopic($course, 'climate adaptation');
    $savedFilter = $user->savedSearchFilters()->create([
        'name' => 'Forestry Topics',
        'filters' => [
            'view' => 'courses',
            'properties' => ['topics'],
            'course_codes' => [],
            'course_levels' => [],
            'program_ids' => [],
        ],
    ]);

    $applyResponse = $this->actingAs($user)->get(route('search.filters.apply', [
        'savedFilterId' => $savedFilter->id,
        'query' => 'climate',
    ]));
    $applyResponse->assertSessionHas('success', 'Filter preset applied.');

    $response = $this->get($applyResponse->headers->get('Location'));
    $response->assertStatus(200);
    $response->assertSee('Current preset:');
    $response->assertSee('Forestry Topics');
    $response->assertDontSee('Climate Forestry');
    $response->assertViewHas('searchPerformed', false);
    $response->assertDontSee('No matches found.');
}

public function test_applied_course_filters_show_correct_programs_in_program_view(){

    $selectedProgramId = $this->createProgram('Terraria Program');
    $otherProgramId = $this->createProgram('Different terraria program');

    $matchingFilterCourse = $this->createSearchCourse('FRST', 303, 'Climate Resilient');

    $this->attachCourseToProgram($matchingFilterCourse, $selectedProgramId);

    $excludedFilterCourse = $this->createSearchCourse('CONS', 303, 'Climate Resilient');

    $this->attachCourseToProgram($excludedFilterCourse, $otherProgramId);

    $parameters = [
        'view' => 'courses',
        'query' => 'terraria',
        'course_filters_applied' => 1,
        'course_codes' => ['FRST'],
    ];

    $programResponse = $this->get(route('search.index', [
        ...$parameters,
        'view' => 'programs',
    ]));
    $programResults = $programResponse->viewData('programResults');

    $programResponse->assertStatus(200);
    $this->assertCount(1, $programResults);
    $this->assertSame($selectedProgramId, $programResults->first()->program_id);
    $this->assertSame('Terraria Program', $programResults->first()->program);
    $this->assertCount(0, $programResults->first()->courses);
    

}

}

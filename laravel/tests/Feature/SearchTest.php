<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\AssessmentMethod;
use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\CourseTopic;
use App\Models\LearningOutcome;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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

        $accessibleCourse = Course::factory()->create([
            'course_code' => 'OPEN',
            'course_num' => 101,
            'course_title' => 'Accessium Visible Course',
        ]);
        $otherAccessibleCourse = Course::factory()->create([
            'course_code' => 'EDIT',
            'course_num' => 102,
            'course_title' => 'Accessium Editor Course',
        ]);

        Course::factory()->create([
            'course_code' => 'HIDE',
            'course_num' => 202,
            'course_title' => 'Accessium Hidden Course',
        ]);

        $this->giveUserDirectCourseAccess($regularUser, $accessibleCourse, 3);
        $this->giveUserDirectCourseAccess($regularUser, $otherAccessibleCourse, 2);

        $response = $this->get(route('search.index', [
            'query' => 'accessium',
        ]));

        $response->assertStatus(200);
        $response->assertSee('Accessium Visible Course');
        $response->assertSee('Accessium Editor Course');
        $response->assertDontSee('Accessium Hidden Course');
        $response->assertSee('Courses: 2');
        $this->assertSame(['EDIT', 'OPEN'], $response->viewData('availableCourseCodes'));
    }

    public function test_admin_can_search_courses_without_direct_access()
    {
        $this->createCourseScaleCategory();

        Course::factory()->create([
            'course_code' => 'ADMN',
            'course_num' => 303,
            'course_title' => 'Adminium Visible Course',
        ]);

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

        $directedProgramId = DB::table('programs')->insertGetId([
            'program' => 'Visible Director Program',
            'level' => 'Bachelors',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'program_id');

        $otherProgramId = DB::table('programs')->insertGetId([
            'program' => 'Hidden Director Program',
            'level' => 'Bachelors',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'program_id');

        $visibleCourse = Course::factory()->create([
            'course_code' => 'PDIR',
            'course_num' => 301,
            'course_title' => 'Directorium Visible Course',
        ]);

        $hiddenCourse = Course::factory()->create([
            'course_code' => 'HIDE',
            'course_num' => 302,
            'course_title' => 'Directorium Hidden Course',
        ]);

        DB::table('course_programs')->insert([
            [
                'course_id' => $visibleCourse->course_id,
                'program_id' => $directedProgramId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'course_id' => $hiddenCourse->course_id,
                'program_id' => $otherProgramId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->giveUserProgramDirectorAccess($programDirector, $directedProgramId);

        $response = $this->get(route('search.index', [
            'query' => 'directorium',
        ]));

        $response->assertStatus(200);
        $response->assertSee('Directorium Visible Course');
        $response->assertDontSee('Directorium Hidden Course');
        $response->assertSee('Courses: 1');
    }

    public function test_program_director_keeps_direct_course_access_outside_directed_program()
    {
        $this->createCourseScaleCategory();

        $programDirector = User::factory()->create();
        $this->assignRoleToUser($programDirector, 'program director');
        $this->actingAs($programDirector);

        $directedProgramId = DB::table('programs')->insertGetId([
            'program' => 'Director Access Program',
            'level' => 'Bachelors',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'program_id');

        $programCourse = Course::factory()->create([
            'course_code' => 'PDIR',
            'course_num' => 401,
            'course_title' => 'Accessblend Program Course',
        ]);

        $directCourse = Course::factory()->create([
            'course_code' => 'OPEN',
            'course_num' => 402,
            'course_title' => 'Accessblend Direct Course',
        ]);

        Course::factory()->create([
            'course_code' => 'HIDE',
            'course_num' => 403,
            'course_title' => 'Accessblend Hidden Course',
        ]);

        DB::table('course_programs')->insert([
            'course_id' => $programCourse->course_id,
            'program_id' => $directedProgramId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->giveUserProgramDirectorAccess($programDirector, $directedProgramId);
        $this->giveUserDirectCourseAccess($programDirector, $directCourse, 3);

        $response = $this->get(route('search.index', [
            'query' => 'accessblend',
        ]));

        $response->assertStatus(200);
        $response->assertSee('Accessblend Program Course');
        $response->assertSee('Accessblend Direct Course');
        $response->assertDontSee('Accessblend Hidden Course');
        $response->assertSee('Courses: 2');
    }

    public function test_program_director_filter_options_only_show_accessible_courses_and_programs()
    {
        $this->createCourseScaleCategory();

        $programDirector = User::factory()->create();
        $this->actingAs($programDirector);

        $directedProgramId = $this->createProgram('Visible Filter Program');
        $otherProgramId = $this->createProgram('Hidden Filter Program');

        $visibleCourse = Course::factory()->create([
            'course_code' => 'PDIR',
            'course_num' => 301,
            'course_title' => 'Filter Option Visible Course',
        ]);
        $hiddenCourse = Course::factory()->create([
            'course_code' => 'HIDE',
            'course_num' => 302,
            'course_title' => 'Filter Option Hidden Course',
        ]);

        $this->attachCourseToProgram($visibleCourse, $directedProgramId);
        $this->attachCourseToProgram($hiddenCourse, $otherProgramId);
        $this->giveUserProgramDirectorAccess($programDirector, $directedProgramId);

        $response = $this->get(route('search.index'));
        $availablePrograms = $response->viewData('availablePrograms')->pluck('program')->all();

        $response->assertStatus(200);
        $this->assertSame(['PDIR'], $response->viewData('availableCourseCodes'));
        $this->assertSame(['Visible Filter Program'], $availablePrograms);
    }

    public function test_program_director_direct_program_match_requires_accessible_course()
    {
        $this->createCourseScaleCategory();

        $programDirector = User::factory()->create();
        $this->actingAs($programDirector);

        $directedProgramId = $this->createProgram('Terraria Visible Program');
        $otherProgramId = $this->createProgram('Terraria Hidden Program');

        $visibleCourse = Course::factory()->create([
            'course_code' => 'PDIR',
            'course_num' => 401,
            'course_title' => 'Program Match Visible Course',
        ]);
        $hiddenCourse = Course::factory()->create([
            'course_code' => 'HIDE',
            'course_num' => 402,
            'course_title' => 'Program Match Hidden Course',
        ]);

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
        $response->assertSee('Terraria Visible Program');
        $response->assertDontSee('Terraria Hidden Program');
    }

    public function test_department_head_can_search_courses_with_role_and_direct_access()
    {
        $this->createCourseScaleCategory();

        $departmentHead = User::factory()->create();
        $this->actingAs($departmentHead);

        $accessibleCourse = Course::factory()->create([
            'course_code' => 'DHED',
            'course_num' => 301,
            'course_title' => 'Headium Visible Course',
        ]);
        $directCourse = Course::factory()->create([
            'course_code' => 'OPEN',
            'course_num' => 302,
            'course_title' => 'Headium Direct Course',
        ]);
        Course::factory()->create([
            'course_code' => 'HIDE',
            'course_num' => 303,
            'course_title' => 'Headium Hidden Course',
        ]);

        $this->giveUserDepartmentHeadCourseAccess($departmentHead, $accessibleCourse);
        $this->giveUserDirectCourseAccess($departmentHead, $directCourse, 3);

        $response = $this->get(route('search.index', [
            'query' => 'headium',
        ]));

        $response->assertStatus(200);
        $response->assertSee('Headium Visible Course');
        $response->assertSee('Headium Direct Course');
        $response->assertDontSee('Headium Hidden Course');
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

        $accessibleCourse = Course::factory()->create([
            'course_code' => 'DHED',
            'course_num' => 401,
            'course_title' => 'Department Program Visible Course',
        ]);
        $hiddenCourse = Course::factory()->create([
            'course_code' => 'HIDE',
            'course_num' => 402,
            'course_title' => 'Department Program Hidden Course',
        ]);

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
        $response->assertSee('Terraria Department Program');
        $response->assertDontSee('Terraria Hidden Department Program');
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

    public function test_search_finds_course_by_compact_course_code(){
        $this->createCourseScaleCategory();

        Course::factory()->create([
            'course_code' => 'CONS',
            'course_num' => 123,
            'course_title' => 'Compact Code Match Course',
        ]);

        $response = $this->get(route('search.index', [
            'query' => 'CONS123',
        ]));

        $response->assertStatus(200);
        $response->assertSee('Compact Code Match Course');
        $response->assertSee('<mark>CONS</mark>', false);
        $response->assertDontSee('<strong>Course:</strong>', false);
    }

    public function test_search_finds_course_by_course_title(){
        $this->createCourseScaleCategory();

        Course::factory()->create([
            'course_code' => 'FRST',
            'course_num' => 321,
            'course_title' => 'Auralith Forest Policy',
        ]);

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

        Course::factory()->create([
            'course_code' => 'CONS',
            'course_num' => 123,
            'course_title' => 'Actual Course Match',
        ]);

        $contentOnlyCourse = Course::factory()->create([
            'course_code' => 'FRST',
            'course_num' => 456,
            'course_title' => 'Description Mention Course',
        ]);

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

    private function createProgram(string $programName): int
    {
        return DB::table('programs')->insertGetId([
            'program' => $programName,
            'level' => 'Bachelors',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'program_id');
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


    //Search Topics Querying tests
    public function test_search_finds_course_by_topic(){
        $this->createCourseScaleCategory();

        $course = Course::factory()->create([
            'course_code' => 'TEST',
            'course_num' => 101,
            'course_title' => 'Test Course',
        ]);

        CourseTopic::factory()->create([
            'course_id' => $course->course_id,
            'topic' => 'Climate change adaptaion of something something'
        ]);

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

    $course = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 101,
        'course_title' => 'Nonmatching Sentence',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $course->course_id,
        'topic' => 'Forest ecology and biodiversity',
    ]);

    $response = $this->get(route('search.index', [
        'query' => 'climate change',
    ]));

    $response->assertStatus(200);
    $response->assertDontSee('Nonmatching Sentence');
}

public function test_search_only_returns_course_with_matching_topic()
{
    $this->createCourseScaleCategory();

    $matchingCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 101,
        'course_title' => 'Matching Course',
    ]);

    $nonMatchingCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 202,
        'course_title' => 'Non Matching Course',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $matchingCourse->course_id,
        'topic' => 'Climate change adaptation strategies',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $nonMatchingCourse->course_id,
        'topic' => 'Forest inventory and timber supply',
    ]);

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

    $course = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 101,
        'course_title' => 'Climate Course',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $course->course_id,
        'topic' => 'Climate change adaptation strategies',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $course->course_id,
        'topic' => 'Climate change impacts on forests',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $course->course_id,
        'topic' => 'Soil classification methods',
    ]);

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

    $course = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 303,
        'course_title' => 'Description Match Course',
    ]);

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

    $matchingCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 606,
        'course_title' => 'Learning Objective Match Course',
    ]);

    $nonMatchingCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 607,
        'course_title' => 'Learning Objective Non Match Course',
    ]);

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

    $matchingCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 707,
        'course_title' => 'Description Only Match Course',
    ]);

    $nonMatchingCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 708,
        'course_title' => 'Description Non Match Course',
    ]);

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

    $course = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 404,
        'course_title' => 'Material Match Course',
    ]);

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

    $matchingCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 808,
        'course_title' => 'Material Only Match Course',
    ]);

    $nonMatchingCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 809,
        'course_title' => 'Material Non Match Course',
    ]);

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

public function test_search_finds_course_by_assessment()
{
    $this->createCourseScaleCategory();

    $course = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 505,
        'course_title' => 'Assessment Match Course',
    ]);

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

    $matchingCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 909,
        'course_title' => 'Assessment Only Match Course',
    ]);

    $nonMatchingCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 910,
        'course_title' => 'Assessment Non Match Course',
    ]);

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

    $topicCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 111,
        'course_title' => 'Topic Match Course',
    ]);

    $materialCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 222,
        'course_title' => 'Material Match Course',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $topicCourse->course_id,
        'topic' => 'Zenthos climate adaptation',
    ]);

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

    $topicCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 333,
        'course_title' => 'Single Topic Course',
    ]);

    $materialCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 444,
        'course_title' => 'Multiple Material Matches Course',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $topicCourse->course_id,
        'topic' => 'Vorlan sustainability systems',
    ]);

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

    Course::factory()->create([
        'course_code' => 'CONS',
        'course_num' => 123,
        'course_title' => 'Direct Course Match',
    ]);

    $contentCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 555,
        'course_title' => 'High Content Match Course',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $contentCourse->course_id,
        'topic' => 'CONS123 policy topic',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $contentCourse->course_id,
        'topic' => 'CONS123 advanced topic',
    ]);

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

    $course = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 111,
        'course_title' => 'Stats Match Course',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $course->course_id,
        'topic' => 'Glacier adaptation topic one',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $course->course_id,
        'topic' => 'Glacier adaptation topic two',
    ]);

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

public function test_search_stats_count_distinct_courses_and_total_topic_matches()
{
    $this->createCourseScaleCategory();

    $firstCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 222,
        'course_title' => 'First Stats Course',
    ]);

    $secondCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 333,
        'course_title' => 'Second Stats Course',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $firstCourse->course_id,
        'topic' => 'Hydrology climate topic one',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $firstCourse->course_id,
        'topic' => 'Hydrology climate topic two',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $secondCourse->course_id,
        'topic' => 'Hydrology climate topic three',
    ]);

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

    $course = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 555,
        'course_title' => 'Per Course Stats Course',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $course->course_id,
        'topic' => 'Watershed resilience planning topic one',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $course->course_id,
        'topic' => 'Watershed resilience planning topic two',
    ]);

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

    Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 444,
        'course_title' => 'No Match Course',
    ]);

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
        $course = Course::factory()->create([
            'course_code' => 'PAGE',
            'course_num' => 100 + $index,
            'course_title' => "Pagination Course {$index}",
        ]);

        CourseTopic::factory()->create([
            'course_id' => $course->course_id,
            'topic' => 'Pagination testing topic',
        ]);
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

    $course = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 901,
        'course_title' => 'Program Display Course',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $course->course_id,
        'topic' => 'Astronomy program search topic',
    ]);

    $programId = DB::table('programs')->insertGetId([
        'program' => 'Astronomy Program',
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

    $response = $this->get(route('search.index', [
        'query' => 'astronomy',
    ]));

    $response->assertStatus(200);
    $response->assertSee('Astronomy Program');
    $response->assertSee(route('programWizard.step1', $programId));
}

public function test_search_stats_count_distinct_programs()
{
    $this->createCourseScaleCategory();

    $firstCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 902,
        'course_title' => 'First Program Stats Course',
    ]);

    $secondCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 903,
        'course_title' => 'Second Program Stats Course',
    ]);

    foreach ([$firstCourse, $secondCourse] as $course) {
        CourseTopic::factory()->create([
            'course_id' => $course->course_id,
            'topic' => 'Geophysics program statistics topic',
        ]);
    }

    $firstProgramId = DB::table('programs')->insertGetId([
        'program' => 'First Geophysics Program',
        'level' => 'Bachelors',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], 'program_id');

    $secondProgramId = DB::table('programs')->insertGetId([
        'program' => 'Second Geophysics Program',
        'level' => 'Bachelors',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], 'program_id');

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
    $matchingProgramId = DB::table('programs')->insertGetId([
        'program' => 'Quasar Studies',
        'level' => 'Bachelors',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], 'program_id');

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

    $programId = DB::table('programs')->insertGetId([
        'program' => 'Quantum Studies',
        'level' => 'Bachelors',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], 'program_id');

    $course = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 904,
        'course_title' => 'Quantum Course',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $course->course_id,
        'topic' => 'Quantum systems and applications',
    ]);

    $unassignedCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 905,
        'course_title' => 'Unassigned Quantum Course',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $unassignedCourse->course_id,
        'topic' => 'Quantum theory without a program assignment',
    ]);

    DB::table('course_programs')->insert([
        'course_id' => $course->course_id,
        'program_id' => $programId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

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

    $topicCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 910,
        'course_title' => 'Default Topic Filter Course',
    ]);

    $descriptionCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 911,
        'course_title' => 'Default Description Filter Course',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $topicCourse->course_id,
        'topic' => 'Filterium topic applications',
    ]);

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
    ]);
}

public function test_topic_only_filter_excludes_description_matches()
{
    $this->createCourseScaleCategory();

    $topicCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 912,
        'course_title' => 'Topic Only Filter Course',
    ]);

    $descriptionCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 913,
        'course_title' => 'Excluded Description Filter Course',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $topicCourse->course_id,
        'topic' => 'Filterium topic applications',
    ]);

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

    $topicCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 914,
        'course_title' => 'Multiple Topic Filter Course',
    ]);

    $descriptionCourse = Course::factory()->create([
        'course_code' => 'TEST',
        'course_num' => 915,
        'course_title' => 'Multiple Description Filter Course',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $topicCourse->course_id,
        'topic' => 'Filterium topic applications',
    ]);

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

    $consCourse = Course::factory()->create([
        'course_code' => 'CONS',
        'course_num' => 221,
        'course_title' => 'Selected Code Course',
    ]);

    $frstCourse = Course::factory()->create([
        'course_code' => 'FRST',
        'course_num' => 222,
        'course_title' => 'Excluded Code Course',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $consCourse->course_id,
        'topic' => 'Codefilterium conservation methods',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $frstCourse->course_id,
        'topic' => 'Codefilterium forestry methods',
    ]);

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

    $consCourse = Course::factory()->create([
        'course_code' => 'CONS',
        'course_num' => 321,
        'course_title' => 'Selected CONS Course',
    ]);

    $frstCourse = Course::factory()->create([
        'course_code' => 'FRST',
        'course_num' => 322,
        'course_title' => 'Selected FRST Course',
    ]);

    $bestCourse = Course::factory()->create([
        'course_code' => 'BEST',
        'course_num' => 323,
        'course_title' => 'Excluded BEST Course',
    ]);

    foreach ([$consCourse, $frstCourse, $bestCourse] as $course) {
        CourseTopic::factory()->create([
            'course_id' => $course->course_id,
            'topic' => 'Multicodefilterium search topic',
        ]);
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

    $lowerLevelCourse = Course::factory()->create([
        'course_code' => 'FRST',
        'course_num' => 150,
        'course_title' => 'Excluded Lower Level Course',
    ]);

    $selectedLevelCourse = Course::factory()->create([
        'course_code' => 'FRST',
        'course_num' => 350,
        'course_title' => 'Selected Upper Level Course',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $lowerLevelCourse->course_id,
        'topic' => 'Levelfilterium introductory methods',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $selectedLevelCourse->course_id,
        'topic' => 'Levelfilterium advanced methods',
    ]);

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

    $lowerLevelCourse = Course::factory()->create([
        'course_code' => 'CONS',
        'course_num' => 151,
        'course_title' => 'All Levels Lower Course',
    ]);

    $upperLevelCourse = Course::factory()->create([
        'course_code' => 'CONS',
        'course_num' => 351,
        'course_title' => 'All Levels Upper Course',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $lowerLevelCourse->course_id,
        'topic' => 'Alllevelium introductory methods',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $upperLevelCourse->course_id,
        'topic' => 'Alllevelium advanced methods',
    ]);

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

    $lowerConsCourse = Course::factory()->create([
        'course_code' => 'CONS',
        'course_num' => 152,
        'course_title' => 'Excluded CONS Lower Course',
    ]);

    $upperConsCourse = Course::factory()->create([
        'course_code' => 'CONS',
        'course_num' => 352,
        'course_title' => 'Selected CONS Upper Course',
    ]);

    $upperFrstCourse = Course::factory()->create([
        'course_code' => 'FRST',
        'course_num' => 352,
        'course_title' => 'Excluded FRST Upper Course',
    ]);

    foreach ([$lowerConsCourse, $upperConsCourse, $upperFrstCourse] as $course) {
        CourseTopic::factory()->create([
            'course_id' => $course->course_id,
            'topic' => 'Combinedfilterium course methods',
        ]);
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
    $selectedProgramId = DB::table('programs')->insertGetId([
        'program' => 'Forest Sciences',
        'level' => 'Bachelors',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], 'program_id');

    $otherProgramId = DB::table('programs')->insertGetId([
        'program' => 'Bioeconomy Sciences',
        'level' => 'Bachelors',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], 'program_id');

    $matchingCourse = Course::factory()->create([
        'course_code' => 'FRST',
        'course_num' => 302,
        'course_title' => 'Forest Genetics',
    ]);

    $excludedCourse = Course::factory()->create([
        'course_code' => 'BEST',
        'course_num' => 300,
        'course_title' => 'Climate Adaptation',
    ]);

    DB::table('course_programs')->insert([
        [
            'course_id' => $matchingCourse->course_id,
            'program_id' => $selectedProgramId,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'course_id' => $excludedCourse->course_id,
            'program_id' => $otherProgramId,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    CourseTopic::factory()->create([
        'course_id' => $matchingCourse->course_id,
        'topic' => 'climate adaptation in forest ecosystems',
    ]);

    CourseTopic::factory()->create([
        'course_id' => $excludedCourse->course_id,
        'topic' => 'climate adaptation in forest ecosystems',
    ]);

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
    $selectedProgramId = DB::table('programs')->insertGetId([
        'program' => 'Selected Forest Program',
        'level' => 'Bachelors',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], 'program_id');

    $otherProgramId = DB::table('programs')->insertGetId([
        'program' => 'Unselected Climate Program',
        'level' => 'Bachelors',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], 'program_id');

    $course = Course::factory()->create([
        'course_code' => 'FRST',
        'course_num' => 303,
        'course_title' => 'Climate Resilient Forests',
    ]);

    DB::table('course_programs')->insert([
        [
            'course_id' => $course->course_id,
            'program_id' => $selectedProgramId,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'course_id' => $course->course_id,
            'program_id' => $otherProgramId,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);



    CourseTopic::factory()->create([
        'course_id' => $course->course_id,
        'topic' => 'Climate resilience in managed forests',
    ]);

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
    $programId = DB::table('programs')->insertGetId([
        'program' => 'Forest Sciences',
        'level' => 'Bachelors',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], 'program_id');

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

public function test_applied_saved_filter_is_shown_as_the_current_preset(): void
{
    $user = User::factory()->create();
    $this->createCourseScaleCategory();
    $course = Course::factory()->create([
        'course_code' => 'FRST',
        'course_num' => 300,
        'course_title' => 'Climate Forestry',
    ]);
    CourseTopic::factory()->create([
        'course_id' => $course->course_id,
        'topic' => 'climate adaptation',
    ]);
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

    $selectedProgramId = DB::table('programs')->insertGetId([
        'program' => 'Terraria Program',
        'level' => 'Bachelors',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], 'program_id');

    $otherProgramId = DB::table('programs')->insertGetId([
        'program' => 'Different terraria program',
        'level' => 'Bachelors',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], 'program_id');

    $matchingFilterCourse = Course::factory()->create([
        'course_code' => 'FRST',
        'course_num' => 303,
        'course_title' => 'Climate Resilient',
    ]);

    DB::table('course_programs')->insert([
        [
            'course_id' => $matchingFilterCourse->course_id,
            'program_id' => $selectedProgramId,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $excludedFilterCourse = Course::factory()->create([
        'course_code' => 'CONS',
        'course_num' => 303,
        'course_title' => 'Climate Resilient',
    ]);

    DB::table('course_programs')->insert([
        [
            'course_id' => $excludedFilterCourse->course_id,
            'program_id' => $otherProgramId,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

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

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class SearchPerformanceSeeder extends Seeder
{
    private const DEFAULT_COURSE_COUNT = 2000;
    private const DEFAULT_PROGRAM_COUNT = 1500;
    private const CHUNK_SIZE = 500;
    private const COURSE_PREFIX = 'Performance Test Course ';
    private const PROGRAM_PREFIX = 'Performance Test Program ';
    private const EMAIL_PREFIX = 'search-performance-';
    private const PASSWORD = 'password';

    private int $courseCount;
    private int $programCount;

    /**
     * Create a deterministic dataset for measuring course search performance.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('The search performance seeder cannot run in production.');
        }

        $this->courseCount = max(1, (int) env('SEARCH_PERFORMANCE_COURSES', self::DEFAULT_COURSE_COUNT));
        $this->programCount = max(1, (int) env('SEARCH_PERFORMANCE_PROGRAMS', self::DEFAULT_PROGRAM_COUNT));

        $this->command?->info("Creating {$this->courseCount} courses and {$this->programCount} programs...");

        DB::disableQueryLog();

        DB::transaction(function () {
            $this->removePreviousDataset();
            $this->synchronizeSequences();

            $roleIds = $this->ensureRoles();
            $categoryIds = $this->ensureCourseCategories();
            $organization = $this->ensureOrganization();
            $userIds = $this->createUsers($roleIds);
            $programIds = $this->createPrograms($organization);
            $courseIds = $this->createCourses($categoryIds, $organization);

            $this->createCoursePrograms($courseIds, $programIds);
            $this->createSearchableCourseContent($courseIds);
            $this->createDirectAccess($courseIds, $programIds, $userIds);
            $this->createRoleAccess($courseIds, $programIds, $userIds, $roleIds, $organization);
        });

        $this->showSummary();
    }

    /**
     * Delete only rows created by this seeder so rerunning it is safe.
     */
    private function removePreviousDataset(): void
    {
        DB::table('users')
            ->where('email', 'like', self::EMAIL_PREFIX.'%@example.test')
            ->delete();

        DB::table('courses')
            ->where('course_title', 'like', self::COURSE_PREFIX.'%')
            ->delete();

        DB::table('programs')
            ->where('program', 'like', self::PROGRAM_PREFIX.'%')
            ->delete();
    }

    /**
     * Keep PostgreSQL sequences ahead of rows that may have been imported with explicit IDs.
     */
    private function synchronizeSequences(): void
    {
        $sequences = [
            'campuses' => 'campus_id',
            'faculties' => 'faculty_id',
            'departments' => 'department_id',
            'roles' => 'id',
            'standard_categories' => 'standard_category_id',
            'standards_scale_categories' => 'scale_category_id',
            'users' => 'id',
            'programs' => 'program_id',
            'courses' => 'course_id',
            'course_programs' => 'id',
            'course_description' => 'id',
            'course_topics' => 'course_topic_id',
            'learning_outcomes' => 'l_outcome_id',
            'assessment_methods' => 'a_method_id',
            'course_materials' => 'course_material_id',
            'course_users' => 'id',
            'program_users' => 'id',
            'department_head' => 'id',
            'program_user_role' => 'id',
            'course_user_role' => 'id',
        ];

        foreach ($sequences as $table => $column) {
            DB::statement("
                SELECT setval(
                    pg_get_serial_sequence('{$table}', '{$column}'),
                    COALESCE(MAX({$column}), 0) + 1,
                    false
                )
                FROM {$table}
            ");
        }
    }

    /**
     * Ensure the roles needed by the benchmark accounts exist.
     */
    private function ensureRoles(): array
    {
        $roleIds = [];

        foreach (['administrator', 'program director', 'department head', 'user'] as $role) {
            $roleId = DB::table('roles')->where('role', $role)->value('id');

            if (! $roleId) {
                $roleId = DB::table('roles')->insertGetId([
                    'role' => $role,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $roleIds[$role] = (int) $roleId;
        }

        return $roleIds;
    }

    /**
     * Ensure required course category foreign keys are available.
     */
    private function ensureCourseCategories(): array
    {
        $standardCategoryId = DB::table('standard_categories')->value('standard_category_id');
        if (! $standardCategoryId) {
            $standardCategoryId = DB::table('standard_categories')->insertGetId([
                'sc_name' => 'Performance Test Standard Category',
            ], 'standard_category_id');
        }

        $scaleCategoryId = DB::table('standards_scale_categories')->value('scale_category_id');
        if (! $scaleCategoryId) {
            $scaleCategoryId = DB::table('standards_scale_categories')->insertGetId([
                'name' => 'Performance Test Scale Category',
            ], 'scale_category_id');
        }

        return [
            'standard' => (int) $standardCategoryId,
            'scale' => (int) $scaleCategoryId,
        ];
    }

    /**
     * Create one faculty with two departments for department and faculty-wide access testing.
     */
    private function ensureOrganization(): array
    {
        $timestamp = now();
        $campusName = 'Performance Test Campus';
        $facultyName = 'Performance Test Faculty';

        $campusId = DB::table('campuses')->where('campus', $campusName)->value('campus_id');
        if (! $campusId) {
            $campusId = DB::table('campuses')->insertGetId([
                'campus' => $campusName,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], 'campus_id');
        }

        $facultyId = DB::table('faculties')
            ->where('faculty', $facultyName)
            ->where('campus_id', $campusId)
            ->value('faculty_id');
        if (! $facultyId) {
            $facultyId = DB::table('faculties')->insertGetId([
                'faculty' => $facultyName,
                'campus_id' => $campusId,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], 'faculty_id');
        }

        $departments = [];
        foreach (['Performance Test Department A', 'Performance Test Department B'] as $departmentName) {
            $departmentId = DB::table('departments')
                ->where('department', $departmentName)
                ->where('faculty_id', $facultyId)
                ->value('department_id');

            if (! $departmentId) {
                $departmentId = DB::table('departments')->insertGetId([
                    'department' => $departmentName,
                    'faculty_id' => $facultyId,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ], 'department_id');
            }

            $departments[] = [
                'id' => (int) $departmentId,
                'name' => $departmentName,
            ];
        }

        return [
            'campus' => $campusName,
            'faculty' => $facultyName,
            'faculty_id' => (int) $facultyId,
            'departments' => $departments,
        ];
    }

    /**
     * Create verified benchmark users and assign their global roles.
     */
    private function createUsers(array $roleIds): array
    {
        $timestamp = now();
        $password = Hash::make(self::PASSWORD);
        $accounts = [
            'no_access' => ['No Access', 'no-access', 'user'],
            'direct' => ['Limited Direct Access', 'direct', 'user'],
            'owner' => ['Broad Direct Access', 'owner', 'user'],
            'program_director' => ['Program Director', 'program-director', 'program director'],
            'department_head' => ['Department Head', 'department-head', 'department head'],
            'faculty_wide' => ['Faculty-Wide Department Head', 'faculty-wide', 'department head'],
            'admin' => ['Administrator', 'admin', 'administrator'],
        ];

        $userRows = [];
        foreach ($accounts as [$name, $emailName]) {
            $userRows[] = [
                'name' => 'Search Performance '.$name,
                'email' => self::EMAIL_PREFIX.$emailName.'@example.test',
                'email_verified_at' => $timestamp,
                'password' => $password,
                'has_temp' => false,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }
        DB::table('users')->insert($userRows);

        $userIds = [];
        foreach ($accounts as $key => [, $emailName, $role]) {
            $userId = (int) DB::table('users')
                ->where('email', self::EMAIL_PREFIX.$emailName.'@example.test')
                ->value('id');
            $userIds[$key] = $userId;

            DB::table('role_user')->insert([
                'role_id' => $roleIds[$role],
                'user_id' => $userId,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        return $userIds;
    }

    /**
     * Insert generic programs with indexed names and repeatable search terms.
     */
    private function createPrograms(array $organization): array
    {
        $timestamp = now();
        $rows = [];

        for ($index = 1; $index <= $this->programCount; $index++) {
            $rows[] = [
                'program' => $this->programName($index),
                'faculty' => $organization['faculty'],
                'department' => $this->departmentForIndex($organization, $index)['name'],
                'level' => $index % 5 === 0 ? 'Masters' : 'Bachelors',
                'status' => 1,
                'campus' => $organization['campus'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }
        $this->insertInChunks('programs', $rows);

        $programsByName = DB::table('programs')
            ->where('program', 'like', self::PROGRAM_PREFIX.'%')
            ->pluck('program_id', 'program');

        $programIds = [];
        for ($index = 1; $index <= $this->programCount; $index++) {
            $programIds[$index] = (int) $programsByName[$this->programName($index)];
        }

        return $programIds;
    }

    /**
     * Insert generic courses covering every supported course level.
     */
    private function createCourses(array $categoryIds, array $organization): array
    {
        $timestamp = now();
        $rows = [];

        for ($index = 1; $index <= $this->courseCount; $index++) {
            $rows[] = [
                'course_code' => $this->courseCode($index),
                'course_num' => (string) (100 + (($index - 1) % 600)),
                'delivery_modality' => 'I',
                'year' => 2026,
                'semester' => $index % 2 === 0 ? 'W1' : 'W2',
                'section' => str_pad((string) (($index % 9) + 1), 3, '0', STR_PAD_LEFT),
                'course_title' => $this->courseTitle($index),
                'status' => 1,
                'assigned' => 1,
                'type' => 'unassigned',
                'standard_category_id' => $categoryIds['standard'],
                'scale_category_id' => $categoryIds['scale'],
                'campus' => $organization['campus'],
                'faculty' => $organization['faculty'],
                'department' => $this->departmentForIndex($organization, $index)['name'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }
        $this->insertInChunks('courses', $rows);

        $coursesByTitle = DB::table('courses')
            ->where('course_title', 'like', self::COURSE_PREFIX.'%')
            ->pluck('course_id', 'course_title');

        $courseIds = [];
        for ($index = 1; $index <= $this->courseCount; $index++) {
            $courseIds[$index] = (int) $coursesByTitle[$this->courseTitle($index)];
        }

        return $courseIds;
    }

    /**
     * Attach every course to one or two programs using a deterministic distribution.
     */
    private function createCoursePrograms(array $courseIds, array $programIds): void
    {
        $timestamp = now();
        $rows = [];

        for ($index = 1; $index <= $this->courseCount; $index++) {
            foreach ($this->programIndexesForCourse($index) as $programIndex) {
                $rows[] = [
                    'course_id' => $courseIds[$index],
                    'program_id' => $programIds[$programIndex],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        $this->insertInChunks('course_programs', $rows);
    }

    /**
     * Add two topics, outcomes, assessments, and materials plus one description per course.
     */
    private function createSearchableCourseContent(array $courseIds): void
    {
        $timestamp = now();
        $descriptions = [];
        $topics = [];
        $outcomes = [];
        $assessments = [];
        $materials = [];

        for ($index = 1; $index <= $this->courseCount; $index++) {
            $courseId = $courseIds[$index];
            $label = str_pad((string) $index, 5, '0', STR_PAD_LEFT);
            $primaryTerm = $index % 2 === 0 ? 'climate' : 'forest';
            $secondaryTerm = $index % 20 === 0 ? 'watershed' : 'policy';
            $rareTerm = $index === $this->rareCourseIndex() ? ' cryosphere' : '';

            $descriptions[] = [
                'course_id' => $courseId,
                'description' => "Performance description {$label} examines {$primaryTerm}, {$secondaryTerm}, sustainability, evidence, and applied planning.{$rareTerm}",
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            $topics[] = [
                'course_id' => $courseId,
                'topic' => "Performance topic {$label}: {$primaryTerm} systems",
                'description' => "Indexed topic about {$secondaryTerm} and sustainability.{$rareTerm}",
                'position' => 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            $topics[] = [
                'course_id' => $courseId,
                'topic' => "Performance topic {$label}: data and community planning",
                'description' => "Supporting topic for {$primaryTerm} analysis.",
                'position' => 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            $outcomes[] = [
                'clo_shortphrase' => "Performance outcome {$label}A",
                'l_outcome' => "Analyze {$primaryTerm} evidence and explain its relationship to {$secondaryTerm} decisions.{$rareTerm}",
                'course_id' => $courseId,
                'pos_in_alignment' => 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            $outcomes[] = [
                'clo_shortphrase' => "Performance outcome {$label}B",
                'l_outcome' => "Evaluate sustainability options and communicate an indexed recommendation for course {$label}.",
                'course_id' => $courseId,
                'pos_in_alignment' => 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            $assessments[] = [
                'a_method' => "Performance {$primaryTerm} analysis {$label}",
                'weight' => 40,
                'course_id' => $courseId,
                'pos_in_alignment' => 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            $assessments[] = [
                'a_method' => "Performance {$secondaryTerm} project {$label}{$rareTerm}",
                'weight' => 60,
                'course_id' => $courseId,
                'pos_in_alignment' => 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            $materials[] = [
                'course_id' => $courseId,
                'name' => "Performance {$primaryTerm} handbook {$label}",
                'type' => 'book',
                'description' => "Indexed material covering {$secondaryTerm} and sustainability.{$rareTerm}",
                'is_required' => true,
                'position' => 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            $materials[] = [
                'course_id' => $courseId,
                'name' => "Performance data collection {$label}",
                'type' => 'dataset',
                'description' => "Supporting evidence for {$primaryTerm} analysis.",
                'is_required' => false,
                'position' => 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        $this->insertInChunks('course_description', $descriptions);
        $this->insertInChunks('course_topics', $topics);
        $this->insertInChunks('learning_outcomes', $outcomes);
        $this->insertInChunks('assessment_methods', $assessments);
        $this->insertInChunks('course_materials', $materials);
    }

    /**
     * Create limited and broad direct-access distributions.
     */
    private function createDirectAccess(array $courseIds, array $programIds, array $userIds): void
    {
        $timestamp = now();
        $courseRows = [];

        foreach ($courseIds as $courseId) {
            $courseRows[] = [
                'course_id' => $courseId,
                'user_id' => $userIds['owner'],
                'permission' => 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        $limitedCourseCount = min(100, $this->courseCount);
        for ($index = 1; $index <= $limitedCourseCount; $index++) {
            $courseRows[] = [
                'course_id' => $courseIds[$index],
                'user_id' => $userIds['direct'],
                'permission' => 3,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }
        $this->insertInChunks('course_users', $courseRows);

        $programRows = [];
        foreach ($programIds as $programId) {
            $programRows[] = [
                'program_id' => $programId,
                'user_id' => $userIds['owner'],
                'permission' => 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        $limitedProgramCount = min(100, $this->programCount);
        for ($index = 1; $index <= $limitedProgramCount; $index++) {
            $programRows[] = [
                'program_id' => $programIds[$index],
                'user_id' => $userIds['direct'],
                'permission' => 3,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }
        $this->insertInChunks('program_users', $programRows);
    }

    /**
     * Materialize Program Director, Department Head, and faculty-wide access rows.
     */
    private function createRoleAccess(
        array $courseIds,
        array $programIds,
        array $userIds,
        array $roleIds,
        array $organization
    ): void {
        $timestamp = now();
        $primaryDepartment = $organization['departments'][0];
        $directorProgramCount = max(1, (int) ceil($this->programCount * 0.2));

        DB::table('department_head')->insert([
            [
                'department_id' => $primaryDepartment['id'],
                'user_id' => $userIds['department_head'],
                'has_access_to_all_courses_in_faculty' => false,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'department_id' => $primaryDepartment['id'],
                'user_id' => $userIds['faculty_wide'],
                'has_access_to_all_courses_in_faculty' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);

        $programRoleRows = [];
        for ($index = 1; $index <= $this->programCount; $index++) {
            if ($index <= $directorProgramCount) {
                $programRoleRows[] = $this->programRoleRow(
                    $programIds[$index],
                    $userIds['program_director'],
                    $roleIds['program director'],
                    null,
                    false,
                    $timestamp
                );
            }

            if ($this->departmentForIndex($organization, $index)['id'] === $primaryDepartment['id']) {
                $programRoleRows[] = $this->programRoleRow(
                    $programIds[$index],
                    $userIds['department_head'],
                    $roleIds['department head'],
                    $primaryDepartment['id'],
                    false,
                    $timestamp
                );
            }

            $programRoleRows[] = $this->programRoleRow(
                $programIds[$index],
                $userIds['faculty_wide'],
                $roleIds['department head'],
                $primaryDepartment['id'],
                true,
                $timestamp
            );
        }
        $this->insertInChunks('program_user_role', $programRoleRows);

        $courseRoleRows = [];
        for ($index = 1; $index <= $this->courseCount; $index++) {
            $courseId = $courseIds[$index];

            foreach ($this->programIndexesForCourse($index) as $programIndex) {
                if ($programIndex <= $directorProgramCount) {
                    $courseRoleRows[] = [
                        'course_id' => $courseId,
                        'user_id' => $userIds['program_director'],
                        'role_id' => $roleIds['program director'],
                        'program_id' => $programIds[$programIndex],
                        'department_id' => null,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }
            }

            if ($this->departmentForIndex($organization, $index)['id'] === $primaryDepartment['id']) {
                $courseRoleRows[] = [
                    'course_id' => $courseId,
                    'user_id' => $userIds['department_head'],
                    'role_id' => $roleIds['department head'],
                    'program_id' => null,
                    'department_id' => $primaryDepartment['id'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            $courseRoleRows[] = [
                'course_id' => $courseId,
                'user_id' => $userIds['faculty_wide'],
                'role_id' => $roleIds['department head'],
                'program_id' => null,
                'department_id' => $primaryDepartment['id'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }
        $this->insertInChunks('course_user_role', $courseRoleRows);
    }

    private function programRoleRow(
        int $programId,
        int $userId,
        int $roleId,
        ?int $departmentId,
        bool $facultyWide,
        $timestamp
    ): array {
        return [
            'program_id' => $programId,
            'user_id' => $userId,
            'role_id' => $roleId,
            'department_id' => $departmentId,
            'has_access_to_all_courses_in_faculty' => $facultyWide,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    private function insertInChunks(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    private function departmentForIndex(array $organization, int $index): array
    {
        return $organization['departments'][intdiv($index - 1, 10) % 2];
    }

    private function primaryProgramIndex(int $courseIndex): int
    {
        return (($courseIndex - 1) % $this->programCount) + 1;
    }

    private function programIndexesForCourse(int $courseIndex): array
    {
        $programIndexes = [$this->primaryProgramIndex($courseIndex)];

        if ($this->programCount > 1) {
            $secondaryProgramIndex = (($courseIndex * 37) % $this->programCount) + 1;
            if ($secondaryProgramIndex === $programIndexes[0]) {
                $secondaryProgramIndex = ($secondaryProgramIndex % $this->programCount) + 1;
            }
            $programIndexes[] = $secondaryProgramIndex;
        }

        return $programIndexes;
    }

    private function programName(int $index): string
    {
        $label = str_pad((string) $index, 4, '0', STR_PAD_LEFT);
        $focus = match ($index % 4) {
            0 => 'Climate Studies',
            1 => 'Forest Policy',
            2 => 'Watershed Planning',
            default => 'Sustainability',
        };

        if ($index === $this->programCount) {
            $focus = 'Cryosphere Research';
        }

        return self::PROGRAM_PREFIX.$label.' - '.$focus;
    }

    private function courseTitle(int $index): string
    {
        $label = str_pad((string) $index, 5, '0', STR_PAD_LEFT);
        $focus = match ($index % 4) {
            0 => 'Climate Systems',
            1 => 'Forest Policy',
            2 => 'Watershed Planning',
            default => 'Sustainability Practice',
        };

        if ($index === $this->rareCourseIndex()) {
            $focus = 'Cryosphere Monitoring';
        }

        return self::COURSE_PREFIX.$label.' - '.$focus;
    }

    private function courseCode(int $index): string
    {
        $codeIndex = ($index - 1) % 100;

        return 'PF'.chr(65 + intdiv($codeIndex, 26)).chr(65 + ($codeIndex % 26));
    }

    private function rareCourseIndex(): int
    {
        return max(1, $this->courseCount - 10);
    }

    /**
     * Print reusable credentials, search terms, and access sizes after seeding.
     */
    private function showSummary(): void
    {
        $this->command?->newLine();
        $this->command?->info('Search performance dataset created successfully.');
        $this->command?->line('Password for every account: '.self::PASSWORD);
        $this->command?->line('Common query: climate');
        $this->command?->line('Medium query: watershed');
        $this->command?->line('Rare query: cryosphere');
        $this->command?->newLine();

        foreach ([
            'no-access' => 'No access',
            'direct' => 'Limited direct access (up to 100 courses)',
            'owner' => 'Broad direct access (all performance courses)',
            'program-director' => 'Program Director access',
            'department-head' => 'Department Head access (Department A)',
            'faculty-wide' => 'Faculty-wide access (all performance courses)',
            'admin' => 'Administrator access',
        ] as $emailName => $description) {
            $this->command?->line(self::EMAIL_PREFIX.$emailName.'@example.test - '.$description);
        }
    }
}

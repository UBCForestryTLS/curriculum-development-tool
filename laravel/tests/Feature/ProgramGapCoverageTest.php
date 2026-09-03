<?php

namespace Tests\Feature;

use App\Helpers\ProgramGapCoverage;
use App\Models\Course;
use App\Models\CourseProgram;
use App\Models\LearningOutcome;
use App\Models\MappingScale;
use App\Models\Program;
use App\Models\ProgramLearningOutcome;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProgramGapCoverageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_classifies_coverage_by_distinct_course_count(): void
    {
        $cases = [
            0 => 'not_covered',
            1 => 'somewhat_covered',
            2 => 'sufficiently_covered',
            3 => 'sufficiently_covered',
            4 => 'abundantly_covered',
        ];

        foreach ($cases as $courseCount => $expectedLevel) {
            $classification = ProgramGapCoverage::classifyCoverage($courseCount);

            $this->assertSame($expectedLevel, $classification['level']);
        }
    }

    public function test_authorized_user_can_get_gap_coverage_report(): void
    {
        $program = Program::create([
            'program' => 'Gap Coverage Endpoint Program',
            'level' => 'Bachelors',
            'status' => 1,
        ]);
        $programLearningOutcome = ProgramLearningOutcome::create([
            'program_id' => $program->program_id,
            'pl_outcome' => 'Interpret program coverage evidence.',
            'plo_shortphrase' => 'Coverage Evidence',
        ]);
        $user = User::factory()->create();

        DB::table('program_users')->insert([
            'program_id' => $program->program_id,
            'user_id' => $user->id,
            'permission' => 3,
        ]);

        $response = $this->actingAs($user)->getJson(route('programWizard.gapCoverage', $program->program_id));

        $response->assertOk()
            ->assertJsonPath('program_id', $program->program_id)
            ->assertJsonPath('mapping_completeness.is_complete', true)
            ->assertJsonPath('coverage.0.pl_outcome_id', $programLearningOutcome->pl_outcome_id)
            ->assertJsonPath('coverage.0.mapped_clo_count', 0);
    }

    public function test_user_without_program_access_cannot_get_gap_coverage_report(): void
    {
        $program = Program::create([
            'program' => 'Restricted Gap Coverage Program',
            'level' => 'Bachelors',
            'status' => 1,
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('programWizard.gapCoverage', $program->program_id));

        $response->assertRedirect(route('home'));
    }

    public function test_it_identifies_incomplete_course_mappings(): void
    {
        $program = Program::create([
            'program' => 'Mapping Completeness Test Program',
            'level' => 'Bachelors',
            'status' => 1,
        ]);
        $course = Course::factory()->create([
            'course_code' => 'MCMP',
            'course_num' => '101',
            'course_title' => 'Mapping Completeness Course',
        ]);
        CourseProgram::create([
            'program_id' => $program->program_id,
            'course_id' => $course->course_id,
            'course_required' => 1,
        ]);

        $firstPlo = ProgramLearningOutcome::create([
            'program_id' => $program->program_id,
            'pl_outcome' => 'First completeness outcome.',
        ]);
        $secondPlo = ProgramLearningOutcome::create([
            'program_id' => $program->program_id,
            'pl_outcome' => 'Second completeness outcome.',
        ]);
        $clo = LearningOutcome::create([
            'course_id' => $course->course_id,
            'l_outcome' => 'Demonstrate completeness.',
        ]);
        $mappingScale = MappingScale::create([
            'title' => 'Mapping Completeness Scale',
            'abbreviation' => 'MCS',
            'description' => 'Used for the mapping completeness test.',
            'colour' => '#80bdff',
        ]);

        DB::table('outcome_maps')->insert([
            'l_outcome_id' => $clo->l_outcome_id,
            'pl_outcome_id' => $firstPlo->pl_outcome_id,
            'map_scale_id' => $mappingScale->map_scale_id,
        ]);

        $incompleteResult = ProgramGapCoverage::mappingCompleteness($program);

        $this->assertTrue($incompleteResult['has_incomplete_mappings']);
        $this->assertSame(2, $incompleteResult['expected_counts'][$course->course_id]);
        $this->assertSame(1, $incompleteResult['actual_counts'][$course->course_id]);
        $this->assertSame(1, $incompleteResult['incomplete_courses'][0]['missing_mapping_count']);

        DB::table('outcome_maps')->insert([
            'l_outcome_id' => $clo->l_outcome_id,
            'pl_outcome_id' => $secondPlo->pl_outcome_id,
            'map_scale_id' => $mappingScale->map_scale_id,
        ]);

        $completeResult = ProgramGapCoverage::mappingCompleteness($program);

        $this->assertTrue($completeResult['is_complete']);
        $this->assertEmpty($completeResult['incomplete_courses']);
    }

    public function test_it_summarizes_raw_coverage_for_each_program_learning_outcome(): void
    {
        $program = Program::create([
            'program' => 'Gap Coverage Test Program',
            'level' => 'Bachelors',
            'status' => 1,
        ]);

        $requiredCourse = Course::factory()->create([
            'course_code' => 'GCOV',
            'course_num' => '101',
            'course_title' => 'Required Coverage Course',
        ]);
        $nonRequiredCourse = Course::factory()->create([
            'course_code' => 'GCOV',
            'course_num' => '201',
            'course_title' => 'Non-Required Coverage Course',
        ]);

        CourseProgram::create([
            'program_id' => $program->program_id,
            'course_id' => $requiredCourse->course_id,
            'course_required' => 1,
        ]);
        CourseProgram::create([
            'program_id' => $program->program_id,
            'course_id' => $nonRequiredCourse->course_id,
            'course_required' => 0,
        ]);

        $coveredPlo = ProgramLearningOutcome::create([
            'program_id' => $program->program_id,
            'pl_outcome' => 'Apply evidence to solve curriculum problems.',
            'plo_shortphrase' => 'Evidence',
        ]);
        $uncoveredPlo = ProgramLearningOutcome::create([
            'program_id' => $program->program_id,
            'pl_outcome' => 'Communicate with professional audiences.',
            'plo_shortphrase' => 'Communication',
        ]);

        $requiredClo = LearningOutcome::create([
            'course_id' => $requiredCourse->course_id,
            'l_outcome' => 'Identify relevant forms of evidence.',
            'clo_shortphrase' => 'Identify Evidence',
        ]);
        $nonRequiredClo = LearningOutcome::create([
            'course_id' => $nonRequiredCourse->course_id,
            'l_outcome' => 'Analyze evidence from multiple sources.',
            'clo_shortphrase' => 'Analyze Evidence',
        ]);
        $notApplicableClo = LearningOutcome::create([
            'course_id' => $nonRequiredCourse->course_id,
            'l_outcome' => 'Describe the course schedule.',
            'clo_shortphrase' => 'Course Schedule',
        ]);

        $introduced = MappingScale::create([
            'title' => 'Gap Coverage Introduced',
            'abbreviation' => 'GCI',
            'description' => 'Introduced for the gap coverage test.',
            'colour' => '#80bdff',
        ]);
        $developing = MappingScale::create([
            'title' => 'Gap Coverage Developing',
            'abbreviation' => 'GCD',
            'description' => 'Developing for the gap coverage test.',
            'colour' => '#1aa7ff',
        ]);
        $notApplicable = MappingScale::firstOrCreate([
            'map_scale_id' => 0,
        ], [
            'title' => 'Not Applicable',
            'abbreviation' => 'N/A',
            'description' => 'Not Applicable',
            'colour' => '#ffffff',
        ]);

        DB::table('outcome_maps')->insert([
            [
                'l_outcome_id' => $requiredClo->l_outcome_id,
                'pl_outcome_id' => $coveredPlo->pl_outcome_id,
                'map_scale_id' => $introduced->map_scale_id,
            ],
            [
                'l_outcome_id' => $nonRequiredClo->l_outcome_id,
                'pl_outcome_id' => $coveredPlo->pl_outcome_id,
                'map_scale_id' => $developing->map_scale_id,
            ],
            [
                'l_outcome_id' => $notApplicableClo->l_outcome_id,
                'pl_outcome_id' => $coveredPlo->pl_outcome_id,
                'map_scale_id' => $notApplicable->map_scale_id,
            ],
        ]);

        $coverage = ProgramGapCoverage::analyze($program);
        $coveredResult = $coverage->firstWhere('pl_outcome_id', $coveredPlo->pl_outcome_id);
        $uncoveredResult = $coverage->firstWhere('pl_outcome_id', $uncoveredPlo->pl_outcome_id);

        $this->assertCount(2, $coverage);
        $this->assertSame(2, $coveredResult['mapped_clo_count']);
        $this->assertSame(2, $coveredResult['covering_course_count']);
        $this->assertSame('sufficiently_covered', $coveredResult['coverage_level']);
        $this->assertSame(1, $coveredResult['required_course_count']);
        $this->assertSame(1, $coveredResult['non_required_course_count']);
        $this->assertSame(1, $coveredResult['n_a_clo_count']);
        $this->assertSame(['GCI', 'GCD'], collect($coveredResult['mapping_scale_distribution'])->pluck('abbreviation')->all());
        $this->assertSame(['GCOV 101', 'GCOV 201'], collect($coveredResult['courses'])->map(function ($course) {
            return $course['course_code'].' '.$course['course_num'];
        })->all());
        $this->assertSame(0, $uncoveredResult['mapped_clo_count']);
        $this->assertSame(0, $uncoveredResult['covering_course_count']);
        $this->assertSame('not_covered', $uncoveredResult['coverage_level']);
        $this->assertEmpty($uncoveredResult['mapping_scale_distribution']);
        $this->assertEmpty($uncoveredResult['courses']);
    }
}

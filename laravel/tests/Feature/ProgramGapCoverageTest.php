<?php

namespace Tests\Feature;

use App\Helpers\ProgramGapCoverage;
use App\Models\Course;
use App\Models\CourseProgram;
use App\Models\LearningOutcome;
use App\Models\MappingScale;
use App\Models\Program;
use App\Models\ProgramLearningOutcome;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProgramGapCoverageTest extends TestCase
{
    use DatabaseTransactions;

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
        $this->assertSame(1, $coveredResult['required_course_count']);
        $this->assertSame(1, $coveredResult['non_required_course_count']);
        $this->assertSame(1, $coveredResult['n_a_clo_count']);
        $this->assertSame(['GCI', 'GCD'], collect($coveredResult['mapping_scale_distribution'])->pluck('abbreviation')->all());
        $this->assertSame(['GCOV 101', 'GCOV 201'], collect($coveredResult['courses'])->map(function ($course) {
            return $course['course_code'].' '.$course['course_num'];
        })->all());
        $this->assertSame(0, $uncoveredResult['mapped_clo_count']);
        $this->assertSame(0, $uncoveredResult['covering_course_count']);
        $this->assertEmpty($uncoveredResult['mapping_scale_distribution']);
        $this->assertEmpty($uncoveredResult['courses']);
    }
}

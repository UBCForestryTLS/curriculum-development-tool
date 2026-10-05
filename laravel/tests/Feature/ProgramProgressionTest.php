<?php

namespace Tests\Feature;

use App\Models\BloomDomain;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\MappingScale;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProgramProgressionTest extends TestCase
{
    use DatabaseTransactions;

    private function createProgram(): Program
    {
        return Program::create([
            'program' => 'Progression Test Program',
            'level' => 'Bachelors',
            'status' => 1,
        ]);
    }

    private function signInAsViewer(Program $program): void
    {
        $user = User::factory()->create();
        $program->users()->attach($user->id, ['permission' => 3]);
        $this->actingAs($user);
    }

    public function test_viewer_can_get_an_empty_program(): void
    {
        $program = $this->createProgram();
        $this->signInAsViewer($program);

        $this->getJson(route('programWizard.progression', $program->program_id))
            ->assertOk()
            ->assertExactJson([
                'program_id' => $program->program_id,
                'selected_plo' => null,
                'plos' => [],
                'has_incomplete_mappings' => false,
                'bloom_reference_available' => false,
                'bloom_levels' => [],
                'course_groups' => [],
                'program_totals' => ['course_count' => 0, 'clo_count' => 0],
                'scope_totals' => ['course_count' => 0, 'clo_count' => 0],
                'courses' => [],
            ]);
    }

    public function test_it_returns_current_program_courses_and_all_their_clos(): void
    {
        $program = $this->createProgram();
        $otherProgram = $this->createProgram();
        $this->signInAsViewer($program);

        $required = Course::factory()->create([
            'course_code' => 'ENVD',
            'course_num' => '101',
            'course_title' => 'Environmental Data',
        ]);
        $optional = Course::factory()->create(['course_num' => '201']);
        $unspecified = Course::factory()->create(['course_num' => null]);
        $outside = Course::factory()->create();
        $removed = Course::factory()->create();

        $program->courses()->attach([
            $required->course_id => ['course_required' => 1],
            $optional->course_id => ['course_required' => 0],
            $unspecified->course_id => ['course_required' => null],
            $removed->course_id => ['course_required' => 1],
        ]);
        $program->courses()->detach($removed->course_id);
        $otherProgram->courses()->attach([
            $required->course_id => ['course_required' => 0],
            $outside->course_id => ['course_required' => 1],
        ]);

        $firstClo = LearningOutcome::create([
            'course_id' => $required->course_id,
            'l_outcome' => 'Analyze environmental data and explain the results.',
            'clo_shortphrase' => 'Data analysis',
        ]);
        $secondClo = LearningOutcome::create([
            'course_id' => $required->course_id,
            'l_outcome' => 'Describe research methods.',
        ]);
        // Identical text still represents a separate CLO when its ID is different.
        $thirdClo = LearningOutcome::create([
            'course_id' => $optional->course_id,
            'l_outcome' => $secondClo->l_outcome,
        ]);
        foreach ([$outside, $removed] as $course) {
            LearningOutcome::create([
                'course_id' => $course->course_id,
                'l_outcome' => 'An outcome outside the current program.',
            ]);
        }

        $this->getJson(route('programWizard.progression', $program->program_id))
            ->assertOk()
            ->assertJsonPath('program_totals', ['course_count' => 3, 'clo_count' => 3])
            ->assertJsonPath('bloom_levels', [])
            ->assertJsonCount(3, 'course_groups')
            ->assertJsonPath('course_groups.0.course_level', 100)
            ->assertJsonPath('course_groups.0.clo_count', 2)
            ->assertJsonPath('course_groups.0.matched_clo_count', null)
            ->assertJsonPath('course_groups.0.unmatched_clo_count', null)
            ->assertJsonPath('course_groups.1.course_level', 200)
            ->assertJsonPath('course_groups.2.course_level', 'other')
            ->assertJsonCount(3, 'courses')
            ->assertJsonPath('courses.0.course_id', $required->course_id)
            ->assertJsonPath('courses.0.course_code', 'ENVD')
            ->assertJsonPath('courses.0.course_num', '101')
            ->assertJsonPath('courses.0.course_title', 'Environmental Data')
            ->assertJsonPath('courses.0.course_required', true)
            ->assertJsonPath('courses.0.clos', [
                ['l_outcome_id' => $firstClo->l_outcome_id, 'l_outcome' => $firstClo->l_outcome, 'clo_shortphrase' => 'Data analysis', 'bloom_levels' => null],
                ['l_outcome_id' => $secondClo->l_outcome_id, 'l_outcome' => $secondClo->l_outcome, 'clo_shortphrase' => null, 'bloom_levels' => null],
            ])
            ->assertJsonPath('courses.1.course_id', $optional->course_id)
            ->assertJsonPath('courses.1.course_required', false)
            ->assertJsonPath('courses.1.clos.0.l_outcome_id', $thirdClo->l_outcome_id)
            ->assertJsonPath('courses.2.course_id', $unspecified->course_id)
            ->assertJsonPath('courses.2.course_num', null)
            ->assertJsonPath('courses.2.course_required', null)
            ->assertJsonPath('courses.2.clos', []);
    }

    public function test_endpoint_returns_distributions_with_the_full_ordered_reference(): void
    {
        $program = $this->createProgram();
        $this->signInAsViewer($program);
        $domain = BloomDomain::create(['name' => 'Cognitive']);
        $later = $domain->levels()->create(['position' => 2, 'name' => 'Later']);
        $earlier = $domain->levels()->create(['position' => 1, 'name' => 'Earlier']);
        $earlier->verbs()->create(['term' => 'Example-term']);
        $later->verbs()->create(['term' => 'Unused-term']);
        $course = Course::factory()->create(['course_num' => '301']);
        $program->courses()->attach($course->course_id);
        $course->learningOutcomes()->create(['l_outcome' => 'Example-term']);
        $course->learningOutcomes()->create(['l_outcome' => 'Unrelated text']);

        $this->getJson(route('programWizard.progression', $program->program_id))
            ->assertOk()
            ->assertJsonPath('bloom_reference_available', true)
            ->assertJsonPath('bloom_levels', [
                ['id' => $earlier->id, 'name' => 'Earlier', 'position' => 1],
                ['id' => $later->id, 'name' => 'Later', 'position' => 2],
            ])
            ->assertJsonCount(1, 'course_groups')
            ->assertJsonPath('course_groups.0.course_level', 300)
            ->assertJsonPath('course_groups.0.course_count', 1)
            ->assertJsonPath('course_groups.0.clo_count', 2)
            ->assertJsonPath('course_groups.0.matched_clo_count', 1)
            ->assertJsonPath('course_groups.0.unmatched_clo_count', 1)
            ->assertJsonPath('course_groups.0.levels.0.level_id', $earlier->id)
            ->assertJsonPath('course_groups.0.levels.0.clo_count', 1)
            ->assertJsonPath('course_groups.0.levels.0.percentage', 50)
            ->assertJsonPath('course_groups.0.levels.1.level_id', $later->id)
            ->assertJsonPath('course_groups.0.levels.1.clo_count', 0)
            ->assertJsonPath('course_groups.0.levels.1.percentage', 0)
            ->assertJsonPath('courses.0.clos.0.bloom_levels.0.level_id', $earlier->id)
            ->assertJsonPath('courses.0.clos.1.bloom_levels', []);
    }

    public function test_plo_scope_filters_clos_without_counting_multiple_mappings_twice(): void
    {
        $program = $this->createProgram();
        $this->signInAsViewer($program);
        $plo = $program->programLearningOutcomes()->create(['pl_outcome' => 'Selected outcome']);
        $course = Course::factory()->create(['course_num' => '201']);
        $program->courses()->attach($course->course_id);
        $mapped = $course->learningOutcomes()->create(['l_outcome' => 'Mapped outcome']);
        $course->learningOutcomes()->create(['l_outcome' => 'Unmapped outcome']);
        foreach (['First', 'Second'] as $index => $name) {
            $scale = MappingScale::create([
                'title' => $name, 'abbreviation' => $name, 'description' => $name, 'colour' => '#ffffff',
            ]);
            $program->mappingScaleLevels()->attach($scale->map_scale_id, ['position' => $index + 1]);
            $mapped->programLearningOutcomes()->attach($plo->pl_outcome_id, ['map_scale_id' => $scale->map_scale_id]);
        }

        $this->getJson(route('programWizard.progression', [$program->program_id, 'plo_id' => $plo->pl_outcome_id]))
            ->assertOk()
            ->assertJsonPath('program_totals', ['course_count' => 1, 'clo_count' => 2])
            ->assertJsonPath('scope_totals', ['course_count' => 1, 'clo_count' => 1])
            ->assertJsonPath('plos.0.pl_outcome_id', $plo->pl_outcome_id)
            ->assertJsonPath('has_incomplete_mappings', true)
            ->assertJsonCount(1, 'courses.0.clos')
            ->assertJsonPath('courses.0.clos.0.l_outcome_id', $mapped->l_outcome_id)
            ->assertJsonPath('course_groups.0.clo_count', 1);
    }

    public function test_plo_scope_rejects_invalid_ids_and_another_programs_plo(): void
    {
        $program = $this->createProgram();
        $this->signInAsViewer($program);
        $otherPlo = $this->createProgram()->programLearningOutcomes()->create(['pl_outcome' => 'Other outcome']);

        $this->getJson(route('programWizard.progression', [$program->program_id, 'plo_id' => 'invalid']))
            ->assertUnprocessable()->assertJsonValidationErrors('plo_id');
        $this->getJson(route('programWizard.progression', [$program->program_id, 'plo_id' => $otherPlo->pl_outcome_id]))
            ->assertNotFound();
    }

    public function test_user_without_program_access_cannot_get_progression_data(): void
    {
        $program = $this->createProgram();

        $this->actingAs(User::factory()->create())
            ->getJson(route('programWizard.progression', $program->program_id))
            ->assertRedirect(route('home'));
    }

    public function test_guest_cannot_get_progression_data(): void
    {
        $program = $this->createProgram();

        $this->getJson(route('programWizard.progression', $program->program_id))
            ->assertUnauthorized();
    }
}

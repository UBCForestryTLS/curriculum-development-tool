<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\MappingScale;
use App\Models\Program;
use App\Models\User;
use App\Services\MappingJobCreator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MappingJobCreatorTest extends TestCase
{
    use DatabaseTransactions;

    private Course $course;
    private Program $program;
    private User $user;
    private MappingJobCreator $creator;
    private array $inputs;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->creator = new MappingJobCreator;
        $this->user = User::factory()->create();
        $this->course = Course::factory()->create();
        $this->program = Program::create(['program' => 'Mapping job test', 'level' => 'Undergraduate', 'status' => 0]);
        $this->program->courses()->attach($this->course->course_id);
        $this->inputs = ['course_learning_outcomes' => [], 'program_learning_outcomes' => [], 'mapping_scales' => []];

        // Two CLOs and three PLOs give us six pairs to track.
        foreach (['Apply ecological principles.', 'Evaluate restoration plans.'] as $text) {
            $clo = $this->course->learningOutcomes()->create(['l_outcome' => $text]);
            $this->inputs['course_learning_outcomes'][] = ['l_outcome_id' => $clo->l_outcome_id, 'l_outcome' => $text];
        }
        foreach (['Design restoration plans.', 'Evaluate evidence.', 'Communicate findings.'] as $text) {
            $plo = $this->program->programLearningOutcomes()->create(['pl_outcome' => $text]);
            $this->inputs['program_learning_outcomes'][] = ['pl_outcome_id' => $plo->pl_outcome_id, 'pl_outcome' => $text];
        }
        foreach (['USE', 'EVAL'] as $position => $label) {
            $level = ['title' => $label, 'abbreviation' => $label, 'description' => 'Demonstrate '.$label];
            $scale = MappingScale::create([...$level, 'colour' => '#000000']);
            $this->program->mappingScaleLevels()->attach($scale->map_scale_id, ['position' => $position + 1]);
            $this->inputs['mapping_scales'][] = ['map_scale_id' => $scale->map_scale_id, ...$level];
        }
    }

    public function test_creation_saves_the_api_inputs_and_initial_progress_locally(): void
    {
        $job = $this->creator->createOrGetActive($this->course, $this->program, $this->user)->fresh();

        $this->assertTrue(Str::isUuid($job->job_id));
        $this->assertSame([
            'job_id' => $job->job_id,
            'course_id' => $this->course->course_id,
            'program_id' => $this->program->program_id,
            ...$this->inputs,
        ], $job->input_snapshot);
        $this->assertDatabaseHas('mapping_jobs', [
            'job_id' => $job->job_id,
            'course_id' => $this->course->course_id,
            'program_id' => $this->program->program_id,
            'requested_by' => $this->user->id,
            'status' => 'pending',
            'total_clos' => 2, 'total_plos' => 3, 'total_pairs' => 6,
            'processed_pairs' => 0, 'mapped_pairs' => 0, 'errored_pairs' => 0,
        ]);
        Http::assertNothingSent();
    }

    #[TestWith(['pending'])]
    #[TestWith(['in_progress'])]
    #[TestWith(['importing'])]
    public function test_repeated_submissions_preserve_the_active_job(string $status): void
    {
        $job = $this->creator->createOrGetActive($this->course, $this->program, $this->user);
        $job->update(['status' => $status, 'processed_pairs' => 1, 'mapped_pairs' => 1]);

        // Another user submitting after an edit should still get the original active job.
        $this->course->learningOutcomes()->update(['l_outcome' => 'Changed after submission.']);
        $reused = $this->creator->createOrGetActive($this->course, $this->program, User::factory()->create());

        $this->assertSame($job->job_id, $reused->job_id);
        $this->assertSame($job->getAttributes(), $reused->getAttributes());
        $this->assertDatabaseCount('mapping_jobs', 1);
    }

    #[TestWith(['completed'])]
    #[TestWith(['failed'])]
    public function test_a_finished_job_allows_a_new_run_with_current_inputs(string $status): void
    {
        $original = $this->creator->createOrGetActive($this->course, $this->program, $this->user);
        $original->update(['status' => $status]);
        $this->course->learningOutcomes()->update(['l_outcome' => 'Updated outcome.']);

        $newJob = $this->creator->createOrGetActive($this->course, $this->program, $this->user);

        $this->assertNotSame($original->job_id, $newJob->job_id);
        $this->assertSame('pending', $newJob->status);
        $this->assertSame('Updated outcome.', $newJob->input_snapshot['course_learning_outcomes'][0]['l_outcome']);
        $this->assertSame($original->input_snapshot, $original->fresh()->input_snapshot);
        $this->assertDatabaseCount('mapping_jobs', 2);
    }

    #[TestWith(['course_learning_outcomes'])]
    #[TestWith(['program_learning_outcomes'])]
    #[TestWith(['mapping_scales'])]
    #[TestWith(['course_learning_outcomes.0.l_outcome'])]
    #[TestWith(['mapping_scales.1.abbreviation'])]
    public function test_invalid_inputs_do_not_save_a_job(string $field): void
    {
        // Each case changes one part of an otherwise valid request.
        match ($field) {
            'course_learning_outcomes' => $this->course->learningOutcomes()->delete(),
            'program_learning_outcomes' => $this->program->programLearningOutcomes()->delete(),
            'mapping_scales' => $this->program->mappingScaleLevels()->detach(),
            'course_learning_outcomes.0.l_outcome' => $this->course->learningOutcomes()->update(['l_outcome' => '   ']),
            'mapping_scales.1.abbreviation' => MappingScale::findOrFail($this->inputs['mapping_scales'][1]['map_scale_id'])->update(['abbreviation' => 'USE']),
        };

        try {
            $this->creator->createOrGetActive($this->course, $this->program, $this->user);
            $this->fail('Expected invalid mapping inputs to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertDatabaseCount('mapping_jobs', 0);
    }

    public function test_a_course_outside_the_program_cannot_create_a_job(): void
    {
        $unlinkedCourse = Course::factory()->create();

        try {
            $this->creator->createOrGetActive($unlinkedCourse, $this->program, $this->user);
            $this->fail('Expected the course/program link to be required.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('mapping_jobs', 0);
        }
    }
}

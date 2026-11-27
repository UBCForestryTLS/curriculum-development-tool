<?php

namespace Tests\Feature;

use App\Jobs\ProcessMappingJob;
use App\Models\Course;
use App\Models\MappingJob;
use App\Models\Program;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProcessMappingJobTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Use the real database queue, but keep locks in memory and block unfaked HTTP calls.
        config(['cache.default' => 'array', 'queue.default' => 'sync', 'services.lo_mapping_api' => [
            'base_url' => 'https://mapping.example.test',
            'key_id' => 'test-id', 'key_secret' => 'test-secret',
            'timeout' => 35, 'connect_timeout' => 5,
        ]]);
        Http::preventStrayRequests();
    }

    public function test_dispatch_uses_the_background_queue_after_commit(): void
    {
        $job = $this->createMappingJob();
        DB::transaction(function () use ($job) {
            ProcessMappingJob::dispatch($job->job_id);
            // Another worker must not pick this up before the local transaction finishes.
            $this->assertSame(0, DB::table('jobs')->where('queue', 'mapping')->count());
        });

        $payload = DB::table('jobs')->where('queue', 'mapping')->sole()->payload;
        $this->assertStringContainsString($job->job_id, $payload);
        $this->assertStringNotContainsString('Evaluate evidence.', $payload);
        $this->assertStringNotContainsString('test-secret', $payload);
        Http::assertNothingSent();
    }

    public function test_each_execution_runs_one_batch_and_saves_progress_until_importing(): void
    {
        $job = $this->createMappingJob();
        Http::fakeSequence()
            ->push($this->jobSummary($job, 'pending'))
            ->push($this->jobSummary($job, 'in_progress', processed: 1, mapped: 1))
            ->push($this->jobSummary($job, 'in_progress', processed: 1, mapped: 1))
            ->push($this->jobSummary($job, 'completed', processed: 2, mapped: 1));

        ProcessMappingJob::dispatch($job->job_id);
        $this->runWorker();
        $this->assertDatabaseHas('mapping_jobs', [
            'job_id' => $job->job_id, 'status' => 'in_progress',
            'processed_pairs' => 1, 'mapped_pairs' => 1, 'errored_pairs' => 0,
        ]);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'mapping')->count());
        Http::assertSentCount(2);

        // Advance past the queue delay without sleeping. The next execution must reuse the same job.
        $this->travel(2)->seconds();
        $this->runWorker();
        $this->assertDatabaseHas('mapping_jobs', [
            'job_id' => $job->job_id, 'status' => 'importing',
            'processed_pairs' => 2, 'mapped_pairs' => 1, 'errored_pairs' => 0,
        ]);
        $this->assertSame(0, DB::table('jobs')->where('queue', 'mapping')->count());
        $url = 'https://mapping.example.test/jobs/'.$job->job_id;
        Http::assertSentInOrder([
            fn ($request) => $request->method() === 'POST' && $request->url() === 'https://mapping.example.test/jobs'
                && $request->data() === $job->input_snapshot,
            fn ($request) => $request->method() === 'POST' && $request->url() === $url.'/run',
            fn ($request) => $request->method() === 'GET' && $request->url() === $url,
            fn ($request) => $request->method() === 'POST' && $request->url() === $url.'/run',
        ]);
        Http::assertSentCount(4);
    }

    #[TestWith(['completed', 2, 0])]
    #[TestWith(['failed', 1, 1])]
    public function test_finished_api_jobs_go_to_importing_without_another_batch(string $status, int $processed, int $errors): void
    {
        $job = $this->createMappingJob('in_progress');
        // A finished API job with pair errors still has outcomes we need to import.
        Http::fakeSequence()->push($this->jobSummary($job, $status, processed: $processed, mapped: 1, errors: $errors));

        ProcessMappingJob::dispatch($job->job_id);
        $this->runWorker();

        $this->assertDatabaseHas('mapping_jobs', [
            'job_id' => $job->job_id, 'status' => 'importing',
            'processed_pairs' => $processed, 'mapped_pairs' => 1, 'errored_pairs' => $errors,
        ]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://mapping.example.test/jobs/'.$job->job_id);
        $this->assertSame(0, DB::table('jobs')->where('queue', 'mapping')->count());
    }

    #[TestWith(['importing'])]
    #[TestWith(['completed'])]
    #[TestWith(['failed'])]
    #[TestWith(['deleted'])]
    public function test_inactive_or_deleted_local_jobs_do_not_send_requests(string $status): void
    {
        Http::fake();
        $job = $this->createMappingJob();
        if ($status === 'deleted') {
            $job->delete();
        } else {
            $job->update(['status' => $status]);
        }
        ProcessMappingJob::dispatch($job->job_id);
        $this->runWorker();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('jobs')->where('queue', 'mapping')->count());
    }

    public function test_a_locked_job_skips_duplicate_work(): void
    {
        Http::fake();
        $job = $this->createMappingJob();
        // Hold the lock as if another worker is already processing this job.
        $lock = Cache::lock('mapping-job:'.$job->job_id, 90);
        $this->assertTrue($lock->get());
        try {
            ProcessMappingJob::dispatch($job->job_id);
            $this->runWorker();
            Http::assertNothingSent();
            $this->assertSame($job->getAttributes(), $job->fresh()->getAttributes());
            $this->assertSame(0, DB::table('jobs')->where('queue', 'mapping')->count());
        } finally {
            $lock->release();
        }
    }

    private function createMappingJob(string $status = 'pending'): MappingJob
    {
        $course = Course::factory()->create();
        $program = Program::create(['program' => 'Mapping worker test', 'level' => 'Undergraduate', 'status' => 0]);
        $jobId = (string) Str::uuid();

        return MappingJob::create([
            'job_id' => $jobId, 'course_id' => $course->course_id, 'program_id' => $program->program_id,
            'status' => $status, 'total_clos' => 1, 'total_plos' => 2, 'total_pairs' => 2,
            'input_snapshot' => [
                'job_id' => $jobId, 'course_id' => $course->course_id, 'program_id' => $program->program_id,
                'course_learning_outcomes' => [['l_outcome_id' => 1, 'l_outcome' => 'Evaluate evidence.']],
                'program_learning_outcomes' => [
                    ['pl_outcome_id' => 1, 'pl_outcome' => 'Evaluate research.'],
                    ['pl_outcome_id' => 2, 'pl_outcome' => 'Communicate evidence.'],
                ],
                'mapping_scales' => [[
                    'map_scale_id' => 1, 'title' => 'Use', 'abbreviation' => 'USE', 'description' => 'Apply knowledge.',
                ]],
            ],
        ])->refresh();
    }

    private function jobSummary(MappingJob $job, string $status, int $processed = 0, int $mapped = 0, int $errors = 0): array
    {
        return [
            'job_id' => $job->job_id, 'course_id' => $job->course_id, 'program_id' => $job->program_id,
            'status' => $status, 'total_pairs' => 2, 'pairs_ready' => true,
            'processed_pairs' => $processed, 'mapped_pairs' => $mapped, 'errored_pairs' => $errors,
        ];
    }

    private function runWorker(): void
    {
        $this->app->make('queue.worker')->runNextJob('mapping', 'mapping', new WorkerOptions(sleep: 0));
    }
}

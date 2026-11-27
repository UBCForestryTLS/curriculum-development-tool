<?php

namespace App\Jobs;

use App\Exceptions\MappingApiException;
use App\Models\MappingJob;
use App\Services\MappingApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessMappingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Allow two API requests at the default 35 seconds each, plus time to save progress.
    // Keep this below the mapping queue's retry_after so another worker doesn't pick it up early
    public int $timeout = 80;
    public bool $failOnTimeout = true;

    public int $tries = 1;

    public function __construct(public string $jobId)
    {
        // Queue only the ID. The inputs stay in our database and credentials stay in config.
        $this->onConnection('mapping');
        $this->onQueue('mapping');
    }

    /** Process at most one batch, then queue another execution if pairs remain. */
    public function handle(MappingApiClient $client): void
    {
        // Workers must share the cache store for this lock. The API also has its own batch lock.
        // If another worker owns this job, let that worker save progress and queue the next batch.
        $continueProcessing = Cache::lock('mapping-job:'.$this->jobId, 90)->get(function () use ($client) {
            $job = MappingJob::find($this->jobId);
            if (!$job || !in_array($job->status, ['pending', 'in_progress'], true)) {
                return false;
            }

            if ($job->status === 'pending') {
                // Reuse the saved UUID and inputs if creation succeeded but its response was lost.
                $progress = $client->createJob($job->input_snapshot);
            } else {
                // Read saved progress first; AWS may already have finished a previous request.
                $progress = $client->getJob($job->job_id);
            }
            $this->saveProgress($job, $progress);
            if ($job->status === 'importing') {
                return false;
            }

            $finishedPairs = $job->processed_pairs + $job->errored_pairs;
            $this->saveProgress($job, $client->runBatch($job->job_id));
            if ($job->status === 'importing') {
                return false;
            }
            // Don't keep scheduling successful HTTP responses that never finish any pairs.
            if ($job->processed_pairs + $job->errored_pairs <= $finishedPairs) {
                throw new MappingApiException('Mapping batch made no progress.', 'retry');
            }

            return true;
        });

        // Queue after releasing the lock so the next execution can acquire it immediately.
        // A new queue job keeps normal batch continuation separate from failure retries.
        if ($continueProcessing) {
            self::dispatch($this->jobId)->delay(1);
        }
    }

    /** Save only matching, consistent progress; finished API jobs still need their results imported. */
    private function saveProgress(MappingJob $job, array $progress): void
    {
        foreach (['processed_pairs', 'mapped_pairs', 'errored_pairs'] as $field) {
            if (!is_int($progress[$field] ?? null) || $progress[$field] < $job->$field) {
                throw new MappingApiException('Mapping API returned invalid progress.');
            }
        }

        $finished = in_array($progress['status'], ['completed', 'failed'], true);
        $finishedPairs = $progress['processed_pairs'] + $progress['errored_pairs'];
        if ($progress['job_id'] !== $job->job_id
            || ($progress['course_id'] ?? null) !== (int) $job->course_id
            || ($progress['program_id'] ?? null) !== (int) $job->program_id
            || ($progress['total_pairs'] ?? null) !== $job->total_pairs
            || ($progress['pairs_ready'] ?? null) !== true
            || $progress['mapped_pairs'] > $progress['processed_pairs']
            || $finishedPairs > $job->total_pairs
            || ($finished && $finishedPairs !== $job->total_pairs)) {
            throw new MappingApiException('Mapping API returned invalid progress.');
        }

        // API status "failed" means processing finished with pair errors. Import those too.
        // Local "failed" is reserved here for a worker that stopped before reaching that stage.
        $job->update([
            'status' => $finished ? 'importing' : 'in_progress',
            'processed_pairs' => $progress['processed_pairs'],
            'mapped_pairs' => $progress['mapped_pairs'],
            'errored_pairs' => $progress['errored_pairs'],
        ]);
    }

    /** Keep the last saved counts when queue processing fails or times out. */
    public function failed(?Throwable $exception): void
    {
        MappingJob::where('job_id', $this->jobId)
            ->whereIn('status', ['pending', 'in_progress'])
            ->update(['status' => 'failed']);
    }
}

<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseProgram;
use App\Models\MappingJob;
use App\Models\Program;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MappingJobCreator
{
    /** Prepare a local job or return the active one. The caller must check the user's edit access first. */
    public function createOrGetActive(Course $course, Program $program, User $requestedBy): MappingJob
    {
        return DB::transaction(function () use ($course, $program, $requestedBy) {
            // Lock the existing link, since there may not be a job row to lock yet.
            // A second submission waits here, then sees the job created by the first one.
            CourseProgram::where('course_id', $course->course_id)
                ->where('program_id', $program->program_id)
                ->orderBy('id')->lockForUpdate()->firstOrFail();

            $activeJob = MappingJob::where('course_id', $course->course_id)
                ->where('program_id', $program->program_id)
                ->whereIn('status', MappingJob::ACTIVE_STATUSES)->first();
            if ($activeJob) {
                return $activeJob;
            }

            $jobId = (string) Str::uuid();
            $inputs = $this->buildInputSnapshot($course, $program, $jobId);
            $totalClos = count($inputs['course_learning_outcomes']);
            $totalPlos = count($inputs['program_learning_outcomes']);

            // Only save locally here. The worker will send this exact request to the API later.
            return MappingJob::create([
                'job_id' => $jobId,
                'course_id' => $course->course_id,
                'program_id' => $program->program_id,
                'requested_by' => $requestedBy->id,
                'input_snapshot' => $inputs,
                'total_clos' => $totalClos,
                'total_plos' => $totalPlos,
                'total_pairs' => $totalClos * $totalPlos,
            ])->refresh();
        });
    }

    /** Read current outcomes and levels into the Python API's request format and reject unusable inputs. */
    private function buildInputSnapshot(Course $course, Program $program, string $jobId): array
    {
        $inputs = [
            'job_id' => $jobId,
            'course_id' => (int) $course->course_id,
            'program_id' => (int) $program->program_id,
            'course_learning_outcomes' => $course->learningOutcomes()->orderBy('l_outcome_id')
                ->get(['l_outcome_id', 'l_outcome'])->toArray(),
            'program_learning_outcomes' => $program->programLearningOutcomes()->orderBy('pl_outcome_id')
                ->get(['pl_outcome_id', 'pl_outcome'])->toArray(),
            'mapping_scales' => $program->mappingScaleLevels()->get()
                ->map(fn ($scale) => $scale->only(['map_scale_id', 'title', 'abbreviation', 'description']))->all(),
        ];

        // Match the API's input requirements without trimming or otherwise changing the saved text.
        $textRules = ['required', 'string', 'regex:/\S/u'];
        Validator::make($inputs, [
            'course_learning_outcomes' => ['required', 'array', 'min:1'],
            'course_learning_outcomes.*.l_outcome_id' => ['required', 'integer', 'min:1', 'distinct:strict'],
            'course_learning_outcomes.*.l_outcome' => $textRules,
            'program_learning_outcomes' => ['required', 'array', 'min:1'],
            'program_learning_outcomes.*.pl_outcome_id' => ['required', 'integer', 'min:1', 'distinct:strict'],
            'program_learning_outcomes.*.pl_outcome' => $textRules,
            'mapping_scales' => ['required', 'array', 'min:1'],
            'mapping_scales.*.map_scale_id' => ['required', 'integer', 'min:1', 'distinct:strict'],
            'mapping_scales.*.title' => $textRules,
            // The model returns these labels, so each one must identify a single level.
            'mapping_scales.*.abbreviation' => [...$textRules, 'distinct:strict'],
            'mapping_scales.*.description' => $textRules,
        ])->validate();

        return $inputs;
    }
}

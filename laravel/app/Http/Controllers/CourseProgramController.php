<?php

namespace App\Http\Controllers;

use App\Helpers\RoleAssignmentHelpers;
use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseProgram;
use App\Models\CourseUser;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\LearningOutcome;
use App\Models\OutcomeMapAiSuggestion;
use App\Models\Program;
use App\Models\ProgramLearningOutcome;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CourseProgramController extends Controller
{
    //

    private $roleAssignmentHelper;
    public function __construct()
    {
        $this->roleAssignmentHelper = new RoleAssignmentHelpers();
    }

    /**
     * Add courses to a program
     */
    public function addCoursesToProgram(Request $request): RedirectResponse
    {
        $this->validate($request, [
            'program_id' => 'required',
        ]);

        $programId = $request->input('program_id');
        // if courseIds is null, use an empty array
        if (!$courseIds = $request->input('selectedCourses')) {
            $courseIds = [];
        }

        $numCoursesAddedSuccessfully = 0;

        // // get all courses that currently belong to this program
        // $currentProgramCourseIds = Program::find($programId)->courses()->pluck('course_programs.course_id');
        // foreach ($currentProgramCourseIds as $currentProgramCourseId) {
        //     if (!in_array(strval($currentProgramCourseId), $courseIds)) {
        //         // delete course program record for the courses that were removed from this program
        //         CourseProgram::where([
        //             ['course_id', $currentProgramCourseId],
        //             ['program_id', $programId],
        //         ])->delete();
        //     }
        // }

        // update or create a programCourse for each course
        foreach ($courseIds as $index => $courseId) {
            $isCourseRequired = $request->input('require' . $courseId);
            // if a courseProgram with course_id and program_id exists then update course_required field else create a new courseProgram record
            CourseProgram::updateOrCreate(
                ['course_id' => $courseId, 'program_id' => $programId],
                ['course_required' => ($isCourseRequired) ? 1 : 0]
            );
            $numCoursesAddedSuccessfully++;

        }

        if ($numCoursesAddedSuccessfully == count($courseIds)) {
            // update courses 'updated_at' field
            $program = Program::find($request->input('program_id'));
            $program->touch();

            // get users name for last_modified_user
            $user = User::find(Auth::id());
            $program->last_modified_user = $user->name;
            $program->save();
            $this->addDirectorsToProgramCourses($program);
            $this->addDepartmentHeadsToProgramCourses($program);

            $request->session()->flash('success', 'Successfully added ' . strval(count($courseIds)) . ' course(s) to this program.');
        } else {
            $request->session()->flash('error', 'There was an error adding ' . strval(count($courseIds) - $numCoursesAddedSuccessfully));
        }

        return redirect()->route('programWizard.step3', $request->input('program_id'));
    }

    /**
     * Helper function to add all program directors to the courses of the program.
     */

    private function addDirectorsToProgramCourses($program)
    {
        $programDirectors = $program->directors()->get();
        $coursesInProgram = $program->courses()->get();
        $programDirectorRole = Role::where('role', 'program director')->first();

        foreach ($programDirectors as $director) {
            foreach ($coursesInProgram as $course) {
                $this->roleAssignmentHelper->addElevatedRoleUserToCourse(
                    $director,
                    $programDirectorRole,
                    $course,
                    $program->program_id,
                    null
                );
            }
        }
    }

    /**
     * Helper function to add all department heads to the courses of the program.
     */
    private function addDepartmentHeadsToProgramCourses($program)
    {
        $errorMessages = Collection::make();
        $department = $this->roleAssignmentHelper->getDepartmentFromEntity($program);
        if ($department) {
            $departmentHeadRole = Role::where('role', 'department head')->first();
            $departmentHeads = $department->heads()->get();
            $coursesInProgram = $program->courses()->get();

            foreach ($departmentHeads as $departmentHead) {
                foreach ($coursesInProgram as $course) {
                    $errorMessage = $this->roleAssignmentHelper->addElevatedRoleUserToCourse(
                        $departmentHead,
                        $departmentHeadRole,
                        $course,
                        $program->program_id,
                        $department->department_id
                    );
                    if ($errorMessage) {
                        $errorMessages->add($errorMessage);
                    }
                }
            }
        }
    }


    public function editCourseRequired(Request $request): RedirectResponse
    {
        $courseId = $request->input('course_id');
        $programId = $request->input('program_id');
        $required = $request->input('required');
        $note = $request->input('note');

        $course = Course::where('course_id', $courseId)->first();

        if ($courseId != null && $programId != null && $required != null) {
            CourseProgram::updateOrCreate(
                ['course_id' => $courseId, 'program_id' => $programId],
                ['course_required' => $required]
            );

            CourseProgram::where(['course_id' => $courseId, 'program_id' => $programId])->update(['note' => $note]);
            // update courses 'updated_at' field
            $program = Program::find($request->input('program_id'));
            $program->touch();

            // get users name for last_modified_user
            $user = User::find(Auth::id());
            $program->last_modified_user = $user->name;
            $program->save();

            $this->addDirectorsToProgramCourses($program);
            $this->addDepartmentHeadsToProgramCourses($program);


            $request->session()->flash('success', 'Successfully updated: ' . strval($course->course_title));
        } else {
            $request->session()->flash('error', 'There was an error updating the course');
        }

        return redirect()->route('programWizard.step3', $request->input('program_id'));
    }

    public function updateManualMapStatus(Request $request, $courseId, $programId)
    {
        $courseProgram = CourseProgram::where(['course_id' => $courseId, 'program_id' => $programId])->first();

        // Mark mapping as hidden
        $courseProgram->manual_map_status = !$courseProgram->manual_map_status;
        $courseProgram->save();
    }

    public function updateAiSuggestionStatus(Request $request, $courseId, $programId)
    {
        $courseProgram = CourseProgram::where(['course_id' => $courseId, 'program_id' => $programId])->first();

        // Mark mapping as hidden
        $courseProgram->ai_suggestion_status = !$courseProgram->ai_suggestion_status;
        $courseProgram->save();
    }

    public function generateAiSuggestions(Request $request, $courseId, $programId)
    {
        try {
            $course = Course::findOrFail($courseId);
            $program = Program::findOrFail($programId);

            $clos = $course->learningOutcomes()->get();
            $plos = $program->programLearningOutcomes()->get();
            $scales = $program->mappingScaleLevels()->get();

            $payload = [
                'course_id' => $courseId,
                'program_id' => $programId,
                'course_learning_outcomes' => $clos->map(function ($clo) {
                    return [
                        'l_outcome_id' => $clo->l_outcome_id,
                        'l_outcome' => $clo->l_outcome,
                    ];
                })->toArray(),
                'program_learning_outcomes' => $plos->map(function ($plo) {
                    return [
                        'pl_outcome_id' => $plo->pl_outcome_id,
                        'pl_outcome' => $plo->pl_outcome,
                    ];
                })->toArray(),
                'mapping_scales' => $scales->map(function ($scale) {
                    return [
                        'map_scale_id' => $scale->map_scale_id,
                        'title' => $scale->title,
                        'abbreviation' => $scale->abbreviation,
                        'description' => $scale->description,
                    ];
                })->toArray(),
            ];

            $loMappingServiceUrl = config('services.lo_mapping.base_url');
            Log::info("Calling lo_mapping_service at: $loMappingServiceUrl/map-program-outcomes");

            $response = Http::post($loMappingServiceUrl . '/map-program-outcomes', $payload);

            if ($response->failed()) {
                Log::error('lo_mapping_service returned an error: ' . $response->body());
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to generate AI suggestions',
                ], 500);
            }

            $data = $response->json();
            Log::info('AI suggestion request submitted successfully: ' . json_encode($data));

            return response()->json([
                'status' => 'success',
                'request_id' => $data['startedForRecordId'] ?? null,
                'job_name' => $data['jobName'] ?? null,
                'message' => $data['message'] ?? 'Request submitted',
            ]);

        } catch (\Exception $e) {
            Log::error('Error generating AI suggestions: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while processing the request',
            ], 500);
        }
    }

    /**
     * Query the lo_mapping_service for in-flight status of multiple (course, program) pairs.
     * Returns ['<programId>' => bool, ...] for the given course/program pairs.
     * Used at Step 5 render time and on-click to check if job is already being worked on.
     */
    public static function getInFlightStatuses(int $courseId, array $programIds): array
    {
        $pairs = array_map(fn(int $pid) => ['course_id' => $courseId, 'program_id' => $pid], $programIds);

        try {
            $loMappingServiceUrl = config('services.lo_mapping.base_url');
            $response = Http::timeout(10)->post(
                $loMappingServiceUrl . '/in-flight-status',
                ['pairs' => $pairs]
            );

            if ($response->failed()) {
                Log::warning('getInFlightStatuses: lo_mapping_service returned an error: ' . $response->body());
                return array_fill_keys($programIds, false);
            }

            $statuses = $response->json('statuses') ?? [];
            $result = array_fill_keys($programIds, false);
            foreach ($statuses as $entry) {
                $pid = (int) ($entry['program_id'] ?? 0);
                $result[$pid] = (bool) ($entry['in_flight'] ?? false);
            }
            return $result;
        } catch (\Exception $e) {
            Log::error('getInFlightStatuses exception: ' . $e->getMessage());
            return array_fill_keys($programIds, false);
        }
    }

    public function checkInFlight(Request $request, int $courseId, int $programId)
    {
        $statuses = self::getInFlightStatuses($courseId, [$programId]);
        return response()->json([
            'in_flight' => $statuses[$programId] ?? false,
        ]);
    }


    /**
     * Checks if AI result is ready in the local DB.
     * If not ready, requests the lo_mapping srvice to poll for and process any
     * ready results for this course-program pair,
     * which will eventually update the local DB when done.
     */
    public function checkAiResults(Request $request, int $courseId, int $programId)
    {
        // Check if complete according to local (laravel) DB
        // storeAiSuggestions has run, ai_suggestion_status is true and we are done.
        $courseProgram = CourseProgram::where(['course_id' => $courseId, 'program_id' => $programId])->first();
        if ($courseProgram && $courseProgram->ai_suggestion_status) {
            return response()->json(['status' => 'complete']);
        }

        // If not yet complete according to local DB:
        // Ask FastAPI to poll for and trigger processing of any
        // ready record for this course-program pair
        try {
            $loMappingServiceUrl = config('services.lo_mapping.base_url');
            $payload = [
                'course_id'  => $courseId,
                'program_id' => $programId,
            ];

            $statusRes = Http::timeout(10)->post(
                $loMappingServiceUrl . '/poll-results-status',
                $payload
            );

            if ($statusRes->ok() && ($statusRes->json('status') ?? null) === 'ready_to_process') {
                Http::timeout(5)->post(
                    $loMappingServiceUrl . '/process-pending-results',
                    $payload
                );
            }
        } catch (\Exception $e) {
            Log::warning('checkAiResults: trigger error (will retry next poll): ' . $e->getMessage());
        }

        return response()->json(['status' => 'pending']);
    }

    public function setManualMapsToAiSuggestions(int $courseId, int $programId)
    {
        // Make manual maps match AI suggestions
        // Leave any rows with existing manual maps untouched
        $cloIds = LearningOutcome::where('course_id', $courseId)->pluck('l_outcome_id');
        $ploIds = ProgramLearningOutcome::where('program_id', $programId)->pluck('pl_outcome_id');

        $aiSuggestions = OutcomeMapAiSuggestion::whereIn('l_outcome_id', $cloIds)
            ->whereIn('pl_outcome_id', $ploIds)
            ->get(['l_outcome_id', 'pl_outcome_id', 'map_scale_id']);

        $alreadyMappedPairs = DB::table('outcome_maps')
            ->whereIn('l_outcome_id', $cloIds)
            ->whereIn('pl_outcome_id', $ploIds)
            ->get(['l_outcome_id', 'pl_outcome_id']);

        foreach ($aiSuggestions as $aiSuggestion) {
            $isAlreadyMapped = $alreadyMappedPairs
                ->where('l_outcome_id', $aiSuggestion->l_outcome_id)
                ->where('pl_outcome_id', $aiSuggestion->pl_outcome_id)
                ->isNotEmpty();
            if ($isAlreadyMapped) {
                continue;
            }
            DB::table('outcome_maps')->insert([
                'l_outcome_id'  => $aiSuggestion->l_outcome_id,
                'pl_outcome_id' => $aiSuggestion->pl_outcome_id,
                'map_scale_id'  => $aiSuggestion->map_scale_id,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    public function storeAiSuggestions(Request $request)
    {
        $courseId = $request->input('course_id');
        $programId = $request->input('program_id');
        $status    = $request->input('status');
        $results   = $request->input('results', []);

        Log::info("Receiving AI suggestions: course_id=$courseId program_id=$programId status=$status results_count=" . count($results));

        if ($status !== 'AWAITING_COMPLETION') {
            Log::warning("AI suggestion job did not complete successfully (status=$status). Skipping suggestion storage.");
            return response()->json([
                'status'  => 'skipped',
                'message' => "Job status was '$status', no suggestions to store.",
            ]);
        }

        $program = Program::find($programId);
        if (!$program) {
            return response()->json(['status' => 'error', 'message' => "Program $programId not found"], 404);
        }

        $abbreviationToScaleId = $program->mappingScaleLevels
            ->pluck('map_scale_id', 'abbreviation')
            ->toArray();

        // Look up the "Not Applicable" scale (used to record AI's "is_mapped: false"
        // suggestions on the N/A column). If not found, those suggestions are skipped
        $notApplicableScaleId = \App\Models\MappingScale::where('map_scale_id', 0)
            ->orWhere('abbreviation', 'N/A')
            ->value('map_scale_id');

        $currentCloIds = LearningOutcome::where('course_id', $courseId)->pluck('l_outcome_id');
        $currentPloIds = ProgramLearningOutcome::where('program_id', $programId)->pluck('pl_outcome_id');

        DB::beginTransaction();
        try {
            $rowsWritten = 0;

            foreach ($results as $result) {
                $cloId     = isset($result['clo_id']) ? (int) $result['clo_id'] : null;
                $ploId     = isset($result['plo_id']) ? (int) $result['plo_id'] : null;
                $isMapped  = $result['is_mapped']  ?? false;
                $mapLabels = $result['map_labels'] ?? [];

                if ($cloId === null || $ploId === null) {
                    Log::warning("Skipping result with missing clo_id or plo_id: " . json_encode($result));
                    continue;
                }

                // If we don't skip deleted LOs, the foreign key constraints causes the whole update to fail
                if (!$currentCloIds->contains($cloId) || !$currentPloIds->contains($ploId)) {
                    Log::info("Skipping result for clo_id=$cloId plo_id=$ploId: outcome was deleted.");
                    continue;
                }

                if (!$isMapped || empty($mapLabels)) {
                    if ($notApplicableScaleId !== null) {
                        OutcomeMapAiSuggestion::updateOrCreate([
                            'l_outcome_id'  => $cloId,
                            'pl_outcome_id' => $ploId,
                            'map_scale_id'  => $notApplicableScaleId,
                        ]);
                        $rowsWritten++;
                    }
                    continue;
                }

                foreach ($mapLabels as $label) {
                    if (!isset($abbreviationToScaleId[$label])) {
                        Log::warning("Unknown mapping scale abbreviation '$label' for program $programId. Skipping.");
                        continue;
                    }
                    OutcomeMapAiSuggestion::updateOrCreate([
                        'l_outcome_id'  => $cloId,
                        'pl_outcome_id' => $ploId,
                        'map_scale_id'  => $abbreviationToScaleId[$label],
                    ]);
                    $rowsWritten++;
                }
            }

            $courseProgram = CourseProgram::where(['course_id' => $courseId, 'program_id' => $programId])->first();
            if ($courseProgram) {
                $courseProgram->ai_suggestion_status = true;
                $courseProgram->save();
            } else {
                Log::warning("CourseProgram pivot not found for course_id=$courseId program_id=$programId. ai_suggestion_status not updated.");
            }

            DB::commit();
            Log::info("AI suggestions stored: $rowsWritten rows.");

            $this->setManualMapsToAiSuggestions($courseId, $programId);

            return response()->json([
                'status'       => 'ok',
                'rows_written' => $rowsWritten,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error storing AI suggestions: ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to store AI suggestions',
            ], 500);
        }
    }
}

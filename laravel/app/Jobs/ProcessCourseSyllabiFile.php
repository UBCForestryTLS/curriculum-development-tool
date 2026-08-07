<?php

namespace App\Jobs;

use App\Helpers\RoleAssignmentHelpers;
use App\Models\AssessmentMethod;
use App\Models\Course;
use App\Models\CourseDescription;
use App\Models\CourseSyllabiFile;
use App\Models\CourseUser;
use App\Models\FacultyCourseCodes;
use App\Models\LearningOutcome;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Bus\Batchable;
use App\Models\CourseTopic;
use App\Models\CourseMaterial;

class ProcessCourseSyllabiFile implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $courseFileId;
    protected $userId;
    private $roleAssignmentHelper;

    /**
     * Create a new job instance.
     */
    public function __construct($courseFile, $userId)
    {
        $this->courseFileId = $courseFile;
        $this->userId = $userId;
        $this->roleAssignmentHelper = new RoleAssignmentHelpers();

    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $serviceBaseUrl = config('services.python_api.base_url');

        $courseFile = CourseSyllabiFile::where('id', $this->courseFileId)->first();

        $rawFileContents = Storage::get($courseFile->file_path);

        try {
            $response = HTTP::acceptJson()
                ->attach(
                    'file',
                    $rawFileContents,
                    $courseFile->file_name
                )
                ->post($serviceBaseUrl . '/create_course_from_syllabi');
        } catch (\Exception $e) {
            // handle any other exception
            Log::error('Failed to get syllabi service response: ' . $e->getMessage());
            throw $e;
        }

        if($response->failed()){
            Log::error('Error in creating course from ' . $courseFile->file_name . ': ' . $response->body());
            throw new \Exception('Error in creating course from ' . $courseFile->file_name);
        }

        // Decode the response to get the course object
        $response = json_decode($response->body());

        if($response->status !='success'){
            Log::error('Error in creating course from ' . $courseFile->file_name . ': ' . $response->message);
            throw new \Exception('Error in creating course from ' . $courseFile->file_name);
        }
        // TO DO: CHANGE AFTER API CALL
        $courseObject = $response->data;
        $course = new Course();
        $course->course_code = $courseObject->code;
        $course->course_num = $courseObject->number;
        $course->delivery_modality = 'O';
        $course->year = $courseObject->year;
        $course->semester = $courseObject->term;
        $course->course_title = $courseObject->title;
        $course->assigned = 1;
        $course->type = 'unassigned';
        $course->save();

        $courseDescription = new CourseDescription();
        $courseDescription->course_id = $course->course_id;
        $courseDescription->description = $courseObject->description;
        $courseDescription->save();

        $courseFile = CourseSyllabiFile::where(['file_name'=> $courseFile->file_name,
            'file_path'=> $courseFile->file_path])->first();
        $courseFile->course_id = $course->course_id;
        $courseFile->save();

        $learningOutcomes = $courseObject->goals;
        $learningOutcomesToInsert = [];
        $timestamp = now();

        foreach($learningOutcomes as $lo){
            $learningOutcomesToInsert[] = [
                'l_outcome' => $lo,
                'clo_shortphrase' => null,
                'course_id' => $course->course_id,
                'pos_in_alignment' => 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        if (!empty($learningOutcomesToInsert)) {
            LearningOutcome::insert($learningOutcomesToInsert);
        }

        $assessmentMethods = $courseObject->assessments;
        $assessmentMethodsToInsert = [];

        foreach($assessmentMethods as $am){
            $assessmentMethodsToInsert[] = [
                'a_method' => $am[0],
                'weight' => $am[1],
                'course_id' => $course->course_id,
                'pos_in_alignment' => 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        if (!empty($assessmentMethodsToInsert)) {
            AssessmentMethod::insert($assessmentMethodsToInsert);
        }

        $courseTopics = $courseObject->topics ?? [];
        $topicsToInsert = [];

        foreach ($courseTopics as $index => $ct) {
            if (!empty($ct)) {
                $topicsToInsert[] = [
                    'course_id' => $course->course_id,
                    'topic' => $ct,
                    'position' => $index + 1,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        if (!empty($topicsToInsert)) {
            CourseTopic::insert($topicsToInsert);
        }

        $courseMaterials = $courseObject->materials ?? [];
        $materialsToInsert = [];

        foreach ($courseMaterials as $index => $cm) {
            if (!empty($cm) && !empty($cm->name)) {
                $materialsToInsert[] = [
                    'course_id' => $course->course_id,
                    'name' => $cm->name,
                    'type' => $cm->type ?? null,
                    'description' => $cm->description ?? null,
                    'is_required' => false,
                    'url' => null,
                    'position' => $index + 1,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        if (!empty($materialsToInsert)) {
            CourseMaterial::insert($materialsToInsert);
        }

        $user = User::where('id', $this->userId)->first();
        $adminAddErrorMessages = $this->roleAssignmentHelper->addAllAdminsToEntity($course);

        //Add department heads and program directors of Faculty of Forestry owners of all courses in the faculty
        if(FacultyCourseCodes::where('course_code', $course->course_code)->exists()){
            $facultyId = FacultyCourseCodes::where('course_code', $course->course_code)->first()->faculty_id;
            $this->roleAssignmentHelper->addFacultyDepartmentHeadsToCourse($course, $facultyId);
            $this->roleAssignmentHelper->addFacultyProgramDirectorsToCourse($course, $facultyId);
        }

        $courseUser = new CourseUser;
        $courseUser->course_id = $course->course_id;
        $courseUser->user_id = $user->id;
        // assign the creator of the course the owner permission
        $courseUser->permission = 1;
        if ($courseUser->save()) {

        } else {
            Log::error('Error in creating course user for ' . $courseFile->file_name);
        }
    }
}

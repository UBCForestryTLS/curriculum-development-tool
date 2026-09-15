<?php

use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\CourseMaterialFile;
use App\Models\CourseTopic;
use Illuminate\Support\Facades\Storage;

function makeTestMaterial(Course $course, string $name = 'Test Material'): CourseMaterial
{
    return CourseMaterial::create([
        'course_id' => $course->course_id,
        'name' => $name,
        'type' => 'article',
    ]);
}

function addCourseTopic(Course $course, string $topic): CourseTopic
{
    return CourseTopic::create([
        'course_id' => $course->course_id,
        'topic' => $topic,
        'position' => CourseTopic::where('course_id', $course->course_id)->max('position') + 1,
    ]);
}

function uploadFixturePdf(Course $course, CourseMaterial $material, string $fixtureName): CourseMaterialFile
{
    $fixturePath = base_path("tests/Browser/TextExtractionE2E/fixtures/{$fixtureName}");
    $diskPath = "course-materials/{$course->course_id}/" . \Illuminate\Support\Str::uuid() . '.pdf';

    Storage::disk('local')->put($diskPath, file_get_contents($fixturePath));

    return CourseMaterialFile::create([
        'course_material_id' => $material->course_material_id,
        'course_id' => $course->course_id,
        'uploaded_by' => Auth::id(),
        'file_name' => $fixtureName,
        'file_path' => $diskPath,
        'file_size' => filesize($fixturePath),
        'status' => CourseMaterialFile::STATUS_PENDING,
        'extraction_engine' => 'tesseract',
        'ocr_enabled' => false,
        'ocr_threshold' => 0,
    ]);
}

function materialFileUrl(Course $course, CourseMaterial $material, CourseMaterialFile $file): string
{
    return "/courses/{$course->course_id}/materials/{$material->course_material_id}/files/{$file->course_material_file_id}";
}

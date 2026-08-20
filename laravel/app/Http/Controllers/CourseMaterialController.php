<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class CourseMaterialController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'verified']);
    }

    public function index(): RedirectResponse
    {
        return redirect()->back();
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'course_id' => ['required', 'exists:courses,course_id'],
            'current_material.*.name' => ['required', 'string'],
            'current_material.*.type' => ['required', 'string'],
            'new_material.*.name' => ['required', 'string'],
            'new_material.*.type' => ['required', 'string'],
        ]);

        try {
            $courseId = $request->input('course_id');
            $currentMaterial = $request->input('current_material');
            $newMaterial = $request->input('new_material');

            $course = Course::find($courseId);

            if (!$currentMaterial && !$newMaterial) {
                $course->courseMaterials()->delete();
            }

            $courseMaterials = $course->courseMaterials;

            foreach ($courseMaterials as $material) {
                if ($currentMaterial && array_key_exists($material->course_material_id, $currentMaterial)) {
                    $materialData = $currentMaterial[$material->course_material_id];

                    $material->name = $materialData['name'];
                    $material->type = $materialData['type'] ?? null;
                    $material->description = $materialData['description'] ?? null;
                    $material->is_required = isset($materialData['is_required']);
                    $material->url = $materialData['url'] ?? null;

                    $material->save();
                } else {
                    $material->delete();
                }
            }

            if ($newMaterial) {
                $materialsToInsert = [];
                $timestamp = now();

                foreach ($newMaterial as $index => $newmat) {
                    if (empty(trim($newmat['name'] ?? ''))) {
                        continue;
                    }

                    $materialsToInsert[] = [
                        'name' => $newmat['name'],
                        'type' => $newmat['type'] ?? null,
                        'description' => $newmat['description'] ?? null,
                        'is_required' => isset($newmat['is_required']),
                        'url' => $newmat['url'] ?? null,
                        'course_id' => $courseId,
                        'position' => $index + 1,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }

                if (!empty($materialsToInsert)) {
                    CourseMaterial::insert($materialsToInsert);
                }
            }

            $course = Course::find($courseId);
            $course->touch();

            $user = User::find(Auth::id());
            $course->last_modified_user = $user->name;
            $course->save();

            $request->session()->flash('success', 'Your course material was updated successfully!');
        } catch (Throwable $exception) {
            $request->session()->flash('error', 'There was an error updating your course material.');
        } finally {
            return redirect()->route('courseWizard.step10', $request->input('course_id'));
        }
    }

    public function destroy(Request $request, $course_material_id): RedirectResponse
    {
        $course_id = $request->input('course_id');
        $courseMaterial = CourseMaterial::where('course_material_id', $course_material_id)
            ->where('course_id', $course_id)
            ->first();

        if ($courseMaterial && $courseMaterial->delete()) {
            $course = Course::find($course_id);
            $course->touch();

            $user = User::find(Auth::id());
            $course->last_modified_user = $user->name;
            $course->save();

            $request->session()->flash('success', 'Course material has been deleted.');
        } else {
            $request->session()->flash('error', 'There was an error deleting the course material.');
        }

        return redirect()->route('courseWizard.step10', $request->input('course_id'));
    }
}

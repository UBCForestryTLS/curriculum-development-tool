<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SavedSearchFilterController extends Controller
{
    /**
     * Store a named search filter preset for the authenticated user.
     */
    public function store(Request $request): RedirectResponse
    {
        $userId = $request->user()->id;
        $availableProperties = [
            'course',
            'topics',
            'learning_outcomes',
            'assessments',
            'descriptions',
            'materials',
        ];

        $validated = $request->validate([
            //these are all the validation rules so laravel can check the req against every rule
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('saved_search_filters', 'name')//only allow unique names per user
                    ->where(fn ($query) => $query->where('user_id', $userId)),
            ],
            'view' => ['nullable', 'in:courses,programs'],
            'properties' => ['nullable', 'array'],
            'properties.*' => ['in:' . implode(',', $availableProperties)],
            'course_codes' => ['nullable', 'array'],
            'course_codes.*' => ['nullable', 'string', 'max:10'],
            'course_levels' => ['nullable', 'array'],
            'course_levels.*' => ['nullable', 'in:100,200,300,400,500,600'],
            'program_ids' => ['nullable', 'array'],
            'program_ids.*' => ['nullable', 'integer', 'exists:programs,program_id'],
        ]);

        $filters = [
            //filter normalization: This code block makes it so the controller creates one consistent filter structure
            //with normalized properties that are in line with the core search engine
            'view' => $validated['view'] ?? 'courses',
            'properties' => collect($validated['properties'] ?? $availableProperties)
                ->unique()
                ->values()
                ->all(),

            'course_codes' => collect($validated['course_codes'] ?? [])
                ->filter(fn ($code) => is_string($code) && trim($code) !== '')
                ->map(fn ($code) => strtoupper(trim($code)))
                ->unique()
                ->values()
                ->all(),

            'course_levels' => collect($validated['course_levels'] ?? [])
                ->filter(fn ($level) => $level !== null && $level !== '')
                ->map(fn ($level) => (string) $level)
                ->unique()
                ->values()
                ->all(),

            'program_ids' => collect($validated['program_ids'] ?? [])
                ->filter(fn ($programId) => is_numeric($programId))
                ->map(fn ($programId) => (int) $programId)
                ->unique()
                ->values()
                ->all(),
        ];

        $request->user()->savedSearchFilters()->create([
            'name' => trim($validated['name']),
            'filters' => $filters,
        ]);

        return redirect()->back()->with('success', 'Search filter saved successfully.');
    }

    /**
     * Delete a saved search filter owned by the authenticated user.
     */
    public function destroy(Request $request, int $savedFilterId): RedirectResponse
    {
        $savedFilter = $request->user()
            ->savedSearchFilters()
            ->findOrFail($savedFilterId);

        $savedFilter->delete();

        return redirect()->back()->with('success', 'Saved search filter deleted successfully.');
    }
}

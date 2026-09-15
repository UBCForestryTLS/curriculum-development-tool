<?php

namespace App\Http\Requests;

use App\Helpers\SearchFilterOptions;

class SearchRequestRules
{
    /**
     * Get the validation rules shared by search pages and result exports.
     */
    public static function shared(): array
    {
        return [
            'view' => ['nullable', 'in:courses,programs'],
            'property_filters_applied' => ['nullable', 'boolean'],
            'properties' => ['nullable', 'array'],
            'properties.*' => [SearchFilterOptions::propertyValidationRule()],
            'course_filters_applied' => ['nullable', 'boolean'],
            'course_codes' => ['nullable', 'array'],
            'course_codes.*' => ['nullable', 'string', 'max:10'],
            'course_levels' => ['nullable', 'array'],
            'course_levels.*' => ['nullable', 'in:100,200,300,400,500,600'],
            'program_filters_applied' => ['nullable', 'boolean'],
            'program_ids' => ['nullable', 'array'],
            'program_ids.*' => ['nullable', 'integer'],
        ];
    }
}

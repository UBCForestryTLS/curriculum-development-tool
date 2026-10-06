<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProgramReportExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array((int) $this->user()->effectivePermissionForProgram(
            $this->route('program')->program_id,
        ), [1, 2, 3], true);
    }

    public function rules(): array
    {
        $rules = [
            'format' => ['required', 'in:pdf,xlsx'],
            'units' => ['nullable', 'in:counts,percentages'],
        ];

        return $rules + ($this->routeIs('programReports.exportGapCoverage') ? [
            'expectations' => ['nullable', 'array'],
            'metric' => ['nullable', 'in:mapped_clo_count,covering_course_count,required_course_count,non_required_course_count'],
        ] : [
            'plo_id' => ['nullable', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'view' => ['nullable', 'in:comparison,progression'],
        ]);
    }
}

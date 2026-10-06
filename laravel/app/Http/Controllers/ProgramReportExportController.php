<?php

namespace App\Http\Controllers;

use App\Helpers\GapCoverageReport;
use App\Helpers\ProgramProgression;
use App\Http\Requests\ProgramReportExportRequest;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ProgramReportExportController extends Controller
{
    public function gapCoverage(ProgramReportExportRequest $request, Program $program): JsonResponse
    {
        $options = $request->validated();
        $options['units'] = $options['units'] ?? 'percentages';
        $options['metric'] = $options['metric'] ?? 'mapped_clo_count';
        $report = GapCoverageReport::report($program, $options['expectations'] ?? null);
        // Keep only normalized applied settings in the prepared export.
        $options['expectations'] = $report['expectations'];

        return $this->preparedResponse($program, 'gap-and-redundancy', $options, $report);
    }

    public function progression(ProgramReportExportRequest $request, Program $program): JsonResponse
    {
        $options = $request->validated();
        $options['units'] = $options['units'] ?? 'percentages';
        $options['view'] = $options['view'] ?? 'comparison';
        $options['plo_id'] = isset($options['plo_id']) ? (int) $options['plo_id'] : null;
        $report = ProgramProgression::report($program, $options['plo_id']);

        return $this->preparedResponse($program, 'progression', $options, $report);
    }

    /** Format writers will consume this prepared data in the next export steps. */
    private function preparedResponse(Program $program, string $reportName, array $options, array $report): JsonResponse
    {
        $generatedAt = now();
        $programSlug = Str::slug(Str::limit($program->program, 80, '')) ?: 'program';

        return response()->json([
            'program_name' => $program->program,
            'generated_at' => $generatedAt->toIso8601String(),
            'filename' => "{$programSlug}-{$program->program_id}-{$reportName}-{$generatedAt->format('Y-m-d')}.{$options['format']}",
            'options' => $options,
            'report' => $report,
        ]);
    }
}

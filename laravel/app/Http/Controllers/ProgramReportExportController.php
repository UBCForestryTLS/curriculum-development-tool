<?php

namespace App\Http\Controllers;

use App\Exports\GapCoverageSpreadsheet;
use App\Helpers\GapCoverageReport;
use App\Helpers\ProgramProgression;
use App\Http\Requests\ProgramReportExportRequest;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProgramReportExportController extends Controller
{
    public function gapCoverage(ProgramReportExportRequest $request, Program $program): JsonResponse|StreamedResponse
    {
        $options = $request->validated();
        $options['units'] = $options['units'] ?? 'percentages';
        $options['metric'] = $options['metric'] ?? 'mapped_clo_count';
        $report = GapCoverageReport::report($program, $options['expectations'] ?? null);
        // Keep only normalized applied settings in the prepared export.
        $options['expectations'] = $report['expectations'];

        $export = $this->prepare($program, 'gap-and-redundancy', $options, $report);
        if ($options['format'] === 'xlsx') {
            $spreadsheet = (new GapCoverageSpreadsheet)->build($export);

            return response()->streamDownload(function () use ($spreadsheet) {
                try {
                    (new Xlsx($spreadsheet))->save('php://output');
                } finally {
                    $spreadsheet->disconnectWorksheets();
                }
            }, $export['filename'], ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->json($export);
    }

    public function progression(ProgramReportExportRequest $request, Program $program): JsonResponse
    {
        $options = $request->validated();
        $options['units'] = $options['units'] ?? 'percentages';
        $options['view'] = $options['view'] ?? 'comparison';
        $options['plo_id'] = isset($options['plo_id']) ? (int) $options['plo_id'] : null;
        $report = ProgramProgression::report($program, $options['plo_id']);

        return response()->json($this->prepare($program, 'progression', $options, $report));
    }

    /** Share report metadata across export formats. */
    private function prepare(Program $program, string $reportName, array $options, array $report): array
    {
        $generatedAt = now();
        $programSlug = Str::slug(Str::limit($program->program, 80, '')) ?: 'program';

        return [
            'program_name' => $program->program,
            'generated_at' => $generatedAt->toIso8601String(),
            'filename' => "{$programSlug}-{$program->program_id}-{$reportName}-{$generatedAt->format('Y-m-d')}.{$options['format']}",
            'options' => $options,
            'report' => $report,
        ];
    }
}

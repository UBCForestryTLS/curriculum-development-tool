<?php

namespace App\Http\Controllers;

use App\Exports\GapCoverageSpreadsheet;
use App\Exports\ProgressionSpreadsheet;
use App\Helpers\GapCoverageReport;
use App\Helpers\ProgramProgression;
use App\Helpers\ProgramReportChart;
use App\Http\Requests\ProgramReportExportRequest;
use App\Models\Program;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response;
use PDF;

class ProgramReportExportController extends Controller
{
    public function gapCoverage(ProgramReportExportRequest $request, Program $program): Response
    {
        $options = $request->validated();
        $options['units'] = $options['units'] ?? 'percentages';
        $options['metric'] = $options['metric'] ?? 'mapped_clo_count';
        $report = GapCoverageReport::report($program, $options['expectations'] ?? null);
        // Keep only normalized applied settings in the prepared export.
        $options['expectations'] = $report['expectations'];

        $export = $this->prepare($program, 'gap-and-redundancy', $options, $report);
        if ($options['format'] === 'xlsx') {
            return $this->downloadSpreadsheet((new GapCoverageSpreadsheet)->build($export), $export['filename']);
        }

        $export['chartImage'] = ProgramReportChart::image(ProgramReportChart::gapCoverage($report, $options));

        return PDF::loadView('programs.exports.gap-coverage', $export)
            ->setPaper('a4')->download($export['filename']);
    }

    public function progression(ProgramReportExportRequest $request, Program $program): Response
    {
        $options = $request->validated();
        $options['units'] = $options['units'] ?? 'percentages';
        $options['view'] = $options['view'] ?? 'comparison';
        $options['plo_id'] = isset($options['plo_id']) ? (int) $options['plo_id'] : null;
        $report = ProgramProgression::report($program, $options['plo_id']);

        $export = $this->prepare($program, 'progression', $options, $report);
        if ($options['format'] === 'xlsx') {
            return $this->downloadSpreadsheet((new ProgressionSpreadsheet)->build($export), $export['filename']);
        }

        $export['chartImage'] = ProgramReportChart::image(ProgramReportChart::progression($report, $options));

        return PDF::loadView('programs.exports.progression', $export)
            ->setPaper('a4')->download($export['filename']);
    }

    private function downloadSpreadsheet(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheet) {
            try {
                (new Xlsx($spreadsheet))->save('php://output');
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
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

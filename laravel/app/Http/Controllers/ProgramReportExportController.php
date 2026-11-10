<?php

namespace App\Http\Controllers;

use App\Exports\GapCoverageSpreadsheet;
use App\Exports\ProgressionSpreadsheet;
use App\Helpers\GapCoverageReport;
use App\Helpers\ProgramProgression;
use App\Helpers\ProgramReportChart;
use App\Http\Requests\ProgramReportExportRequest;
use App\Models\Program;
use Illuminate\Support\Facades\Log;
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
        // Add export details to existing warnings and exception logs without logging errors twice.
        Log::withContext([
            'export_report' => 'gap-and-redundancy',
            'program_id' => (int) $program->program_id,
            'export_format' => $options['format'],
            'plo_id' => null,
            'export_stage' => 'prepare_report',
        ]);
        $report = GapCoverageReport::report($program, $options['expectations'] ?? null);
        // Keep only normalized applied settings in the prepared export.
        $options['expectations'] = $report['expectations'];

        $export = $this->prepare($program, 'gap-and-redundancy', $options, $report);
        if ($options['format'] === 'xlsx') {
            Log::withContext(['export_stage' => 'build_spreadsheet']);
            return $this->downloadSpreadsheet((new GapCoverageSpreadsheet)->build($export), $export['filename']);
        }

        Log::withContext(['export_stage' => 'generate_chart']);
        $export['chartImage'] = ProgramReportChart::image(ProgramReportChart::gapCoverage($report, $options));

        Log::withContext(['export_stage' => 'render_pdf']);
        return PDF::loadView('programs.exports.gap-coverage', $export)
            ->setPaper('a4')->download($export['filename']);
    }

    public function progression(ProgramReportExportRequest $request, Program $program): Response
    {
        $options = $request->validated();
        $options['units'] = $options['units'] ?? 'percentages';
        $options['view'] = $options['view'] ?? 'comparison';
        $options['plo_id'] = isset($options['plo_id']) ? (int) $options['plo_id'] : null;
        Log::withContext([
            'export_report' => 'progression',
            'program_id' => (int) $program->program_id,
            'export_format' => $options['format'],
            'plo_id' => $options['plo_id'],
            'export_stage' => 'prepare_report',
        ]);
        $report = ProgramProgression::report($program, $options['plo_id']);
        $scopeTotals = $report['scope_totals'] ?? $report['program_totals'];
        if (! $report['bloom_reference_available'] && $scopeTotals['clo_count'] > 0) {
            Log::warning('The progression report is being exported without Bloom classifications because the cognitive Bloom reference is unavailable.', [
                'clo_count' => $scopeTotals['clo_count'],
            ]);
        }
        // Use the same scoped distribution for the PDF chart, tables and Excel sheet.
        $report['cognitive_distribution'] = ProgramProgression::summarizeCognitiveLevels($report);

        $export = $this->prepare($program, 'progression', $options, $report);
        if ($options['format'] === 'xlsx') {
            Log::withContext(['export_stage' => 'build_spreadsheet']);
            return $this->downloadSpreadsheet((new ProgressionSpreadsheet)->build($export), $export['filename']);
        }

        Log::withContext(['export_stage' => 'generate_chart']);
        $export['chartImage'] = ProgramReportChart::image(ProgramReportChart::progression($report, $options));

        Log::withContext(['export_stage' => 'render_pdf']);
        return PDF::loadView('programs.exports.progression', $export)
            ->setPaper('a4')->download($export['filename']);
    }

    private function downloadSpreadsheet(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheet) {
            Log::withContext(['export_stage' => 'write_spreadsheet']);
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

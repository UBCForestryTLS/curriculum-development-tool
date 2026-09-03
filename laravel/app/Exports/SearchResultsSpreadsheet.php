<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SearchResultsSpreadsheet
{
    private const STAT_LABELS = [
        'courses' => 'Courses',
        'programs' => 'Programs',
        'topics' => 'Topics',
        'learning_outcomes' => 'Learning Objectives',
        'assessments' => 'Assessments',
        'descriptions' => 'Descriptions',
        'materials' => 'Materials',
        'material_content' => 'Material Content',
    ];

    private const MATCH_STAT_LABELS = [
        'topics' => 'Topic Matches',
        'learning_outcomes' => 'Learning Objective Matches',
        'assessments' => 'Assessment Matches',
        'descriptions' => 'Description Matches',
        'materials' => 'Material Matches',
        'material_content' => 'Material Content Matches',
    ];

    /**
     * Builds the always-present search parameters and result summary sheets.
     *
     * @param array $filters The normalized search filters.
     * @param array $searchData The complete access-controlled search results and statistics.
     * @param array $filterSummary The selected filters formatted for display.
     */
    public function build(array $filters, array $searchData, array $filterSummary): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        $this->buildParametersSheet(
            $spreadsheet->getActiveSheet(),
            $filters,
            $searchData['stats'],
            $filterSummary,
        );
        $this->buildSummarySheet($spreadsheet->createSheet(), $filters['selectedView'], $searchData);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function buildParametersSheet(
        Worksheet $sheet,
        array $filters,
        array $stats,
        array $filterSummary,
    ): void {
        $sheet->setTitle('Search Parameters');
        $sheet->fromArray(['Category', 'Item', 'Value'], null, 'A1');

        $rows = [
            ['Search', 'Search Term', $filters['searchTerm']],
            ['Search', 'Result View', ucfirst($filters['selectedView'])],
            ['Search', 'Generated At', now()->format('Y-m-d H:i:s')],
        ];

        foreach ($filterSummary as $label => $value) {
            $rows[] = ['Filter', $label, $value];
        }

        foreach (self::STAT_LABELS as $key => $label) {
            $rows[] = ['Overall Statistic', $label, $stats[$key] ?? 0];
        }

        $sheet->fromArray($rows, null, 'A2');
        $this->formatSheet($sheet, count($rows) + 1, 3);
    }

    private function buildSummarySheet(Worksheet $sheet, string $selectedView, array $searchData): void
    {
        $sheet->setTitle('Search Summary');

        if ($selectedView === 'programs') {
            $this->buildProgramSummary($sheet, $searchData['programResults']);

            return;
        }

        $this->buildCourseSummary($sheet, $searchData['results']);
    }

    private function buildCourseSummary(Worksheet $sheet, $results): void
    {
        $headings = [
            'Course Code',
            'Course Number',
            'Course Title',
            'Programs',
            'Relevance Score',
            'Course Identity Match',
            ...array_values(self::MATCH_STAT_LABELS),
        ];
        $sheet->fromArray($headings, null, 'A1');

        $rows = $results->map(function ($course) {
            return [
                $course->course_code,
                $course->course_num,
                $course->course_title,
                $course->programs->pluck('program')->implode(', '),
                $course->score,
                $course->is_course_match ? 1 : 0,
                ...collect(array_keys(self::MATCH_STAT_LABELS))
                    ->map(fn ($key) => $course->match_stats[$key] ?? 0)
                    ->all(),
            ];
        })->all();

        if (!empty($rows)) {
            $sheet->fromArray($rows, null, 'A2');
        }

        $this->formatSheet($sheet, count($rows) + 1, count($headings));
    }

    private function buildProgramSummary(Worksheet $sheet, $programResults): void
    {
        $headings = [
            'Program',
            'Program Name Match',
            'Matching Courses',
            'Course Identity Matches',
            ...array_values(self::MATCH_STAT_LABELS),
        ];
        $sheet->fromArray($headings, null, 'A1');

        $rows = $programResults->map(function ($program) {
            $courses = $program->courses;

            return [
                $program->program,
                $program->program_match_snippet ? 1 : 0,
                $courses->count(),
                $courses->where('is_course_match', true)->count(),
                ...collect(array_keys(self::MATCH_STAT_LABELS))
                    ->map(fn ($key) => $courses->sum(fn ($course) => $course->match_stats[$key] ?? 0))
                    ->all(),
            ];
        })->all();

        if (!empty($rows)) {
            $sheet->fromArray($rows, null, 'A2');
        }

        $this->formatSheet($sheet, count($rows) + 1, count($headings));
    }

    private function formatSheet(Worksheet $sheet, int $lastRow, int $lastColumn): void
    {
        $lastColumnLetter = $sheet->getCell([$lastColumn, 1])->getColumn();

        $sheet->getStyle("A1:{$lastColumnLetter}1")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'color' => ['rgb' => 'C6E0F5'],
            ],
        ]);
        $sheet->setAutoFilter("A1:{$lastColumnLetter}{$lastRow}");
        $sheet->freezePane('A2');

        foreach (range(1, $lastColumn) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }
    }
}

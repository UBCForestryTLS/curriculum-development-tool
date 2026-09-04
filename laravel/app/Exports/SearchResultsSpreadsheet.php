<?php

namespace App\Exports;

use Illuminate\Support\Collection;
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

    private const PROPERTY_SHEETS = [
        'Topics' => 'topic',
        'Learning Objectives' => 'learning outcome',
        'Assessments' => 'assessment',
        'Descriptions' => 'description',
        'Materials' => 'material',
        'Material Content' => 'material content',
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
        $this->buildPropertySheets($spreadsheet, $filters['selectedView'], $searchData);

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

        $sheet->fromArray($rows, null, 'A2', true);
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
            $sheet->fromArray($rows, null, 'A2', true);
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
            $sheet->fromArray($rows, null, 'A2', true);
        }

        $this->formatSheet($sheet, count($rows) + 1, count($headings));
    }

    private function buildPropertySheets(Spreadsheet $spreadsheet, string $selectedView, array $searchData): void
    {
        if ($selectedView === 'programs') {
            $this->buildProgramNameSheet($spreadsheet, $searchData['programResults']);
        }

        $courses = $this->coursesForView($selectedView, $searchData);
        $this->buildCourseIdentitySheet($spreadsheet, $courses);

        foreach (self::PROPERTY_SHEETS as $sheetTitle => $property) {
            $rows = $courses->flatMap(function ($course) use ($property) {
                return $course->matches
                    ->where('property', $property)
                    ->map(fn ($match) => [
                        $course->course_code,
                        $course->course_num,
                        $course->course_title,
                        $course->programs->pluck('program')->implode(', '),
                        $match->matched_text,
                        $match->file_name,
                        $match->page_number,
                    ]);
            })->values();

            if ($rows->isEmpty()) {
                continue;
            }

            $headings = ['Course Code', 'Course Number', 'Course Title', 'Programs', 'Matched Text'];

            if ($property === 'material content') {
                $headings = [...$headings, 'File Name', 'Page Number'];
            } else {
                $rows = $rows->map(fn ($row) => array_slice($row, 0, 5));
            }

            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($sheetTitle);
            $sheet->fromArray($headings, null, 'A1');
            $sheet->fromArray($rows->all(), null, 'A2', true);
            $this->formatDetailSheet($sheet, $rows->count() + 1, count($headings));
        }
    }

    private function coursesForView(string $selectedView, array $searchData): Collection
    {
        if ($selectedView === 'programs') {
            return $searchData['programResults']
                ->flatMap(fn ($program) => $program->courses)
                ->unique('course_id')
                ->values();
        }

        return $searchData['results'];
    }

    private function buildProgramNameSheet(Spreadsheet $spreadsheet, Collection $programResults): void
    {
        $rows = $programResults
            ->where('is_program_match', true)
            ->map(fn ($program) => [$program->program])
            ->values();

        if ($rows->isEmpty()) {
            return;
        }

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Program Names');
        $sheet->fromArray(['Program'], null, 'A1');
        $sheet->fromArray($rows->all(), null, 'A2', true);
        $this->formatSheet($sheet, $rows->count() + 1, 1);
    }

    private function buildCourseIdentitySheet(Spreadsheet $spreadsheet, Collection $courses): void
    {
        $rows = $courses
            ->where('is_course_match', true)
            ->map(fn ($course) => [
                $course->course_code,
                $course->course_num,
                $course->course_title,
                $course->programs->pluck('program')->implode(', '),
            ])
            ->values();

        if ($rows->isEmpty()) {
            return;
        }

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Course Identity');
        $sheet->fromArray(['Course Code', 'Course Number', 'Course Title', 'Programs'], null, 'A1');
        $sheet->fromArray($rows->all(), null, 'A2', true);
        $this->formatSheet($sheet, $rows->count() + 1, 4);
    }

    private function formatDetailSheet(Worksheet $sheet, int $lastRow, int $lastColumn): void
    {
        $this->formatSheet($sheet, $lastRow, $lastColumn);
        $sheet->getColumnDimension('E')->setAutoSize(false)->setWidth(80);
        $sheet->getStyle("E2:E{$lastRow}")->getAlignment()->setWrapText(true);
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

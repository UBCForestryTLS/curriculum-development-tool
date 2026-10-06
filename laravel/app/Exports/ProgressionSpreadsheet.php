<?php

namespace App\Exports;

use App\Helpers\ProgramProgression;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class ProgressionSpreadsheet extends ReportSpreadsheet
{
    public function build(array $export): Spreadsheet
    {
        $report = $export['report'];
        $available = $report['bloom_reference_available'];
        $plo = $report['selected_plo'];
        $clos = collect($report['courses'])->flatMap(fn ($course) => $course['clos'])->unique('l_outcome_id');
        $matched = $available ? $clos->filter(fn ($clo) => ! empty($clo['bloom_levels']))->count() : null;
        $reviewCount = $available ? $clos->filter(fn ($clo) => $this->needsReview($clo))->count() : null;
        $book = new Spreadsheet;
        $summary = $book->getActiveSheet()->setTitle('Summary');
        $this->writeRows($summary, [
            ['Curriculum Progression Report', 'Value'],
            ['Program', $export['program_name']],
            ['Program ID', $report['program_id']],
            ['Generated at', $export['generated_at']],
            ['Scope', $plo === null ? 'Entire program' : 'Selected PLO'],
            ['PLO ID', $plo['pl_outcome_id'] ?? null],
            ['PLO', $plo['plo_shortphrase'] ?? null],
            ['PLO description', $plo['pl_outcome'] ?? null],
            ['Program courses', $report['program_totals']['course_count']],
            ['Program CLOs', $report['program_totals']['clo_count']],
            ['Courses in scope', $report['scope_totals']['course_count']],
            ['CLOs in scope', $report['scope_totals']['clo_count']],
            ['Matched CLOs', $matched],
            ['Unmatched CLOs', $available ? $clos->count() - $matched : null],
            ['CLOs suggested for review', $reviewCount],
            ['Bloom reference', $available ? 'Available' : 'Unavailable. Classification values are blank.'],
            ['Mapping warning', $report['has_incomplete_mappings']
                ? 'Program mappings are incomplete. PLO scope may be incomplete.' : 'None'],
            ['Percentages', 'Each level uses all CLOs in its course group, including unmatched CLOs. Overlapping matches can total above 100%.'],
            ['Unmatched', 'No cognitive Bloom level matched the CLO text.'],
            ['Review suggested', 'CLOs matching three or more distinct cognitive levels. This is a suggestion to check the classification.'],
            ['Blank percentages', 'No CLOs in the group, or the Bloom reference is unavailable.'],
        ], false);
        $summary->getColumnDimension('B')->setWidth(85);

        $distributions = [['Course group', 'Courses', 'CLOs', 'Classification', 'CLO count', 'Percentage (%)']];
        foreach ($report['course_groups'] as $group) {
            $prefix = [$this->groupLabel($group['course_level']), $group['course_count'], $group['clo_count']];
            foreach ($group['levels'] as $level) {
                $distributions[] = [...$prefix, $level['name'], $level['clo_count'], $level['percentage']];
            }
            $distributions[] = [...$prefix, 'Unmatched', $group['unmatched_clo_count'],
                $available && $group['clo_count'] > 0 ? round(100 * $group['unmatched_clo_count'] / $group['clo_count'], 2) : null];
        }
        $this->writeRows($book->createSheet()->setTitle('Course-group distributions'), $distributions);

        $details = [['Course group', 'Course ID', 'Course code', 'Course number', 'Course title', 'Required',
            'CLO ID', 'CLO', 'CLO description', 'Classification', 'Bloom level', 'Matched terms', 'Review suggested']];
        foreach (ProgramProgression::groupCourses(collect($report['courses'])) as $group => $courses) {
            foreach ($courses as $course) {
                foreach ($course['clos'] as $clo) {
                    $matches = $clo['bloom_levels'] ?? [];
                    $status = ! $available ? 'Reference unavailable' : ($matches === [] ? 'Unmatched' : 'Matched');
                    // One row per matched level keeps its terms together; unmatched CLOs still get a row.
                    foreach ($matches ?: [null] as $level) {
                        $details[] = [$this->groupLabel($group), $course['course_id'], $course['course_code'],
                            (string) $course['course_num'], $course['course_title'],
                            $course['course_required'] === null ? 'Unspecified' : ($course['course_required'] ? 'Yes' : 'No'),
                            $clo['l_outcome_id'], $clo['clo_shortphrase'], $clo['l_outcome'], $status,
                            $level['name'] ?? null, $level === null ? null : implode(', ', $level['matched_terms']),
                            $available ? ($this->needsReview($clo) ? 'Yes' : 'No') : null];
                    }
                }
            }
        }
        $this->writeRows($book->createSheet()->setTitle('CLO classifications'), $details);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function needsReview(array $clo): bool
    {
        return collect($clo['bloom_levels'])->unique('level_id')->count() >= 3;
    }

    private function groupLabel(int|string $group): string
    {
        return $group === 'other' ? 'Other/unknown' : "{$group}-level";
    }
}

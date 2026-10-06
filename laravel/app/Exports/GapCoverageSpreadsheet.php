<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;

class GapCoverageSpreadsheet extends ReportSpreadsheet
{
    private const METRICS = [
        'mapped_clo_count' => 'Mapped CLOs',
        'covering_course_count' => 'Covering courses',
        'required_course_count' => 'Required covering courses',
        'non_required_course_count' => 'Non-required covering courses',
    ];

    private const RESULTS = [
        'gap' => 'Potential gap',
        'redundancy' => 'Potential redundancy',
        'within_expectations' => 'Within expectations',
        'no_expectation' => 'No expectation applied',
        'not_applicable' => 'Not applicable (zero denominator)',
    ];

    public function build(array $export): Spreadsheet
    {
        $report = $export['report'];
        $settings = $report['expectations'];
        $spreadsheet = new Spreadsheet;
        $summary = $spreadsheet->getActiveSheet()->setTitle('Summary and expectations');
        $rows = [
            ['Gap and Redundancy Report', 'Value'],
            ['Program', $export['program_name']],
            ['Program ID', $report['program_id']],
            ['Generated at', $export['generated_at']],
            ['Program courses', $report['program_totals']['course_count']],
            ['Program CLOs', $report['program_totals']['clo_count']],
            ['PLOs', count($report['coverage'])],
            ['Check potential gaps', ($settings['concerns']['gaps'] ?? false) ? 'Yes' : 'No'],
            ['Check potential redundancies', ($settings['concerns']['redundancies'] ?? false) ? 'Yes' : 'No'],
            ['Mapping completeness', $report['mapping_completeness']['has_incomplete_mappings']
                ? 'Program mappings are incomplete. Findings are provisional.' : 'Complete'],
            ['Percentages', 'Mapped CLOs use all program CLOs. All course metrics use all program courses.'],
            ['Ranges', 'Below minimum: potential gap. Above maximum: potential redundancy. Boundaries are inclusive.'],
            ['Notes', 'Percentages can overlap across levels. Blank bounds are unset. N/A mappings do not count as coverage.'],
        ];
        foreach (['evaluated_plo_count' => 'PLOs evaluated', 'concern_plo_count' => 'PLOs with any concern',
            'gap_plo_count' => 'PLOs with potential gaps', 'redundancy_plo_count' => 'PLOs with potential redundancies'] as $key => $label) {
            $rows[] = [$label, $report['results']['summary'][$key]];
        }
        $rows[] = [];
        $rows[] = ['Metric', 'Mapping level', 'Minimum (%)', 'Maximum (%)'];
        $levels = [];
        foreach ($report['coverage'] as $plo) {
            foreach ($plo['mapping_scale_histogram'] as $level) {
                $levels[$level['map_scale_id']] = $level['title'];
            }
        }
        foreach ($settings['metrics'] ?? [] as $metric => $selection) {
            foreach ($selection['levels'] as $id => $bounds) {
                $rows[] = [self::METRICS[$metric], $levels[$id] ?? "Level ID {$id}", $bounds['min'], $bounds['max']];
            }
        }
        $this->writeRows($summary, $rows, false);
        $summary->getColumnDimension('B')->setWidth(85);

        $comparisons = [['PLO ID', 'PLO', 'Description', 'Metric', 'Mapping level', 'Count', 'Denominator', 'Coverage (%)', 'Minimum (%)', 'Maximum (%)', 'Result']];
        $mappings = [['PLO ID', 'PLO', 'Course ID', 'Course code', 'Course number', 'Course title', 'Required', 'CLO ID', 'CLO', 'CLO description', 'Mapping level']];
        $results = array_column($report['results']['plos'], null, 'pl_outcome_id');
        foreach ($report['coverage'] as $plo) {
            foreach ($results[$plo['pl_outcome_id']]['comparisons'] as $comparison) {
                $comparisons[] = [$plo['pl_outcome_id'], $plo['plo_shortphrase'], $plo['pl_outcome'],
                    self::METRICS[$comparison['metric']], $levels[$comparison['map_scale_id']],
                    $comparison['count'], $comparison['denominator'], $comparison['percentage'],
                    $comparison['min'], $comparison['max'], self::RESULTS[$comparison['status']]];
            }
            foreach ($plo['courses'] as $course) {
                foreach ($course['learning_outcomes'] as $clo) {
                    $mappings[] = [$plo['pl_outcome_id'], $plo['plo_shortphrase'], $course['course_id'],
                        $course['course_code'], (string) $course['course_num'], $course['course_title'],
                        $course['course_required'] === null ? 'Unspecified' : ($course['course_required'] ? 'Yes' : 'No'),
                        $clo['l_outcome_id'], $clo['clo_shortphrase'], $clo['l_outcome'], $clo['map_scale_title']];
                }
            }
        }
        $this->writeRows($spreadsheet->createSheet()->setTitle('Coverage comparisons'), $comparisons);
        $this->writeRows($spreadsheet->createSheet()->setTitle('Supporting mappings'), $mappings);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }
}

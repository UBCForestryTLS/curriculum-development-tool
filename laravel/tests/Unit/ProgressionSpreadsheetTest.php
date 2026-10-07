<?php

namespace Tests\Unit;

use App\Exports\ProgressionSpreadsheet;
use App\Helpers\ProgramProgression;
use Tests\TestCase;

class ProgressionSpreadsheetTest extends TestCase
{
    public function test_exports_preserve_overlapping_matches_unmatched_and_unavailable_reference(): void
    {
        $levels = array_map(fn ($id) => ['id' => $id, 'name' => "Level {$id}", 'position' => $id], [1, 2, 3]);
        $matches = array_map(fn ($level) => ['level_id' => $level['id'], 'name' => $level['name'], 'matched_terms' => ['term']], $levels);
        foreach ([true, false] as $available) {
            $courses = collect([['course_id' => 1, 'course_code' => 'TEST', 'course_num' => '101',
                'course_title' => 'Course', 'course_required' => null, 'clos' => [
                    ['l_outcome_id' => 1, 'clo_shortphrase' => 'Multiple', 'l_outcome' => '=1+1', 'bloom_levels' => $available ? $matches : null],
                    ['l_outcome_id' => 2, 'clo_shortphrase' => 'Unmatched', 'l_outcome' => 'Other text', 'bloom_levels' => $available ? [] : null],
                ]]]);
            $export = ['program_name' => 'Test', 'generated_at' => '2026-10-06', 'report' => [
                'bloom_levels' => $levels, 'program_id' => 1, 'selected_plo' => null, 'bloom_reference_available' => $available,
                'has_incomplete_mappings' => false, 'program_totals' => ['course_count' => 1, 'clo_count' => 2],
                'scope_totals' => ['course_count' => 1, 'clo_count' => 2], 'courses' => $courses,
                'course_groups' => ProgramProgression::distributions($courses, $levels, $available),
            ]];
            $chart = \App\Helpers\ProgramReportChart::progression($export['report'], ['units' => 'percentages', 'view' => 'comparison']);
            $this->assertSame('column', $chart['chart']['type']);
            $this->assertEquals($available ? [50] : [], $chart['series'][0]['data'] ?? []);
            $pdf = \PDF::loadView('programs.exports.progression', $export)->setPaper('a4')->output();
            $text = (new \Smalot\PdfParser\Parser)->parseContent($pdf)->getText();
            $this->assertStringContainsString('Scope: Entire program', $text);
            $this->assertStringContainsString('100-level', $text);
            if ($available) {
                foreach (['50%', 'Review suggested: 1', 'TEST 101', 'Multiple', 'Level 3', 'term'] as $expected) {
                    $this->assertStringContainsString($expected, $text);
                }
                $this->assertStringNotContainsString('Other text', $text);
            } else {
                $this->assertStringContainsString('Review suggestions are unavailable', $text);
                $this->assertStringContainsString('Matched CLOs: N/A', $text);
            }
            $book = (new ProgressionSpreadsheet)->build($export);
            try {
                $this->assertEquals($available ? 1 : null, $book->getSheet(0)->getCell('B15')->getValue());
                $distribution = $book->getSheet(1);
                $this->assertEquals($available ? 50 : null, $distribution->getCell('F2')->getValue());
                $this->assertEquals($available ? 50 : null, $distribution->getCell('F5')->getValue());
                $details = $book->getSheet(2);
                $this->assertSame($available ? 5 : 3, $details->getHighestRow());
                $this->assertSame('s', $details->getCell('I2')->getDataType());
                $this->assertSame($available ? 'Yes' : null, $details->getCell('M2')->getValue());
                $this->assertSame($available ? 'term' : null, $details->getCell('L2')->getValue());
                $this->assertSame($available ? 'Unmatched' : 'Reference unavailable', $details->getCell('J'.$details->getHighestRow())->getValue());
            } finally {
                $book->disconnectWorksheets();
            }
        }
    }
}

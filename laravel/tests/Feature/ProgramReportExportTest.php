<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProgramReportExportTest extends TestCase
{
    use DatabaseTransactions;

    private function program(): Program
    {
        return Program::create(['program' => 'Export Test', 'level' => 'Bachelors', 'status' => 1]);
    }

    public function test_report_exports_require_program_access(): void
    {
        $program = $this->program();
        $user = User::factory()->create();
        foreach (['exportGapCoverage', 'exportProgression'] as $action) {
            $url = route('programReports.'.$action, $program);
            $this->postJson($url, ['format' => 'pdf'])->assertUnauthorized();
        }
        $this->actingAs($user);
        foreach (['exportGapCoverage', 'exportProgression'] as $action) {
            $url = route('programReports.'.$action, $program);
            $this->postJson($url, ['format' => 'pdf'])->assertForbidden();
            foreach ([1, 2, 3] as $permission) {
                $program->users()->sync([$user->id => ['permission' => $permission]]);
                $response = $this->postJson($url, ['format' => 'pdf'])->assertOk();
                if ($action === 'exportGapCoverage') {
                    $response->assertHeader('content-type', 'application/pdf');
                } else {
                    $response->assertJsonPath('program_name', 'Export Test')
                        ->assertJsonPath('report.program_id', $program->program_id);
                }
            }
            $program->users()->detach($user->id);
        }
    }

    public function test_gap_export_validates_settings_and_prepares_statistics_only(): void
    {
        $program = $this->program();
        $user = User::factory()->create();
        $program->users()->attach($user->id, ['permission' => 3]);
        $this->actingAs($user);
        $url = route('programReports.exportGapCoverage', $program);
        $pdf = $this->postJson($url, ['format' => 'pdf'])->assertOk()
            ->assertDownload('export-test-'.$program->program_id.'-gap-and-redundancy-'.now()->format('Y-m-d').'.pdf');
        $text = (new \Smalot\PdfParser\Parser)->parseContent($pdf->getContent())->getText();
        $this->assertStringContainsString('Statistics only', $text);
        $this->assertStringContainsString('No PLOs have been added', $text);
        $download = $this->postJson($url, ['format' => 'xlsx'])->assertOk()
            ->assertDownload('export-test-'.$program->program_id.'-gap-and-redundancy-'.now()->format('Y-m-d').'.xlsx');
        $path = tempnam(sys_get_temp_dir(), 'gap-export-');
        try {
            file_put_contents($path, $download->streamedContent());
            $workbook = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $this->assertSame(['Summary and expectations', 'Coverage comparisons', 'Supporting mappings'], $workbook->getSheetNames());
            $this->assertSame('Export Test', $workbook->getSheet(0)->getCell('B2')->getValue());
            $this->assertSame('No', $workbook->getSheet(0)->getCell('B8')->getValue());
            $workbook->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        foreach ([['format' => 'csv'], ['format' => 'pdf', 'units' => 'invalid'],
            ['format' => 'pdf', 'metric' => 'invalid'], ['format' => 'pdf', 'expectations' => 'invalid'],
            ['format' => 'pdf', 'expectations' => ['concerns' => ['gaps' => true, 'redundancies' => true],
                'metrics' => ['covering_course_count' => ['levels' => [999 => ['min' => 25, 'max' => 75]]]]]]]
            as $input) {
            $this->postJson($url, $input)->assertUnprocessable();
        }
    }

    public function test_gap_pdf_contains_applied_ranges_and_plo_findings(): void
    {
        $program = $this->program();
        $user = User::factory()->create();
        $program->users()->attach($user->id, ['permission' => 3]);
        $scale = \App\Models\MappingScale::create(['title' => 'Introduced', 'description' => 'Introductory coverage', 'abbreviation' => 'I', 'colour' => '#ffffff']);
        $program->mappingScaleLevels()->attach($scale->map_scale_id, ['position' => 1]);
        $course = \App\Models\Course::factory()->create();
        $program->courses()->attach($course->course_id, ['course_required' => 1]);
        $clo = $course->learningOutcomes()->create(['l_outcome' => 'Analyze data']);
        $plo = $program->programLearningOutcomes()->create(['pl_outcome' => 'Mapped outcome']);
        $program->programLearningOutcomes()->create(['pl_outcome' => 'Unmapped outcome']);
        \Illuminate\Support\Facades\DB::table('outcome_maps')->insert([
            'pl_outcome_id' => $plo->pl_outcome_id, 'l_outcome_id' => $clo->l_outcome_id, 'map_scale_id' => $scale->map_scale_id,
        ]);
        $pdf = $this->actingAs($user)->postJson(route('programReports.exportGapCoverage', $program), [
            'format' => 'pdf', 'expectations' => ['concerns' => ['gaps' => true, 'redundancies' => true],
                'metrics' => ['covering_course_count' => ['levels' => [$scale->map_scale_id => ['min' => 25, 'max' => 75]]]]],
        ])->assertOk()->assertHeader('content-type', 'application/pdf');
        $text = (new \Smalot\PdfParser\Parser)->parseContent($pdf->getContent())->getText();
        foreach (['Mapped outcome', 'Unmapped outcome', 'Introduced', '25%', '75%', '100%',
            'Potential gap', 'Potential redundancy', 'provisional', '2 PLOs evaluated against ranges'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
    }

    public function test_progression_export_preserves_scope_and_rejects_foreign_plos(): void
    {
        $program = $this->program();
        $user = User::factory()->create();
        $program->users()->attach($user->id, ['permission' => 3]);
        $this->actingAs($user);
        $plo = $program->programLearningOutcomes()->create(['pl_outcome' => 'In scope']);
        $otherPlo = $this->program()->programLearningOutcomes()->create(['pl_outcome' => 'Outside scope']);
        $url = route('programReports.exportProgression', $program);
        $this->postJson($url, ['format' => 'pdf', 'plo_id' => $plo->pl_outcome_id, 'view' => 'progression', 'units' => 'counts'])
            ->assertOk()->assertJsonPath('report.selected_plo.pl_outcome_id', $plo->pl_outcome_id)
            ->assertJsonPath('options.view', 'progression')->assertJsonPath('options.units', 'counts');
        $download = $this->postJson($url, ['format' => 'xlsx', 'plo_id' => $plo->pl_outcome_id])->assertOk()
            ->assertDownload('export-test-'.$program->program_id.'-progression-'.now()->format('Y-m-d').'.xlsx');
        $path = tempnam(sys_get_temp_dir(), 'progression-export-');
        try {
            file_put_contents($path, $download->streamedContent());
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $this->assertSame(['Summary', 'Course-group distributions', 'CLO classifications'], $book->getSheetNames());
            $this->assertSame($plo->pl_outcome_id, $book->getSheet(0)->getCell('B6')->getValue());
            $this->assertSame(0, $book->getSheet(0)->getCell('B12')->getValue());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        $this->postJson($url, ['format' => 'pdf', 'plo_id' => $otherPlo->pl_outcome_id])->assertNotFound();
        $this->postJson($url, ['format' => 'pdf', 'plo_id' => 'invalid', 'view' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['plo_id', 'view']);
        $this->postJson(route('programReports.exportProgression', 2147483647), ['format' => 'pdf'])->assertNotFound();
    }
}

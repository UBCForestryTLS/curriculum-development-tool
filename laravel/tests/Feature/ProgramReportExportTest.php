<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Browser\ProgramReports\ReportDownloads;
use Tests\Browser\ProgramReports\ReportFixtures;
use Tests\TestCase;

class ProgramReportExportTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Export service outages must not prevent downloading the report tables.
        \Illuminate\Support\Facades\Http::fake(['export.highcharts.com/*' => \Illuminate\Support\Facades\Http::response('', 503)]);
    }

    private function program(): Program
    {
        return Program::create(['program' => 'Export Test', 'level' => 'Bachelors', 'status' => 1]);
    }

    public static function exportFormats(): array
    {
        return ['PDF' => ['pdf'], 'Excel' => ['xlsx']];
    }

    #[DataProvider('exportFormats')]
    public function test_report_exports_require_program_access(string $format): void
    {
        $program = $this->program();
        $user = User::factory()->create();
        foreach (['exportGapCoverage', 'exportProgression'] as $action) {
            $url = route('programReports.'.$action, $program);
            $this->postJson($url, ['format' => $format])->assertUnauthorized();
        }
        $this->actingAs($user);
        foreach (['exportGapCoverage', 'exportProgression'] as $action) {
            $url = route('programReports.'.$action, $program);
            $this->postJson($url, ['format' => $format])->assertForbidden();
            foreach ([1, 2, 3] as $permission) {
                $program->users()->sync([$user->id => ['permission' => $permission]]);
                $response = $this->postJson($url, ['format' => $format])->assertOk();
                $response->assertHeader('content-type', $format === 'pdf'
                    ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            }
            $program->users()->detach($user->id);
        }
    }

    public static function roleExportCases(): array
    {
        $cases = [];
        foreach (['administrator', 'program director', 'department head'] as $role) {
            foreach (['pdf', 'xlsx'] as $format) {
                $cases[$role.' '.$format] = [$role, $format];
            }
        }

        return $cases;
    }

    #[DataProvider('roleExportCases')]
    public function test_role_access_allows_exports_only_for_the_assigned_program(string $roleName, string $format): void
    {
        $program = $this->program();
        $otherProgram = $this->program();
        $user = User::factory()->create();
        $role = Role::where('role', $roleName)->firstOrFail();
        $user->roles()->attach($role->id);
        // Use role access only, so direct collaborator permissions cannot make this pass.
        $user->programsWithElevatedRoleAccess()->attach($program->program_id, ['role_id' => $role->id]);
        $this->actingAs($user);

        foreach (['exportGapCoverage', 'exportProgression'] as $action) {
            $this->postJson(route('programReports.'.$action, $program), ['format' => $format])->assertOk()->assertDownload();
            $this->postJson(route('programReports.'.$action, $otherProgram), ['format' => $format])->assertForbidden();
        }
    }

    #[DataProvider('exportFormats')]
    public function test_unverified_users_cannot_export_even_with_program_access(string $format): void
    {
        $program = $this->program();
        $user = User::factory()->create(['email_verified_at' => null]);
        $program->users()->attach($user->id, ['permission' => 1]);
        $this->actingAs($user);

        foreach (['exportGapCoverage', 'exportProgression'] as $action) {
            $this->postJson(route('programReports.'.$action, $program), ['format' => $format])->assertForbidden();
        }
    }

    #[DataProvider('exportFormats')]
    public function test_progression_exports_include_only_the_selected_plos_courses_and_clos(string $format): void
    {
        $fixture = ReportFixtures::create();
        $this->actingAs($fixture['user']);
        $url = route('programReports.exportProgression', $fixture['program']);
        $response = $this->postJson($url, [
            'format' => $format, 'plo_id' => $fixture['beta']->pl_outcome_id, 'view' => 'cognitive-levels',
        ])->assertOk();
        if ($format === 'pdf') {
            $text = (new \Smalot\PdfParser\Parser)->parseContent($response->getContent())->getText();
            $this->assertStringContainsString('Advanced topics', $text);
            $this->assertStringNotContainsString('Foundations', $text);
            $this->assertMatchesRegularExpression('/Analyze\s*1\s*100%/', $text);
        } else {
            $sheets = ReportDownloads::spreadsheetRows($response->streamedContent());
            $this->assertSame(1, $sheets['Summary'][11][1]);
            $this->assertStringContainsString('Analyze new questions.', json_encode($sheets['CLO classifications']));
            $this->assertStringNotContainsString('Remember the main ideas.', json_encode($sheets['CLO classifications']));
            $this->assertSame('TEST 201 — Advanced topics', $sheets['Cognitive-level distribution'][2][3]);
        }

        $otherPlo = $this->program()->programLearningOutcomes()->create(['pl_outcome' => 'Other program outcome']);
        $this->postJson($url, ['format' => $format, 'plo_id' => $otherPlo->pl_outcome_id])->assertNotFound();
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
            'Potential gap', 'Potential redundancy', 'provisional', '2 PLOs evaluated against ranges', 'The chart is unavailable'] as $expected) {
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
        $pdf = $this->postJson($url, ['format' => 'pdf', 'plo_id' => $plo->pl_outcome_id, 'view' => 'cognitive-levels', 'units' => 'counts'])
            ->assertOk()->assertHeader('content-type', 'application/pdf')
            ->assertDownload('export-test-'.$program->program_id.'-progression-'.now()->format('Y-m-d').'.pdf');
        $text = (new \Smalot\PdfParser\Parser)->parseContent($pdf->getContent())->getText();
        $this->assertStringContainsString('Scope: Selected PLO', $text);
        $this->assertStringContainsString('In scope', $text);
        $this->assertStringNotContainsString('Outside scope', $text);
        $this->assertStringContainsString('No courses are included in this scope.', $text);
        $download = $this->postJson($url, ['format' => 'xlsx', 'plo_id' => $plo->pl_outcome_id, 'view' => 'cognitive-levels'])->assertOk()
            ->assertDownload('export-test-'.$program->program_id.'-progression-'.now()->format('Y-m-d').'.xlsx');
        $path = tempnam(sys_get_temp_dir(), 'progression-export-');
        try {
            file_put_contents($path, $download->streamedContent());
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $this->assertSame(['Summary', 'Course-group distributions', 'CLO classifications', 'Cognitive-level distribution'], $book->getSheetNames());
            $this->assertSame($plo->pl_outcome_id, $book->getSheet(0)->getCell('B6')->getValue());
            $this->assertSame(0, $book->getSheet(0)->getCell('B12')->getValue());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        $this->postJson($url, ['format' => 'pdf', 'plo_id' => $otherPlo->pl_outcome_id])->assertNotFound();
        $this->postJson($url, ['format' => 'pdf', 'plo_id' => 'invalid', 'view' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['plo_id', 'view']);
        $this->postJson($url, ['format' => 'pdf', 'view' => 'progression'])->assertUnprocessable()->assertJsonValidationErrors('view');
        $this->postJson(route('programReports.exportProgression', 2147483647), ['format' => 'pdf'])->assertNotFound();
    }
}

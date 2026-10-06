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
                $this->postJson($url, ['format' => 'pdf'])->assertOk()
                    ->assertJsonPath('program_name', 'Export Test')
                    ->assertJsonPath('report.program_id', $program->program_id);
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
        $this->postJson($url, ['format' => 'xlsx'])->assertOk()
            ->assertJsonPath('report.expectations', null)
            ->assertJsonPath('options.metric', 'mapped_clo_count')
            ->assertJsonPath('filename', 'export-test-'.$program->program_id.'-gap-and-redundancy-'.now()->format('Y-m-d').'.xlsx');
        foreach ([['format' => 'csv'], ['format' => 'pdf', 'units' => 'invalid'],
            ['format' => 'pdf', 'metric' => 'invalid'], ['format' => 'pdf', 'expectations' => 'invalid'],
            ['format' => 'pdf', 'expectations' => ['concerns' => ['gaps' => true, 'redundancies' => true],
                'metrics' => ['covering_course_count' => ['levels' => [999 => ['min' => 25, 'max' => 75]]]]]]]
            as $input) {
            $this->postJson($url, $input)->assertUnprocessable();
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
        $this->postJson($url, ['format' => 'pdf', 'plo_id' => $otherPlo->pl_outcome_id])->assertNotFound();
        $this->postJson($url, ['format' => 'pdf', 'plo_id' => 'invalid', 'view' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['plo_id', 'view']);
        $this->postJson(route('programReports.exportProgression', 2147483647), ['format' => 'pdf'])->assertNotFound();
    }
}

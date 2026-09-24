<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\MappingScale;
use App\Models\Program;
use App\Models\ProgramLearningOutcome;
use App\Models\StandardCategory;
use App\Models\Standard;
use App\Models\StandardScale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardMappingProgressTest extends TestCase
{
    use DatabaseTransactions;

    public function test_mapping_and_standards_preserve_progress(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $nextCategoryId = StandardCategory::max('standard_category_id') + 1;
        $nextStandardId = Standard::max('standard_id') + 1;
        $mapScale = MappingScale::firstOrFail()->map_scale_id;
        $standardScale = StandardScale::firstOrFail()->standard_scale_id;
        DB::table('standard_categories')->insertOrIgnore(['standard_category_id' => 0, 'sc_name' => 'Not applicable']);
        $nextPloId = ProgramLearningOutcome::max('pl_outcome_id') + 1;
        $cases = [
            [[], 0, 'none', 10, false, false],
            [[0], 0, 'none', 10, false, false],
            [[2], 1, 'partial', 10, false, false],
            [[2], 2, 'all', 30, true, true],
            [[2, 2], 2, 'none', 10, false, false],
            // Preserve the existing multi-program threshold, including its minus-one adjustment.
            [[2, 2], 3, 'none', 20, true, false],
            [[], 0, 'na', 11, false, true],
        ];
        $expected = [];
        foreach ($cases as [$ploCounts, $mapped, $standardState, $percentage, $mappingComplete, $standardsComplete]) {
            $categoryId = $nextCategoryId++;
            DB::table('standard_categories')->insert(['standard_category_id' => $categoryId, 'sc_name' => 'Progress test standards']);
            for ($i = 0; $i < 2; $i++) {
                Standard::create(['standard_id' => $nextStandardId++, 'standard_category_id' => $categoryId, 's_shortphrase' => 'Test', 's_outcome' => 'Test standard']);
            }
            $standards = Standard::where('standard_category_id', $categoryId)->get();
            $course = Course::factory()->create(['standard_category_id' => $standardState === 'na' ? 0 : $categoryId]);
            $user->courses()->attach($course->course_id, ['permission' => 3]);
            $clo = $course->learningOutcomes()->create(['l_outcome' => 'Explain the topic']);
            foreach ($ploCounts as $ploCount) {
                $program = Program::create(['program' => 'Progress test program', 'level' => 'Undergraduate', 'status' => 0]);
                $course->programs()->attach($program->program_id);
                for ($i = 0; $i < $ploCount; $i++) {
                    $plo = $program->programLearningOutcomes()->create(['pl_outcome_id' => $nextPloId++, 'pl_outcome' => 'Program outcome']);
                    if ($mapped-- > 0) {
                        $clo->programLearningOutcomes()->attach($plo->pl_outcome_id, ['map_scale_id' => $mapScale]);
                    }
                }
            }
            foreach ($standards->take(match ($standardState) { 'all' => $standards->count(), 'partial' => 1, default => 0 }) as $standard) {
                DB::table('standards_outcome_maps')->insert(['course_id' => $course->course_id, 'standard_id' => $standard->standard_id, 'standard_scale_id' => $standardScale]);
            }
            $expected[$course->course_id] = [$percentage, $mappingComplete, $standardsComplete];
        }

        $response = $this->actingAs($user)->get(route('home'))->assertOk();
        foreach ($expected as $courseId => [$percentage, $mappingComplete, $standardsComplete]) {
            $this->assertSame($percentage, $response->viewData('progressBar')[$courseId]);
            $message = $response->viewData('progressBarMsg')[$courseId]['statusMsg'];
            $this->assertSame(! $mappingComplete, str_contains($message, 'Program Outcome Mapping (Step 8)'));
            $this->assertSame(! $standardsComplete, str_contains($message, 'Standards (Step 9)'));
        }
    }
}

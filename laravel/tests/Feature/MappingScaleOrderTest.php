<?php

namespace Tests\Feature;

use App\Models\MappingScale;
use App\Models\MappingScaleProgram;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MappingScaleOrderTest extends TestCase
{
    use DatabaseTransactions;

    public function test_program_mapping_scale_levels_use_position_instead_of_id_order(): void
    {
        $program = Program::create([
            'program' => 'Ordered Mapping Scale Program',
            'level' => 'Bachelors',
            'status' => 1,
        ]);
        $first = $this->createScale('First Level', 'F');
        $second = $this->createScale('Second Level', 'S');
        $third = $this->createScale('Third Level', 'T');

        MappingScaleProgram::create([
            'program_id' => $program->program_id,
            'map_scale_id' => $third->map_scale_id,
            'position' => 1,
        ]);
        MappingScaleProgram::create([
            'program_id' => $program->program_id,
            'map_scale_id' => $first->map_scale_id,
            'position' => 2,
        ]);
        MappingScaleProgram::create([
            'program_id' => $program->program_id,
            'map_scale_id' => $second->map_scale_id,
            'position' => 3,
        ]);

        $levels = $program->mappingScaleLevels()->get();

        $this->assertSame(
            [$third->map_scale_id, $first->map_scale_id, $second->map_scale_id],
            $levels->pluck('map_scale_id')->all()
        );
        $this->assertSame([1, 2, 3], $levels->pluck('pivot.position')->all());
    }

    public function test_custom_mapping_scale_is_appended_to_the_program_order(): void
    {
        $program = Program::create([
            'program' => 'Custom Mapping Scale Program',
            'level' => 'Bachelors',
            'status' => 1,
        ]);
        $existingScale = $this->createScale('Existing Level', 'E');
        MappingScaleProgram::create([
            'program_id' => $program->program_id,
            'map_scale_id' => $existingScale->map_scale_id,
            'position' => 4,
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('program.mappingScale.store'), [
            'title' => 'Appended Level',
            'abbreviation' => 'A',
            'description' => 'Appended after the existing configured levels.',
            'colour' => '#123456',
            'program_id' => $program->program_id,
        ])->assertRedirect(route('programWizard.step2', $program->program_id));

        $appendedScale = MappingScale::where('title', 'Appended Level')->firstOrFail();

        $this->assertDatabaseHas('mapping_scale_programs', [
            'program_id' => $program->program_id,
            'map_scale_id' => $appendedScale->map_scale_id,
            'position' => 5,
        ]);
    }

    private function createScale(string $title, string $abbreviation): MappingScale
    {
        return MappingScale::create([
            'title' => $title,
            'abbreviation' => $abbreviation,
            'description' => "{$title} description.",
            'colour' => '#80bdff',
        ]);
    }
}

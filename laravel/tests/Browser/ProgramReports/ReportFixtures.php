<?php

namespace Tests\Browser\ProgramReports;

use App\Models\BloomDomain;
use App\Models\Course;
use App\Models\MappingScale;
use App\Models\Program;
use App\Models\User;

final class ReportFixtures
{
    public static function create(): array
    {
        $user = User::factory()->create();
        $program = Program::create(['program' => 'Browser report program', 'level' => 'Bachelors', 'status' => 1]);
        $program->users()->attach($user->id, ['permission' => 3]);
        $alpha = $program->programLearningOutcomes()->create([
            'plo_shortphrase' => 'Alpha', 'pl_outcome' => 'Use foundational knowledge.',
        ]);
        $beta = $program->programLearningOutcomes()->create([
            'plo_shortphrase' => 'Beta', 'pl_outcome' => 'Investigate new questions.',
        ]);
        $scale = MappingScale::create([
            'title' => 'Introduced', 'abbreviation' => 'I', 'description' => 'Introduced', 'colour' => '#336699',
        ]);
        $program->mappingScaleLevels()->attach($scale->map_scale_id, ['position' => 1]);

        $foundation = Course::factory()->create([
            'course_code' => 'TEST', 'course_num' => '101', 'course_title' => 'Foundations',
        ]);
        $advanced = Course::factory()->create([
            'course_code' => 'TEST', 'course_num' => '201', 'course_title' => 'Advanced topics',
        ]);
        $empty = Course::factory()->create([
            'course_code' => 'TEST', 'course_num' => '301', 'course_title' => 'Future course',
        ]);
        $program->courses()->attach([
            $foundation->course_id => ['course_required' => 1],
            $advanced->course_id => ['course_required' => 0],
            $empty->course_id => ['course_required' => null],
        ]);

        // Alpha gets three of the four CLOs and Beta gets the other one. Each PLO
        // has one of the three courses, so CLO and course percentages are different.
        foreach (['Remember the main ideas.', 'Remember, analyze and create a solution.', 'Work together.'] as $text) {
            $clo = $foundation->learningOutcomes()->create(['l_outcome' => $text]);
            $clo->programLearningOutcomes()->attach($alpha->pl_outcome_id, ['map_scale_id' => $scale->map_scale_id]);
        }
        $clo = $advanced->learningOutcomes()->create(['l_outcome' => 'Analyze new questions.']);
        $clo->programLearningOutcomes()->attach($beta->pl_outcome_id, ['map_scale_id' => $scale->map_scale_id]);

        // Keep the reference small: one CLO matches all three levels and one matches
        // none. This gives us clear cases for the review and No Bloom match filters.
        $domain = BloomDomain::create(['name' => 'Cognitive']);
        $levels = [];
        foreach (['Remember', 'Analyze', 'Create'] as $index => $name) {
            $level = $domain->levels()->create(['name' => $name, 'position' => $index + 1]);
            $level->verbs()->create(['term' => strtolower($name)]);
            $levels[$name] = $level;
        }

        return compact('user', 'program', 'alpha', 'beta', 'scale', 'foundation', 'advanced', 'empty', 'levels');
    }
}

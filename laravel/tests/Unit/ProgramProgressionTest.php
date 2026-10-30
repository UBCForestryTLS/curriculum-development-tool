<?php

namespace Tests\Unit;

use App\Helpers\ProgramProgression;
use PHPUnit\Framework\TestCase;

class ProgramProgressionTest extends TestCase
{
    private array $levels = [
        ['id' => 8, 'name' => 'Later', 'position' => 2],
        ['id' => 20, 'name' => 'Earlier', 'position' => 1],
        ['id' => 30, 'name' => 'Last', 'position' => 3],
    ];

    public function test_cognitive_distribution_counts_scoped_clos_and_courses_once_per_level(): void
    {
        $first = ['course_id' => 1, 'course_num' => '101', 'clos' => [
            ['l_outcome_id' => 1, 'bloom_levels' => [['level_id' => 20], ['level_id' => 20], ['level_id' => 8]]],
        ]];
        $second = ['course_id' => 2, 'course_num' => '201', 'clos' => [
            ['l_outcome_id' => 2, 'bloom_levels' => [['level_id' => 20]]],
            ['l_outcome_id' => 3, 'bloom_levels' => []],
            ['l_outcome_id' => 4, 'bloom_levels' => []],
        ]];
        $report = [
            'courses' => [$first, $first, $second],
            'bloom_reference_available' => true,
            'bloom_levels' => [$this->levels[1], $this->levels[0], $this->levels[2]],
        ];

        $summary = ProgramProgression::summarizeCognitiveLevels($report);
        $this->assertSame(4, $summary['clo_count']);
        $this->assertSame([20, 8, 30], array_column($summary['levels'], 'level_id'));
        $this->assertSame([2, 1, 0], array_column($summary['levels'], 'clo_count'));
        // Two of four CLOs match, even though the course groups have 100% and 33.33% coverage.
        $this->assertSame([50.0, 25.0, 0.0], array_column($summary['levels'], 'percentage'));
        $this->assertSame([1, 2], array_column($summary['levels'][0]['courses'], 'course_id'));
        $this->assertSame([], $summary['levels'][2]['courses']);

        $report['courses'] = [$second];
        $scoped = ProgramProgression::summarizeCognitiveLevels($report);
        $this->assertSame(3, $scoped['clo_count']);
        $this->assertSame([33.33, 0.0, 0.0], array_column($scoped['levels'], 'percentage'));
        $this->assertSame([2], array_column($scoped['levels'][0]['courses'], 'course_id'));
    }

    public function test_cognitive_distribution_keeps_empty_scope_and_missing_reference_distinct(): void
    {
        $report = ['courses' => [], 'bloom_reference_available' => true, 'bloom_levels' => $this->levels];
        $empty = ProgramProgression::summarizeCognitiveLevels($report);
        $this->assertSame(0, $empty['clo_count']);
        $this->assertSame([0, 0, 0], array_column($empty['levels'], 'clo_count'));
        $this->assertSame([null, null, null], array_column($empty['levels'], 'percentage'));

        $report['courses'] = [['course_id' => 1, 'clos' => [['l_outcome_id' => 1, 'bloom_levels' => null]]]];
        $report['bloom_reference_available'] = false;
        $unavailable = ProgramProgression::summarizeCognitiveLevels($report);
        $this->assertSame(1, $unavailable['clo_count']);
        $this->assertSame([null, null, null], array_column($unavailable['levels'], 'clo_count'));
        $this->assertSame([null, null, null], array_column($unavailable['levels'], 'percentage'));
        $this->assertSame([[], [], []], array_column($unavailable['levels'], 'courses'));
    }

    public function test_counts_distinct_clos_and_keeps_unmatched_clos_in_percentages(): void
    {
        $clo = ['l_outcome_id' => 1, 'l_outcome' => 'Same wording', 'bloom_levels' => [
            ['level_id' => 20, 'matched_terms' => ['First', 'Second']],
            ['level_id' => 20],
            ['level_id' => 8],
        ]];
        $course = ['course_id' => 1, 'course_num' => '101', 'clos' => [
            $clo, $clo,
            array_replace($clo, ['l_outcome_id' => 2]),
            ['l_outcome_id' => 3, 'bloom_levels' => []],
        ]];

        $result = ProgramProgression::distributionsByCourseLevel(collect([$course, $course]), $this->levels, true);
        $this->assertCount(1, $result);
        $group = $result[0];
        $this->assertSame(100, $group['course_level']);
        $this->assertSame(1, $group['course_count']);
        $this->assertSame(3, $group['clo_count']);
        $this->assertSame(2, $group['matched_clo_count']);
        $this->assertSame(1, $group['unmatched_clo_count']);
        $this->assertSame([20, 8, 30], array_column($group['blooms_levels'], 'level_id'));
        $this->assertSame([2, 2, 0], array_column($group['blooms_levels'], 'clo_count'));
        $this->assertSame([66.67, 66.67, 0.0], array_column($group['blooms_levels'], 'percentage'));
    }

    public function test_empty_and_unknown_groups_remain_distinct_from_zero_matches(): void
    {
        $courses = collect([
            ['course_id' => 1, 'course_num' => null, 'clos' => [['l_outcome_id' => 1, 'bloom_levels' => []]]],
            ['course_id' => 2, 'course_num' => '300A', 'clos' => []],
        ]);

        $result = ProgramProgression::distributionsByCourseLevel($courses, $this->levels, true);
        $this->assertSame([300, 'other'], array_column($result, 'course_level'));
        $this->assertSame(1, $result[0]['course_count']);
        $this->assertSame(0, $result[0]['clo_count']);
        $this->assertSame(0, $result[0]['matched_clo_count']);
        $this->assertSame(0, $result[0]['unmatched_clo_count']);
        $this->assertSame([0, 0, 0], array_column($result[0]['blooms_levels'], 'clo_count'));
        $this->assertSame([null, null, null], array_column($result[0]['blooms_levels'], 'percentage'));
        $this->assertSame(1, $result[1]['unmatched_clo_count']);
        $this->assertSame([0.0, 0.0, 0.0], array_column($result[1]['blooms_levels'], 'percentage'));
        $this->assertSame([], ProgramProgression::distributionsByCourseLevel(collect(), $this->levels, true));
    }

    public function test_missing_reference_does_not_report_clos_as_unmatched(): void
    {
        $courses = collect([['course_id' => 1, 'course_num' => '201', 'clos' => [
            ['l_outcome_id' => 1, 'bloom_levels' => null],
        ]]]);

        $group = ProgramProgression::distributionsByCourseLevel($courses, $this->levels, false)[0];
        $this->assertSame(1, $group['clo_count']);
        $this->assertNull($group['matched_clo_count']);
        $this->assertNull($group['unmatched_clo_count']);
        $this->assertSame([null, null, null], array_column($group['blooms_levels'], 'clo_count'));
        $this->assertSame([null, null, null], array_column($group['blooms_levels'], 'percentage'));
        $this->assertSame([], ProgramProgression::distributionsByCourseLevel($courses, [], false)[0]['blooms_levels']);
    }
}

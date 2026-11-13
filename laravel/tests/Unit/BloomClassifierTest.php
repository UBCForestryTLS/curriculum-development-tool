<?php

namespace Tests\Unit;

use App\Helpers\BloomClassifier;
use PHPUnit\Framework\TestCase;

class BloomClassifierTest extends TestCase
{
    public function test_all_matching_levels_are_returned_in_reference_order(): void
    {
        // Synthetic reference assignments, not the instructor's vocabulary.
        $levels = [
            ['id' => 8, 'name' => 'Later level', 'position' => 2, 'verbs' => ['Sketch']],
            ['id' => 20, 'name' => 'Earlier level', 'position' => 1, 'verbs' => ['Sketch', 'Label', ' sketch ']],
        ];

        $this->assertSame([
            ['level_id' => 20, 'name' => 'Earlier level', 'position' => 1, 'matched_terms' => ['Sketch', 'Label']],
            ['level_id' => 8, 'name' => 'Later level', 'position' => 2, 'matched_terms' => ['Sketch']],
        ], BloomClassifier::classify('SKETCH a diagram, label it, then sketch another.', $levels));
    }

    public function test_matches_complete_terms_with_unicode_boundaries(): void
    {
        $levels = [
            ['id' => 1, 'name' => 'Example level', 'position' => 1, 'verbs' => ['trace']],
        ];

        $this->assertSame([], BloomClassifier::classify('Retrace traced traces étrace traceé trace2 trace_name', $levels));
        $this->assertSame(['trace'], BloomClassifier::classify('“TRACE,” then stop.', $levels)[0]['matched_terms']);
    }

    public function test_phrases_whitespace_and_literal_punctuation(): void
    {
        $levels = [
            ['id' => 1, 'name' => 'Example level', 'position' => 1, 'verbs' => [' mark   out ', 'role-play', 'test+check']],
        ];

        $this->assertSame(['mark out', 'role-play', 'test+check'], BloomClassifier::classify(
            "Mark\n\tOUT an area; ROLE-PLAY a scenario; test+check the result.",
            $levels,
        )[0]['matched_terms']);
        $this->assertSame([], BloomClassifier::classify('Role play, mark something out, testttcheck.', $levels));
    }

    public function test_empty_or_unmatched_input_returns_no_classifications(): void
    {
        $levels = [
            ['id' => 1, 'name' => 'Example level', 'position' => 1, 'verbs' => ['sketch', '  ']],
            ['id' => 2, 'name' => 'Empty level', 'position' => 2, 'verbs' => []],
        ];

        foreach (['', " \n\t ", 'Unrelated objective.'] as $text) {
            $this->assertSame([], BloomClassifier::classify($text, $levels));
        }
        $this->assertSame([], BloomClassifier::classify('Sketch a diagram.', []));
    }
}

<?php

namespace App\Helpers;

use App\Models\BloomDomain;

class BloomClassifier
{
    /**
     * Load the cognitive reference once for CLO text keyed by distinct CLO ID.
     *
     * @param array<int, string> $clos
     * @return array{reference_available: bool, levels: array, classifications: array<int, array>}
     */
    public static function classifyClos(array $clos): array
    {
        $domain = BloomDomain::whereRaw('lower(trim(name)) = ?', ['cognitive'])
            ->with(['levels.verbs' => fn ($query) => $query->orderBy('id')])
            ->first();

        $levels = $domain?->levels->map(fn ($level) => [
            'id' => $level->id,
            'name' => $level->name,
            'position' => $level->position,
            'verbs' => $level->verbs->pluck('term')->all(),
        ])->all() ?? [];
        $available = collect($levels)->contains(fn ($level) => $level['verbs'] !== []);

        $classifications = [];
        if ($available) {
            foreach ($clos as $id => $text) {
                $classifications[$id] = self::classify($text, $levels);
            }
        }

        return [
            'reference_available' => $available,
            'levels' => array_map(fn ($level) => [
                'id' => $level['id'],
                'name' => $level['name'],
                'position' => $level['position'],
            ], $levels),
            'classifications' => $classifications,
        ];
    }

    /**
     * Match supplied reference terms, retaining every matching level.
     *
     * @param array<int, array{id: int, name: string, position: int, verbs: array<int, string>}> $levels
     * @return array<int, array{level_id: int, name: string, position: int, matched_terms: array<int, string>}>
     */
    public static function classify(string $text, array $levels): array
    {
        $text = self::normalizeWhitespace($text);
        $matches = [];

        foreach ($levels as $level) {
            $terms = [];
            foreach ($level['verbs'] as $term) {
                $term = self::normalizeWhitespace($term);
                if ($term === '') {
                    continue;
                }

                // Match whole words/phrases, including terms containing punctuation.
                $pattern = '/(?<![\p{L}\p{M}\p{N}_])'.preg_quote($term, '/').'(?![\p{L}\p{M}\p{N}_])/iu';
                if (preg_match($pattern, $text) === 1) {
                    $terms[mb_strtolower($term, 'UTF-8')] ??= $term;
                }
            }

            if ($terms !== []) {
                $matches[$level['id']] = [
                    'level_id' => $level['id'],
                    'name' => $level['name'],
                    'position' => $level['position'],
                    'matched_terms' => array_values($terms),
                ];
            }
        }

        usort($matches, fn ($a, $b) => $a['position'] <=> $b['position']);

        return $matches;
    }

    private static function normalizeWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}

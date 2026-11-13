<?php

namespace App\Console\Commands;

use App\Helpers\BloomSpreadsheetReader;
use App\Models\BloomDomain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ImportBloomReference extends Command
{
    protected $signature = 'blooms:import {path : Path to the Bloom reference .xlsx file}
                            {--replace : Replace a different existing Bloom reference}';

    protected $description = 'Import the fixed-format Bloom reference spreadsheet';

    public function handle(): int
    {
        try {
            $data = BloomSpreadsheetReader::read($this->argument('path'));
            $expected = [];
            foreach ($data['entries'] as $entry) {
                $domain = self::normalize($entry['domain']);
                $position = $entry['position'];
                $expected[$domain][$position]['name'] = self::normalize($entry['level']);
                $expected[$domain][$position]['verbs'][self::normalize($entry['term'])] = true;
            }

            $imported = DB::transaction(function () use ($data, $expected) {
                // Serialize imports, including the first import when there are no rows to lock.
                DB::statement('LOCK TABLE bloom_domains, bloom_levels, bloom_verbs IN SHARE ROW EXCLUSIVE MODE');
                $domains = BloomDomain::with('levels.verbs')->get();
                if ($domains->isNotEmpty()) {
                    $existing = [];
                    foreach ($domains as $domain) {
                        $key = self::normalize($domain->name);
                        $existing[$key] = [];
                        foreach ($domain->levels as $level) {
                            $existing[$key][$level->position] = [
                                'name' => self::normalize($level->name),
                                'verbs' => [],
                            ];
                            foreach ($level->verbs as $verb) {
                                $existing[$key][$level->position]['verbs'][self::normalize($verb->term)] = true;
                            }
                        }
                    }
                    // Array equality ignores source row order, but includes empty/extra levels and domains.
                    if ($existing == $expected) {
                        return false;
                    }
                    if (! $this->option('replace')) {
                        throw new RuntimeException('The existing Bloom reference differs from this file or is partially populated. Use --replace to replace it. No changes were made.');
                    }

                    DB::table('bloom_verbs')->delete();
                    DB::table('bloom_levels')->delete();
                    DB::table('bloom_domains')->delete();
                }

                $createdDomains = [];
                $createdLevels = [];
                foreach ($data['entries'] as $entry) {
                    $key = self::normalize($entry['domain']);
                    $position = $entry['position'];
                    $domain = $createdDomains[$key] ??= BloomDomain::create(['name' => $entry['domain']]);
                    $level = $createdLevels[$key][$position] ??= $domain->levels()->create([
                        'position' => $position,
                        'name' => $entry['level'],
                    ]);
                    $level->verbs()->create(['term' => $entry['term']]);
                }

                return true;
            });

            $this->info($imported ? 'Bloom reference imported.' : 'This Bloom reference is already imported. No changes were made.');
            $this->line(sprintf('%d domains, %d levels, %d verb assignments. %d duplicate rows skipped.',
                count($expected), array_sum(array_map('count', $expected)), count($data['entries']), $data['duplicate_rows']));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private static function normalize(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }
}

<?php

namespace Tests\Feature;

use App\Helpers\BloomClassifier;
use App\Models\BloomDomain;
use App\Models\BloomLevel;
use App\Models\BloomVerb;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BloomReferenceTest extends TestCase
{
    use DatabaseTransactions;

    private BloomDomain $domain;

    private BloomLevel $level;

    protected function setUp(): void
    {
        parent::setUp();

        $this->domain = BloomDomain::create(['name' => 'Example Domain']);
        $this->level = $this->domain->levels()->create(['position' => 2, 'name' => 'Second Level']);
    }

    public function test_relationships_preserve_level_order_and_cross_level_terms(): void
    {
        $first = $this->domain->levels()->create(['position' => 1, 'name' => 'First Level']);
        $otherDomain = BloomDomain::create(['name' => 'Other Domain']);
        $otherLevel = $otherDomain->levels()->create(['position' => 2, 'name' => 'Other Level']);

        foreach ([$first, $this->level, $otherLevel] as $level) {
            $verb = $level->verbs()->create(['term' => 'Example-term']);
            $this->assertTrue($verb->level->is($level));
            $this->assertSame('Example-term', $verb->fresh()->term);
        }

        $this->assertTrue($first->domain->is($this->domain));
        $this->assertSame([$first->id, $this->level->id], $this->domain->levels->pluck('id')->all());
        $this->assertSame([1, 2], $this->domain->levels->pluck('position')->all());
        $this->assertCount(1, $this->level->verbs);
    }

    public function test_classifier_loads_only_cognitive_reference_once_for_a_batch(): void
    {
        $domain = BloomDomain::create(['name' => ' Cognitive ']);
        $later = $domain->levels()->create(['position' => 2, 'name' => 'Later']);
        $earlier = $domain->levels()->create(['position' => 1, 'name' => 'Earlier']);
        foreach ([$later, $earlier] as $level) {
            $level->verbs()->create(['term' => 'Example-term']);
        }
        $this->level->verbs()->create(['term' => 'Other-term']);

        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $single = BloomClassifier::classifyClos([10 => 'Example-term']);
            $queryCount = count(DB::getQueryLog());
            DB::flushQueryLog();
            $batch = BloomClassifier::classifyClos([10 => 'Example-term', 11 => 'Other-term', 12 => 'Example-term']);
            $this->assertSame($queryCount, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertTrue($batch['reference_available']);
        $this->assertSame([$earlier->id, $later->id], array_column($batch['classifications'][10], 'level_id'));
        $this->assertSame($single['classifications'][10], $batch['classifications'][12]);
        $this->assertSame([], $batch['classifications'][11]);
    }

    public function test_classifier_distinguishes_missing_reference_from_unmatched_clos(): void
    {
        $unavailable = ['reference_available' => false, 'classifications' => []];
        $this->assertSame($unavailable, BloomClassifier::classifyClos([10 => 'Example-term']));
        $domain = BloomDomain::create(['name' => 'Cognitive']);
        $this->assertSame($unavailable, BloomClassifier::classifyClos([10 => 'Example-term']));
        $level = $domain->levels()->create(['position' => 1, 'name' => 'Example']);
        $this->assertSame($unavailable, BloomClassifier::classifyClos([10 => 'Example-term']));
        $level->verbs()->create(['term' => 'Example-term']);
        $this->assertSame([
            'reference_available' => true,
            'classifications' => [10 => []],
        ], BloomClassifier::classifyClos([10 => 'Unrelated text']));
    }

    public function test_database_rejects_duplicate_domain_names(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23505');

        DB::table('bloom_domains')->insert(['name' => ' example DOMAIN ']);
    }

    public function test_database_rejects_duplicate_positions_within_a_domain(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23505');

        $this->domain->levels()->create(['position' => 2, 'name' => 'Conflicting Level']);
    }

    public function test_database_rejects_duplicate_terms_within_a_level(): void
    {
        $this->level->verbs()->create(['term' => 'Example-term']);
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23505');

        DB::table('bloom_verbs')->insert(['level_id' => $this->level->id, 'term' => ' EXAMPLE-TERM ']);
    }

    public function test_level_must_reference_an_existing_domain(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');

        BloomLevel::create(['domain_id' => -1, 'position' => 1, 'name' => 'Orphan Level']);
    }

    public function test_verb_must_reference_an_existing_level(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');

        BloomVerb::create(['level_id' => -1, 'term' => 'Example-term']);
    }

    public function test_domain_cannot_be_deleted_while_it_has_levels(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23001');

        $this->domain->delete();
    }

    public function test_level_cannot_be_deleted_while_it_has_verbs(): void
    {
        $this->level->verbs()->create(['term' => 'Example-term']);
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23001');

        $this->level->delete();
    }

    #[DataProvider('invalidPositions')]
    public function test_level_position_must_be_positive(int $position): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');

        $this->domain->levels()->create(['position' => $position, 'name' => 'Invalid Level']);
    }

    public static function invalidPositions(): array
    {
        return [[0], [-1]];
    }

    #[DataProvider('blankFields')]
    public function test_reference_text_cannot_be_blank(string $table, string $field): void
    {
        $record = match ($table) {
            'bloom_domains' => [],
            'bloom_levels' => ['domain_id' => $this->domain->id, 'position' => 1],
            'bloom_verbs' => ['level_id' => $this->level->id],
        };
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');

        DB::table($table)->insert($record + [$field => '   ']);
    }

    public static function blankFields(): array
    {
        return [
            ['bloom_domains', 'name'],
            ['bloom_levels', 'name'],
            ['bloom_verbs', 'term'],
        ];
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("
            ALTER TABLE programs
            ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
                to_tsvector('english'::regconfig, coalesce(program, ''))
            ) STORED
        ");

        DB::statement("CREATE INDEX programs_search_vector_gin_idx ON programs USING GIN (search_vector)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS programs_search_vector_gin_idx");
        DB::statement("ALTER TABLE programs DROP COLUMN IF EXISTS search_vector");
    }
};

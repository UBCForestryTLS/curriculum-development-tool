<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE course_material_chunks
            ADD COLUMN content_tsv tsvector
            GENERATED ALWAYS AS (to_tsvector('english', content)) STORED
        ");

        DB::statement("
            CREATE INDEX course_material_chunks_content_tsv_idx
            ON course_material_chunks USING GIN (content_tsv)
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS course_material_chunks_content_tsv_idx");
        DB::statement("ALTER TABLE course_material_chunks DROP COLUMN IF EXISTS content_tsv");
    }
};

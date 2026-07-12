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
            ALTER TABLE courses
            ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
                to_tsvector(
                    'english'::regconfig,
                    coalesce(course_code, '') || ' ' ||
                    coalesce(course_num::text, '') || ' ' ||
                    coalesce(course_title, '')
                )
            ) STORED
        ");
        
        DB::statement("CREATE INDEX courses_search_vector_gin_idx ON courses USING GIN (search_vector)");

        DB::statement("
            ALTER TABLE course_topics
            ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
                to_tsvector('english'::regconfig, coalesce(topic, ''))
            ) STORED
        ");
        DB::statement("CREATE INDEX course_topics_search_vector_gin_idx ON course_topics USING GIN (search_vector)");

        DB::statement("
            ALTER TABLE learning_outcomes
            ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
                to_tsvector('english'::regconfig, coalesce(l_outcome, ''))
            ) STORED
        ");
        DB::statement("CREATE INDEX learning_outcomes_search_vector_gin_idx ON learning_outcomes USING GIN (search_vector)");

        DB::statement("
            ALTER TABLE assessment_methods
            ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
                to_tsvector('english'::regconfig, coalesce(a_method, ''))
            ) STORED
        ");

        DB::statement("CREATE INDEX assessment_methods_search_vector_gin_idx ON assessment_methods USING GIN (search_vector)");

        DB::statement("
            ALTER TABLE course_description
            ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
                to_tsvector('english'::regconfig, coalesce(description, ''))
            ) STORED
        ");

        DB::statement("CREATE INDEX course_description_search_vector_gin_idx ON course_description USING GIN (search_vector)");

        DB::statement("
            ALTER TABLE course_materials
            ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
                to_tsvector(
                    'english'::regconfig,
                    coalesce(name, '') || ' ' ||
                    coalesce(type, '') || ' ' ||
                    coalesce(description, '')
                )
            ) STORED
        ");
        DB::statement("CREATE INDEX course_materials_search_vector_gin_idx ON course_materials USING GIN (search_vector)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS course_materials_search_vector_gin_idx");
        DB::statement("ALTER TABLE course_materials DROP COLUMN IF EXISTS search_vector");

        DB::statement("DROP INDEX IF EXISTS course_description_search_vector_gin_idx");
        DB::statement("ALTER TABLE course_description DROP COLUMN IF EXISTS search_vector");

        DB::statement("DROP INDEX IF EXISTS assessment_methods_search_vector_gin_idx");
        DB::statement("ALTER TABLE assessment_methods DROP COLUMN IF EXISTS search_vector");

        DB::statement("DROP INDEX IF EXISTS learning_outcomes_search_vector_gin_idx");
        DB::statement("ALTER TABLE learning_outcomes DROP COLUMN IF EXISTS search_vector");

        DB::statement("DROP INDEX IF EXISTS course_topics_search_vector_gin_idx");
        DB::statement("ALTER TABLE course_topics DROP COLUMN IF EXISTS search_vector");

        DB::statement("DROP INDEX IF EXISTS courses_search_vector_gin_idx");
        DB::statement("ALTER TABLE courses DROP COLUMN IF EXISTS search_vector");
    }
};

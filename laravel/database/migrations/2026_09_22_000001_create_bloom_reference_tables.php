<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bloom_domains', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('bloom_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('bloom_domains')->restrictOnDelete();
            $table->integer('position');
            $table->string('name');
            $table->timestamps();
            $table->unique(['domain_id', 'position']);
        });

        Schema::create('bloom_verbs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained('bloom_levels')->restrictOnDelete();
            $table->string('term');
            $table->timestamps();
        });

        // Preserve source spelling while enforcing case-insensitive, trimmed uniqueness.
        DB::statement('CREATE UNIQUE INDEX bloom_domains_name_unique ON bloom_domains (lower(trim(name)))');
        DB::statement('CREATE UNIQUE INDEX bloom_verbs_level_term_unique ON bloom_verbs (level_id, lower(trim(term)))');

        DB::statement('ALTER TABLE bloom_domains ADD CONSTRAINT bloom_domains_name_not_blank CHECK (length(trim(name)) > 0)');
        DB::statement('ALTER TABLE bloom_levels ADD CONSTRAINT bloom_levels_name_not_blank CHECK (length(trim(name)) > 0)');
        DB::statement('ALTER TABLE bloom_levels ADD CONSTRAINT bloom_levels_position_positive CHECK (position > 0)');
        DB::statement('ALTER TABLE bloom_verbs ADD CONSTRAINT bloom_verbs_term_not_blank CHECK (length(trim(term)) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bloom_verbs');
        Schema::dropIfExists('bloom_levels');
        Schema::dropIfExists('bloom_domains');
    }
};

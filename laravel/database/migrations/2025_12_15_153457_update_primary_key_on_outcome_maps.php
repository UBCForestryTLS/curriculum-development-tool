<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('outcome_maps', function (Blueprint $table) {
            DB::statement('ALTER TABLE outcome_maps DROP CONSTRAINT idx_42650_primary');
            $table->primary(['l_outcome_id', 'pl_outcome_id', 'map_scale_id']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('outcome_maps', function (Blueprint $table) {
            DB::statement('ALTER TABLE outcome_maps DROP CONSTRAINT outcome_maps_pkey');
            DB::statement('ALTER TABLE outcome_maps ADD CONSTRAINT idx_42650_primary PRIMARY KEY (l_outcome_id, pl_outcome_id)');
        });
    }
};

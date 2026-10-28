<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mapping_scale_programs', function (Blueprint $table) {
            $table->unsignedInteger('position')->nullable()->after('program_id');
        });

        $programIds = DB::table('mapping_scale_programs')
            ->distinct()
            ->orderBy('program_id')
            ->pluck('program_id');

        foreach ($programIds as $programId) {
            // Existing rows have no semantic order, so preserve their stable legacy ID order.
            $mapScaleIds = DB::table('mapping_scale_programs')
                ->where('program_id', $programId)
                ->orderBy('map_scale_id')
                ->pluck('map_scale_id');

            foreach ($mapScaleIds as $index => $mapScaleId) {
                DB::table('mapping_scale_programs')
                    ->where('program_id', $programId)
                    ->where('map_scale_id', $mapScaleId)
                    ->update(['position' => $index + 1]);
            }
        }

        Schema::table('mapping_scale_programs', function (Blueprint $table) {
            $table->unsignedInteger('position')->nullable(false)->change();
            $table->unique(['program_id', 'position'], 'mapping_scale_programs_program_position_unique');
        });
    }

    public function down(): void
    {
        Schema::table('mapping_scale_programs', function (Blueprint $table) {
            $table->dropUnique('mapping_scale_programs_program_position_unique');
            $table->dropColumn('position');
        });
    }
};

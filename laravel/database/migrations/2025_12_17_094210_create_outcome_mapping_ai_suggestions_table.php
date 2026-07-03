<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('outcome_map_ai_suggestions', function (Blueprint $table) {
            $table->unsignedBigInteger('l_outcome_id');
            $table->unsignedBigInteger('pl_outcome_id');
            $table->unsignedBigInteger('map_scale_id')->default(0);
            $table->foreign('map_scale_id')->references('map_scale_id')->on('mapping_scales')->onDelete('cascade')->onUpdate('cascade');
            $table->primary(['l_outcome_id','pl_outcome_id', 'map_scale_id']);
            $table->foreign('l_outcome_id')->references('l_outcome_id')->on('learning_outcomes')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('pl_outcome_id')->references('pl_outcome_id')->on('program_learning_outcomes')->onDelete('cascade')->onUpdate('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outcome_map_ai_suggestions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // These are mapping records for tracking progress, separate from Laravel's queue jobs table.
        Schema::create('mapping_jobs', function (Blueprint $table) {
            $table->uuid('job_id')->primary();
            $table->foreignId('course_id')->constrained('courses', 'course_id')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('programs', 'program_id')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('pending');
            // Save the original request so a retry doesn't pick up later edits to outcomes or levels.
            $table->json('input_snapshot');
            $table->unsignedInteger('total_clos');
            $table->unsignedInteger('total_plos');
            $table->unsignedInteger('total_pairs');
            $table->unsignedInteger('processed_pairs')->default(0);
            $table->unsignedInteger('mapped_pairs')->default(0);
            $table->unsignedInteger('errored_pairs')->default(0);
            $table->timestamps();

            $table->index(['course_id', 'program_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mapping_jobs');
    }
};

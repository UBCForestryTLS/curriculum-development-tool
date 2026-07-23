<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_material_files', function (Blueprint $table) {
            $table->id('course_material_file_id');

            $table->unsignedBigInteger('course_material_id');
            $table->foreign('course_material_id')
                ->references('course_material_id')
                ->on('course_materials')
                ->onDelete('cascade');

            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('set null');

            $table->string('file_name');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size');

            $table->string('status')->default('PENDING');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('page_count')->nullable();

            $table->boolean('ocr_enabled')->default(false);
            $table->unsignedInteger('ocr_threshold')->default(0);
            $table->string('extraction_engine')->default('tesseract');
            $table->unsignedInteger('processing_time_seconds')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_material_files');
    }
};

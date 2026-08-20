<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_material_chunks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_material_file_id');
            $table->foreign('course_material_file_id')
                ->references('course_material_file_id')
                ->on('course_material_files')
                ->onDelete('cascade');
            $table->unsignedInteger('page_number');
            $table->unsignedInteger('chunk_index')->default(0);
            $table->text('content');
            $table->timestamps();

            $table->unique(['course_material_file_id', 'page_number', 'chunk_index'], 'course_material_chunks_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_material_chunks');
    }
};

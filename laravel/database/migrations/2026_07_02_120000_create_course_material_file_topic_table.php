<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Maps course material files to course topics (many-to-many)
        Schema::create('course_material_file_topic', function (Blueprint $table) {
            $table->unsignedBigInteger('course_material_file_id');
            $table->unsignedBigInteger('course_topic_id');

            $table->foreign('course_material_file_id')
                ->references('course_material_file_id')->on('course_material_files')
                ->onDelete('cascade');
            $table->foreign('course_topic_id')
                ->references('course_topic_id')->on('course_topics')
                ->onDelete('cascade');

            $table->primary(['course_material_file_id', 'course_topic_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_material_file_topic');
    }
};
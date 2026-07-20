<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suggested_topics', function (Blueprint $table) {
            $table->id('suggested_topic_id');
            $table->unsignedBigInteger('course_material_file_id');
            $table->text('topic');
            $table->float('score')->nullable();
            $table->unsignedTinyInteger('status')->default(0); // 0=pending, 1=confirmed, 2=rejected
            $table->timestamps();

            $table->foreign('course_material_file_id')
                ->references('course_material_file_id')->on('course_material_files')
                ->onDelete('cascade');

            $table->unique(['course_material_file_id', 'topic']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suggested_topics');
    }
};

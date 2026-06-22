<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            // This column must match the type in the admissions table
            $table->string('studentNumber');
            $table->unsignedBigInteger('section_id')->nullable();
            $table->string('enrollment_type')->default('new');
            // Added 'whole day' as requested
            $table->enum('shift', ['morning', 'afternoon', 'whole day']);
            $table->string('year_level');
            $table->string('school_year');
            $table->string('status')->default('pending');
            $table->timestamps();

            // Cascading Foreign Key
            $table->foreign('studentNumber')
                  ->references('studentNumber')
                  ->on('admissions')
                  ->onUpdate('cascade')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
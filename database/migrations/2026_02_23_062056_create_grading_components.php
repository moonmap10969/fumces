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
        Schema::create('grading_components', function (Blueprint $table) {
    $table->id();
    $table->foreignId('section_id')->constrained()->onDelete('cascade');
    $table->string('code'); // e.g., '01', 'ACT-40'
    $table->string('category'); // e.g., 'Attendance', 'Long Quizzes'
    $table->decimal('percentage', 5, 2); // e.g., 20.00, 40.00
    $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grading_components');
    }
};

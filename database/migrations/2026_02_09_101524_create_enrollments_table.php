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
    Schema::create('enrollments', function (Blueprint $table) {
        $table->id();
        
        // Links to the Admissions record (This pulls the student's name/parent info)
        $table->foreignId('user_id')->constrained('admissions')->onDelete('cascade');
        
        // Links to the Sections table (using your custom PK studentNumber)
        $table->foreignId('section_id')->constrained('sections', 'studentNumber');

        // Enrollment specific details
        $table->string('shift'); // Morning or Afternoon
        $table->string('school_year'); // e.g., "2025-2026"
        $table->enum('status', ['enrolled', 'dropped', 'completed'])->default('enrolled');
        
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};

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
        Schema::table('enrollments', function (Blueprint $table) {
            // Link enrollment to the specific admission record
            $table->foreignId('admission_id')->after('id')->constrained('admissions')->onDelete('cascade');
            $table->foreignId('section_id')->constrained('sections'); // Ensure this exists too
            $table->string('shift');
        });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       Schema::table('enrollments', function (Blueprint $table) {
        $table->foreignId('admission_id')->nullable()->after('id')->constrained('admissions')->onDelete('cascade');
        $table->foreignId('section_id')->nullable()->constrained('sections');
        $table->string('shift')->nullable();
    });
    }
};

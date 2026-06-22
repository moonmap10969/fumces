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
       Schema::table('tuitions', function (Blueprint $table) {
    // Option A: Explicitly use an existing column found from the check above
    $table->string('student_name')->after('id'); 
    
    // Option B: Safest - remove 'after' to just append the column
    // $table->string('student_name'); 
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students_grades');
    }
};

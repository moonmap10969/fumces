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
    Schema::create('admissions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->onDelete('cascade');
        $table->string('studentFirstName');
        $table->string('studentLastName');
        $table->date('dateOfBirth');
        $table->string('year_level');
        $table->string('previousSchool')->nullable();
        $table->string('parentFirstName');
        $table->string('parentLastName');
        $table->string('email');
        $table->string('phone');
        $table->string('address'); 
        $table->string('city'); 
        $table->string('state'); 
        $table->string('zipCode'); 
        $table->string('street'); 
        $table->string('zip');    
        $table->string('status')->default('pending');
        $table->string('student_number')->unique()->nullable();
        $table->string('report_card')->nullable();
        $table->string('birth_certificate')->nullable();
        $table->string('applicant_photo')->nullable();
        $table->string('father_photo')->nullable();
        $table->string('mother_photo')->nullable();
        $table->string('guardian_photo')->nullable();
        $table->string('transferee_docs')->nullable();
        $table->timestamps();
    });
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admissions');
    }
};

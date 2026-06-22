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
        Schema::create('students', function (Blueprint $col) {
    $col->id();
    $col->string('student_number')->unique();
    $col->string('email')->unique();
    $col->string('password');
    $col->timestamp('password_changed_at')->nullable(); // Track if they've reset yet
    $col->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};

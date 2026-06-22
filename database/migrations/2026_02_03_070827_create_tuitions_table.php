<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tuitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('student_name');
            $table->decimal('tuition_fee', 10, 2)->default(0);
            $table->decimal('misc_fees', 10, 2)->default(0);
            $table->decimal('amount', 10, 2)->default(0);
            $table->enum('payment_method', ['gcash', 'bank_transfer', 'cash'])->nullable();
            $table->enum('status', ['pending', 'partial', 'paid'])->default('pending');
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('reference_number')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tuitions');
    }
};
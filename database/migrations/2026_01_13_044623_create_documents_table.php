<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up(): void
{
    Schema::create('documents', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->onDelete('cascade');
        $table->string('file_name');
        $table->string('file_path');
        $table->string('type')->default('general'); // e.g., 'admission', 'tuition', 'general'
        $table->string('status')->default('pending'); // pending, approved, rejected
        $table->timestamps();
    });
}

    public function down()
    {
        Schema::dropIfExists('documents');
    }
};

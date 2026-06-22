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
    Schema::create('lessons', function (Blueprint $table) {
        $table->id(); // auto-increment
        $table->string('title');
        $table->string('subject');
        $table->date('date');
        $table->text('description')->nullable();
        $table->enum('status', ['draft', 'ready', 'completed'])->default('draft');
        $table->string('file_path')->nullable(); // ← add this
        $table->timestamps();
    });
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};

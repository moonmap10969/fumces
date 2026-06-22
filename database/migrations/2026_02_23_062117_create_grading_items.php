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
        Schema::create('grading_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained('grading_components')->onDelete('cascade');
            $table->string('item_name'); 
            $table->integer('max_score'); 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grading_items');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grading_items', function (Blueprint $table) {
            if (!Schema::hasColumn('grading_items', 'is_released')) {
                $table->boolean('is_released')->default(false)->after('date_administered');
            }
        });

        Schema::table('class_records', function (Blueprint $table) {
            if (!Schema::hasColumn('class_records', 'is_released_final')) {
                $table->boolean('is_released_final')->default(false)->after('final_average');
            }
        });
    }

    public function down(): void
    {
        Schema::table('grading_items', function (Blueprint $table) {
            if (Schema::hasColumn('grading_items', 'is_released')) {
                $table->dropColumn('is_released');
            }
        });

        Schema::table('class_records', function (Blueprint $table) {
            if (Schema::hasColumn('class_records', 'is_released_final')) {
                $table->dropColumn('is_released_final');
            }
        });
    }
};


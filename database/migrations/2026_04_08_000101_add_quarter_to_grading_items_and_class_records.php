<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Grading items are created by quarter (Q1..Q4). This field is required
        // so the teacher can be restricted to encoding/releasing only the active quarter.
        Schema::table('grading_items', function (Blueprint $table) {
            if (!Schema::hasColumn('grading_items', 'quarter')) {
                $table->string('quarter', 20)->default('q1_grade')->after('max_score');
                $table->index(['section_id', 'quarter']);
            }
        });

        // NOTE:
        // We are not strictly quarterizing class_records in this patch, but adding the column
        // makes it possible to extend quarter-based release/display later.
        Schema::table('class_records', function (Blueprint $table) {
            if (!Schema::hasColumn('class_records', 'quarter')) {
                $table->string('quarter', 20)->nullable()->after('section_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('grading_items', function (Blueprint $table) {
            if (Schema::hasColumn('grading_items', 'quarter')) {
                $table->dropColumn('quarter');
            }
        });

        Schema::table('class_records', function (Blueprint $table) {
            if (Schema::hasColumn('class_records', 'quarter')) {
                $table->dropColumn('quarter');
            }
        });
    }
};


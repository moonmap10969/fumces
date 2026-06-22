<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up(): void
{
    Schema::table('enrollments', function (Blueprint $table) {
        // Only drop if the foreign key exists
        if (collect(DB::select("SHOW KEYS FROM enrollments WHERE Key_name = 'enrollments_section_id_foreign'"))->isNotEmpty()) {
            $table->dropForeign(['section_id']);
        }
    });

    // Your remaining logic for users or changing column types
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE `users` MODIFY `id` BIGINT UNSIGNED NOT NULL');
    }
};
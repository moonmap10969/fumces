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
    Schema::table('admissions', function (Blueprint $table) {
        // Only add user_id if it doesn't exist yet
        if (!Schema::hasColumn('admissions', 'user_id')) {
            $table->foreignId('user_id')->after('id')->nullable()->constrained()->onDelete('cascade');
        }
        
        // Ensure these columns exist for your Controller mapping
        if (!Schema::hasColumn('admissions', 'date_of_birth')) {
            $table->date('date_of_birth')->nullable();
            $table->string('grade_applied')->nullable();
            $table->string('parent_first_name')->nullable();
            $table->string('parent_last_name')->nullable();
        }
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            //
        });
    }
};

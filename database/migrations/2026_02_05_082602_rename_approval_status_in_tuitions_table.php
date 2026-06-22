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
    Schema::table('tuitions', function (Blueprint $table) {
        // Use change() to rename and fix the default value syntax
        $table->enum('status', ['pending', 'approved', 'rejected'])
              ->default('pending')
              ->change();
              
        // If the above fails to rename, use the native SQL rename first:
        // \DB::statement("ALTER TABLE tuitions CHANGE approval_status status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending'");
    });
}

public function down(): void
{
    Schema::table('tuitions', function (Blueprint $table) {
        $table->renameColumn('status', 'approval_status');
    });
}
};

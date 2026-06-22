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
    Schema::table('users', function (Blueprint $table) {
        if (!Schema::hasColumn('users', 'is_approved')) {
            $table->boolean('is_approved')->default(false)->after('role');
        }
        if (!Schema::hasColumn('users', 'studentNumber')) {
            $table->string('studentNumber')->nullable()->after('is_approved');
        }
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn(['is_approved']);
    });
}
};

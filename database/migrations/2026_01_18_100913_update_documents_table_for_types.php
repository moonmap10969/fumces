<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('documents', function (Blueprint $table) {
            // Add nullable user_id if not exists
            if (!Schema::hasColumn('documents', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            }

            // Add nullable admission_id if not exists
            if (!Schema::hasColumn('documents', 'admission_id')) {
                $table->foreignId('admission_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            }

            // Add type column if not exists
            if (!Schema::hasColumn('documents', 'type')) {
                $table->string('type')->default('general')->after('admission_id');
            }
        });
    }

    public function down()
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['admission_id']);
            $table->dropColumn(['user_id', 'admission_id', 'type']);
        });
    }
};

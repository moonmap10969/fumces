<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        Schema::table('documents', function (Blueprint $table) {
            if (!Schema::hasColumn('documents', 'user_id')) {
                // Add column nullable for safety
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
            }
        });

        // Optional: assign a valid user to existing documents, or leave null
        // DB::table('documents')->whereNull('user_id')->update(['user_id' => 1]);

        // Add foreign key only for non-null user_ids
        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete(); // avoids conflict if user is missing
        });
    }

    public function down()
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};

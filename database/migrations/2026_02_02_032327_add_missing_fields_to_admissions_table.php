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
        if (!Schema::hasColumn('admissions', 'city')) {
            $table->string('city')->after('address');
        }
        if (!Schema::hasColumn('admissions', 'state')) {
            $table->string('state')->after('city');
        }
        if (!Schema::hasColumn('admissions', 'zipCode')) {
            $table->string('zipCode')->after('state');
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

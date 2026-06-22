<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RemoveSemesterFromAcademicYearsTable extends Migration
{
    public function up()
    {
        Schema::table('academic_years', function (Blueprint $table) {
            $table->dropColumn('semester');
        });
    }

    public function down()
    {
        Schema::table('academic_years', function (Blueprint $table) {
            $table->string('semester')->nullable();
        });
    }
}
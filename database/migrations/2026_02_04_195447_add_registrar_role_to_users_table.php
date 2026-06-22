<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE users 
            MODIFY role ENUM(
                'admin',
                'registrar',
                'student',
                'teacher',
                'applicant'
            ) NOT NULL DEFAULT 'applicant'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE users 
            MODIFY role ENUM(
                'admin',
                'student',
                'teacher',
                'applicant'
            ) NOT NULL DEFAULT 'applicant'
        ");
    }
};

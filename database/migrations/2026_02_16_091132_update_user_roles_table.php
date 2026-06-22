<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Convert to string temporarily to allow any data update
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->change();
        });

        // 2. Clean up existing data: Rename 'system admin' to 'admin'
        DB::table('users')->where('role', 'system admin')->update(['role' => 'admin']);

        // 3. Map any old or incompatible roles (like 'developer') to 'admin' or 'user'
        DB::table('users')
            ->whereNotIn('role', ['user', 'applicant', 'student', 'admissions', 'teacher', 'cashier', 'registrar', 'admin'])
            ->update(['role' => 'user']);

        // 4. Lock it down to the final ENUM list with 'user' as the default
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', [
                'user',
                'applicant',
                'student',
                'admissions',
                'teacher',
                'cashier',
                'registrar',
                'admin'
            ])->default('user')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('student')->change();
        });
    }
};
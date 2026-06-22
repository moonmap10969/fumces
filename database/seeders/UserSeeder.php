<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
{
    // Create Admin Account
    \App\Models\User::create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'password' => Hash::make('password'),
        'role' => 'admin',
        'must_change_password' => false,
    ]);

    // Create Student Account for Testing Redirect
    \App\Models\User::create([
        'name' => 'Test Student',
        'username' => '20264686',
        'email' => 'student@example.com',
        'password' => Hash::make('password'),
        'role' => 'student',
        'must_change_password' => true,
    ]);
}
}

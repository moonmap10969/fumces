<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class StudentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
{
    // Example: Using a specific numeric ID as the username
    \App\Models\User::create([
        'name' => 'Test Student',
        'email' => 'student@example.com',
        'username' => '20268321', // The Student ID
        'password' => \Illuminate\Support\Facades\Hash::make('20268321'),
        'role' => 'student',
    ]);
}
}

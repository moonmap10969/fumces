<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Report;

class ReportSeeder extends Seeder
{
    public function run(): void
    {
        // Performance Reports
        Report::create([
            'title' => 'Midterm Student Performance',
            'type' => 'performance',
            'description' => 'Analysis of student grades for midterm exams.',
        ]);

        Report::create([
            'title' => 'End-of-Year Performance Summary',
            'type' => 'performance',
            'description' => 'Final report of student achievements for the year.',
        ]);

        // Financial Reports
        Report::create([
            'title' => 'Monthly Tuition Collection',
            'type' => 'financial',
            'description' => 'Summary of all tuition payments collected in the month.',
        ]);

        Report::create([
            'title' => 'Annual Budget Report',
            'type' => 'financial',
            'description' => 'Overview of school financial budget for the year.',
        ]);
    }
}

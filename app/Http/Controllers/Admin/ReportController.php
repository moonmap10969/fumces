<?php
// app/Http/Controllers/Admin/ReportController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
  public function index()
{
    // 1. Quick Stats
    $statusCounts = Admission::select('status', DB::raw('count(*) as total'))
        ->groupBy('status')
        ->pluck('total', 'status');

    // 2. DEPED Compliance Data (Kinder to Grade 10)
    $enrollments = Admission::select('year_level', 'gender', DB::raw('count(*) as total'))
        ->whereIn('status', ['enrolled', 'approved'])
        ->groupBy('year_level', 'gender')
        ->get();

    $gradeLevels = [
        'kinder1', 'kinder2', 'kinder3', 
        'grade1', 'grade2', 'grade3', 'grade4', 'grade5', 'grade6',
        'grade7', 'grade8', 'grade9', 'grade10'
    ];

    $depedData = [];
    $totalMale = 0;
    $totalFemale = 0;

    foreach ($gradeLevels as $grade) {
        $depedData[$grade] = ['Male' => 0, 'Female' => 0, 'Total' => 0];
    }

    foreach ($enrollments as $row) {
        if (array_key_exists($row->year_level, $depedData)) {
            $gender = $row->gender === 'Female' ? 'Female' : 'Male';
            $depedData[$row->year_level][$gender] = $row->total;
            $depedData[$row->year_level]['Total'] += $row->total;
            
            if ($gender === 'Male') $totalMale += $row->total;
            if ($gender === 'Female') $totalFemale += $row->total;
        }
    }

    // 3. Time-Series Forecasting Logic
    $monthlyData = Admission::select(
        DB::raw('COUNT(id) as count'), 
        DB::raw("DATE_FORMAT(created_at, '%M') as month"),
        DB::raw("MONTH(created_at) as month_num")
    )
    ->where('created_at', '>=', now()->subMonths(6))
    ->groupBy('month_num', 'month')
    ->orderBy('month_num')
    ->get();

    $labels = $monthlyData->pluck('month')->toArray();
    $counts = $monthlyData->pluck('count')->toArray();

    $forecastLabels = [];
    $forecastValues = [];
    $n = count($counts);

    if ($n > 1) {
        $xSum = 0; $ySum = 0; $xySum = 0; $x2Sum = 0;
        foreach ($counts as $i => $y) {
            $x = $i + 1;
            $xSum += $x; $ySum += $y; $xySum += ($x * $y); $x2Sum += ($x * $x);
        }
        $denominator = ($n * $x2Sum - $xSum * $xSum);
        $slope = $denominator != 0 ? ($n * $xySum - $xSum * $ySum) / $denominator : 0;
        $intercept = ($ySum - $slope * $xSum) / $n;

        for ($offset = 1; $offset <= 2; $offset++) {
            $targetDate = now()->addMonths($offset);
            $targetMonthName = $targetDate->format('F');
            $basePrediction = $slope * ($n + $offset) + $intercept;
            $multiplier = in_array($targetMonthName, ['June', 'July']) ? 1.25 : 1.0;
            
            $forecastLabels[] = $targetMonthName;
            $forecastValues[] = max(0, round($basePrediction * $multiplier));
        }
    }

    // 4. Executive Insight Calculations
    $topGrade = collect($depedData)->sortByDesc('Total')->keys()->first();
    $topGradeCount = collect($depedData)->max('Total');
    
    $currentMonthCount = end($counts) ?: 0;
    $prevMonthCount = prev($counts) ?: 1;
    $growthRate = (($currentMonthCount - $prevMonthCount) / $prevMonthCount) * 100;

    return view('admin.reports', compact(
        'statusCounts', 'depedData', 'totalMale', 'totalFemale', 'gradeLevels',
        'labels', 'counts', 'forecastLabels', 'forecastValues',
        'topGrade', 'topGradeCount', 'growthRate'
    ));
}

    public function exportCsv()
{
    // 1. Fetch data specifically for Enrolled/Approved students
    $enrollments = Admission::select('year_level', 'gender', DB::raw('count(*) as total'))
        ->whereIn('status', ['enrolled', 'approved'])
        ->groupBy('year_level', 'gender')
        ->get();

    // 2. Define the full K-10 Academic Offering
    $gradeLevels = [
        'kinder1', 'kinder2', 'kinder3', 
        'grade1', 'grade2', 'grade3', 'grade4', 'grade5', 'grade6',
        'grade7', 'grade8', 'grade9', 'grade10'
    ];

    $depedData = [];
    $totalMale = 0;
    $totalFemale = 0;

    // Initialize the structure
    foreach ($gradeLevels as $grade) {
        $depedData[$grade] = ['Male' => 0, 'Female' => 0, 'Total' => 0];
    }

    // Map database results to the structure
    foreach ($enrollments as $row) {
        if (array_key_exists($row->year_level, $depedData)) {
            $gender = $row->gender === 'Female' ? 'Female' : 'Male';
            $depedData[$row->year_level][$gender] = $row->total;
            $depedData[$row->year_level]['Total'] += $row->total;
            
            if ($gender === 'Male') $totalMale += $row->total;
            if ($gender === 'Female') $totalFemale += $row->total;
        }
    }

    $fileName = 'FUMCES_Institutional_Report_' . date('Y-m-d') . '.csv';
    $headers = [
        "Content-type"        => "text/csv",
        "Content-Disposition" => "attachment; filename=$fileName",
        "Pragma"              => "no-cache",
        "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
        "Expires"             => "0"
    ];

    $callback = function() use($depedData, $totalMale, $totalFemale) {
        $file = fopen('php://output', 'w');
        
        // Add UTF-8 BOM for Excel compatibility with Peso signs
        fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // CSV Headers
        fputcsv($file, ['Grade Level', 'Male', 'Female', 'Total Count']);

        // Data Rows
        foreach ($depedData as $grade => $data) {
            fputcsv($file, [
                ucwords(str_replace(['kinder', 'grade'], ['Kinder ', 'Grade '], $grade)),
                $data['Male'],
                $data['Female'],
                $data['Total']
            ]);
        }

        // Grand Total Row
        fputcsv($file, ['GRAND TOTAL', $totalMale, $totalFemale, $totalMale + $totalFemale]);

        fclose($file);
    };

    return response()->stream($callback, 200, $headers);
}
}
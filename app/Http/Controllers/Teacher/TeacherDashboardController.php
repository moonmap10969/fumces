<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class TeacherDashboardController extends Controller
{
    public function index()
    {
        $teacherName = Auth::user()->name;

        // 1. Fetch relevant enrollments
        $enrollments = Enrollment::whereIn('section_id', function($query) use ($teacherName) {
            $query->select('section_id')->from('schedules')->where('teacher', $teacherName);
        })->with(['admission'])->get();

        // 2. Run Analytics
        $analyticsResults = $this->runPythonAnalytics($enrollments);

        // 3. Stats for Dashboard Cards
        $totalRiskCount = collect($analyticsResults)->where('at_risk', true)->count();
        $totalAnomalyCount = collect($analyticsResults)->where('anomaly_alert', true)->count();
        
        $totalStudents = $enrollments->count();
        $overallPassingRate = $totalStudents > 0 
            ? ($enrollments->where('final_percentage', '>=', 75)->count() / $totalStudents) * 100 
            : 0;

        return view('teacher.index', compact(
            'enrollments', 
            'analyticsResults', 
            'totalRiskCount', 
            'totalAnomalyCount', 
            'overallPassingRate'
        ));
    }

    private function runPythonAnalytics($enrollments)
    {
        if ($enrollments->isEmpty()) return [];

        $teacherName = Auth::user()->name;

        $studentsData = $enrollments->map(function ($enrollment) use ($teacherName) {
            // 1️⃣ Fetch Actual Grade (Ensure this matches your DB column names)
           // app/Http/Controllers/Teacher/TeacherDashboardController.php

        $actualGrade = \App\Models\ClassRecord::where('studentNumber', $enrollment->studentNumber)
            ->where('section_id', $enrollment->section_id) // Use section_id instead of subject_id
            ->where('is_released_final', 1)               // Only pick official/released grades
            ->latest()                                    // Get the most recent quarter
            ->value('final_average');

            // 2️⃣ Fetch Attendance (Ensure this isn't null)
            $attendance = $enrollment->attendance_percentage;

            // 3️⃣ DATA FALLBACK: If data is missing, the AI defaults to 0%. 
            // We ensure a minimum of 1 for the math to work if you want to avoid 0% for new students.
            return [
                'student_id'      => $enrollment->id,
                'attendance_rate' => (float) ($attendance ?? 0),
                'activities_avg'  => (float) ($actualGrade ?? 0),
                'exam_score'      => (float) ($actualGrade ?? 0) 
            ];
        })->values()->toArray();

        // Log the data to see what is being sent to Python (Check your storage/logs/laravel.log)
        \Log::info('AI Payload:', $studentsData);

        try {
            $process = new Process(['python3', base_path('scripts/performance_analytics.py'), json_encode($studentsData)]);
            $process->run();

            if ($process->isSuccessful()) {
                $output = json_decode($process->getOutput(), true);
                return collect($output['data'])->keyBy('student_id')->toArray();
            }
        } catch (\Exception $e) {
            return [];
        }
        return [];
    }
}
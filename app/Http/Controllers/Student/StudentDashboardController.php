<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\ClassRecord;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Subject;
use App\Models\Tuition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class StudentDashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $admission = Admission::where('user_id', $user->id)->first();
        $enrollment = null;
        $currentAverage = null;
        $remainingBalance = 0;
        $academicYearLabel = 'No Active School Year';
        $subjectCount = 0;

        if ($admission) {
            $enrollment = Enrollment::where('studentNumber', $admission->studentNumber)
                ->latest('id')
                ->first();

            if ($enrollment) {
                $academicYearLabel = $enrollment->school_year ?: $academicYearLabel;

               $subjectFinals = ClassRecord::where('studentNumber', $enrollment->id)
                    ->whereNotNull('final_average')
                    ->pluck('final_average')
                    ->filter(fn($v) => $v > 0);

                $currentAverage = $subjectFinals->isNotEmpty()
                    ? round($subjectFinals->avg(), 2)
                    : null;

                // Count subjects from admin-managed subjects table (same source as Student Grades page)
                $rawYearLevel = $user->year_level ?: ($enrollment->year_level ?? '');
                $normalizedYearLevel = strtolower(str_replace(' ', '', (string) $rawYearLevel));
                $subjectGradeLevel = preg_match('/^grade(\d+)$/', $normalizedYearLevel, $matches)
                    ? 'Grade ' . $matches[1]
                    : ucwords(trim((string) $rawYearLevel));

                $subjectCount = Subject::query()
                    ->whereRaw('LOWER(REPLACE(grade_level, " ", "")) = ?', [strtolower(str_replace(' ', '', $subjectGradeLevel))])
                    ->where('is_active', true)
                    ->count();

                $activeTuition = Tuition::where('studentNumber', $admission->studentNumber)
                    ->latest('id')
                    ->first();

                if ($activeTuition) {
                    $totalPaid = Payment::where('tuition_id', $activeTuition->id)
                        ->whereIn('status', ['completed', 'approved'])
                        ->sum('amount');

                    // Align with Tuition page computation: tuition_fee + fee_structures.total_misc
                    $gradeFromDb = $activeTuition->year_level ?? '';
                    $studentLevel = str_replace(' ', '', strtolower($gradeFromDb));

                    $feeRecord = DB::table('fee_structures')
                        ->where('academic_year_id', $activeTuition->academic_year_id)
                        ->where('year_level', $studentLevel)
                        ->first();

                    $baseTuition = (float) $activeTuition->tuition_fee;
                    $totalMisc = $feeRecord ? (float) $feeRecord->total_misc : 0.0;
                    $totalAssessment = $baseTuition + $totalMisc;

                    $remainingBalance = max(0, $totalAssessment - (float) $totalPaid);
                }
            }
        }

        $stats = [
            [
                'label' => 'Current Average',
                'value' => $currentAverage !== null ? $currentAverage . '%' : 'No Grades Yet',
                'color' => 'text-green-600',
                'status' => $currentAverage !== null ? 'Updated' : 'Pending',
            ],
            [
                'label' => 'Active Subjects',
                'value' => $subjectCount,
                'color' => 'text-blue-600',
                'status' => $subjectCount > 0 ? 'Assigned' : 'No Schedule',
            ],
            [
                'label' => 'Balance Due',
                'value' => 'PHP ' . number_format($remainingBalance, 2),
                'color' => 'text-red-600',
                'status' => $remainingBalance > 0 ? 'Unpaid' : 'Settled',
            ],
        ];

        $announcements = [];
        $termLabel = now()->month <= 6 ? 'Second Semester' : 'First Semester';

        return view('student.dashboard', compact('user', 'stats', 'announcements', 'academicYearLabel', 'termLabel'));
    }
}
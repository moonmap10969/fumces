<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Attendance;
use App\Models\Section;
use App\Models\GradingCategory;
use App\Models\GradingItem;
use App\Models\Score;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $selectedSectionId = $request->get('section');
        $date = $request->get('date', date('Y-m-d'));

        // Use fully qualified names to prevent "Undefined type" errors
        $categories = \App\Models\GradingComponent::where('section_id', $selectedSectionId)->get();
        $gradingItems = \App\Models\GradingItem::where('section_id', $selectedSectionId)->get();

        $enrollments = Enrollment::with(['admission', 'scores.gradingItem'])
            ->where('section_id', $selectedSectionId)
            ->get()
            ->map(function($enrollment) use ($categories, $selectedSectionId) {
                $finalGrade = 0;
                foreach ($categories as $category) {
                    if (strtolower($category->name) == 'attendance') {
                        $totalDays = Attendance::where('section_id', $selectedSectionId)->distinct('date')->count();
                        $presentDays = Attendance::where('student_number', $enrollment->student_number)
                                                ->where('status', 'Present')->count();
                        $attPercentage = $totalDays > 0 ? ($presentDays / $totalDays) * 100 : 0;
                        $finalGrade += ($attPercentage * ($category->weight / 100));
                    } else {
                        $catScores = $enrollment->scores->filter(fn($s) => $s->gradingItem->category_id == $category->id);
                        $earned = $catScores->sum('score');
                        $max = $catScores->sum(fn($s) => $s->gradingItem->max_score ?? 0);
                        $catPercentage = $max > 0 ? ($earned / $max) * 100 : 0;
                        $finalGrade += ($catPercentage * ($category->weight / 100));
                    }
                }
                $enrollment->final_percentage = round($finalGrade, 2);
                return $enrollment;
            });

        $attendanceRecords = Attendance::where('section_id', $selectedSectionId)
            ->where('date', $date)
            ->pluck('status', 'student_number')
            ->toArray();

        return view('teacher.grades.index', compact('enrollments', 'gradingItems', 'selectedSectionId', 'date', 'attendanceRecords', 'categories'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'section_id' => 'required',
            'date' => 'required|date',
            'attendance' => 'required|array',
        ]);

        try {
            DB::transaction(function () use ($request) {
                foreach ($request->attendance as $studentNumber => $status) {
                    Attendance::updateOrCreate(
                        ['section_id' => $request->section_id, 'student_number' => $studentNumber, 'date' => $request->date],
                        ['status' => $status, 'updated_at' => now()]
                    );
                }
            });
            return back()->with('success', "Attendance saved.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function storeScores(Request $request)
    {
        $request->validate(['grading_item_id' => 'required', 'scores' => 'required|array']);

        try {
            DB::transaction(function () use ($request) {
                foreach ($request->scores as $enrollmentId => $scoreValue) {
                    if ($scoreValue !== null) {
                        \App\Models\GradingItem::updateOrCreate(
                            ['enrollment_id' => $enrollmentId, 'grading_item_id' => $request->grading_item_id],
                            ['score' => $scoreValue, 'updated_at' => now()]
                        );
                    }
                }
            });
            return back()->with('success', "Scores saved.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\Enrollment;
use App\Models\Schedule; 
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClassListController extends Controller
{
    /**
     * Display a listing of sections assigned to the teacher.
     */
public function index()
{
    $teacherName = Auth::user()->name;

    // 1. Get unique section names and year levels from the schedule
    $assignedSchedules = Schedule::where('teacher', $teacherName)
        ->select('section', 'year_level')
        ->distinct()
        ->get();

    // 2. Map to Section models AND eager load the students/enrollments
    $sections = $assignedSchedules->map(function ($item) {
        return Section::with(['enrollments.admission']) // CRITICAL: Load the relationships
            ->where('name', trim($item->section))
            ->where('year_level', trim($item->year_level))
            ->first();
    })->filter(); // Removes any null results if a schedule points to a non-existent section

    return view('teacher.classlist.index', compact('sections'));
}
    /**
     * Show the student roster for a specific section.
     */
    public function show($id)
    {
        $section = Section::with(['enrollments.admission'])->findOrFail($id);
        return view('teacher.classlist.index', compact('section'));
    }

    /**
     * Handle score submission.
     */
    public function updateScores(Request $request, $id)
    {
        $request->validate([
            'scores' => 'required|array',
            'scores.*' => 'nullable|numeric|min:0|max:100',
        ]);

        foreach ($request->scores as $enrollmentId => $score) {
            Enrollment::where('id', $enrollmentId)->update(['score' => $score]);
        }

        return back()->with('success', 'Student scores have been updated successfully!');
    }
}
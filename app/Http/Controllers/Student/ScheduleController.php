<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Enrollment;
use App\Models\Schedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Subject;

class ScheduleController extends Controller
{
    public function index()
    {
        $student = Auth::user();
        $admission = Admission::where('user_id', $student->id)->first();
        $enrollment = $admission
            ? Enrollment::where('studentNumber', $admission->studentNumber)->latest('id')->first()
            : null;

        // Resolve grade level label for subjects table lookup
        $rawYearLevel = $enrollment?->year_level
            ?: ($student->year_level ?: ($admission?->year_level ?? ''));
        $normalizedYearLevel = strtolower(str_replace(' ', '', (string) $rawYearLevel));
        $subjectGradeLevel = preg_match('/^grade(\d+)$/', $normalizedYearLevel, $matches)
            ? 'Grade ' . $matches[1]
            : ucwords(trim((string) $rawYearLevel));

        // Get allowed subjects for this grade level — normalized to lowercase for safe comparison
        $allowedSubjects = Subject::query()
            ->whereRaw('LOWER(REPLACE(grade_level, " ", "")) = ?', [
                strtolower(str_replace(' ', '', $subjectGradeLevel))
            ])
            ->where('is_active', true)
            ->pluck('name')
            ->map(fn($s) => strtolower(trim((string) $s))) // normalize to lowercase
            ->filter()
            ->values();

        $scheduleQuery = Schedule::query()
            ->when($enrollment?->section_id, fn($q) =>
                $q->where('section_id', $enrollment->section_id))
            ->when(
                !$enrollment?->section_id && !empty($rawYearLevel),
                fn($q) => $q->whereRaw('LOWER(REPLACE(year_level, " ", "")) = ?', [$normalizedYearLevel])
            )
            // Case-insensitive comparison on both sides
            ->when($allowedSubjects->isNotEmpty(), fn($q) =>
                $q->whereIn(DB::raw('TRIM(LOWER(subject))'), $allowedSubjects->all())
            )
            ->orderByRaw("FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday')")
            ->orderBy('start_time');

        $schedules = $scheduleQuery->get();

        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $weeklySchedule = collect($days)->map(fn($day) => [
            'day'      => $day,
            'subjects' => $schedules->where('day_of_week', $day)
        ]);

        $subjects = $schedules->unique('subject')->map(fn($item) => [
            'name'        => $item->subject,
            'units'       => 3,
            'description' => 'No description available',
            'teacher'     => $item->teacher ?? 'TBA',
            'schedule'    => \Carbon\Carbon::parse($item->start_time)->format('g:i A')
                             . ' - ' .
                             \Carbon\Carbon::parse($item->end_time)->format('g:i A'),
            'room'        => $item->room ?? 'TBA',
        ]);

        return view('student.schedule.index', [
            'weeklySchedule' => $weeklySchedule,
            'subjects'       => $subjects,
            'totalUnits'     => $subjects->sum('units'),
        ]);
    }

    public function dashboard(): RedirectResponse
    {
        return redirect()->route('student.dashboard');
    }
}
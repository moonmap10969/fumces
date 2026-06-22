<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\Enrollment;
use Illuminate\Support\Facades\Auth;

class ScheduleController extends Controller
{
    public function index()
{
    $teacherName = Auth::user()->name;

    $schedules = Schedule::where('teacher', $teacherName)
        ->orderByRaw("FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday')")
        ->orderBy('start_time')
        ->get()
        ->map(function ($entry) {
            // Find the Section using the custom primary key 'section_id'
            $section = Section::with('enrollments')
                ->where('name', trim($entry->section))
                ->where('year_level', trim($entry->year_level))
                ->first();

            // Attach the section model and the specific section_id for the URL
            $entry->section_model = $section;
            $entry->section_id = $section ? $section->section_id : null;

            return $entry;
        });

    return view('teacher.schedule.index', compact('schedules'));
}
}
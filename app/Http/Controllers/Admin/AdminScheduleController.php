<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Models\User;    
use App\Models\Section; 
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\Room;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;

class AdminScheduleController extends Controller
{
    public function index(Request $request)
    {
        $day = $request->query('day', 'All Days');
        $grade = $request->query('grade', 'All Grades');
        $teacher = $request->query('teacher', 'All Teachers');

        $schedules = Schedule::query()
            ->when($day !== 'All Days', fn($q) => $q->where('day_of_week', $day))
            ->when($grade !== 'All Grades', fn($q) => $q->where('year_level', $grade))
            ->when($teacher !== 'All Teachers', fn($q) => $q->where('teacher', $teacher))
            ->orderByRaw("FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday')")
            ->orderBy('start_time')
            ->get();

        $teachers = Schedule::distinct()->pluck('teacher'); 

        return view('admin.schedule.index', compact('schedules', 'day', 'grade', 'teacher', 'teachers'));
    }

    public function create()
    {
        $teachers = User::where('role', 'teacher')->orderBy('name')->get();
        $sections = Section::all();
        $rooms    = Room::active()->orderBy('name')->get();
        $subjects = Subject::active()->orderBy('name')->get();
        return view('admin.schedule.create', compact('teachers', 'sections', 'rooms', 'subjects'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject' => 'required|string',
            'teacher' => 'required|string',
            'days' => 'required|array|min:1',
            'days.*' => 'string|in:Monday,Tuesday,Wednesday,Thursday,Friday',
            'start_time' => 'required',
            'end_time' => 'required',
            'room' => 'required|string',
            'year_level' => 'required|string',
            'section' => 'required|string',
        ]);

        $weekOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $days = array_values(array_intersect($weekOrder, array_unique($validated['days'])));

        $sectionRecord = Section::where('name', $request->section)
            ->where('year_level', $request->year_level)
            ->first();

        if (!$sectionRecord) {
            return back()->withErrors(['section' => 'The selected section does not exist for this grade.'])->withInput();
        }

        foreach ($days as $day) {
            if ($conflict = $this->checkForConflicts($request, null, $day)) {
                return $this->handleConflictResponse($conflict, $request, $day);
            }
        }

        $base = [
            'subject' => $validated['subject'],
            'teacher' => $validated['teacher'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'room' => $validated['room'],
            'year_level' => $validated['year_level'],
            'section' => $validated['section'],
            'section_id' => $sectionRecord->section_id,
        ];

        DB::transaction(function () use ($base, $days) {
            foreach ($days as $day) {
                do {
                    $id = random_int(10000, 99999);
                } while (Schedule::where('id', $id)->exists());

                Schedule::create(array_merge($base, [
                    'id' => $id,
                    'day_of_week' => $day,
                ]));
            }
        });

        $dayList = implode(', ', $days);
        $count = count($days);

        return redirect()->route('admin.schedule.index')->with(
            'success',
            $count === 1
                ? 'Schedule added successfully.'
                : "Schedules added for {$dayList} ({$count} entries)."
        );
    }

    public function edit(Schedule $schedule)
    {
        $teachers = User::where('role', 'teacher')->orderBy('name')->get();
        $sections = Section::all();
        $rooms    = Room::active()->orderBy('name')->get();
        $subjects = Subject::active()->orderBy('name')->get();
        return view('admin.schedule.edit', compact('schedule', 'teachers', 'sections', 'rooms', 'subjects'));
    }

    public function update(Request $request, Schedule $schedule)
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'teacher' => 'required|string|max:255',
            'day_of_week' => 'required|string',
            'start_time' => 'required',
            'end_time' => 'required',
            'room' => 'required|string|max:255',
            'year_level' => 'required|string',
            'section' => 'required|string|max:255',
        ]);

        // Find existing section based on UI selection
        $sectionRecord = Section::where('name', $request->section)
            ->where('year_level', $request->year_level)
            ->first();

        if (!$sectionRecord) {
            return back()->withErrors(['section' => 'Invalid section selection.'])->withInput();
        }

        $validated['section_id'] = $sectionRecord->section_id;

        $conflict = $this->checkForConflicts($request, $schedule->id);
        if ($conflict) {
            return $this->handleConflictResponse($conflict, $request);
        }

        $schedule->update($validated);
        return redirect()->route('admin.schedule.index')->with('success', 'Schedule updated successfully.');
    }

    public function destroy(Schedule $schedule)
    {
        $schedule->delete();
        return redirect()->route('admin.schedule.index')->with('success', 'Schedule deleted successfully.');
    }

    private function checkForConflicts($request, $excludeId = null, ?string $dayOfWeek = null)
    {
        $day = $dayOfWeek ?? $request->input('day_of_week');

        return Schedule::when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->where('day_of_week', $day)
            ->where(function ($query) use ($request) {
                $query->where('teacher', $request->teacher)
                      ->orWhere('room', $request->room);
            })
            ->where(function ($query) use ($request) {
                $query->where('start_time', '<', $request->end_time)
                      ->where('end_time', '>', $request->start_time);
            })->first();
    }

    private function handleConflictResponse($conflict, $request, ?string $attemptedDay = null)
    {
        $type = ($conflict->room === $request->room) ? "Room {$request->room}" : "Teacher {$request->teacher}";
        $dayNote = $attemptedDay ? " on {$attemptedDay}" : '';
        return redirect()->back()
            ->with('conflict_popup', [
                'teacher' => $conflict->teacher,
                'subject' => $conflict->subject,
                'room'    => $conflict->room,
                'time'    => Carbon::parse($conflict->start_time)->format('g:i A') . ' - ' . Carbon::parse($conflict->end_time)->format('g:i A'),
                'message' => "Conflict: {$type} is already occupied{$dayNote}."
            ])
            ->withInput();
    }
}
<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class LessonController extends Controller
{
    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function teacherSubjects(): \Illuminate\Support\Collection
    {
        $teacherName  = Auth::user()->name;
        $subjectNames = Schedule::where('teacher', $teacherName)
            ->pluck('subject')->unique()->values();

        return Subject::active()
            ->whereIn('name', $subjectNames)
            ->orderBy('grade_level')
            ->orderBy('name')
            ->get()
            ->groupBy('grade_level');
    }

    /**
     * Flat map: subject_name => { grade_level, time_period }
     * Embedded as JSON in the blade so JS can auto-fill fields on subject change.
     */
    private function subjectMeta(): array
    {
        $teacherName  = Auth::user()->name;
        $subjectNames = Schedule::where('teacher', $teacherName)
            ->pluck('subject')->unique()->values();

        return Subject::active()
            ->whereIn('name', $subjectNames)
            ->get()
            ->keyBy('name')
            ->map(fn($s) => [
                'grade_level' => $s->grade_level,
                'time_period' => $s->time_period,   // 30 or 60 (int)
            ])
            ->toArray();
    }

    private function validateLesson(Request $request, bool $isUpdate = false): array
    {
        $req = $isUpdate ? 'sometimes|required' : 'required';

        return $request->validate([
            'lesson_plan_ref'     => 'nullable|string|max:100',
            'course_ref'          => 'nullable|string|max:100',
            'title'               => "$req|string|max:255",
            'subject'             => "$req|string|exists:subjects,name",
            'topic'               => 'nullable|string|max:255',
            'date'                => "$req|date",
            'grade_level'         => 'nullable|string|max:100',
            'lesson_duration'     => 'nullable|string|max:100',
            'description'         => 'nullable|string',
            'summary_of_tasks'    => 'nullable|string',
            'materials_equipment' => 'nullable|string',
            'references'          => 'nullable|string',
            'take_home_tasks'     => 'nullable|string',
            'status'              => 'nullable|string|in:draft,ready,completed',
            'file'                => 'nullable|file|mimes:pdf,doc,docx,ppt,pptx|max:10240',
        ]);
    }

    // ─── Index ────────────────────────────────────────────────────────────────

    public function index()
    {
        $lessons     = Lesson::latest()->get();
        $subjects    = $this->teacherSubjects();
        $subjectMeta = $this->subjectMeta();

        return view('teacher.lessons.index', compact('lessons', 'subjects', 'subjectMeta'));
    }

    // ─── Store ────────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $validated = $this->validateLesson($request);

        $teacherName     = Auth::user()->name;
        $allowedSubjects = Schedule::where('teacher', $teacherName)->pluck('subject')->unique();
        if (!$allowedSubjects->contains($validated['subject'])) {
            return back()->with('error', 'You can only create lessons for subjects assigned to you.');
        }

        if ($request->hasFile('file')) {
            $validated['file_path'] = $request->file('file')->store('lessons', 'public');
        }

        $validated['status'] = $validated['status'] ?? 'draft';
        Lesson::create($validated);

        return redirect()->route('teacher.lessons.index')
            ->with('success', 'Lesson created successfully!');
    }

    // ─── Edit ─────────────────────────────────────────────────────────────────

    public function edit(Lesson $lesson)
    {
        $subjects    = $this->teacherSubjects();
        $subjectMeta = $this->subjectMeta();

        return view('teacher.lessons.edit', compact('lesson', 'subjects', 'subjectMeta'));
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function update(Request $request, Lesson $lesson)
    {
        $validated = $this->validateLesson($request, isUpdate: true);

        if (isset($validated['subject'])) {
            $teacherName     = Auth::user()->name;
            $allowedSubjects = Schedule::where('teacher', $teacherName)->pluck('subject')->unique();
            if (!$allowedSubjects->contains($validated['subject'])) {
                return back()->with('error', 'You can only select subjects assigned to you.');
            }
        }

        if ($request->hasFile('file')) {
            if ($lesson->file_path && Storage::disk('public')->exists($lesson->file_path)) {
                Storage::disk('public')->delete($lesson->file_path);
            }
            $validated['file_path'] = $request->file('file')->store('lessons', 'public');
        }

        $lesson->update($validated);

        if ($request->has('status') && !$request->has('title')) {
            return back()->with('success', 'Status updated to ' . ucfirst($request->status));
        }

        return redirect()->route('teacher.lessons.index')
            ->with('success', 'Lesson plan updated successfully!');
    }

    // ─── Destroy ──────────────────────────────────────────────────────────────

    public function destroy(Lesson $lesson)
    {
        if ($lesson->file_path && Storage::disk('public')->exists($lesson->file_path)) {
            Storage::disk('public')->delete($lesson->file_path);
        }
        $lesson->delete();

        return back()->with('success', 'Lesson deleted successfully!');
    }

    // ─── Preview — opens the print-ready template in a new tab ───────────────

    public function preview(Lesson $lesson)
    {
        return view('teacher.lessons.pdf-template', compact('lesson'));
    }

    // ─── "Download" — opens the same page and auto-triggers print dialog ─────
    //  No packages needed. Browser handles Save as PDF natively.

    public function download(Lesson $lesson)
    {
        return view('teacher.lessons.pdf-template', [
            'lesson'    => $lesson,
            'autoPrint' => true,
        ]);
    }
}
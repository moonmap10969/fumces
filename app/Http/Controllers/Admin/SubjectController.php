<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subject;

class SubjectController extends Controller
{
    public function index()
    {
        $subjects = Subject::orderBy('grade_level')
            ->orderBy('name')
            ->get()
            ->groupBy('grade_level');

        return view('admin.subjects.index', compact('subjects'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'code'        => 'required|string|max:20|unique:subjects,code',
            'description' => 'nullable|string',
            'grade_level' => 'required|string',
            'time_period' => 'required|in:30,60',
        ]);

        Subject::create([
            'name'        => $request->name,
            'code'        => strtoupper($request->code),
            'description' => $request->description,
            'grade_level' => $request->grade_level,
            'time_period' => $request->time_period,
            'is_active'   => true,
        ]);

        return redirect()->back()->with('success', 'Subject added successfully.');
    }

    public function update(Request $request, Subject $subject)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'code'        => 'required|string|max:20|unique:subjects,code,' . $subject->id,
            'description' => 'nullable|string',
            'grade_level' => 'required|string',
            'time_period' => 'required|in:30,60',
            'is_active'   => 'boolean',
        ]);

        $subject->update([
            'name'        => $request->name,
            'code'        => strtoupper($request->code),
            'description' => $request->description,
            'grade_level' => $request->grade_level,
            'time_period' => $request->time_period,
            'is_active'   => $request->boolean('is_active'),
        ]);

        return redirect()->back()->with('success', 'Subject updated successfully.');
    }

    public function destroy(Subject $subject)
    {
        $subject->delete();
        return redirect()->back()->with('success', 'Subject deleted.');
    }
}
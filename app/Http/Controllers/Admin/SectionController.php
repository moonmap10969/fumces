<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Section;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    public function index()
    {
        $sections = Section::withCount('enrollments')->get();
        return view('admin.sections.index', compact('sections'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:sections,name',
            'section_id' => 'required|unique:sections,section_id',
            'capacity' => 'required|integer|min:1',
            'year_level' => 'required',
            'shift' => 'required|in:morning,afternoon,whole day'
        ], [
            // Custom messages
            'section_id.unique' => "The Section Code ':input' is already assigned to an existing section.",
            'name.unique' => "The Section Name ':input' is already in use. Please choose a different name.",
        ]);

        Section::create($validated);
        return redirect()->route('admin.sections.index')->with('success', 'Section created!');
    }

 public function update(Request $request, $section_id)
{
    $section = Section::where('section_id', $section_id)->firstOrFail();

    $validated = $request->validate([
        'name' => 'required|string|max:255',
        // FIX: Tell Laravel to ignore the record where 'section_id' matches the current one
        'section_id' => 'required|unique:sections,section_id,' . $section->section_id . ',section_id',
        'capacity' => 'required|integer|min:1',
        'year_level' => 'required|string',
        'shift' => 'required|in:morning,afternoon,whole day'
    ]);

    $section->update($validated);
    return redirect()->route('admin.sections.index')->with('success', 'Section updated successfully!');
}

    // Fixed: Using section_id for deletion
    public function destroy($section_id)
    {
        $section = Section::where('section_id', $section_id)->firstOrFail();
        $section->delete();
        return redirect()->route('admin.sections.index')->with('success', 'Section deleted successfully!');
    }
}
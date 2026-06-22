<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\AcademicYear;

class AcademicYearController extends Controller
{
    public function index()
    {
        $years = AcademicYear::orderBy('year_range', 'desc')->get();
        return view('admin.academic_years.index', compact('years'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'year_range' => 'required|string|unique:academic_years,year_range',
        ]);

        AcademicYear::create([
            'year_range' => $request->year_range,
            'is_current' => false,
        ]);

        return redirect()->back()->with('success', 'Academic Year added successfully.');
    }

    public function setCurrent($id)
    {
        AcademicYear::query()->update(['is_current' => false]);
        $year = AcademicYear::findOrFail($id);
        $year->update(['is_current' => true]);

        return redirect()->back()->with('success', "Active year set to {$year->year_range}.");
    }
}
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\QuarterActivation;
use Illuminate\Http\Request;

class QuarterController extends Controller
{
    private array $quarters = [
        'q1_grade' => 'Quarter 1',
        'q2_grade' => 'Quarter 2',
        'q3_grade' => 'Quarter 3',
        'q4_grade' => 'Quarter 4',
    ];

    public function index()
    {
        $activeYear = AcademicYear::where('is_current', true)->first();

        if (!$activeYear) {
            return redirect()->route('admin.index')->with('error', 'No active academic year. Please set an active Academic Year first.');
        }

        // Ensure rows exist for all quarters (default inactive).
        foreach (array_keys($this->quarters) as $q) {
            QuarterActivation::firstOrCreate(
                ['academic_year_id' => $activeYear->id, 'quarter' => $q],
                ['is_active' => false]
            );
        }

        $activations = QuarterActivation::where('academic_year_id', $activeYear->id)
            ->get()
            ->keyBy('quarter');

        return view('admin.quarters.index', compact('activeYear', 'activations'));
    }

    public function update(Request $request)
    {
        $activeYear = AcademicYear::where('is_current', true)->first();

        if (!$activeYear) {
            return redirect()->route('admin.index')->with('error', 'No active academic year. Please set an active Academic Year first.');
        }

        $request->validate([
            'quarters' => 'nullable|array',
            'quarters.q1_grade' => 'nullable|boolean',
            'quarters.q2_grade' => 'nullable|boolean',
            'quarters.q3_grade' => 'nullable|boolean',
            'quarters.q4_grade' => 'nullable|boolean',
        ]);

        foreach (array_keys($this->quarters) as $q) {
            QuarterActivation::updateOrCreate(
                ['academic_year_id' => $activeYear->id, 'quarter' => $q],
                ['is_active' => (bool) ($request->input("quarters.$q") ?? false)]
            );
        }

        return redirect()->back()->with('success', 'Quarter activation updated successfully.');
    }
}


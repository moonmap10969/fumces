<?php

namespace App\Http\Controllers\Registrar;

use Illuminate\Http\Request;
use App\Models\Enrollment;
use App\Models\AcademicYear;
use App\Http\Controllers\Controller;

class DashboardController extends Controller
{

public function index(Request $request)
{
    $activeYear = AcademicYear::where('is_current', true)->first() ?? AcademicYear::latest()->first();
    $selectedYear = $request->get('academic_year', $activeYear->id);

    // Pull from the Admission model where the status is approved
    // This assumes your Admission model has 'status' and 'academic_year_id'
    $pendingEnrollments = \App\Models\Admission::where('status', 'approved')
        ->where('academic_year_id', $selectedYear)
        ->orderBy('updated_at', 'desc')
        ->take(6)
        ->get();

    // Stats (Adjusted to look at Admissions since Enrollments is empty)
    $stats = (object)[
        'total_enrollments' => \App\Models\Enrollment::where('academic_year_id', $selectedYear)->count(),
        'pending_requests'  => \App\Models\Admission::where('status', 'approved')->count(),
        'approved_count'    => \App\Models\Enrollment::where('status', 'enrolled')->count(),
    ];

    return view('registrar.index', compact('activeYear', 'pendingEnrollments', 'stats'));
}

public function approve($id)
{
    $enrollment = Enrollment::findOrFail($id);
    $enrollment->update(['status' => 'enrolled']);

    return back()->with('success', "Student {$enrollment->studentNumber} has been officially enrolled.");
}
}

<?php

namespace App\Http\Controllers\Admissions;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Admission;
use App\Models\AcademicYear;
use Illuminate\Support\Facades\Mail;
use App\Mail\StudentNumberGenerated;
use App\Mail\AdmissionRejectedMail;
use App\Models\Document;
use App\Mail\AdmissionApprovedMail;

class AdmissionsAdmissionController extends Controller
{
    public function index(Request $request)
    {
        // FIX: Filter by current academic year by default, allow switching
        $currentYear = AcademicYear::where('is_current', true)->first();
        $academicYears = AcademicYear::orderBy('year_range', 'desc')->get();
        $selectedYearId = $request->get('academic_year_id', $currentYear?->id);

        $query = Admission::query();

        if ($selectedYearId) {
            $query->where('academic_year_id', $selectedYearId);
        }

        // Server-side search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('studentFirstName', 'like', "%{$search}%")
                  ->orWhere('studentLastName', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Server-side grade filter
        if ($request->filled('grade')) {
            $query->where('year_level', $request->grade);
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $admissions = $query->latest()->paginate(15)->appends($request->all());

        $grades = Admission::where('academic_year_id', $selectedYearId)
            ->select('year_level')
            ->distinct()
            ->pluck('year_level');

        $totalPendingApprovals = Admission::where('academic_year_id', $selectedYearId)->where('status', 'pending')->count();
        $totalApproved         = Admission::where('academic_year_id', $selectedYearId)->where('status', 'approved')->count();
        $totalRejected         = Admission::where('academic_year_id', $selectedYearId)->where('status', 'rejected')->count();
        $totalStudentsRegistered = Admission::where('academic_year_id', $selectedYearId)->count();

        return view('admissions.admissions.index', compact(
            'admissions', 'grades', 'totalPendingApprovals',
            'totalApproved', 'totalRejected', 'totalStudentsRegistered',
            'academicYears', 'selectedYearId'
        ));
    }

    public function show(Admission $admission)
    {
        return view('admissions.admissions.show', compact('admission'));
    }

    public function approve(Request $request, Admission $admission)
    {
        // 1. Safety check: must be pending to approve
        if ($admission->status !== 'pending') {
            return back()->with('error', 'This application has already been processed.');
        }

        // 2. Get associated user
        $user = $admission->user;

        if (!$user) {
            return back()->with('error', 'Associated user account not found. Cannot approve.');
        }

        // 3. FIX: Only change role if user is currently a guest/unapproved role
        // Prevents accidentally demoting admin/teacher/registrar accounts
        $safeToChangeRole = in_array($user->role, ['student', null, '']);
        
        // 4. Generate student number: YYYY + zero-padded admission ID
        $studentNumber = date('Y') . str_pad($admission->id, 6, '0', STR_PAD_LEFT);

        // 5. Update admission
        $admission->update([
            'status'        => 'approved',
            'studentNumber' => $studentNumber,
        ]);

        // 6. Update user — only change role if safe
        $userUpdateData = [
            'is_approved' => true,
            'year_level'  => $admission->year_level,
        ];

        if ($safeToChangeRole) {
            $userUpdateData['role'] = 'student';
        }

        $user->update($userUpdateData);

        // 7. Send approval email
        try {
            if ($user->email) {
                Mail::to($user->email)->send(new AdmissionApprovedMail($studentNumber, $user));
            }
        } catch (\Exception $e) {
            \Log::warning('Approval email failed: ' . $e->getMessage());
        }

        return back()->with('success', "Application approved. Student ID {$studentNumber} has been generated.");
    }

    public function reject(Admission $admission)
    {
        // Safety check: must be pending to reject
        if ($admission->status !== 'pending') {
            return back()->with('error', 'This application has already been processed.');
        }

        $admission->update(['status' => 'rejected']);

        try {
            Mail::to($admission->email)->send(new AdmissionRejectedMail($admission));
        } catch (\Exception $e) {
            \Log::warning('Rejection email failed: ' . $e->getMessage());
        }

        return back()->with('success', 'Application rejected and notification email sent.');
    }

    public function create()
    {
        return view('admissions.admissions.create');
    }
}
<?php

namespace App\Http\Controllers\Registrar;

use App\Http\Controllers\Controller;
use App\Models\Tuition;
use App\Models\Admission;
use App\Models\FeeStructure;
use Illuminate\Http\Request;

class TuitionController extends Controller
{

public function index(Request $request)
{
    $search = $request->get('search');
    $gradeFilter = $request->get('grade_filter');
    $gradeOrder = ['kinder1', 'kinder2', 'grade1', 'grade2', 'grade3', 'grade4', 'grade5', 'grade6', 'grade7', 'grade8', 'grade9', 'grade10'];

    // 1. Fetch the active year
    $activeYear = \App\Models\AcademicYear::where('is_current', true)->first();
    if (!$activeYear) {
        return redirect()->back()->with('error', 'Please set an active Academic Year.');
    }

    // 2. Fetch students with active enrollment and tuition
    // We start from Admission to get full names, but filter strictly by current enrollment
    $students = Admission::with(['tuition' => function($q) use ($activeYear) {
            $q->where('academic_year_id', $activeYear->id);
        }])
        ->whereHas('enrollments', function($q) use ($activeYear) {
            $q->where('academic_year_id', $activeYear->id);
        })
        ->when($search, function($q) use ($search) {
            $q->where(function($query) use ($search) {
                $query->where('studentFirstName', 'like', "%{$search}%")
                      ->orWhere('studentLastName', 'like', "%{$search}%")
                      ->orWhere('studentNumber', 'like', "%{$search}%");
            });
        })
        ->when($gradeFilter, fn($q) => $q->where('year_level', $gradeFilter))
        ->orderBy('studentLastName')
        ->paginate(20)
        ->appends($request->all());

    // 3. Metadata for the view
    $feeStructures = \App\Models\FeeStructure::where('academic_year_id', $activeYear->id)->get()->keyBy('year_level');
    $feeHistory = \App\Models\FeeStructure::with('academicYear')->get()->groupBy('academic_year_id');

    return view('registrar.tuitions.index', compact('students', 'gradeOrder', 'feeStructures', 'activeYear', 'feeHistory'));
}

public function create()
{
    $activeYear = \App\Models\AcademicYear::where('is_current', true)->first();
    $gradeOrder = ['kinder1', 'kinder2', 'grade1', 'grade2', 'grade3', 'grade4', 'grade5', 'grade6', 'grade7', 'grade8', 'grade9', 'grade10'];
    $feeStructures = FeeStructure::all()->keyBy('year_level');

    $students = Admission::all()->mapWithKeys(function ($student) {
        return [$student->studentNumber => [
            'name' => $student->studentLastName . ', ' . $student->studentFirstName,
            'year_level' => $student->year_level,
        ]];
    });

    return view('registrar.tuitions.create', compact('gradeOrder', 'feeStructures', 'students', 'activeYear'));
}

public function store(Request $request)
{
    // 1. Fetch the Global Active Year
    $activeYear = \App\Models\AcademicYear::where('is_current', true)->first();
    if (!$activeYear) return back()->with('error', "No active Academic Year set by Admin.");

    $preset = FeeStructure::where('year_level', $request->year_level)->first();
    if (!$preset) return back()->with('error', "Rates for {$request->year_level} not found.");

    $affiliationDiscount = ($request->umc_affiliation === 'worker') ? 1.0 : (($request->umc_affiliation === 'member') ? 0.5 : 0);
    $siblingDiscount = ['none' => 0, '2nd' => 0.10, '3rd' => 0.20, '4th' => 0.30][$request->sibling_order] ?? 0;

    $finalDiscountRate = max($affiliationDiscount, $siblingDiscount);

    $baseTuition = (float) $preset->base_tuition;
    $miscFees = (float) $preset->total_misc;
    $netTuition = $baseTuition * (1 - $finalDiscountRate);
    $totalAmount = $netTuition + $miscFees;

    // 2. Added academic_year_id to the unique constraints
    Tuition::updateOrCreate(
        [
            'studentNumber' => $request->studentNumber,
            'academic_year_id' => $activeYear->id // Ensure one record per student per semester
        ],
        [
            'name' => $request->name,
            'year_level' => $request->year_level,
            'umc_affiliation' => $request->umc_affiliation, 
            'sibling_order' => $request->sibling_order,     
            'tuition_fee' => $netTuition,
            'misc_fees' => $preset->total_misc,
            'amount' => $totalAmount,
            'balance' => $totalAmount,
            'status' => 'finalized'
        ]
    );

    return back()->with('success', "Assessment finalized for {$activeYear->year_range} and synced.");
}

   public function updateFeeStructures(Request $request)
{
    $activeYear = \App\Models\AcademicYear::where('is_current', true)->first();
    if (!$activeYear) return back()->with('error', "No active Academic Year set.");

    // List of keys to sum up
    $miscKeys = ['reg_fee', 'learning_materials', 'medical_dental', 'testing_materials', 'id_fee', 'insurance', 'av_computer', 'handbook', 'athletes', 'red_cross', 'energy_fee', 'membership_fees', 'prisap_umesa', 'hgp_modules', 'lab_fees'];
    
    // 1. Calculate the totalMisc variable
    $totalMisc = 0;
    foreach ($miscKeys as $key) {
        $totalMisc += (float) ($request->input($key) ?? 0);
    }

    // 2. Save the Master Fee Structure
   $feeStructure = FeeStructure::updateOrCreate(
    ['year_level' => $request->year_level, 'academic_year_id' => $activeYear->id], 
    array_merge($request->all(), ['total_misc' => $totalMisc])
);

    // 3. Sync to students for the CURRENT year only
    $students = Admission::where('year_level', $request->year_level)
                         ->where('academic_year_id', $activeYear->id)
                         ->get();

    foreach ($students as $student) {
        Tuition::updateOrCreate(
            [
                'studentNumber' => $student->studentNumber,
                'academic_year_id' => $activeYear->id 
            ],
            [
                'name'        => $student->studentLastName . ', ' . $student->studentFirstName,
                'year_level'  => $student->year_level,
                'tuition_fee' => (float) $feeStructure->base_tuition,
                'misc_fees'   => (float) $totalMisc, // Variable is now defined
                'amount'      => (float) ($feeStructure->base_tuition + $totalMisc),
                'balance'     => (float) ($feeStructure->base_tuition + $totalMisc),
                'status'      => 'pending'
            ]
        );
    }

    return redirect()->route('registrar.tuitions.create')
        ->with('success', "Fees updated and synced for {$activeYear->year_range}.");
}
public function feeHistory()
{
    $activeYear = \App\Models\AcademicYear::where('is_current', true)->first();
    
    // Fetch all master fees grouped by the academic year they belong to
    $history = \App\Models\FeeStructure::with('academicYear')
        ->get()
        ->groupBy('academic_year_id');

    return view('registrar.fees.history', compact('history', 'activeYear'));
}

    public function syncAllAssessments()
{
    $activeYear = \App\Models\AcademicYear::where('is_current', true)->first();
    $feeStructures = FeeStructure::where('academic_year_id', $activeYear->id)->get()->keyBy('year_level');
    
    // CHANGE: Only sync students who exist in the enrollments table
    $enrolledStudents = \App\Models\Enrollment::where('academic_year_id', $activeYear->id)->get();
    $count = 0;

    foreach ($enrolledStudents as $enrollment) {
        $preset = $feeStructures->get($enrollment->year_level);
        $admission = $enrollment->admission;
        
        if ($preset && $admission) {
            Tuition::updateOrCreate(
                [
                    'studentNumber' => $enrollment->studentNumber,
                    'academic_year_id' => $activeYear->id
                ],
                [
                    'name'        => $admission->studentLastName . ', ' . $admission->studentFirstName,
                    'year_level'  => $enrollment->year_level,
                    'tuition_fee' => (float) $preset->base_tuition,
                    'misc_fees'   => (float) $preset->total_misc,
                    'amount'      => (float) ($preset->base_tuition + $preset->total_misc),
                    'balance'     => (float) ($preset->base_tuition + $preset->total_misc),
                    'status'      => 'pending'
                ]
            );
            $count++;
        }
    }

    return back()->with('success', "Successfully synced $count enrolled students.");
}
}
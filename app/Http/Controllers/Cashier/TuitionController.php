<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use App\Models\Tuition;
use App\Models\FeeStructure;
use App\Models\Admission;
use Illuminate\Http\Request;

class TuitionController extends Controller
{
public function index(Request $request)
{
    $search = $request->get('search');
    $gradeFilter = $request->get('grade_filter');

    // Eager load tuition + payments
    $admissions = Admission::with(['tuition.payments' => function($q) {
        $q->orderBy('created_at', 'desc');
    }])
    ->when($search, function($q) use ($search) {
        $q->where('studentFirstName', 'like', "%{$search}%")
          ->orWhere('studentLastName', 'like', "%{$search}%")
          ->orWhere('studentNumber', 'like', "%{$search}%");
    })
    ->when($gradeFilter, fn($q) => $q->where('year_level', $gradeFilter))
    ->orderBy('studentLastName')
    ->paginate(20)
    ->appends($request->all());

   $admissions->getCollection()->transform(function($student) {
        if ($student->tuition) {
            // --- ADD THIS LINE ---
            // This forces the database to recalculate using your new fix 
            // every time the page is loaded.
            $student->tuition->recalcTotals(); 

            if ($student->tuition->payments) {
                $student->tuition->payments = $student->tuition->payments->map(function($p) {
                    return [
                        'id' => $p->id,
                        'amount' => $p->amount,
                        'payment_method' => $p->payment_method,
                        'created_at' => $p->created_at,
                        'receipt_path' => $p->receipt_path ? route('cashier.payments.show', $p->id) : null
                    ];
                });
            }
        }
        return $student;
    });

    $academicYears = \App\Models\AcademicYear::pluck('year_range', 'id');
    return view('cashier.tuitions.index', compact('admissions', 'academicYears'));
}

    public function store(Request $request)
{
    $preset = FeeStructure::where('year_level', $request->year_level)->first();
    if (!$preset) return back()->with('error', "Rates for {$request->year_level} not found.");

    // 1. Calculate the highest discount rate
    $affiliationDiscount = ($request->umc_affiliation === 'worker') ? 1.0 : (($request->umc_affiliation === 'member') ? 0.5 : 0);
    $siblingDiscount = ['none' => 0, '2nd' => 0.10, '3rd' => 0.20, '4th' => 0.30][$request->sibling_order] ?? 0;
    $grade7Incentive = ($request->year_level === 'grade7') ? 0.30 : 0;
    $finalDiscountRate = max($affiliationDiscount, $siblingDiscount, $grade7Incentive);

    // 2. Explicitly cast to float to ensure addition works
    $baseTuition = (float) $preset->base_tuition;
    $miscFees = (float) $preset->total_misc;

    // 3. Perform the calculation
    $netTuition = $baseTuition * (1 - $finalDiscountRate);
    $totalAmount = $netTuition + $miscFees;

    Tuition::create([
        'studentNumber' => $request->studentNumber,
        'name'          => $request->name,
        'year_level'    => $request->year_level,
        'tuition_fee'   => $netTuition,
        'misc_fees'     => $miscFees,
        'amount'        => $totalAmount,
        'balance'       => $totalAmount,
        'status'        => 'pending'
    ]);

    return back()->with('success', 'Assessment finalized including miscellaneous fees.');
}
    public function setPayment(Request $request, $id)
{
    $tuition = Tuition::findOrFail($id);
    
    // 1. Update the tuition record with schedule changes if necessary
    $data = $this->calculateTuition($request);
    $tuition->update($data);

    // 2. If an initial payment was provided, record it as a Payment record
    if ($request->initial_payment > 0) {
        $tuition->payments()->create([
            'studentNumber'    => $tuition->studentNumber,
            'amount'           => $request->initial_payment,
            'payment_method'   => $request->payment_method ?? 'cash',
            'status'           => 'completed', // Cash is usually instant
            'approval_status'  => 'approved',
            'origin'           => 'cashier',
            'academic_year_id' => $tuition->academic_year_id,
        ]);
    }

    // 3. IMPORTANT: Trigger the recalculation to sync the balance
    $tuition->recalcTotals();

    return back()->with('success', 'Payment processed and balance updated.');
}
    private function calculateTuition(Request $request)
{
    // Fetch the existing assessment made by the Registrar
    $existingTuition = Tuition::where('studentNumber', $request->studentNumber)->first();
    
    // Use Registrar's values if they exist, otherwise fallback to defaults
    $baseTuition = $existingTuition ? (float)$existingTuition->tuition_fee : 7188;
    $miscFees = $existingTuition ? (float)$existingTuition->misc_fees : 8550;

    // Apply Schedule-based incentives (Full 10%, Quarterly 5%)
    $scheduleDiscount = ($request->payment_schedule === 'full') ? 0.10 : (($request->payment_schedule === 'quarterly') ? 0.05 : 0);
    $tuitionAfterSchedule = $baseTuition * (1 - $scheduleDiscount);
    
    $grandTotal = $tuitionAfterSchedule + $miscFees;
    $initialPayment = (float)($request->initial_payment ?? 0);

    return [
        'studentNumber'    => $request->studentNumber,
        'name'             => $request->name,
        'tuition_fee'      => $tuitionAfterSchedule,
        'misc_fees'        => $miscFees,
        'amount'           => $grandTotal,
        'balance'          => max($grandTotal - $initialPayment, 0),
        'payment_method'   => $request->payment_method ?? 'cash',
        'status'           => $initialPayment >= $grandTotal ? 'approved' : 'pending',
        'approval_status'  => $initialPayment > 0 ? 'approved' : 'pending',
        'payment_schedule' => $request->payment_schedule,
    ];
}
}
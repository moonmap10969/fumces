<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Admission;
use App\Models\Tuition;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TuitionController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $admission = Admission::where('user_id', $user->id)->firstOrFail();
        $studentNumber = $admission->studentNumber;

        $studentTuitions = Tuition::with(['academicYear', 'enrollment'])
            ->where('studentNumber', $studentNumber)
            ->get();

        if ($studentTuitions->isEmpty()) {
            return view('student.tuition.index', [
                'tuitions' => collect(), 
                'totalAssessment' => 0, 
                'totalPaid' => 0, 
                'remainingBalance' => 0, 
                'studentTuitions' => collect(), 
                'activeTuition' => null, 
                'selectedYearId' => null, 
                'miscFees' => [], 
                'baseTuition' => 0
            ]);
        }

        $selectedYearId = $request->get('academic_year_id') ?? $studentTuitions->last()->academic_year_id;
        $activeTuition = $studentTuitions->where('academic_year_id', $selectedYearId)->first();

        // THE FIX: Pull the grade level directly from the specific tuition record, NOT the admission record
        $gradeFromDb = $activeTuition->year_level ?? '';
        $studentLevel = str_replace(' ', '', strtolower($gradeFromDb));

        $feeRecord = DB::table('fee_structures')
            ->where('academic_year_id', $activeTuition->academic_year_id)
            ->where('year_level', $studentLevel) 
            ->first();

        $baseTuition = $activeTuition->tuition_fee;
        $totalMisc = $feeRecord ? $feeRecord->total_misc : 0;
        $totalAssessment = $baseTuition + $totalMisc;

        // Map fees and filter out any that are 0 to keep the modal clean
        $miscFees = [];
        if ($feeRecord) {
            $allMiscFees = [
                'Registration Fee' => $feeRecord->reg_fee,
                'Learning Materials' => $feeRecord->learning_materials,
                'Medical & Dental' => $feeRecord->medical_dental,
                'Testing Materials' => $feeRecord->testing_materials,
                'ID Fee' => $feeRecord->id_fee,
                'Insurance' => $feeRecord->insurance,
                'AV / Computer' => $feeRecord->av_computer,
                'Handbook' => $feeRecord->handbook,
                'Athletes' => $feeRecord->athletes,
                'Red Cross' => $feeRecord->red_cross,
                'Energy Fee' => $feeRecord->energy_fee,
                'Membership Fees' => $feeRecord->membership_fees,
                'PRISAP / UMESA' => $feeRecord->prisap_umesa,
                'HGP Modules' => $feeRecord->hgp_modules,
                'Lab Fees' => $feeRecord->lab_fees,
            ];

            $miscFees = array_filter($allMiscFees, function($amount) {
                return (float) $amount > 0;
            });
        }

        $tuitions = Payment::where('tuition_id', $activeTuition->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10)
            ->appends($request->all());

        $totalPaid = Payment::where('tuition_id', $activeTuition->id)
            ->whereIn('status', ['completed', 'approved'])
            ->sum('amount');
            
        $remainingBalance = max(0, $totalAssessment - $totalPaid);

        return view('student.tuition.index', compact(
            'tuitions', 
            'totalAssessment', 
            'totalPaid', 
            'remainingBalance', 
            'studentTuitions', 
            'activeTuition', 
            'selectedYearId', 
            'miscFees', 
            'baseTuition'
        ));
    }

    public function store(Request $request)
    {
        $request->validate([
            'tuition_id' => 'required|exists:tuitions,id',
            'payment_method' => 'required|in:GCash,Bank Transfer',
            'amount' => 'required|numeric|min:1',
            'reference_number' => 'required|string|max:50',
            'payment_proof' => 'required|file|mimes:jpg,jpeg,png|max:2048',
        ]);

        $tuition = Tuition::with('enrollment')->findOrFail($request->tuition_id);
        
        // THE FIX: Pull the grade level from the tuition record here as well
        $gradeFromDb = $tuition->year_level ?? '';
        $studentLevel = str_replace(' ', '', strtolower($gradeFromDb));

        $feeRecord = DB::table('fee_structures')
            ->where('academic_year_id', $tuition->academic_year_id)
            ->where('year_level', $studentLevel)
            ->first();
            
        $baseTuition = $tuition->tuition_fee;
        $totalMisc = $feeRecord ? $feeRecord->total_misc : 0;
        $totalAssessment = $baseTuition + $totalMisc;

        $totalPaid = Payment::where('tuition_id', $tuition->id)
            ->whereIn('status', ['completed', 'approved'])
            ->sum('amount');
            
        $remainingBalance = max(0, $totalAssessment - $totalPaid);

        if ($request->amount > $remainingBalance) {
            return redirect()->back()
                ->withErrors(['amount' => "Payment exceeds remaining balance of ₱" . number_format($remainingBalance, 2)])
                ->withInput();
        }

        $inputRef = $request->reference_number;
        $formattedRef = str_starts_with(strtoupper($inputRef), 'REF-') ? strtoupper($inputRef) : 'REF-' . strtoupper($inputRef);
        
        if (Payment::where('reference_number', $formattedRef)->exists()) {
            return redirect()->back()
                ->withErrors(['reference_number' => 'This reference number has already been used.'])
                ->withInput();
        }

        $file = $request->file('payment_proof');
        $encryptedContent = \App\Helpers\AESHelper::encrypt(file_get_contents($file->getRealPath()));
        
        $filename = time() . '_' . Str::random(10) . '.dat';
        \Illuminate\Support\Facades\Storage::disk('local')->put('receipts/' . $filename, $encryptedContent);

        Payment::create([
            'enrollment_id'    => $tuition->enrollment_id,
            'tuition_id'       => $tuition->id,
            'academic_year_id' => $tuition->academic_year_id,
            'studentNumber'    => $tuition->studentNumber,
            'amount'           => $request->amount,
            'payment_method'   => $request->payment_method,
            'reference_number' => $formattedRef,
            'receipt_path'     => $filename, 
            'status'           => 'pending',
            'origin'           => 'student',
            'approval_status'  => 'pending',
        ]);

        return redirect()->back()->with('success', 'Payment submitted successfully!');
    }
}
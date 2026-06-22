<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Models\Payment;

class PaymentController extends Controller
{
    public function submit(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string', // updated field name
            'reference_number' => 'nullable|string|max:255', // reference can be optional
            'payment_proof' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        $student = Auth::user();

        $payment = new Payment();
        $payment->studentNumber = $student->studentNumber; // updated column
        $payment->name = $student->name; // updated column
        $payment->payment_method = $request->payment_method;
        $payment->amount = $request->amount;
        $payment->reference_number = $request->reference_number ?? null;
        $payment->status = 'pending';

        if ($request->hasFile('payment_proof')) {
            $payment->receipt_path = $request->file('payment_proof')->store('payment_proofs', 'public'); // updated column
        }

        $payment->save();

        return redirect()->back()->with('success', 'Payment submitted successfully!');
    }
}

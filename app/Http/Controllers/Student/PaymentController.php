<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class PaymentController extends Controller
{
    public function submit(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'method' => 'required|string',
            'reference_number' => 'required|string|max:255',
            'payment_proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:2048', // 2MB max
        ]);

        $student = Auth::user();

        // Save file
        $proofPath = $request->file('payment_proof')->store('payment_proofs', 'public');

        // Save payment record (example)
        $student->payments()->create([
            'amount' => $request->amount,
            'method' => $request->method,
            'reference_number' => $request->reference_number,
            'proof_path' => $proofPath,
            'status' => 'pending', // or approved later
        ]);

        return redirect()->back()->with('success', 'Payment submitted successfully!');
    }
}
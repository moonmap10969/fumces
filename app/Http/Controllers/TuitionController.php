<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
   // In App\Http\Controllers\PaymentController.php

public function store(Request $request) {
    $request->validate([
        'reference_number' => 'required|string',
        'amount'           => 'required|numeric',
        'receipt'          => 'required|image|mimes:jpg,png,jpeg|max:2048',
    ]);

    $path = $request->file('receipt')->store('receipts', 'public');

    \App\Models\Payment::create([
        'user_id'          => Auth::id(), // Use the Auth facade to avoid the 'undefined' warning
        'student_number'   => $request->student_number,
        'reference_number' => $request->reference_number,
        'description'      => $request->description,
        'amount'           => $request->amount,
        'receipt_path'     => $path,
    ]);

    return redirect()->back()->with('success', 'Payment submitted for verification!');
}
}

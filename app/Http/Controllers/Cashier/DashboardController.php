<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        // 1. Total Payments: Sum of all approved/completed payments for the current year
        $totalPayments = Payment::whereIn('approval_status', ['approved', 'completed'])
            ->whereYear('created_at', Carbon::now()->year)
            ->sum('amount');

        // 2. Total Payment Requests: Total count of submissions (usually from 'student' origin)
        $totalPaymentRequests = Payment::where('origin', 'student')->count();

        // 3. Pending Verification: Count where approval_status is not set or is pending
        $pendingVerification = Payment::where('origin', 'student')
            ->whereNotIn('approval_status', ['approved', 'rejected'])
            ->count();

        // 4. Verified Today: Count of payments approved within the current date
        $verifiedToday = Payment::whereIn('approval_status', ['approved', 'completed'])
            ->whereDate('updated_at', Carbon::today())
            ->count();

        // 5. Latest Payments: Fetch the most recent transactions for the table
        $latestPayments = Payment::with(['admission', 'enrollment.admission'])
            ->latest()
            ->take(5)
            ->get();

        return view('cashier.index', compact(
            'totalPayments',
            'totalPaymentRequests',
            'pendingVerification',
            'verifiedToday',
            'latestPayments'
        ));
    }
}
<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Helpers\AESHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Tuition;
use App\Models\Payment;
use App\Models\Enrollment;
use App\Models\AcademicYear;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    private function paymongoHttp(): \Illuminate\Http\Client\PendingRequest
    {
        $http = Http::withBasicAuth(config('services.paymongo.secret'), '');

        if (app()->environment('local')) {
            $http = $http->withOptions(['verify' => false]);
        }

        return $http;
    }

    private function isPaymongoSessionPaid(array $data): bool
    {
        $topStatus = strtolower((string) data_get($data, 'attributes.status', ''));
        if (in_array($topStatus, ['paid', 'succeeded', 'completed'], true)) {
            return true;
        }

        foreach ((array) data_get($data, 'attributes.payments', []) as $payment) {
            $status = strtolower((string) data_get($payment, 'attributes.status', ''));
            if (in_array($status, ['paid', 'succeeded', 'completed'], true)) {
                return true;
            }
        }

        $intentStatus = strtolower((string) data_get($data, 'attributes.payment_intent.data.attributes.status', ''));
        return in_array($intentStatus, ['paid', 'succeeded', 'completed'], true);
    }

    private function createCashierPayMongoPayment(string $checkoutId, array $info, string $remarks): ?Payment
    {
        $ref = 'PM-CHK-' . $checkoutId;

        if ($existing = Payment::where('reference_number', $ref)->first()) {
            return $existing;
        }

        $payment = Payment::create([
            'enrollment_id'    => $info['enrollment_id'] ?? null,
            'tuition_id'       => $info['tuition_id'],
            'academic_year_id' => $info['academic_year_id'] ?? null,
            'studentNumber'    => $info['studentNumber'] ?? null,
            'amount'           => $info['amount'],
            'payment_method'   => $info['payment_method'] ?? 'PayMongo QR PH (Cashier)',
            'reference_number' => $ref,
            'receipt_path'     => 'PAYMONGO',
            'status'           => 'completed',
            'approval_status'  => 'approved',
            'origin'           => 'cashier',
            'remarks'          => $remarks,
        ]);

        Cache::forget("paymongo_checkout:{$checkoutId}");

        if (!empty($info['tuition_id'])) {
            $tuition = Tuition::find($info['tuition_id']);
            if ($tuition) {
                $tuition->recalcTotals();
            }
        }

        return $payment;
    }

    // =========================================================================
    // INDEX
    // =========================================================================
    public function index(Request $request)
    {
        $baseQuery = Payment::with([
            'enrollment.admission',
            'admission',
            'tuition.academicYear',
        ]);

        if ($request->filled('academic_year_id')) {
            $baseQuery->where('academic_year_id', $request->academic_year_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $baseQuery->where(function ($q) use ($search) {
                $q->where('studentNumber', 'like', "%{$search}%")
                  ->orWhere('reference_number', 'like', "%{$search}%");
            });
        }

        $payments = $baseQuery->get();

        $walkInPayments = (clone $baseQuery)
            ->where('origin', 'cashier')
            ->latest()
            ->paginate(10, ['*'], 'walkin_page');

        $studentSubmissions = (clone $baseQuery)
            ->where('origin', 'student')
            ->latest()
            ->paginate(10, ['*'], 'student_page');

        $academicYears = AcademicYear::pluck('year_range', 'id');

        return view('cashier.payments.index', compact(
            'walkInPayments',
            'studentSubmissions',
            'payments',
            'academicYears'
        ));
    }

    // =========================================================================
    // RECONCILE ALL
    // =========================================================================
    public function reconcileAll(Request $request)
    {
        $tuitionIds = Tuition::pluck('id');
        $updated = 0;
        foreach ($tuitionIds as $id) {
            $t = Tuition::find($id);
            if ($t) { $t->recalcTotals(); $updated++; }
        }
        return redirect()->back()->with('success', "Reconciled {$updated} tuition records.");
    }

    // =========================================================================
    // CREATE  (show form)
    // =========================================================================
    public function create()
    {
        $students = Enrollment::with(['tuition.academicYear', 'admission'])
            ->whereHas('tuition')
            ->get()
            ->map(function ($e) {
                return [
                    'enrollment_id'    => $e->id,
                    'tuition_id'       => $e->tuition->id,
                    'academic_year_id' => $e->tuition->academic_year_id,
                    'studentNumber'    => $e->studentNumber,
                    'balance'          => $e->tuition->balance,
                    'studentFirstName' => $e->admission->studentFirstName ?? 'N/A',
                    'studentLastName'  => $e->admission->studentLastName  ?? '',
                    'academic_year'    => $e->tuition->academicYear->year_range ?? 'N/A',
                ];
            })
            ->keyBy('studentNumber');

        return view('cashier.payments.create', compact('students'));
    }

// =========================================================================
// STORE  (cash walk-in payment) — cashier enters OR number manually
// =========================================================================
public function store(Request $request)
{
    $request->validate([
        'tuition_id'     => 'required|exists:tuitions,id',
        'amount'         => 'required|numeric|min:0.01',
        'payment_method' => 'required|in:cash',
        'or_number'      => 'required|string|max:100',
    ]);

    // Prevent duplicate OR numbers
    $orNumber = strtoupper(trim($request->or_number));
    if (Payment::where('reference_number', $orNumber)->exists()) {
        return back()->with('error', "OR Number {$orNumber} has already been used.");
    }

    $tuition = Tuition::findOrFail($request->tuition_id);

    $totalPaid        = $tuition->payments()->whereIn('status', ['completed', 'approved'])->sum('amount');
    $remainingBalance = max(0, $tuition->amount - $totalPaid);

    if ((float)$request->amount > $remainingBalance) {
        return back()->with('error', 'Payment amount exceeds the remaining balance of ₱' . number_format($remainingBalance, 2));
    }

    $academicYearId = $request->academic_year_id
                   ?? $tuition->academic_year_id
                   ?? AcademicYear::where('is_current', 1)->value('id');

    $payment = $tuition->payments()->create([
        'enrollment_id'    => $tuition->enrollment_id,
        'tuition_id'       => $tuition->id,
        'studentNumber'    => $tuition->studentNumber,
        'amount'           => min((float)$request->amount, (float)$tuition->balance),
        'payment_method'   => 'cash',
        'reference_number' => $orNumber,
        'origin'           => 'cashier',
        'status'           => 'completed',
        'approval_status'  => 'approved',
        'academic_year_id' => $academicYearId,
        'description'      => $request->description,
        'receipt_path'     => 'CASH_PAYMENT',
    ]);

    $tuition->recalcTotals();

    return back()->with('success', "Payment recorded. OR #: {$orNumber}");
}

    // =========================================================================
    // CREATE QR PH CHECKOUT  (cashier collects via PayMongo QR PH)
    // Creates a PayMongo checkout session with QR PH only.
    // The cashier opens the checkout URL on a screen facing the student.
    // Once the student scans and pays, the webhook auto-creates the Payment record
    // on the *student side* (origin = 'cashier') so it shows in their history.
    // =========================================================================
    public function createQrPhCheckout(Request $request)
{
    $request->validate([
        'tuition_id' => 'required|exists:tuitions,id',
        'amount'     => 'required|numeric|min:1',
    ]);

    $tuition = Tuition::with('academicYear')->findOrFail($request->tuition_id);

    $alreadyPaid = Payment::where('tuition_id', $tuition->id)
        ->whereIn('status', ['completed', 'approved'])
        ->sum('amount');

    // ← FIXED: was using misc_total which doesn't exist
   $assessment = (float) ($tuition->amount
    ?: ((float)($tuition->tuition_fee ?? 0) + (float)($tuition->misc_fees ?? 0)));
    $remaining  = max(0, $assessment - $alreadyPaid);
    $amount     = min((float) $request->amount, $remaining);

    if ($amount <= 0) {
        return back()->with('error', 'No remaining balance for this student.');
    }

    $host       = $request->getSchemeAndHttpHost();
    $successUrl = $host . route('cashier.payments.qrph.success', [], false);
    $cancelUrl  = $host . route('cashier.payments.create', [], false);

    $payload = [
        'data' => [
            'attributes' => [
                'cancel_url'           => $cancelUrl,
                'success_url'          => $successUrl,
                'line_items'           => [[
                    'amount'   => (int) round($amount * 100),
                    'currency' => 'PHP',
                    'name'     => 'Tuition Payment (QR PH)',
                    'quantity' => 1,
                ]],
                'payment_method_types' => ['qrph'],
                'description'          => 'Tuition – ' . $tuition->studentNumber,
                'metadata'             => [
                    'tuition_id'           => (string) $tuition->id,
                    'enrollment_id'        => (string) ($tuition->enrollment_id ?? ''),
                    'academic_year_id'     => (string) ($tuition->academic_year_id ?? ''),
                    'studentNumber'        => (string) $tuition->studentNumber,
                    'amount'               => (string) $amount,
                    'payment_method_label' => 'QR PH (Cashier)',
                    'origin'               => 'cashier',
                ],
            ],
        ],
    ];

    $response = $this->paymongoHttp()->post(config('services.paymongo.base') . '/checkout_sessions', $payload);

    if (!$response->successful()) {
        Log::error('[PayMongo Cashier] Checkout failed', ['body' => $response->body()]);
        return back()->with('error', 'Could not create QR PH session. ' . $response->body());
    }

    $checkout   = $response->json('data');
    $checkoutId = $checkout['id'] ?? null;
    $url        = $checkout['attributes']['checkout_url'] ?? null;

    if (!$checkoutId || !$url) {
        return back()->with('error', 'PayMongo did not return a checkout URL.');
    }

    Cache::put("paymongo_checkout:{$checkoutId}", [
        'tuition_id'       => $tuition->id,
        'enrollment_id'    => $tuition->enrollment_id,
        'academic_year_id' => $tuition->academic_year_id,
        'studentNumber'    => $tuition->studentNumber,
        'amount'           => $amount,
        'payment_method'   => 'PayMongo QR PH (Cashier)',
        'origin'           => 'cashier',
    ], now()->addDay());
    $request->session()->put('paymongo_checkout_id', $checkoutId);

    return redirect()->away($url);
}
    // =========================================================================
    // QR PH SUCCESS  (PayMongo redirects cashier browser here after payment)
    // =========================================================================
    public function qrPhSuccess(Request $request)
    {
        $checkoutId = (string) ($request->query('checkout_session_id')
            ?? $request->session()->pull('paymongo_checkout_id', ''));

        if ($checkoutId === '') {
            return redirect()->route('cashier.payments.index')
                ->with('error', 'QR PH payment returned without a checkout session. Please verify the payment in PayMongo and try again.');
        }

        $res = $this->paymongoHttp()->get(config('services.paymongo.base') . '/checkout_sessions/' . $checkoutId);
        if (!$res->successful()) {
            Log::warning('[PayMongo Cashier] Success return could not verify checkout session', [
                'checkout_id' => $checkoutId,
                'body' => $res->body(),
            ]);

            return redirect()->route('cashier.payments.index')
                ->with('error', 'QR PH payment returned, but verification with PayMongo failed. Please try again in a moment.');
        }

        $data = $res->json('data');

        if (!$this->isPaymongoSessionPaid($data)) {
            $status = strtolower((string) data_get($data, 'attributes.status', 'unknown'));

            return redirect()->route('cashier.payments.index')
                ->with('error', "QR PH payment is not confirmed yet (status: {$status}).");
        }

        $ref = 'PM-CHK-' . $checkoutId;
        $existing = Payment::where('reference_number', $ref)->first();

        if ($existing) {
            if ($existing->status !== 'completed' || $existing->approval_status !== 'approved') {
                $existing->update([
                    'status' => 'completed',
                    'approval_status' => 'approved',
                    'origin' => 'cashier',
                    'remarks' => 'Confirmed via cashier QR PH success return',
                ]);

                if ($existing->tuition_id) {
                    $tuition = Tuition::find($existing->tuition_id);
                    if ($tuition) {
                        $tuition->recalcTotals();
                    }
                }
            }
        } else {
            $meta = data_get($data, 'attributes.metadata', []);
            $cached = Cache::get("paymongo_checkout:{$checkoutId}");

            $info = $cached ?? [
                'tuition_id'       => (int) ($meta['tuition_id'] ?? 0),
                'enrollment_id'    => $meta['enrollment_id'] ?? null,
                'academic_year_id' => $meta['academic_year_id'] ?? null,
                'studentNumber'    => $meta['studentNumber'] ?? null,
                'amount'           => (float) ($meta['amount'] ?? 0),
                'payment_method'   => 'PayMongo ' . ($meta['payment_method_label'] ?? 'QR PH (Cashier)'),
            ];

            if (!$info['tuition_id'] || !$info['amount']) {
                Log::warning('[PayMongo Cashier] Success return missing tuition metadata', [
                    'checkout_id' => $checkoutId,
                    'metadata' => $meta,
                ]);

                return redirect()->route('cashier.payments.index')
                    ->with('error', 'QR PH payment was confirmed, but the app could not map it to a tuition record.');
            }

            $this->createCashierPayMongoPayment($checkoutId, $info, 'Confirmed via cashier QR PH success return');
        }

        return redirect()->route('cashier.payments.index')
            ->with('success', 'QR PH payment confirmed and recorded. The student\'s balance has been updated.');
    }

    // =========================================================================
    // APPROVE ONLINE SUBMISSION — Fixed to use recalcTotals()
    // =========================================================================
    public function approveOnline($id)
{
    $payment = Payment::findOrFail($id);

    if ($payment->status === 'completed' && $payment->approval_status === 'approved') {
        return redirect()->back()->with('error', 'This payment is already approved.');
    }

    $payment->update([
        'status'          => 'completed',
        'approval_status' => 'approved',
        'remarks'         => 'Approved by cashier on ' . now()->format('M d, Y h:i A'),
    ]);

    $tuition = Tuition::find($payment->tuition_id);
    if ($tuition) $tuition->recalcTotals();

    return redirect()->back()->with('success', 'Payment approved and student balance updated.');
}

    // =========================================================================
    // REJECT
    // =========================================================================
    public function reject(Request $request, $id)
    {
        $request->validate(['remarks' => 'required|string|max:255']);

        Payment::findOrFail($id)->update([
            'status'          => 'rejected',
            'remarks'         => $request->remarks,
            'approval_status' => 'rejected',
        ]);

        return redirect()->back()->with('success', 'Payment rejected with remarks.');
    }

    // =========================================================================
    // SHOW RECEIPT
    // =========================================================================
    public function show($id)
    {
        $payment = Payment::findOrFail($id);
        $student = \App\Models\Admission::where('studentNumber', $payment->studentNumber)->first();
        $name    = $student ? "{$student->studentFirstName} {$student->studentLastName}" : 'Unknown Student';

        // ── Cash walk-in receipt ─────────────────────────────────────────────
        if ($payment->receipt_path === 'CASH_PAYMENT' || $payment->payment_method === 'cash') {
            return response($this->buildCashReceipt($payment, $name), 200)
                ->header('Content-Type', 'text/html');
        }

        // ── PayMongo (QR PH / GCash / Card) receipt ─────────────────────────
        if (
            $payment->receipt_path === 'PAYMONGO'
            || stripos((string)$payment->payment_method, 'paymongo') !== false
        ) {
            return response($this->buildPayMongoReceipt($payment, $name), 200)
                ->header('Content-Type', 'text/html');
        }

        // ── Encrypted file receipt ───────────────────────────────────────────
        $dbPath  = $payment->receipt_path;
        $fullPath = str_contains((string)$dbPath, 'receipts/') ? $dbPath : "receipts/{$dbPath}";

        if (!Storage::disk('local')->exists($fullPath)) {
            abort(404, 'Receipt file not found.');
        }

        $decrypted   = AESHelper::decrypt(Storage::disk('local')->get($fullPath));
        $contentType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($decrypted);

        return response($decrypted, 200)->header('Content-Type', $contentType);
    }
    // Add to PaymentController
public function lookupStudent(Request $request)
{
    $request->validate(['studentNumber' => 'required|string|max:100']);

    $studentNumber = trim((string) $request->studentNumber);

    $tuition = Tuition::with(['academicYear', 'admission', 'enrollment'])
        ->where('studentNumber', $studentNumber)
        ->orderByDesc('academic_year_id')
        ->orderByDesc('id')
        ->first();

    if (!$tuition) {
        return response()->json(['error' => 'Student not found or has no tuition record.'], 404);
    }

    $enrollment = $tuition->enrollment
        ?? Enrollment::with('admission')
            ->where('studentNumber', $studentNumber)
            ->when($tuition->academic_year_id, fn ($q) => $q->where('academic_year_id', $tuition->academic_year_id))
            ->latest('id')
            ->first()
        ?? Enrollment::with('admission')
            ->where('studentNumber', $studentNumber)
            ->latest('id')
            ->first();

    $admission = $tuition->admission ?? $enrollment?->admission;

    // Use stored amount field (tuition_fee + misc_fees combined)
    $assessment  = (float) ($tuition->amount
        ?: ((float)($tuition->tuition_fee ?? 0) + (float)($tuition->misc_fees ?? 0)));

    $alreadyPaid = Payment::where('tuition_id', $tuition->id)
                       ->whereIn('status', ['completed', 'approved'])
                       ->sum('amount');

    $remaining   = max(0, $assessment - $alreadyPaid);

    return response()->json([
        'enrollment_id'    => $enrollment?->id,
        'tuition_id'       => $tuition->id,
        'academic_year_id' => $tuition->academic_year_id,
        'academic_year'    => $tuition->academicYear->year_range ?? 'N/A',
        'studentNumber'    => $studentNumber,
        'name'             => trim(($admission->studentFirstName ?? '') . ' ' . ($admission->studentLastName ?? '')),
        'total_assessment' => $assessment,
        'paid_amount'      => $alreadyPaid,
        'balance'          => $remaining,
    ]);
}

    // =========================================================================
    // DOWNLOAD
    // =========================================================================
    public function download($id)
    {
        $payment = Payment::findOrFail($id);

        if (!$payment->receipt_path || !Storage::disk('local')->exists($payment->receipt_path)) {
            abort(404);
        }

        $decrypted = AESHelper::decrypt(Storage::disk('local')->get($payment->receipt_path));

        return response($decrypted, 200)
            ->header('Content-Type', 'application/octet-stream')
            ->header('Content-Disposition', "attachment; filename=\"receipt_{$payment->reference_number}.jpg\"");
    }

    // =========================================================================
    // HELPERS — Receipt HTML builders
    // =========================================================================

    private function buildCashReceipt(Payment $payment, string $name): string
    {
        $or     = htmlspecialchars($payment->reference_number);
        $amount = number_format($payment->amount, 2);
        $date   = $payment->created_at->format('M d, Y h:i A');

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"><title>Official Receipt</title>
        <style>
            body { font-family: 'Courier New', monospace; max-width: 380px; margin: 40px auto; padding: 24px; border: 2px solid #e2e8f0; border-radius: 16px; }
            h2 { color: #15803d; text-align: center; margin-bottom: 4px; }
            .sub { text-align: center; font-size: 11px; color: #64748b; margin-bottom: 20px; }
            hr { border: none; border-top: 1px dashed #cbd5e1; margin: 16px 0; }
            table { width: 100%; border-collapse: collapse; }
            td { padding: 6px 0; font-size: 13px; }
            td:last-child { text-align: right; font-weight: bold; }
            .total td { font-size: 16px; border-top: 2px solid #15803d; padding-top: 12px; color: #15803d; }
            .btn { display: block; margin-top: 20px; padding: 10px; background: #15803d; color: white; border: none; border-radius: 10px; cursor: pointer; width: 100%; font-size: 13px; }
        </style>
        </head>
        <body>
            <h2>OFFICIAL RECEIPT</h2>
            <p class="sub">FUMCES Financial Office</p>
            <hr>
            <table>
                <tr><td>OR Number</td><td>{$or}</td></tr>
                <tr><td>Student</td><td>{$name}</td></tr>
                <tr><td>Payment Method</td><td>Cash</td></tr>
                <tr><td>Date</td><td>{$date}</td></tr>
                <tr class="total"><td>Amount Paid</td><td>₱{$amount}</td></tr>
            </table>
            <button class="btn" onclick="window.print()">🖨 Print Receipt</button>
        </body>
        </html>
        HTML;
    }

    private function buildPayMongoReceipt(Payment $payment, string $name): string
    {
        $ref    = htmlspecialchars($payment->reference_number);
        $method = htmlspecialchars($payment->payment_method);
        $amount = number_format($payment->amount, 2);
        $status = ucfirst($payment->status) . ' (' . ucfirst($payment->approval_status) . ')';
        $date   = $payment->created_at->format('M d, Y h:i A');

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"><title>PayMongo Receipt</title>
        <style>
            body { font-family: 'Courier New', monospace; max-width: 420px; margin: 40px auto; padding: 24px; border: 2px solid #e0f2fe; border-radius: 16px; }
            h2 { color: #0f766e; text-align: center; margin-bottom: 4px; }
            .sub { text-align: center; font-size: 11px; color: #64748b; margin-bottom: 20px; }
            hr { border: none; border-top: 1px dashed #bae6fd; margin: 16px 0; }
            table { width: 100%; border-collapse: collapse; }
            td { padding: 6px 0; font-size: 13px; }
            td:last-child { text-align: right; font-weight: bold; }
            .total td { font-size: 16px; border-top: 2px solid #0f766e; padding-top: 12px; color: #0f766e; }
            .note { font-size: 11px; color: #94a3b8; text-align: center; margin-top: 12px; }
            .btn { display: block; margin-top: 16px; padding: 10px; background: #0f766e; color: white; border: none; border-radius: 10px; cursor: pointer; width: 100%; font-size: 13px; }
        </style>
        </head>
        <body>
            <h2>PAYMONGO RECEIPT</h2>
            <p class="sub">FUMCES Financial Office</p>
            <hr>
            <table>
                <tr><td>Reference</td><td>{$ref}</td></tr>
                <tr><td>Student</td><td>{$name}</td></tr>
                <tr><td>Method</td><td>{$method}</td></tr>
                <tr><td>Status</td><td>{$status}</td></tr>
                <tr><td>Date</td><td>{$date}</td></tr>
                <tr class="total"><td>Amount Paid</td><td>₱{$amount}</td></tr>
            </table>
            <p class="note">Processed via PayMongo Secure Checkout</p>
            <button class="btn" onclick="window.print()">🖨 Print Receipt</button>
        </body>
        </html>
        HTML;
    }
}

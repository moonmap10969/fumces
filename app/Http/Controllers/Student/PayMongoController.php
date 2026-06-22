<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\Tuition;
use App\Models\Payment;

class PayMongoController extends Controller
{
    // ---------------------------------------------------------------------------
    // Helper: build authenticated HTTP client
    // ---------------------------------------------------------------------------
    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $client = Http::withBasicAuth(config('services.paymongo.secret'), '');
        if (app()->environment('local')) {
            $client = $client->withOptions(['verify' => false]);
        }
        return $client;
    }

    // ---------------------------------------------------------------------------
    // Helper: check if a PayMongo checkout_session data object represents a paid state
    // ---------------------------------------------------------------------------
    private function isPaid(array $data): bool
    {
        $topStatus = strtolower((string) data_get($data, 'attributes.status', ''));
        if (in_array($topStatus, ['paid', 'succeeded', 'completed'])) return true;

        // Nested payments array
        foreach ((array) data_get($data, 'attributes.payments', []) as $p) {
            if (in_array(strtolower((string) data_get($p, 'attributes.status', '')), ['paid', 'succeeded', 'completed'])) {
                return true;
            }
        }

        // Payment intent
        $intentStatus = strtolower((string) data_get($data, 'attributes.payment_intent.data.attributes.status', ''));
        return in_array($intentStatus, ['paid', 'succeeded', 'completed']);
    }

    // ---------------------------------------------------------------------------
    // Helper: create a Payment record from cached or metadata info
    // ---------------------------------------------------------------------------
   // Replace the createPaymentRecord helper
private function createPaymentRecord(string $checkoutId, array $info, string $remarks = 'Paid via PayMongo'): ?Payment
{
    $ref = 'PM-CHK-' . $checkoutId;

    if (Payment::where('reference_number', $ref)->exists()) {
        return Payment::where('reference_number', $ref)->first();
    }

    $isStudent = ($info['origin'] ?? 'student') === 'student';

    $payment = Payment::create([
        'enrollment_id'    => $info['enrollment_id'],
        'tuition_id'       => $info['tuition_id'],
        'academic_year_id' => $info['academic_year_id'],
        'studentNumber'    => $info['studentNumber'],
        'amount'           => $info['amount'],
        'payment_method'   => $info['payment_method'] ?? 'PayMongo',
        'reference_number' => $ref,
        'receipt_path'     => 'PAYMONGO',
        // Student payments → pending (cashier must approve)
        // Cashier QR PH   → auto-approved
        'status'           => $isStudent ? 'pending'  : 'completed',
        'approval_status'  => $isStudent ? 'pending'  : 'approved',
        'origin'           => $info['origin'] ?? 'student',
        'remarks'          => $remarks,
    ]);

    Cache::forget("paymongo_checkout:{$checkoutId}");

    // Recalc balance only for auto-approved cashier payments
    if (!$isStudent && $info['tuition_id']) {
        $tuition = Tuition::find($info['tuition_id']);
        if ($tuition) $tuition->recalcTotals();
    }

    return $payment;
}

public function handleSuccess(Request $request)
{
    $checkoutId = (string) ($request->query('checkout_session_id')
        ?? $request->session()->pull('paymongo_checkout_id', ''));

    if ($checkoutId === '') {
        return redirect()->route('student.tuition.index')
            ->with('success', 'Payment received by PayMongo. If it does not appear in your payment history shortly, please contact the cashier.');
    }

    $res = $this->http()->get(config('services.paymongo.base') . '/checkout_sessions/' . $checkoutId);
    if (!$res->successful()) {
        Log::warning('[PayMongo] Success return could not verify checkout session', [
            'checkout_id' => $checkoutId,
            'body' => $res->body(),
        ]);

        return redirect()->route('student.tuition.index')
            ->with('success', 'Payment received by PayMongo. Verification is still in progress and should appear in your payment history shortly.');
    }

    $data = $res->json('data');

    if (!$this->isPaid($data)) {
        $status = strtolower((string) data_get($data, 'attributes.status', 'unknown'));

        return redirect()->route('student.tuition.index')
            ->with('success', "PayMongo returned you to the site, but the payment status is still {$status}. It should appear once confirmed.");
    }

    $ref = 'PM-CHK-' . $checkoutId;
    $existing = Payment::where('reference_number', $ref)->first();

    if (!$existing) {
        $meta   = data_get($data, 'attributes.metadata', []);
        $cached = Cache::get("paymongo_checkout:{$checkoutId}");

        $info = $cached ?? [
            'tuition_id'       => (int) ($meta['tuition_id'] ?? 0),
            'enrollment_id'    => $meta['enrollment_id'] ?? null,
            'academic_year_id' => $meta['academic_year_id'] ?? null,
            'studentNumber'    => $meta['studentNumber'] ?? null,
            'amount'           => (float) ($meta['amount'] ?? 0),
            'payment_method'   => 'PayMongo ' . ($meta['payment_method_label'] ?? 'GCash'),
            'origin'           => $meta['origin'] ?? 'student',
        ];

        if ($info['tuition_id'] && $info['amount']) {
            $this->createPaymentRecord($checkoutId, $info, 'Confirmed via PayMongo success return');
        } else {
            Log::warning('[PayMongo] Success return missing tuition metadata', [
                'checkout_id' => $checkoutId,
                'metadata' => $meta,
            ]);
        }
    }

    return redirect()->route('student.tuition.index')
        ->with('success', 'Payment received by PayMongo and added to your payment history. It will be credited once verified by the cashier.');
}

    // ---------------------------------------------------------------------------
    // POST: Create PayMongo Checkout Session
    // FIX: We NO LONGER create a Payment record here — only after confirmation.
    //       This prevents "phantom pending" payments when the student just opens checkout.
    // ---------------------------------------------------------------------------
    public function createCheckout(Request $request)
    {
        $request->validate([
            'tuition_id'          => 'required|exists:tuitions,id',
            'amount'              => 'required|numeric|min:1',
            'payment_method_type' => 'nullable|in:gcash,qrph,card',
        ]);

        $tuition = Tuition::with('academicYear')->findOrFail($request->tuition_id);

        // Safety: only the owner can pay
       $user = Auth::user();
        $admission = \App\Models\Admission::where('user_id', $user->id)->first();

        if (!$admission || $tuition->studentNumber !== $admission->studentNumber) {
            abort(403, 'You are not authorised to pay this tuition.');
        }

        // Recalculate remaining balance from confirmed payments only
        $alreadyPaid = Payment::where('tuition_id', $tuition->id)
            ->whereIn('status', ['completed', 'approved'])
            ->sum('amount');

        $assessment = (float)($tuition->tuition_fee ?? 0) + (float)($tuition->misc_total ?? 0);
        $remaining  = max(0, $assessment - $alreadyPaid);

        $amount = min((float) $request->amount, $remaining);
        if ($amount <= 0) {
            return redirect()->back()->with('error', 'You have no remaining balance to pay.');
        }

        // Build URLs using the live request host (avoids APP_URL local mismatch)
        $host       = $request->getSchemeAndHttpHost();
        $cancelUrl  = $host . route('student.tuition.index', [], false);
        $successUrl = $host . route('student.tuition.paymongo.success', [], false)
                    . '?tuition_id=' . $tuition->id;

        // Map type to PayMongo accepted payment_method_types
        $methodType = $request->input('payment_method_type', 'gcash');
        $paymentMethodTypes = match ($methodType) {
            'card'  => ['card'],
            'qrph'  => ['qrph'],
            default => ['gcash'],
        };

        $methodLabel = match ($methodType) {
            'card'  => 'Card (Visa/Mastercard)',
            'qrph'  => 'QR PH (InstaPay)',
            default => 'GCash',
        };

        $payload = [
            'data' => [
                'attributes' => [
                    'cancel_url'           => $cancelUrl,
                    'success_url'          => $successUrl,
                    'line_items'           => [[
                        'amount'   => (int) round($amount * 100),
                        'currency' => 'PHP',
                        'name'     => 'Tuition Payment',
                        'quantity' => 1,
                    ]],
                    'payment_method_types' => $paymentMethodTypes,
                    'description'          => 'Tuition – ' . $tuition->studentNumber,
                    'metadata'             => [
                        'tuition_id'           => (string) $tuition->id,
                        'enrollment_id'        => (string) ($tuition->enrollment_id ?? ''),
                        'academic_year_id'     => (string) ($tuition->academic_year_id ?? ''),
                        'studentNumber'        => (string) $tuition->studentNumber,
                        'amount'               => (string) $amount,
                        'payment_method_label' => $methodLabel,
                        'origin'               => 'student',
                    ],
                ],
            ],
        ];

        $response = $this->http()->post(config('services.paymongo.base') . '/checkout_sessions', $payload);

        if (!$response->successful()) {
            Log::error('[PayMongo] Checkout creation failed', ['body' => $response->body()]);
            return redirect()->back()->with('error', 'Could not start the payment session. Please try again.');
        }

        $checkout  = $response->json('data');
        $checkoutId = $checkout['id'] ?? null;
        $url        = $checkout['attributes']['checkout_url'] ?? null;

        if (!$checkoutId || !$url) {
            return redirect()->back()->with('error', 'PayMongo did not return a checkout URL.');
        }

        // Cache the checkout intent for 24 hours.
        // The Payment DB record is created ONLY after the webhook or manual verify confirms payment.
        Cache::put("paymongo_checkout:{$checkoutId}", [
            'tuition_id'       => $tuition->id,
            'enrollment_id'    => $tuition->enrollment_id,
            'academic_year_id' => $tuition->academic_year_id,
            'studentNumber'    => $tuition->studentNumber,
            'amount'           => $amount,
            'payment_method'   => 'PayMongo ' . $methodLabel,
            'origin'           => 'student',
        ], now()->addDay());
        $request->session()->put('paymongo_checkout_id', $checkoutId);

        return redirect()->away($url);
    }

    // ---------------------------------------------------------------------------
    // POST: Manual verify (student presses "Verify Payment" for a PM-CHK- reference)
    // This handles cases where the webhook is delayed.
    // ---------------------------------------------------------------------------
    public function verify(Request $request)
    {
        $request->validate(['reference' => 'required|string']);

        $ref = $request->reference;
        if (!str_starts_with($ref, 'PM-CHK-')) {
            return back()->with('error', 'Invalid reference format.');
        }
        $sessionId = substr($ref, 7);

        $res = $this->http()->get(config('services.paymongo.base') . '/checkout_sessions/' . $sessionId);
        if (!$res->successful()) {
            return back()->with('error', 'Could not reach PayMongo. Please try again later.');
        }

        $data = $res->json('data');

        if (!$this->isPaid($data)) {
            $status = strtolower((string) data_get($data, 'attributes.status', 'unknown'));
            return back()->with('error', "Payment has not been confirmed yet (status: {$status}). Please wait a moment and try again.");
        }

        // Payment is confirmed — find existing record or create one
        $existing = Payment::where('reference_number', $ref)->first();

        if ($existing) {
            if ($existing->status !== 'completed') {
                $existing->update([
                    'status'          => 'completed',
                    'approval_status' => 'approved',
                    'remarks'         => 'Confirmed via manual verify',
                ]);
                $tuition = Tuition::find($existing->tuition_id);
                if ($tuition) $tuition->recalcTotals();
            }
        } else {
            // No record yet — build from cache or PayMongo metadata
            $meta   = data_get($data, 'attributes.metadata', []);
            $cached = Cache::get("paymongo_checkout:{$sessionId}");

            $info = $cached ?? [
                'tuition_id'       => (int) ($meta['tuition_id'] ?? 0),
                'enrollment_id'    => $meta['enrollment_id'] ?? null,
                'academic_year_id' => $meta['academic_year_id'] ?? null,
                'studentNumber'    => $meta['studentNumber'] ?? null,
                'amount'           => (float) ($meta['amount'] ?? 0),
                'payment_method'   => 'PayMongo ' . ($meta['payment_method_label'] ?? 'GCash'),
                'origin'           => $meta['origin'] ?? 'student',
            ];

            if (!$info['tuition_id'] || !$info['amount']) {
                return back()->with('error', 'Could not locate tuition details for this payment. Please contact the cashier.');
            }

            $this->createPaymentRecord($sessionId, $info, 'Confirmed via manual verify');
        }

        return back()->with('success', 'Payment verified and recorded successfully!');
    }
}

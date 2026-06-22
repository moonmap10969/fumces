<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\Payment;
use App\Models\Tuition;

class PayMongoWebhookController extends Controller
{
    private function verifySignature(Request $request): bool
    {
        $secret = config('services.paymongo.webhook_secret');
        if (!$secret) return true;

        $sigHeader = $request->header('Paymongo-Signature');
        if (!$sigHeader) {
            Log::warning('[PayMongo] Webhook: missing signature header');
            return false;
        }

        $parts = [];
        foreach (explode(',', $sigHeader) as $part) {
            [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
            $parts[$k] = $v;
        }

        $timestamp = $parts['t'] ?? null;
        if (!$timestamp) return false;

        $expected = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);
        return hash_equals($expected, $parts['te'] ?? '')
            || hash_equals($expected, $parts['li'] ?? '');
    }

    public function handle(Request $request)
    {
        if (!$this->verifySignature($request)) {
            Log::warning('[PayMongo] Webhook rejected — bad signature');
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->all();
        $type    = data_get($payload, 'data.attributes.type', '');

        Log::info('[PayMongo] Webhook received', ['type' => $type]);

        // ── checkout_session.payment.paid ──────────────────────────────────
        if (str_contains($type, 'checkout_session') || str_contains($type, 'checkout')) {
            $sessionId = data_get($payload, 'data.attributes.data.id')
                      ?? data_get($payload, 'data.id');

            $meta = data_get($payload, 'data.attributes.data.attributes.metadata', [])
                 ?? data_get($payload, 'data.attributes.metadata', []);

            if (!$sessionId) {
                Log::warning('[PayMongo] Webhook: no session ID');
                return response()->json(['ok' => true, 'note' => 'no_session_id']);
            }

            $ref      = 'PM-CHK-' . $sessionId;
            $existing = Payment::where('reference_number', $ref)->first();

            if ($existing) {
                // Already recorded — nothing to do
                Log::info('[PayMongo] Webhook: payment already exists', ['ref' => $ref]);
            } else {
                $cached = Cache::get("paymongo_checkout:{$sessionId}");

                $info = $cached ?? [
                    'tuition_id'       => (int)   ($meta['tuition_id']       ?? 0),
                    'enrollment_id'    =>          ($meta['enrollment_id']    ?? null),
                    'academic_year_id' =>          ($meta['academic_year_id'] ?? null),
                    'studentNumber'    =>          ($meta['studentNumber']    ?? null),
                    'amount'           => (float)  ($meta['amount']           ?? 0),
                    'payment_method'   => 'PayMongo ' . ($meta['payment_method_label'] ?? 'GCash'),
                    'origin'           =>          ($meta['origin']           ?? 'student'),
                ];

                if ($info['tuition_id'] && $info['amount'] > 0) {
                    $this->createPayment($sessionId, $info, 'Confirmed via PayMongo webhook');
                } else {
                    Log::warning('[PayMongo] Webhook: missing tuition_id or amount', [
                        'session' => $sessionId, 'meta' => $meta,
                    ]);
                }
            }

            return response()->json(['ok' => true]);
        }

        // ── payment.paid ───────────────────────────────────────────────────
        if (str_contains($type, 'payment') && str_contains($type, 'paid')) {
            $meta      = data_get($payload, 'data.attributes.metadata', []);
            $sessionId = $meta['checkout_session_id'] ?? null;
            $tuitionId = (int) ($meta['tuition_id'] ?? 0);

            if ($sessionId) {
                $ref      = 'PM-CHK-' . $sessionId;
                $existing = Payment::where('reference_number', $ref)->first();

                if (!$existing) {
                    $cached = Cache::get("paymongo_checkout:{$sessionId}");
                    if ($cached) {
                        $this->createPayment($sessionId, $cached, 'Confirmed via payment.paid');
                    }
                }
            }

            return response()->json(['ok' => true]);
        }

        Log::info('[PayMongo] Webhook: unhandled event', ['type' => $type]);
        return response()->json(['ok' => true, 'note' => 'unhandled']);
    }

    private function createPayment(string $sessionId, array $info, string $remarks): void
    {
        $ref = 'PM-CHK-' . $sessionId;
        if (Payment::where('reference_number', $ref)->exists()) return;

        $isCashier = ($info['origin'] ?? 'student') === 'cashier';

        Payment::create([
            'enrollment_id'    => $info['enrollment_id']    ?? null,
            'tuition_id'       => $info['tuition_id'],
            'academic_year_id' => $info['academic_year_id'] ?? null,
            'studentNumber'    => $info['studentNumber']    ?? null,
            'amount'           => $info['amount'],
            'payment_method'   => $info['payment_method']   ?? 'PayMongo',
            'reference_number' => $ref,
            'receipt_path'     => 'PAYMONGO',
            // Student payments → PENDING for cashier double-check
            // Cashier QR PH   → auto-completed
            'status'           => $isCashier ? 'completed' : 'pending',
            'approval_status'  => $isCashier ? 'approved'  : 'pending',
            'origin'           => $info['origin'] ?? 'student',
            'remarks'          => $remarks,
        ]);

        Cache::forget("paymongo_checkout:{$sessionId}");

        // Only recalc balance if auto-approved (cashier origin)
        if ($isCashier) {
            $this->recalc($info['tuition_id']);
        }

        Log::info('[PayMongo] Payment record created', ['ref' => $ref]);
    }

    private function recalc(int|string|null $id): void
    {
        if (!$id) return;
        $t = Tuition::find($id);
        if ($t) $t->recalcTotals();
    }
}
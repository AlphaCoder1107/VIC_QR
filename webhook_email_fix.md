# Razorpay Paid Ticket Email Fix
**Problem:** Payment completes successfully but ticket email is NOT sent via Brevo.
**Free ticket emails work fine — only paid (Razorpay) ticket emails are broken.**

---

## Step 1 — Check If the Webhook Is Even Reaching the Server

```bash
# Check Laravel logs for any webhook hit
docker exec -it <backend_container> tail -100 storage/logs/laravel.log | grep -i "razorpay\|webhook\|payment"

# Also check for any errors in the last 50 lines
docker exec -it <backend_container> tail -50 storage/logs/laravel.log
```

**Report the full output.** If there are NO webhook log entries, the webhook is never reaching the server — jump to Step 2A. If there ARE entries with errors, jump to Step 2B.

---

## Step 2A — If Webhook Is NOT Reaching Server (Most Likely)

This happens because Razorpay needs to POST to `https://vic.codepode.in/api/webhooks/razorpay` but either:
- The webhook URL is not registered in Razorpay dashboard
- The webhook secret is wrong/missing

### Fix: Add a Payment Verification Fallback Endpoint

Since the frontend already shows "Ticket Issued!" after Razorpay modal success, we can add a **server-side payment verification endpoint** that the frontend calls immediately after Razorpay modal success. This verifies the payment with Razorpay's API and triggers the email — without relying on the webhook at all.

**Create:** `backend/app/Http/Actions/Enrollment/Public/VerifyRazorpayPaymentAction.php`

```php
<?php

namespace HiEvents\Http\Actions\Enrollment\Public;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use HiEvents\Jobs\SendOrderDetailsEmailJob;

class VerifyRazorpayPaymentAction
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'razorpay_order_id'   => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature'  => 'required|string',
            'enrollment_no'       => 'required|string',
            'event_id'            => 'required|integer',
        ]);

        $orderId   = $request->input('razorpay_order_id');
        $paymentId = $request->input('razorpay_payment_id');
        $signature = $request->input('razorpay_signature');

        // STEP 1: Verify Razorpay signature (timing-safe)
        $expectedSig = hash_hmac('sha256', $orderId . '|' . $paymentId,
            Config::get('services.razorpay.key_secret'));

        if (!hash_equals($expectedSig, $signature)) {
            Log::warning('Razorpay signature mismatch', compact('orderId', 'paymentId'));
            return response()->json(['error' => 'Invalid payment signature'], 400);
        }

        $enrollmentNo = preg_replace('/[^0-9]/', '', $request->input('enrollment_no'));
        $eventId      = (int) $request->input('event_id');

        // STEP 2: Find the pending order by razorpay_order_id
        $order = DB::table('orders')
            ->where('razorpay_order_id', $orderId)
            ->where('event_id', $eventId)
            ->first();

        if (!$order) {
            Log::error('Order not found for razorpay_order_id: ' . $orderId);
            return response()->json(['error' => 'Order not found'], 404);
        }

        // STEP 3: Idempotency check — already processed?
        if ($order->payment_verified_at) {
            Log::info('Payment already verified for order: ' . $order->id);
            return response()->json(['status' => 'already_processed']);
        }

        // STEP 4: Atomically mark as paid and issue ticket
        DB::transaction(function () use ($order, $paymentId, $enrollmentNo, $eventId) {
            // Lock the student roster row
            $student = DB::table('student_roster')
                ->where('event_id', $eventId)
                ->where('enrollment_no', $enrollmentNo)
                ->lockForUpdate()
                ->first();

            if (!$student || $student->has_purchased) {
                return; // Already processed — idempotent
            }

            // Mark order as completed
            DB::table('orders')->where('id', $order->id)->update([
                'status'               => 'COMPLETED',
                'razorpay_payment_id'  => $paymentId,
                'payment_verified_at'  => now(),
            ]);

            // Mark student as purchased
            DB::table('student_roster')
                ->where('enrollment_no', $enrollmentNo)
                ->where('event_id', $eventId)
                ->update(['has_purchased' => true]);

            Log::info('Payment verified and ticket issued', [
                'order_id'    => $order->id,
                'payment_id'  => $paymentId,
                'enrollment'  => $enrollmentNo,
            ]);
        });

        // STEP 5: Dispatch ticket email (outside transaction — same as free ticket path)
        $freshOrder = DB::table('orders')->find($order->id);
        if ($freshOrder && $freshOrder->payment_verified_at) {
            try {
                SendOrderDetailsEmailJob::dispatch($order->id);
                Log::info('Ticket email dispatched for order: ' . $order->id);
            } catch (\Exception $e) {
                Log::error('Email dispatch failed: ' . $e->getMessage());
                // Do NOT fail the request — payment is confirmed, email can be resent
            }
        }

        return response()->json([
            'status'  => 'verified',
            'message' => 'Payment verified. Check your email for your ticket.',
        ]);
    }
}
```

**Register the route** in `backend/routes/api.php` inside the throttled enrollment group:
```php
Route::post('/events/{event_id}/enrollment/verify-payment',
    \HiEvents\Http\Actions\Enrollment\Public\VerifyRazorpayPaymentAction::class);
```

---

## Step 2B — Update Frontend to Call Verify Endpoint After Razorpay Modal

**File:** `frontend/src/components/enrollment/RazorpayCheckout.tsx`

Find the Razorpay modal `handler.on('payment.success', ...)` callback. Currently it only calls `onSuccess()` for UI. Add a call to the new verify endpoint:

```typescript
handler: async (response: {
    razorpay_payment_id: string;
    razorpay_order_id: string;
    razorpay_signature: string;
}) => {
    // Show loading state immediately
    onSuccess(); // shows "Payment received, sending your ticket..."

    try {
        // Call verify endpoint — this triggers the ticket email
        await fetch(`/api/public/events/${eventId}/enrollment/verify-payment`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                razorpay_order_id:   response.razorpay_order_id,
                razorpay_payment_id: response.razorpay_payment_id,
                razorpay_signature:  response.razorpay_signature,
                enrollment_no:       enrollmentNo,
                event_id:            eventId,
            }),
        });
        // Email is now dispatched server-side — frontend just shows success UI
    } catch (err) {
        console.error('Payment verification call failed:', err);
        // Payment is done — don't show error to student, ticket will be recoverable
    }
},
```

**Important:** The `onSuccess()` call stays FIRST — the student sees "Ticket on its way" immediately. The verify call happens in the background. If it fails for network reasons, the student can use "Resend ticket" from the portal.

---

## Step 3 — Check SendOrderDetailsEmailJob Signature

The `ClaimFreeTicketAction` dispatches this job and it works. Find exactly how it dispatches:

```bash
grep -r "SendOrderDetailsEmailJob\|OrderDetailsEmail" \
  backend/app --include="*.php" | grep -v vendor | head -20
```

Make sure `VerifyRazorpayPaymentAction` dispatches it with the **exact same arguments**. Common mismatch: free ticket passes `$order->id` but the job expects an Order domain object.

```bash
# Check the job's constructor signature
cat backend/app/Jobs/SendOrderDetailsEmailJob.php | head -40
```

Fix `VerifyRazorpayPaymentAction` dispatch call to match exactly.

---

## Step 4 — Check Queue Configuration

If the job is dispatched to a queue but no queue worker is running, emails queue up silently and never send.

```bash
# Check if queues are being used
docker exec -it <backend_container> php artisan tinker --execute="
echo config('queue.default');
"
```

If it returns `database` or `redis` instead of `sync`:
```bash
# Either: run queue worker
docker exec -it <backend_container> php artisan queue:work --tries=3 &

# Or: force sync dispatch in .env (simpler for your setup)
# Add to .env:
QUEUE_CONNECTION=sync
# Then:
docker exec -it <backend_container> php artisan config:cache
```

`sync` means jobs run immediately inline — no worker needed. This is the right setting for your single-server Docker setup.

---

## Step 5 — Fix the Blocked Email in Brevo

In Image 5 you have a **Blocked** email to `committee@vic.college`. This is the admin notification email. Brevo is blocking it because `vic.college` domain is not verified in your Brevo account.

Fix: In Brevo → **Senders & Domains → Add and verify `vic.college` domain** OR change the admin notification sender to `no-reply.vic@codepode.in` which is already verified.

```bash
# Check what address is set as admin notification FROM
docker exec -it <backend_container> php artisan tinker --execute="
echo config('mail.from.address');
echo config('mail.from.name');
"

# If it's events@vic.college, change to the verified domain:
# In .env:
# MAIL_FROM_ADDRESS=no-reply.vic@codepode.in
# MAIL_FROM_NAME="VIC Events"
docker exec -it <backend_container> php artisan config:cache
```

---

## Step 6 — Also Register Webhook in Razorpay (Parallel Fix)

Even with the verify endpoint working, register the webhook for reliability:

1. Razorpay Dashboard → **Webhooks → Add New Webhook**
2. URL: `https://vic.codepode.in/api/webhooks/razorpay`
3. Secret: copy the value of `RAZORPAY_WEBHOOK_SECRET` from your `.env`
4. Events: **payment.captured only**
5. Save

Both the verify-endpoint and webhook will now try to issue the ticket. The idempotency check (`payment_verified_at` already set) prevents double-issuance.

---

## Step 7 — Verify the Fix End to End

```bash
# 1. Watch logs in real time during a test payment
docker exec -it <backend_container> tail -f storage/logs/laravel.log

# 2. Do a test Razorpay payment in a new terminal/browser

# 3. Should see in logs:
#    "Payment verified and ticket issued order_id=X payment_id=pay_XXX"
#    "Ticket email dispatched for order: X"

# 4. Check Brevo real-time dashboard for delivery confirmation

# 5. Run test suite
docker exec -it <backend_container> php artisan test
# Must still show all tests passing
```

---

## Summary of Changes

| File | Change |
|---|---|
| `Actions/Enrollment/Public/VerifyRazorpayPaymentAction.php` | New — server-side payment verify + email dispatch |
| `routes/api.php` | New route: `POST /enrollment/verify-payment` |
| `components/enrollment/RazorpayCheckout.tsx` | Call verify endpoint after modal success |
| `.env` | `QUEUE_CONNECTION=sync` + fix FROM address |
| Razorpay Dashboard | Register webhook URL |
| Brevo Dashboard | Verify `vic.college` domain or change FROM address |

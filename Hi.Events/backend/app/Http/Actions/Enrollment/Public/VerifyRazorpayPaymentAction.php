<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Enrollment\Public;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

class VerifyRazorpayPaymentAction extends BaseAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
    ) {
    }

    public function __invoke(Request $request, int $eventId): JsonResponse
    {
        $request->validate([
            'razorpay_order_id'   => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature'  => 'required|string',
            'enrollment_no'       => 'required|string',
        ]);

        $orderId   = $request->input('razorpay_order_id');
        $paymentId = $request->input('razorpay_payment_id');
        $signature = $request->input('razorpay_signature');
        $enrollmentNo = preg_replace('/[^0-9]/', '', $request->input('enrollment_no'));

        // STEP 1: Verify Razorpay signature (timing-safe)
        $expectedSig = hash_hmac(
            'sha256',
            $orderId . '|' . $paymentId,
            Config::get('services.razorpay.key_secret')
        );

        if (!hash_equals($expectedSig, $signature)) {
            Log::warning('Razorpay signature mismatch', compact('orderId', 'paymentId'));
            return $this->errorResponse('Invalid payment signature', Response::HTTP_BAD_REQUEST);
        }

        // STEP 2: Find the pending order by razorpay_order_id
        $order = DB::table('orders')
            ->where('razorpay_order_id', $orderId)
            ->where('event_id', $eventId)
            ->first();

        if (!$order) {
            Log::error('Order not found for razorpay_order_id: ' . $orderId);
            return $this->errorResponse('Order not found', Response::HTTP_NOT_FOUND);
        }

        // STEP 3: Idempotency check — already processed?
        if ($order->payment_verified_at) {
            Log::info('Payment already verified for order: ' . $order->id);
            return $this->jsonResponse([
                'status' => 'already_processed',
                'message' => 'Payment already verified.',
            ]);
        }

        // STEP 4: Atomically mark as paid and issue ticket
        DB::transaction(function () use ($order, $paymentId, $enrollmentNo, $eventId) {
            // Lock the student roster row
            $student = DB::table('student_rosters')
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
                'payment_status'       => 'PAYMENT_RECEIVED',
                'razorpay_payment_id'  => $paymentId,
                'payment_verified_at'  => now(),
                'updated_at'           => now(),
            ]);

            // Create order item and attendee if they do not exist
            $product = DB::table('products')
                ->where('event_id', $eventId)
                ->whereNull('deleted_at')
                ->first();

            if ($product) {
                $productPrice = DB::table('product_prices')
                    ->where('product_id', $product->id)
                    ->first();

                if ($productPrice) {
                    $itemExists = DB::table('order_items')
                        ->where('order_id', $order->id)
                        ->exists();

                    if (!$itemExists) {
                        DB::table('order_items')->insert([
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'product_price_id' => $productPrice->id,
                            'item_name' => $product->title,
                            'price' => $order->total_gross,
                            'quantity' => 1,
                            'product_type' => 'TICKET',
                            'total_before_additions' => $order->total_gross,
                        ]);

                        DB::table('attendees')->insert([
                            'event_id' => $eventId,
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'product_price_id' => $productPrice->id,
                            'first_name' => $student->name,
                            'last_name' => '',
                            'email' => $student->email,
                            'status' => \HiEvents\DomainObjects\Status\AttendeeStatus::ACTIVE->name,
                            'public_id' => \HiEvents\Helper\IdHelper::publicId(\HiEvents\Helper\IdHelper::ATTENDEE_PREFIX),
                            'short_id' => \HiEvents\Helper\IdHelper::shortId(\HiEvents\Helper\IdHelper::ATTENDEE_PREFIX),
                            'locale' => 'en',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            // Mark student as purchased
            DB::table('student_rosters')
                ->where('enrollment_no', $enrollmentNo)
                ->where('event_id', $eventId)
                ->update([
                    'has_purchased' => true,
                    'updated_at' => now(),
                ]);

            Log::info('Payment verified and ticket issued', [
                'order_id'    => $order->id,
                'payment_id'  => $paymentId,
                'enrollment'  => $enrollmentNo,
            ]);
        });

        // STEP 5: Dispatch ticket email
        $freshOrder = DB::table('orders')->find($order->id);
        if ($freshOrder && $freshOrder->payment_verified_at) {
            $orderDomain = $this->orderRepository->findFirstWhere([
                OrderDomainObjectAbstract::ID => $order->id,
            ]);

            if ($orderDomain !== null) {
                try {
                    event(new \HiEvents\Events\OrderStatusChangedEvent($orderDomain));
                    Log::info('OrderStatusChangedEvent fired for order: ' . $order->id);
                } catch (\Exception $e) {
                    Log::error('Event dispatch failed: ' . $e->getMessage());
                }
            }
        }

        return $this->jsonResponse([
            'status'  => 'verified',
            'message' => 'Payment verified. Check your email for your ticket.',
        ]);
    }
}

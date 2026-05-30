<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organisers\Events\PaymentVerifications;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VerifyPaymentSubmissionAction extends BaseAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
    ) {
    }

    public function __invoke(Request $request, int $organiserId, int $eventId, int $id): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $submission = DB::table('payment_verifications')
            ->where('event_id', $eventId)
            ->where('id', $id)
            ->first();

        if (!$submission) {
            return $this->errorResponse('Submission not found.', 404);
        }

        if ($submission->status === 'VERIFIED') {
            return $this->errorResponse('Submission is already verified.', 422);
        }

        $student = DB::table('student_rosters')
            ->where('event_id', $eventId)
            ->where('enrollment_no', $submission->enrollment_no)
            ->first();

        if (!$student) {
            return $this->errorResponse('Student not found in master roster.', 422);
        }

        if ((bool)$student->has_purchased) {
            return $this->errorResponse('This student enrollment has already purchased/received a ticket.', 422);
        }

        // Determine price
        $freePrefixes = DB::table('free_ticket_prefixes')
            ->where('event_id', $eventId)
            ->pluck('prefix')
            ->toArray();

        $isFree = false;
        foreach ($freePrefixes as $prefix) {
            if (str_starts_with((string)$submission->enrollment_no, (string)$prefix)) {
                $isFree = true;
                break;
            }
        }

        $eventSettings = DB::table('event_settings')
            ->where('event_id', $eventId)
            ->select(['ticket_price_paise'])
            ->first();

        $price = $isFree ? 0 : (($eventSettings->ticket_price_paise ?? 20000) / 100);

        $orderId = null;

        DB::transaction(function () use ($eventId, $submission, $student, $price, &$orderId) {
            // Create Order
            $orderId = DB::table('orders')->insertGetId([
                'short_id' => \HiEvents\Helper\IdHelper::shortId(\HiEvents\Helper\IdHelper::ORDER_PREFIX),
                'public_id' => \HiEvents\Helper\IdHelper::publicId(\HiEvents\Helper\IdHelper::ORDER_PREFIX),
                'event_id' => $eventId,
                'enrollment_no' => $submission->enrollment_no,
                'total_gross' => $price,
                'payment_provider' => 'MANUAL_VERIFICATION',
                'payment_gateway' => 'manual',
                'payment_status' => OrderPaymentStatus::PAYMENT_RECEIVED->name,
                'status' => OrderStatus::COMPLETED->name,
                'first_name' => $submission->name,
                'last_name' => '',
                'email' => $submission->email ?: ($student->email ?: ''),
                'locale' => 'en',
                'currency' => 'INR',
                'payment_verified_at' => Carbon::now(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            // Create Order Item & Attendee
            $product = DB::table('products')
                ->where('event_id', $eventId)
                ->whereNull('deleted_at')
                ->first();

            if ($product) {
                $productPrice = DB::table('product_prices')
                    ->where('product_id', $product->id)
                    ->first();

                if ($productPrice) {
                    DB::table('order_items')->insert([
                        'order_id' => $orderId,
                        'product_id' => $product->id,
                        'product_price_id' => $productPrice->id,
                        'item_name' => $product->title,
                        'price' => $price,
                        'quantity' => 1,
                        'product_type' => 'TICKET',
                        'total_before_additions' => $price,
                    ]);

                    DB::table('attendees')->insert([
                        'event_id' => $eventId,
                        'order_id' => $orderId,
                        'product_id' => $product->id,
                        'product_price_id' => $productPrice->id,
                        'first_name' => $submission->name,
                        'last_name' => '',
                        'email' => $submission->email ?: ($student->email ?: ''),
                        'status' => \HiEvents\DomainObjects\Status\AttendeeStatus::ACTIVE->name,
                        'public_id' => \HiEvents\Helper\IdHelper::publicId(\HiEvents\Helper\IdHelper::ATTENDEE_PREFIX),
                        'short_id' => \HiEvents\Helper\IdHelper::shortId(\HiEvents\Helper\IdHelper::ATTENDEE_PREFIX),
                        'locale' => 'en',
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ]);
                }
            }

            // Update Student Roster
            DB::table('student_rosters')
                ->where('event_id', $eventId)
                ->where('enrollment_no', $submission->enrollment_no)
                ->update([
                    'has_purchased' => true,
                    'email' => $submission->email ?: $student->email,
                    'phone' => $submission->phone ?: $student->phone,
                    'updated_at' => Carbon::now(),
                ]);

            // Update Submission Status
            DB::table('payment_verifications')
                ->where('id', $submission->id)
                ->update([
                    'status' => 'VERIFIED',
                    'updated_at' => Carbon::now(),
                ]);
        });

        // Trigger OrderStatusChangedEvent
        if ($orderId !== null) {
            $orderDomain = $this->orderRepository->findFirstWhere([
                OrderDomainObjectAbstract::ID => $orderId,
            ]);

            if ($orderDomain !== null) {
                try {
                    event(new \HiEvents\Events\OrderStatusChangedEvent($orderDomain));
                    Log::info('OrderStatusChangedEvent fired for manual verification: ' . $orderId);
                } catch (\Exception $e) {
                    Log::error('Manual Verification Event dispatch failed: ' . $e->getMessage());
                }
            }
        }

        return $this->jsonResponse([
            'status' => 'VERIFIED',
            'message' => 'Submission verified and ticket issued successfully.',
        ]);
    }
}

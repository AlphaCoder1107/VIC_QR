<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Enrollment\Public;

use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class ClaimFreeTicketAction extends BaseAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
    ) {
    }

    public function __invoke(Request $request, int $eventId): JsonResponse
    {
        $validated = $request->validate([
            'enrollment_no' => ['required', 'string', 'max:50'],
        ]);

        $enrollmentNo = preg_replace('/[^0-9]/', '', trim($validated['enrollment_no']));

        if (strlen($enrollmentNo) < 4 || strlen($enrollmentNo) > 20) {
            return $this->errorResponse('Enrollment number not found. Contact VIC.', 404);
        }

        $orderId = null;

        try {
            DB::transaction(function () use ($eventId, $enrollmentNo, &$orderId): void {
                $student = DB::table('student_rosters')
                    ->where('event_id', $eventId)
                    ->where('enrollment_no', $enrollmentNo)
                    ->lockForUpdate()
                    ->first();

                if (!$student) {
                    throw new \RuntimeException('student_not_found');
                }

                if ((bool) $student->has_purchased) {
                    throw new \RuntimeException('already_purchased');
                }

                // Prefix check against free_ticket_prefixes inside transaction
                $freePrefixes = DB::table('free_ticket_prefixes')
                    ->where('event_id', $eventId)
                    ->pluck('prefix')
                    ->toArray();

                $isFree = false;
                $matchedPrefix = null;
                foreach ($freePrefixes as $prefix) {
                    if (str_starts_with((string) $enrollmentNo, (string) $prefix)) {
                        $isFree = true;
                        $matchedPrefix = $prefix;
                        break;
                    }
                }

                if (!$isFree) {
                    throw new \RuntimeException('not_free');
                }

                $now = Carbon::now();

                $product = DB::table('products')
                    ->where('event_id', $eventId)
                    ->whereNull('deleted_at')
                    ->first();

                if (!$product) {
                    throw new \RuntimeException('No ticket product found for this event.');
                }

                $productPrice = DB::table('product_prices')
                    ->where('product_id', $product->id)
                    ->first();

                if (!$productPrice) {
                    throw new \RuntimeException('No ticket price found for this event.');
                }

                $orderId = DB::table('orders')->insertGetId([
                    'event_id' => $eventId,
                    'enrollment_no' => $enrollmentNo,
                    'total_gross' => 0,
                    'payment_gateway' => 'complimentary',
                    'payment_status' => OrderPaymentStatus::NO_PAYMENT_REQUIRED->name,
                    'status' => OrderStatus::COMPLETED->name,
                    'first_name' => $student->name,
                    'last_name' => '',
                    'email' => $student->email,
                    'short_id' => \HiEvents\Helper\IdHelper::shortId(\HiEvents\Helper\IdHelper::ORDER_PREFIX),
                    'public_id' => \HiEvents\Helper\IdHelper::publicId(\HiEvents\Helper\IdHelper::ORDER_PREFIX),
                    'locale' => 'en',
                    'currency' => 'INR',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $ruleName = $matchedPrefix ? "Complimentary senior ($matchedPrefix)" : "Complimentary senior";

                DB::table('order_items')->insert([
                    'order_id' => $orderId,
                    'product_id' => $product->id,
                    'product_price_id' => $productPrice->id,
                    'item_name' => $ruleName,
                    'price' => 0.0,
                    'quantity' => 1,
                    'product_type' => 'TICKET',
                    'total_before_additions' => 0.0,
                ]);

                DB::table('attendees')->insert([
                    'event_id' => $eventId,
                    'order_id' => $orderId,
                    'product_id' => $product->id,
                    'product_price_id' => $productPrice->id,
                    'first_name' => $student->name,
                    'last_name' => '',
                    'email' => $student->email,
                    'status' => \HiEvents\DomainObjects\Status\AttendeeStatus::ACTIVE->name,
                    'public_id' => \HiEvents\Helper\IdHelper::publicId(\HiEvents\Helper\IdHelper::ATTENDEE_PREFIX),
                    'short_id' => \HiEvents\Helper\IdHelper::shortId(\HiEvents\Helper\IdHelper::ATTENDEE_PREFIX),
                    'locale' => 'en',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('student_rosters')
                    ->where('event_id', $eventId)
                    ->where('enrollment_no', $enrollmentNo)
                    ->update([
                        'has_purchased' => true,
                        'purchased_at' => $now,
                        'updated_at' => $now,
                    ]);
            });
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();

            if ($msg === 'student_not_found') {
                return $this->errorResponse('Enrollment number not found. Contact VIC.', Response::HTTP_NOT_FOUND);
            }

            if ($msg === 'already_purchased') {
                return $this->errorResponse('Ticket already purchased for this enrollment number.', Response::HTTP_CONFLICT);
            }

            if ($msg === 'not_free') {
                return $this->errorResponse('This enrollment is not eligible for free entry.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            \Illuminate\Support\Facades\Log::error('Failed to claim free ticket: ' . $e->getMessage(), [
                'exception' => $e,
                'enrollment_no' => $enrollmentNo,
            ]);

            return $this->errorResponse('Failed to claim free ticket.', Response::HTTP_BAD_REQUEST);
        }

        // Fetch domain order and dispatch email job
        if ($orderId !== null) {
            $order = $this->orderRepository->findFirstWhere([
                OrderDomainObjectAbstract::ID => $orderId,
            ]);

            if ($order !== null) {
                event(new \HiEvents\Events\OrderStatusChangedEvent($order));
            }
        }

        return $this->jsonResponse([
            'status' => 'issued',
            'message' => 'Check your email for your ticket',
        ]);
    }
}

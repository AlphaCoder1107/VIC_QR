<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Enrollment\Public;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class InitiateRazorpayOrderAction extends BaseAction
{
    public function __invoke(Request $request, int $eventId): JsonResponse
    {
        $validated = $request->validate([
            'enrollment_no' => ['required', 'string', 'max:50'],
        ]);

        $enrollmentNo = preg_replace('/[^0-9]/', '', trim($validated['enrollment_no']));

        if ($enrollmentNo === '' || strlen($enrollmentNo) < 4 || strlen($enrollmentNo) > 20) {
            return $this->errorResponse('Enrollment number not found. Contact VIC.', Response::HTTP_NOT_FOUND);
        }

        $student = DB::table('student_rosters')
            ->where('event_id', $eventId)
            ->where('enrollment_no', $enrollmentNo)
            ->first();

        if (!$student) {
            return $this->errorResponse('Enrollment number not found. Contact VIC.', Response::HTTP_NOT_FOUND);
        }

        if ((bool) $student->has_purchased) {
            return $this->errorResponse('Ticket already purchased for this enrollment number.', Response::HTTP_CONFLICT);
        }

        $freePrefixes = DB::table('free_ticket_prefixes')
            ->where('event_id', $eventId)
            ->pluck('prefix')
            ->toArray();

        $isFree = false;
        foreach ($freePrefixes as $prefix) {
            if (str_starts_with((string) $enrollmentNo, (string) $prefix)) {
                $isFree = true;
                break;
            }
        }

        if ($isFree) {
            return $this->errorResponse('This enrollment is eligible for free entry. Use the claim-free endpoint.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $eventSettings = DB::table('event_settings')
            ->where('event_id', $eventId)
            ->select(['ticket_price_paise'])
            ->first();

        $amountPaise = (int) ($eventSettings->ticket_price_paise ?? 20000);

        $keyId = Config::get('services.razorpay.key_id');
        $keySecret = Config::get('services.razorpay.key_secret');

        if (!is_string($keyId) || !is_string($keySecret) || $keyId === '' || $keySecret === '') {
            return $this->errorResponse('Payment gateway is not configured.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $response = Http::withBasicAuth($keyId, $keySecret)
            ->post('https://api.razorpay.com/v1/orders', [
                'amount' => $amountPaise,
                'currency' => 'INR',
                'receipt' => 'VIC-' . $enrollmentNo,
            ]);

        if (!$response->ok()) {
            return $this->errorResponse('Failed to create Razorpay order.', Response::HTTP_BAD_GATEWAY);
        }

        $body = $response->json();
        $razorpayOrderId = isset($body['id']) ? (string) $body['id'] : '';

        if ($razorpayOrderId === '') {
            return $this->errorResponse('Invalid response from payment gateway.', Response::HTTP_BAD_GATEWAY);
        }

        DB::transaction(function () use ($eventId, $enrollmentNo, $amountPaise, $razorpayOrderId, $student) {
            DB::table('orders')->insert([
                'short_id' => \HiEvents\Helper\IdHelper::shortId(\HiEvents\Helper\IdHelper::ORDER_PREFIX),
                'public_id' => \HiEvents\Helper\IdHelper::publicId(\HiEvents\Helper\IdHelper::ORDER_PREFIX),
                'event_id' => $eventId,
                'enrollment_no' => $enrollmentNo,
                'total_gross' => $amountPaise / 100, // store as major units where schema expects float
                'razorpay_order_id' => $razorpayOrderId,
                'payment_provider' => 'RAZORPAY',
                'payment_gateway' => 'razorpay',
                'payment_status' => OrderPaymentStatus::AWAITING_PAYMENT->name,
                'status' => OrderStatus::RESERVED->name,
                'first_name' => $student->name,
                'last_name' => '',
                'email' => $student->email,
                'locale' => 'en',
                'currency' => 'INR',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        });

        return $this->jsonResponse([
            'razorpay_order_id' => $razorpayOrderId,
            'amount_paise' => $amountPaise,
            'key_id' => $keyId,
        ]);
    }
}

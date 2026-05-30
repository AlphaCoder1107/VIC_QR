<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Webhooks;

use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Jobs\Order\SendOrderDetailsEmailJob;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class RazorpayWebhookAction extends BaseAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $payload = $request->getContent();
            $signature = (string) $request->header('X-Razorpay-Signature', '');

            if (!$this->isValidSignature($payload, $signature)) {
                Log::warning('Rejected Razorpay webhook with invalid signature');

                return $this->noContentResponse(HttpResponse::HTTP_BAD_REQUEST);
            }

            $decodedPayload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            if (($decodedPayload['event'] ?? null) !== 'payment.captured') {
                return $this->noContentResponse();
            }

            $paymentEntity = $decodedPayload['payload']['payment']['entity'] ?? null;
            if (!is_array($paymentEntity)) {
                Log::warning('Razorpay webhook missing payment entity', ['payload' => $decodedPayload]);

                return $this->noContentResponse(HttpResponse::HTTP_BAD_REQUEST);
            }

            $razorpayOrderId = (string) ($paymentEntity['order_id'] ?? '');
            $razorpayPaymentId = (string) ($paymentEntity['id'] ?? '');

            if ($razorpayOrderId === '' || $razorpayPaymentId === '') {
                return $this->noContentResponse(HttpResponse::HTTP_BAD_REQUEST);
            }

            DB::transaction(function () use ($razorpayOrderId, $razorpayPaymentId): void {
                $orderRow = DB::table('orders')
                    ->where('razorpay_order_id', $razorpayOrderId)
                    ->lockForUpdate()
                    ->first();

                if (!$orderRow) {
                    Log::warning('Razorpay webhook order not found', [
                        'razorpay_order_id' => $razorpayOrderId,
                        'razorpay_payment_id' => $razorpayPaymentId,
                    ]);

                    return;
                }

                if ($orderRow->status === OrderStatus::COMPLETED->name && $orderRow->payment_status === OrderPaymentStatus::PAYMENT_RECEIVED->name) {
                    return;
                }

                $student = null;
                if (is_string($orderRow->enrollment_no) && $orderRow->enrollment_no !== '') {
                    $student = DB::table('student_rosters')
                        ->where('event_id', $orderRow->event_id)
                        ->where('enrollment_no', $orderRow->enrollment_no)
                        ->first();

                    DB::table('student_rosters')
                        ->where('event_id', $orderRow->event_id)
                        ->where('enrollment_no', $orderRow->enrollment_no)
                        ->update([
                            'has_purchased' => true,
                            'purchased_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ]);
                }

                $email = $student?->email ?? '';
                $name = $student?->name ?? '';
                $phone = $student?->phone ?? '';

                DB::table('orders')
                    ->where('id', $orderRow->id)
                    ->update([
                        'razorpay_payment_id' => $razorpayPaymentId,
                        'payment_verified_at' => Carbon::now(),
                        'payment_provider' => 'RAZORPAY',
                        'payment_status' => OrderPaymentStatus::PAYMENT_RECEIVED->name,
                        'status' => OrderStatus::COMPLETED->name,
                        'first_name' => $name,
                        'last_name' => '',
                        'email' => $email,
                        'short_id' => $orderRow->short_id ?? \HiEvents\Helper\IdHelper::shortId(\HiEvents\Helper\IdHelper::ORDER_PREFIX),
                        'public_id' => $orderRow->public_id ?? \HiEvents\Helper\IdHelper::publicId(\HiEvents\Helper\IdHelper::ORDER_PREFIX),
                        'locale' => 'en',
                        'currency' => 'INR',
                        'updated_at' => Carbon::now(),
                    ]);

                // Create order item and attendee if they do not exist
                $product = DB::table('products')
                    ->where('event_id', $orderRow->event_id)
                    ->whereNull('deleted_at')
                    ->first();

                if ($product) {
                    $productPrice = DB::table('product_prices')
                        ->where('product_id', $product->id)
                        ->first();

                    if ($productPrice) {
                        $itemExists = DB::table('order_items')
                            ->where('order_id', $orderRow->id)
                            ->exists();

                        if (!$itemExists) {
                            DB::table('order_items')->insert([
                                'order_id' => $orderRow->id,
                                'product_id' => $product->id,
                                'product_price_id' => $productPrice->id,
                                'item_name' => $product->title,
                                'price' => $orderRow->total_gross,
                                'quantity' => 1,
                                'product_type' => 'TICKET',
                                'total_before_additions' => $orderRow->total_gross,
                            ]);

                            DB::table('attendees')->insert([
                                'event_id' => $orderRow->event_id,
                                'order_id' => $orderRow->id,
                                'product_id' => $product->id,
                                'product_price_id' => $productPrice->id,
                                'first_name' => $name,
                                'last_name' => '',
                                'email' => $email,
                                'status' => \HiEvents\DomainObjects\Status\AttendeeStatus::ACTIVE->name,
                                'public_id' => \HiEvents\Helper\IdHelper::publicId(\HiEvents\Helper\IdHelper::ATTENDEE_PREFIX),
                                'short_id' => \HiEvents\Helper\IdHelper::shortId(\HiEvents\Helper\IdHelper::ATTENDEE_PREFIX),
                                'locale' => 'en',
                                'created_at' => Carbon::now(),
                                'updated_at' => Carbon::now(),
                            ]);
                        }
                    }
                }

                $order = $this->orderRepository->findFirstWhere([
                    OrderDomainObjectAbstract::ID => $orderRow->id,
                ]);

                if ($order !== null) {
                    event(new \HiEvents\Events\OrderStatusChangedEvent($order));
                }
            });

            return $this->noContentResponse();
        } catch (Throwable $exception) {
            Log::error('Failed to handle Razorpay webhook', [
                'exception' => $exception,
                'payload' => $request->getContent(),
            ]);

            return $this->noContentResponse(HttpResponse::HTTP_BAD_REQUEST);
        }
    }

    private function isValidSignature(string $payload, string $signature): bool
    {
        $webhookSecret = Config::get('services.razorpay.webhook_secret');

        if (!is_string($webhookSecret) || $webhookSecret === '' || $signature === '') {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

        return hash_equals($expectedSignature, $signature);
    }
}
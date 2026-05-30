<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class CompleteRazorpayOrderCommand extends Command
{
    protected $signature = 'razorpay:complete-order {razorpay_order_id : The Razorpay order ID} {razorpay_payment_id : The Razorpay payment ID}';

    protected $description = 'Manually complete a Razorpay order that was paid but did not update via webhook.';

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $razorpayOrderId = $this->argument('razorpay_order_id');
        $razorpayPaymentId = $this->argument('razorpay_payment_id');

        $this->info("Locating order for Razorpay Order ID: $razorpayOrderId...");

        $orderRow = DB::table('orders')
            ->where('razorpay_order_id', $razorpayOrderId)
            ->first();

        if (!$orderRow) {
            $this->error("Order not found with Razorpay Order ID: $razorpayOrderId");
            return self::FAILURE;
        }

        $this->info("Found Order ID: {$orderRow->id} (Status: {$orderRow->status}, Payment Status: {$orderRow->payment_status})");

        if ($orderRow->status === OrderStatus::COMPLETED->name && $orderRow->payment_status === OrderPaymentStatus::PAYMENT_RECEIVED->name) {
            $this->comment("Order is already marked as COMPLETED and PAID.");
            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($orderRow, $razorpayPaymentId): void {
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

                $email = $student?->email ?? $orderRow->email ?? '';
                $name = $student?->name ?? $orderRow->first_name ?? '';

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

            $this->info("✓ Order {$orderRow->id} has been successfully completed, student roster status updated, and ticket issued!");
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Failed to complete order: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}

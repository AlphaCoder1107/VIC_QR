<?php

namespace HiEvents\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillMissingAttendeesCommand extends Command
{
    protected $signature = 'vic:backfill-attendees {event_id=5}';
    protected $description = 'Backfill order_items and attendees for orders missing them';

    public function handle(): void
    {
        $eventId = (int) $this->argument('event_id');

        // Get the ticket product for this event
        $product = DB::table('products')
            ->where('event_id', $eventId)
            ->whereNull('deleted_at')
            ->first();

        if (!$product) {
            $this->error('No product found for event ' . $eventId);
            return;
        }

        // Get product price
        $productPrice = DB::table('product_prices')
            ->where('product_id', $product->id)
            ->first();

        if (!$productPrice) {
            $this->error('No product_prices row found for product ' . $product->id);
            return;
        }

        // Find all completed orders with no order_items
        $brokenOrders = DB::table('orders')
            ->where('event_id', $eventId)
            ->where('status', 'COMPLETED')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('order_items')
                  ->whereColumn('order_items.order_id', 'orders.id');
            })
            ->get();

        $this->info('Found ' . $brokenOrders->count() . ' orders to backfill...');

        foreach ($brokenOrders as $order) {
            DB::transaction(function () use ($order, $product, $productPrice) {
                // Check if attendee already exists
                $exists = DB::table('attendees')
                    ->where('order_id', $order->id)
                    ->exists();

                if (!$exists) {
                    DB::table('order_items')->insert([
                        'order_id'              => $order->id,
                        'product_id'             => $product->id,
                        'product_price_id'       => $productPrice->id,
                        'item_name'             => $product->title,
                        'price'                 => $order->total_gross,
                        'quantity'              => 1,
                        'product_type'          => 'TICKET',
                        'total_before_additions' => $order->total_gross,
                    ]);

                    DB::table('attendees')->insert([
                        'order_id'         => $order->id,
                        'event_id'         => $order->event_id,
                        'product_id'       => $product->id,
                        'product_price_id' => $productPrice->id,
                        'status'           => \HiEvents\DomainObjects\Status\AttendeeStatus::ACTIVE->name,
                        'first_name'       => $order->first_name,
                        'last_name'        => $order->last_name ?? '',
                        'email'            => $order->email,
                        'public_id'        => \HiEvents\Helper\IdHelper::publicId(\HiEvents\Helper\IdHelper::ATTENDEE_PREFIX),
                        'short_id'         => \HiEvents\Helper\IdHelper::shortId(\HiEvents\Helper\IdHelper::ATTENDEE_PREFIX),
                        'locale'           => 'en',
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ]);
                    $this->line('  ✅ Backfilled: ' . $order->first_name . ' ' . $order->last_name . ' (' . $order->email . ')');
                } else {
                    $this->line('  ⚠️  Attendee already exists for order ' . $order->id . ' — skipped');
                }
            });
        }

        $this->info('Done. ' . $brokenOrders->count() . ' orders processed.');

        // Final count
        $attendeeCount = DB::table('attendees')->where('event_id', $eventId)->count();
        $this->info('Total attendees for event ' . $eventId . ': ' . $attendeeCount);
    }
}

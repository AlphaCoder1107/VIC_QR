# Check-In + Missing Attendees Fix
**Problems:**
1. 4 paid orders (dfs, lijff, lilly, rose) have 0 order_items → no attendee record → can't check in
2. Check-In Lists page says "Please create a ticket" — no ticket type linked to event
3. Free ticket orders also missing attendees in some cases

---

## Step 1 — Diagnose Exactly Which Orders Are Broken

```bash
docker exec -it <backend_container> php artisan tinker --execute="
// Orders with 0 items (broken)
\$broken = DB::table('orders')
    ->where('event_id', 5)
    ->where('status', 'COMPLETED')
    ->whereNotExists(function(\$q) {
        \$q->select(DB::raw(1))
           ->from('order_items')
           ->whereColumn('order_items.order_id', 'orders.id');
    })
    ->get(['id','first_name','last_name','email','total_gross','payment_gateway']);

echo 'BROKEN ORDERS (missing order_items): ' . \$broken->count() . PHP_EOL;
echo json_encode(\$broken, JSON_PRETTY_PRINT);

// Also check products table
\$product = DB::table('tickets')->where('event_id', 5)->first();
echo PHP_EOL . 'TICKET PRODUCT: ' . json_encode(\$product);

// Check ticket prices
\$price = DB::table('ticket_prices')->where('event_id', 5)->first();
echo PHP_EOL . 'TICKET PRICE: ' . json_encode(\$price);
"
```

Report the full output — especially the ticket/ticket_prices rows. The table might be called `tickets` or `products` depending on Hi.Events version.

---

## Step 2 — Backfill Missing order_items + attendees

Find the exact table/column names first:

```bash
# Check what the product table is actually called
docker exec -it <backend_container> php artisan tinker --execute="
\$tables = DB::select(\"SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename\");
\$names = array_column(\$tables, 'tablename');
\$relevant = array_filter(\$names, fn(\$t) => str_contains(\$t, 'ticket') || str_contains(\$t, 'product') || str_contains(\$t, 'order'));
echo implode(PHP_EOL, \$relevant);
"
```

Then create a backfill artisan command:

**File:** `backend/app/Console/Commands/BackfillMissingAttendeesCommand.php`

```php
<?php

namespace HiEvents\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillMissingAttendeesCommand extends Command
{
    protected $signature = 'vic:backfill-attendees {event_id=5}';
    protected $description = 'Backfill order_items and attendees for orders missing them';

    public function handle(): void
    {
        $eventId = (int) $this->argument('event_id');

        // Get the ticket product for this event
        // Try 'tickets' table first, then 'products'
        $ticket = DB::table('tickets')->where('event_id', $eventId)->first()
               ?? DB::table('products')->where('event_id', $eventId)->first();

        if (!$ticket) {
            $this->error('No ticket/product found for event ' . $eventId);
            $this->error('Run Step 3 first to create the ticket product.');
            return;
        }

        // Get ticket price
        $ticketPrice = DB::table('ticket_prices')
            ->where('ticket_id', $ticket->id)
            ->first();

        if (!$ticketPrice) {
            $this->error('No ticket_prices row found for ticket ' . $ticket->id);
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
            DB::transaction(function () use ($order, $ticket, $ticketPrice) {
                // Insert order_item
                $orderItemId = DB::table('order_items')->insertGetId([
                    'order_id'        => $order->id,
                    'ticket_id'       => $ticket->id,
                    'ticket_price_id' => $ticketPrice->id,
                    'quantity'        => 1,
                    'price'           => $order->total_gross,
                    'total_before_discount' => $order->total_gross,
                    'price_before_discount' => $order->total_gross,
                ]);

                // Check if attendee already exists
                $exists = DB::table('attendees')
                    ->where('order_id', $order->id)
                    ->exists();

                if (!$exists) {
                    DB::table('attendees')->insert([
                        'order_id'        => $order->id,
                        'event_id'        => $order->event_id,
                        'ticket_id'       => $ticket->id,
                        'ticket_price_id' => $ticketPrice->id,
                        'order_item_id'   => $orderItemId,
                        'status'          => 'ACTIVE',
                        'first_name'      => $order->first_name,
                        'last_name'       => $order->last_name,
                        'email'           => $order->email,
                        'public_id'       => strtoupper(substr(str_replace('-', '', \Illuminate\Support\Str::uuid()), 0, 8)),
                        'short_id'        => strtoupper(\Illuminate\Support\Str::random(6)),
                        'locale'          => 'en',
                        'created_at'      => now(),
                        'updated_at'      => now(),
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
```

Register in `backend/app/Console/Kernel.php` — add to `$commands` array:
```php
\HiEvents\Console\Commands\BackfillMissingAttendeesCommand::class,
```

Run it:
```bash
docker exec -it <backend_container> php artisan vic:backfill-attendees 5
```

---

## Step 3 — Fix Check-In: Ensure Ticket Product Exists + Create Check-In List

The Check-In page says "Please create a ticket" because it checks for a `tickets` record in the DB for this event. Our seeder may have created it under a different table or with missing columns.

```bash
docker exec -it <backend_container> php artisan tinker --execute="
// Check what exists
\$t = DB::table('tickets')->where('event_id', 5)->get();
echo 'TICKETS: ' . json_encode(\$t, JSON_PRETTY_PRINT);

\$tp = DB::table('ticket_prices')->where('event_id', 5)->get();
echo 'TICKET_PRICES: ' . json_encode(\$tp, JSON_PRETTY_PRINT);
"
```

If the ticket record is missing or malformed, create it properly:

```bash
docker exec -it <backend_container> php artisan tinker --execute="
\$existing = DB::table('tickets')->where('event_id', 5)->first();

if (!\$existing) {
    \$ticketId = DB::table('tickets')->insertGetId([
        'event_id'        => 5,
        'title'           => 'Event Ticket',
        'type'            => 'GENERAL_ADMISSION',
        'status'          => 'ACTIVE',
        'quantity_available' => 2000,
        'order_bump'      => false,
        'is_hidden'       => false,
        'sale_start_date' => now()->subDay(),
        'sale_end_date'   => now()->addDays(30),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    DB::table('ticket_prices')->insert([
        'ticket_id'  => \$ticketId,
        'event_id'   => 5,
        'price'      => 30000,   // ₹300 in paise — ignored by our enrollment flow
        'label'      => 'Standard',
        'is_default' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    echo 'Created ticket ID: ' . \$ticketId;
} else {
    echo 'Ticket already exists: ID ' . \$existing->id;
}
"
```

---

## Step 4 — Create the Check-In List via Admin UI

After the ticket exists in the DB, go to:
`vic.codepode.in/manage/event/5/check-in` → **Create Check-In List**

- Name: `Main Entry Gate`
- Tickets: select the ticket you just created
- Save

This generates a **shareable check-in URL** for gate volunteers — they don't need to log in. Share that URL with your gate team.

Repeat for a second list if needed:
- Name: `Food Counter`

---

## Step 5 — Fix VerifyRazorpayPaymentAction to Always Create Attendees

The permanent fix — ensure the paid flow never leaves an order without an attendee:

In `VerifyRazorpayPaymentAction.php`, after the DB transaction that marks the order COMPLETED, add:

```php
// After order is marked COMPLETED — ensure attendee exists
$attendeeExists = DB::table('attendees')
    ->where('order_id', $order->id)
    ->exists();

if (!$attendeeExists) {
    $ticket = DB::table('tickets')
        ->where('event_id', $eventId)
        ->whereNull('deleted_at')
        ->first();

    $ticketPrice = DB::table('ticket_prices')
        ->where('ticket_id', $ticket->id)
        ->first();

    // Upsert order_item
    $existingItem = DB::table('order_items')
        ->where('order_id', $order->id)
        ->first();

    $orderItemId = $existingItem?->id ?? DB::table('order_items')->insertGetId([
        'order_id'              => $order->id,
        'ticket_id'             => $ticket->id,
        'ticket_price_id'       => $ticketPrice->id,
        'quantity'              => 1,
        'price'                 => $freshOrder->total_gross,
        'total_before_discount' => $freshOrder->total_gross,
        'price_before_discount' => $freshOrder->total_gross,
    ]);

    DB::table('attendees')->insert([
        'order_id'        => $order->id,
        'event_id'        => $eventId,
        'ticket_id'       => $ticket->id,
        'ticket_price_id' => $ticketPrice->id,
        'order_item_id'   => $orderItemId,
        'status'          => 'ACTIVE',
        'first_name'      => $freshOrder->first_name,
        'last_name'       => $freshOrder->last_name ?? '',
        'email'           => $freshOrder->email,
        'public_id'       => strtoupper(substr(str_replace('-', '', Str::uuid()), 0, 8)),
        'short_id'        => strtoupper(Str::random(6)),
        'locale'          => 'en',
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
}
```

---

## Step 6 — Verify Everything Is Fixed

```bash
# Run the backfill
docker exec -it <backend_container> php artisan vic:backfill-attendees 5

# Should now show 12 attendees matching 12 orders
docker exec -it <backend_container> php artisan tinker --execute="
echo 'Orders: '   . DB::table('orders')->where('event_id',5)->where('status','COMPLETED')->count();
echo 'Items: '    . DB::table('order_items')->whereHas... // count via join
echo 'Attendees: '. DB::table('attendees')->where('event_id',5)->count();
"

# Run tests
docker exec -it <backend_container> php artisan test
```

After this:
- All 12 orders → 12 attendees
- Check-In Lists page → can create lists
- Gate volunteers can scan all tickets (free + paid)

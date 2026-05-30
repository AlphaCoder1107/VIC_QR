# VIC Dashboard Stats Fix — Agent Prompt
Paste this into the coding agent to fix the 0-value dashboard stats.

---

## Problem

The event dashboard at `/manage/event/{id}/dashboard` shows 0 for all stats:
- Attendees
- Products Sold
- Gross Sales (₹0.00)
- Refunded
- Page Views
- Completed Orders

This is because our custom enrollment flow creates orders via `ClaimFreeTicketAction` (free/senior tickets) and `RazorpayWebhookAction` (paid tickets). The dashboard stats queries may not be counting these correctly — either because of a wrong `status` filter, missing join to `order_items` or `attendees`, or because the custom payment path sets a different `payment_gateway` value than the stats query expects.

---

## Task 1 — Find the Stats Query

First locate where the dashboard numbers are computed:

```bash
# Find the stats/summary action
grep -r "ATTENDEES\|attendees_count\|gross_sales\|completed_orders\|products_sold" \
  backend/app --include="*.php" -l

# Also check repositories
grep -r "dashboard\|summary\|stats" \
  backend/app/Repository --include="*.php" -l 2>/dev/null

# Check what routes serve the dashboard data
grep -r "dashboard\|summary\|stats" backend/routes/api.php
```

Report the file names found.

---

## Task 2 — Inspect the Actual DB Data

Before fixing code, confirm what data actually exists in the DB:

```bash
docker exec -it <backend_container> php artisan tinker --execute="
// Check orders table
\$orders = DB::table('orders')->select('id','status','total_gross','payment_gateway','event_id')->get();
echo 'ORDERS: ' . json_encode(\$orders, JSON_PRETTY_PRINT);

// Check order_items
\$items = DB::table('order_items')->select('id','order_id','quantity','price')->get();
echo 'ORDER_ITEMS: ' . json_encode(\$items, JSON_PRETTY_PRINT);

// Check attendees
\$att = DB::table('attendees')->select('id','order_id','event_id','status')->get();
echo 'ATTENDEES: ' . json_encode(\$att, JSON_PRETTY_PRINT);

// Check student_roster purchase flags
\$roster = DB::table('student_roster')->where('has_purchased', true)->count();
echo 'PURCHASED_FROM_ROSTER: ' . \$roster;
"
```

Report the full output. This tells us whether data exists but isn't being counted, or whether it was never written at all.

---

## Task 3 — Find the Status Values Used

The stats query almost certainly filters by `orders.status = 'COMPLETED'` or similar. Our custom actions may have written a different value.

```bash
docker exec -it <backend_container> php artisan tinker --execute="
\$statuses = DB::table('orders')->distinct()->pluck('status');
echo 'ORDER STATUSES IN DB: ' . json_encode(\$statuses);

\$gateways = DB::table('orders')->distinct()->pluck('payment_gateway');
echo 'PAYMENT GATEWAYS IN DB: ' . json_encode(\$gateways);
"
```

Then find the status constants the stats query uses:
```bash
grep -r "COMPLETED\|OrderStatus\|order_status\|payment_status" \
  backend/app --include="*.php" | grep -v "vendor" | head -20
```

If our custom actions wrote `status = 'completed'` (lowercase) but the stats query filters on `OrderStatus::COMPLETED` (which might be uppercase or an enum), that mismatch causes 0s.

---

## Task 4 — Fix the Stats Query / Data Mismatch

Based on Task 2 and 3 findings, apply ONE of these fixes:

### Fix A — If data exists but wrong status value
If orders exist but with wrong status string, update them:
```bash
docker exec -it <backend_container> php artisan tinker --execute="
// Check what the correct COMPLETED enum value is
echo DB::table('orders')->where('status', 'COMPLETED')->count() . ' COMPLETED';
echo DB::table('orders')->where('status', 'completed')->count() . ' completed';
echo DB::table('orders')->where('status', 'Complete')->count() . ' Complete';
"
```

Find the correct enum value from the codebase:
```bash
grep -r "COMPLETED\|= 'completed'" backend/app/Enums --include="*.php" 2>/dev/null
grep -r "OrderStatus" backend/app --include="*.php" | head -5
```

Then in `ClaimFreeTicketAction.php` and `RazorpayWebhookAction.php`, find the line that sets `status` on the order and update it to use the correct enum value:
```php
// Wrong (raw string):
'status' => 'completed',

// Right (use the existing Hi.Events enum):
'status' => OrderStatus::COMPLETED->value,
// or whatever the correct enum reference is in this codebase
```

### Fix B — If data exists but attendees table is empty
If orders exist but `attendees` count is 0, the attendee creation inside `ClaimFreeTicketAction` and `RazorpayWebhookAction` failed silently.

Find where the existing Hi.Events flow creates attendees (for reference):
```bash
grep -r "attendees\|Attendee::create\|insert.*attendees" \
  backend/app/Services --include="*.php" | head -10
```

Then fix both action files to create attendees using the same column set and values that the native Hi.Events flow uses. Specifically check for required columns that our custom insert might be missing (public_id, short_id, status, locale, etc.).

### Fix C — If no data exists at all (orders table is empty)
The custom actions are not being reached. Check:
```bash
# Check if routes are registered correctly
docker exec -it <backend_container> php artisan route:list | grep enrollment

# Test the free claim endpoint directly
curl -X POST http://localhost:<PORT>/api/public/events/1/enrollment/claim-free \
  -H "Content-Type: application/json" \
  -d '{"enrollment_no":"22045","email":"test@test.com"}'
```

Report the curl response and any Laravel logs:
```bash
docker exec -it <backend_container> tail -50 storage/logs/laravel.log
```

---

## Task 5 — Fix the Dashboard Stats Aggregation Query

After fixing the data layer, fix the stats query itself so it includes both paid and free (complimentary) tickets.

Find the stats action file (from Task 1) and locate the query. It likely looks like:

```php
// Existing — may miss complimentary orders
->where('payment_gateway', '!=', 'offline')
->where('status', OrderStatus::COMPLETED)
```

Update it to explicitly include our complimentary payment gateway:
```php
// Fixed — includes both Razorpay and complimentary free tickets
->whereIn('payment_gateway', ['razorpay', 'complimentary', 'offline'])
->where('status', OrderStatus::COMPLETED)
```

Also check the **Gross Sales** aggregation — it should SUM `total_gross` from orders. Free tickets have `total_gross = 0` which is correct (they won't inflate revenue). Confirm:
```php
// Gross sales should sum only paid orders
$grossSales = Order::where('event_id', $eventId)
    ->where('status', OrderStatus::COMPLETED)
    ->where('payment_gateway', 'razorpay')  // only paid
    ->sum('total_gross');

// Attendees count should include free + paid
$attendees = Attendee::where('event_id', $eventId)
    ->where('status', '!=', 'cancelled')
    ->count();

// Products sold = sum of order_items quantity
$productsSold = OrderItem::whereHas('order', function($q) use ($eventId) {
    $q->where('event_id', $eventId)
      ->where('status', OrderStatus::COMPLETED);
})->sum('quantity');
```

---

## Task 6 — Verify the Fix

After applying all fixes, run migrations if any schema changes were made:
```bash
docker exec -it <backend_container> php artisan migrate --force
docker exec -it <backend_container> php artisan config:cache
```

Seed one test purchase to verify counts appear:
```bash
docker exec -it <backend_container> php artisan tinker --execute="
// Check counts after fix
echo 'Orders: ' . DB::table('orders')->where('status', 'COMPLETED')->count();
echo 'Attendees: ' . DB::table('attendees')->count();
echo 'Order items: ' . DB::table('order_items')->sum('quantity');
echo 'Gross sales: ' . DB::table('orders')->where('payment_gateway','razorpay')->sum('total_gross');
"
```

Then hit the dashboard API endpoint directly to confirm it returns non-zero:
```bash
curl -s http://localhost:<PORT>/api/auth/organiser/1/events/1/dashboard-stats \
  -H "Authorization: Bearer <your_admin_token>" | python3 -m json.tool
# (adjust endpoint path to match what the frontend calls — check network tab in browser)
```

---

## Task 7 — Run Full Test Suite

```bash
docker exec -it <backend_container> php artisan test
# Must still show 402 tests passing
```

---

## Expected Dashboard After Fix

| Stat | What It Should Count |
|---|---|
| Attendees | All students (free + paid) who completed registration |
| Products Sold | Total ticket quantity from `order_items` |
| Gross Sales | Sum of `total_gross` from Razorpay-paid orders only |
| Refunded | Orders with `status = REFUNDED` (likely 0 for now) |
| Page Views | Tracked separately — may still show 0 if page view tracking isn't hooked |
| Completed Orders | Count of orders with `status = COMPLETED` (free + paid) |

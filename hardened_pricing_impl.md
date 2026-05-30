# Hardened Pricing & Roster Control — Full Implementation Guide
**Fixes:** Free ticket loophole, wrong prefix extraction, random roll no bypass
**New features:** Admin-defined free prefix list, live price manager on roster page

---

## Root Cause of the Current Bug

The enrollment number in the screenshot is `2401126897963`.

| What the code did | What it should do |
|---|---|
| Extracted first **2** digits → `24` | Extract first **3** digits → `240` |
| Compared `24` against rule `range 22–22` | Compare `240` against free list `[220]` |
| Rule evaluation broke → defaulted to free | `240` ≠ `220` → must trigger ₹200 payment |

**Fix:** Switch from a numeric range system to an **explicit prefix allowlist** stored in the DB and managed by admin. This is more secure and more flexible.

---

## New Architecture — Two-Layer Validation

```
Student enters roll no: 2401126897963
              │
              ▼
    LAYER 1 — ROSTER CHECK
    Does this roll no exist in student_roster
    for this event_id?
              │
        ┌─────┴─────┐
       YES           NO
        │             │
        ▼             ▼
   Continue      ❌ BLOCKED
                 "Not on guest list.
                  Contact VIC."
              │
              ▼
    LAYER 2 — PREFIX CHECK
    Does the first N digits of this roll no
    match any entry in free_ticket_prefixes?
              │
        ┌─────┴──────┐
       YES            NO
        │              │
        ▼              ▼
   ₹0 Free        ₹200 Paid
   Fast path       Razorpay
```

**Security guarantee:** Random roll numbers that aren't in the roster are blocked at Layer 1 before pricing is ever evaluated. Someone cannot guess a free-tier roll number pattern and bypass payment — they must be in the uploaded roster first.

---

## Part 1 — Backend: Fix Prefix Extraction

### 1A. New DB Table — `free_ticket_prefixes`

```sql
CREATE TABLE free_ticket_prefixes (
  id           BIGSERIAL PRIMARY KEY,
  event_id     BIGINT NOT NULL REFERENCES events(id) ON DELETE CASCADE,
  prefix       VARCHAR(20) NOT NULL,   -- e.g. "220", "2201", "22011"
  label        VARCHAR(100),           -- e.g. "Final Year 2022 Batch"
  created_by   BIGINT REFERENCES users(id),
  created_at   TIMESTAMP DEFAULT NOW(),
  UNIQUE(event_id, prefix)
);
```

**Migration file:**
`backend/database/migrations/2026_05_26_000001_create_free_ticket_prefixes_table.php`

### 1B. Remove Old Range-Based Rule

The `enrollment_pricing_rules` table's `range_start`/`range_end` columns are no longer used for free/paid decisions. Keep the table for the ticket price value only:

```sql
-- Keep: price_paise column (used for paid ticket amount)
-- Drop: range_start, range_end (replaced by free_ticket_prefixes)

ALTER TABLE enrollment_pricing_rules
  DROP COLUMN IF EXISTS range_start,
  DROP COLUMN IF EXISTS range_end,
  DROP COLUMN IF EXISTS is_free;
```

Add a single `default_price_paise` concept — one price for all non-free students per event:

```sql
-- Simpler: use event_settings for the paid price
ALTER TABLE event_settings
  ADD COLUMN IF NOT EXISTS ticket_price_paise INT NOT NULL DEFAULT 20000;
  -- 20000 paise = ₹200
```

### 1C. Fix the Lookup Action

**File:** `backend/app/Http/Actions/Enrollment/Public/GetEnrollmentLookupActionPublic.php`

Replace the current batch-year extraction with:

```php
public function __invoke(Request $request, int $eventId): JsonResponse
{
    $enrollmentNo = trim($request->input('enrollment_no'));

    // LAYER 1 — Roster check (security gate)
    $student = DB::table('student_roster')
        ->where('event_id', $eventId)
        ->where('enrollment_no', $enrollmentNo)
        ->first();

    if (!$student) {
        // Generic message — do NOT reveal whether it's a wrong number
        // or not on the roster (prevents enumeration attacks)
        return response()->json([
            'status'  => 'not_found',
            'message' => 'Enrollment number not found. Please contact VIC.',
        ], 404);
    }

    // LAYER 1B — Already purchased check
    if ($student->has_purchased) {
        return response()->json([
            'status'       => 'already_purchased',
            'message'      => 'Ticket already issued for this enrollment number.',
            'masked_email' => $this->maskEmail($student->email),
        ], 200);
    }

    // LAYER 2 — Prefix check against free_ticket_prefixes
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

    // Get paid ticket price from event_settings
    $settings = DB::table('event_settings')
        ->where('event_id', $eventId)
        ->first();
    $pricePaise = $settings->ticket_price_paise ?? 20000;

    return response()->json([
        'status'         => 'found',
        'name'           => $student->name,
        'enrollment_no'  => $enrollmentNo,
        'masked_email'   => $this->maskEmail($student->email),
        'email'          => $student->email,   // pre-fill editable field
        'is_free'        => $isFree,
        'price_paise'    => $isFree ? 0 : $pricePaise,
        'price_display'  => $isFree ? '₹0 — Free Entry' : '₹' . ($pricePaise / 100),
        'matched_prefix' => $matchedPrefix,    // for UI badge label
    ]);
}

private function maskEmail(?string $email): string
{
    if (!$email) return '';
    [$local, $domain] = explode('@', $email) + ['', ''];
    return substr($local, 0, 2) . str_repeat('*', max(0, strlen($local) - 2)) . '@' . $domain;
}
```

### 1D. Fix ClaimFreeTicketAction — Re-validate at Claim Time

**File:** `backend/app/Http/Actions/Enrollment/Public/ClaimFreeTicketAction.php`

Add a second prefix check right before issuing the ticket:

```php
// Re-validate prefix server-side — never trust the frontend's is_free claim
$freePrefixes = DB::table('free_ticket_prefixes')
    ->where('event_id', $eventId)
    ->pluck('prefix')
    ->toArray();

$isFree = collect($freePrefixes)
    ->contains(fn($p) => str_starts_with((string) $enrollmentNo, (string) $p));

if (!$isFree) {
    // Someone tampered with the request — abort
    return response()->json([
        'status'  => 'payment_required',
        'message' => 'This enrollment number requires payment.',
    ], 402);
}

// Only now proceed to DB::transaction() + has_purchased = true
```

---

## Part 2 — Admin UI on Student Roster Page

Add two new management blocks to `/manage/event/{id}/student-roster`:

---

### Block A — Free Ticket Prefix Manager

```
┌──────────────────────────────────────────────────────┐
│  🎟  Free Ticket Roll Number Prefixes                 │
│  Students whose enrollment no starts with these      │
│  prefixes get free entry — no payment required.      │
├──────────────────────────────────────────────────────┤
│  Current free prefixes:                              │
│                                                      │
│  ┌──────┬──────────────────────────┬────────────┐    │
│  │ 220  │ Final Year 2022 Batch    │  [Remove]  │    │
│  └──────┴──────────────────────────┴────────────┘    │
│                                                      │
│  Add new prefix:                                     │
│  ┌─────────────────────┐ ┌────────────────────┐      │
│  │ Roll no prefix      │ │ Label (optional)   │      │
│  │ e.g. 220            │ │ e.g. Final Year    │      │
│  └─────────────────────┘ └────────────────────┘      │
│  [ + Add Free Prefix ]                               │
│                                                      │
│  ℹ️  System checks: Is roll no in roster? → Is       │
│     prefix in this list? → Free or Paid.            │
└──────────────────────────────────────────────────────┘
```

**How prefix matching works (shown to admin):**

| Prefix entered | Matches | Doesn't match |
|---|---|---|
| `220` | `220xxxx`, `2201xxx`, `22012xxx` | `221xxx`, `230xxx`, `240xxx` |
| `2201` | `2201xxx`, `22011xxx` | `2202xxx`, `220xxx` without `2201` |
| `22011` | `22011xxx` only | Everything else |

Admin can be as broad or narrow as needed.

---

### Block B — Ticket Price Manager

```
┌──────────────────────────────────────────────────────┐
│  💰 Ticket Price                                      │
│  Applied to all students NOT matched by a free       │
│  prefix above.                                       │
├──────────────────────────────────────────────────────┤
│                                                      │
│  Current price:   ₹ [200        ]                    │
│                                                      │
│  [ Update Price ]                                    │
│                                                      │
│  ⚠️  Price changes apply to new sessions only.        │
│     Students already in checkout are not affected.  │
│                                                      │
│  Last changed: May 26 2026, 7:31 AM by Admin        │
└──────────────────────────────────────────────────────┘
```

---

## Part 3 — Backend: Admin Endpoints for Both Blocks

### 3A. Free Prefix CRUD

```
GET    /api/auth/organiser/{org}/events/{event}/free-prefixes
POST   /api/auth/organiser/{org}/events/{event}/free-prefixes
DELETE /api/auth/organiser/{org}/events/{event}/free-prefixes/{id}
```

**POST body:**
```json
{ "prefix": "220", "label": "Final Year 2022 Batch" }
```

**Validation in CreateFreePrefixAction:**
```php
// Prefix must be numeric string, min 3 chars, max 10 chars
$request->validate([
    'prefix' => ['required', 'string', 'min:3', 'max:10', 'regex:/^\d+$/'],
    'label'  => ['nullable', 'string', 'max:100'],
]);

// Overlap check — prevent admin from adding a prefix that
// is a substring of an existing one (ambiguous matching)
$existing = DB::table('free_ticket_prefixes')
    ->where('event_id', $eventId)
    ->pluck('prefix');

foreach ($existing as $ep) {
    if (str_starts_with($prefix, $ep) || str_starts_with($ep, $prefix)) {
        return response()->json([
            'error' => "Prefix '{$prefix}' overlaps with existing prefix '{$ep}'"
        ], 422);
    }
}
```

### 3B. Price Update Endpoint

```
PATCH /api/auth/organiser/{org}/events/{event}/ticket-price
```

**Body:** `{ "price": 250 }` (admin inputs rupees, backend converts to paise)

```php
// UpdateTicketPriceAction.php
$rupees = $request->validate(['price' => 'required|integer|min:0|max:10000'])['price'];

DB::table('event_settings')
    ->where('event_id', $eventId)
    ->update([
        'ticket_price_paise' => $rupees * 100,
        'price_updated_at'   => now(),
        'price_updated_by'   => auth()->id(),
    ]);
```

Add columns to `event_settings`:
```sql
ALTER TABLE event_settings
  ADD COLUMN IF NOT EXISTS ticket_price_paise   INT DEFAULT 20000,
  ADD COLUMN IF NOT EXISTS price_updated_at     TIMESTAMP,
  ADD COLUMN IF NOT EXISTS price_updated_by     BIGINT;
```

---

## Part 4 — Complete Security Hardening

### 4A. Rate Limiting (tighten for free-claim endpoint)
```php
// routes/api.php
Route::middleware(['throttle:10,1'])->group(function () {
    Route::post('/enrollment-lookup',       ...);  // 10/min/IP
    Route::post('/enrollment/update-email', ...);
    Route::post('/enrollment/resend-ticket',...);
});

Route::middleware(['throttle:5,1'])->group(function () {
    Route::post('/enrollment/claim-free',      ...); // 5/min/IP ← tighter
    Route::post('/enrollment/initiate-order',  ...); // 5/min/IP ← tighter
});
```

### 4B. Enrollment Number Sanitization
```php
// In GetEnrollmentLookupActionPublic before any DB query:
$enrollmentNo = preg_replace('/[^0-9]/', '', trim($request->input('enrollment_no')));

if (strlen($enrollmentNo) < 4 || strlen($enrollmentNo) > 20) {
    return response()->json(['status' => 'not_found', 'message' => '...'], 404);
}
// This strips letters/symbols — "220abc" becomes "220", "'; DROP TABLE" becomes ""
```

### 4C. Atomic Transaction with Lock
```php
// ClaimFreeTicketAction — prevent race condition double-claim
DB::transaction(function () use ($enrollmentNo, $eventId) {
    $student = DB::table('student_roster')
        ->where('event_id', $eventId)
        ->where('enrollment_no', $enrollmentNo)
        ->lockForUpdate()    // pessimistic lock — blocks concurrent requests
        ->first();

    abort_if(!$student, 404);
    abort_if($student->has_purchased, 409, 'Already purchased');

    // Re-validate prefix inside transaction
    $isFree = ... // prefix check as above
    abort_unless($isFree, 402, 'Payment required');

    // Now safe to issue ticket
    DB::table('student_roster')
        ->where('id', $student->id)
        ->update(['has_purchased' => true]);
});
```

### 4D. Response Hardening — No Information Leakage
```php
// WRONG — tells attacker roll no exists but isn't free
if (!$isFree) return response()->json(['error' => 'Not eligible for free ticket'], 403);

// RIGHT — same generic message for all failure cases
return response()->json(['status' => 'not_found', 'message' => 'Enrollment number not found.'], 404);
```

---

## Part 5 — Complete Workflow (All Elements Together)

```
ADMIN SETUP (before event opens):
  1. Upload student roster Excel
  2. Add free prefix: "220" → "Final Year 2022 Batch"
  3. Set ticket price: ₹200
  4. Verify: 1 free prefix active, price shows ₹200

STUDENT PURCHASE FLOW:
  Student enters: 2401126897963

  Layer 1 — Roster check:
    SELECT * FROM student_roster
    WHERE event_id = 5 AND enrollment_no = '2401126897963'
    → Found ✅

  Layer 1B — Already purchased?
    has_purchased = false → Continue ✅

  Layer 2 — Prefix check:
    free_prefixes for event 5 = ["220"]
    "2401126897963".startsWith("220") → false
    → is_free = false

  Response to frontend:
    { is_free: false, price_paise: 20000, price_display: "₹200" }

  Frontend renders:
    🎟 Student Ticket — ₹200
    [Pay & Get Ticket] → Razorpay modal opens

  Student pays ₹200 via Razorpay
  Webhook fires: payment.captured
  Server verifies signature ✅
  Sets has_purchased = true inside lockForUpdate transaction
  Sends QR ticket to email


SENIOR FREE FLOW:
  Student enters: 2201126897963

  Layer 1 — Roster check → Found ✅
  Layer 2 — "2201126897963".startsWith("220") → true ✅
  Response: { is_free: true, price_paise: 0 }
  Frontend renders: 🎉 Final Year — Free Entry
  [Confirm & Get Ticket] → ClaimFreeTicketAction
  Server re-validates prefix inside lockForUpdate ✅
  Sets has_purchased = true
  Sends QR ticket to email


ATTACK SCENARIOS BLOCKED:
  ┌──────────────────────────────────────────────────────┐
  │ Attack: Random roll no "2209999999"                  │
  │ Layer 1: Not in roster → 404 "Not found" ← BLOCKED  │
  ├──────────────────────────────────────────────────────┤
  │ Attack: Tamper POST body { is_free: true }           │
  │ ClaimFreeTicket re-validates prefix server-side      │
  │ → 402 "Payment required" ← BLOCKED                   │
  ├──────────────────────────────────────────────────────┤
  │ Attack: Concurrent double-claim (two tabs)           │
  │ lockForUpdate() blocks second request                │
  │ → 409 "Already purchased" ← BLOCKED                  │
  ├──────────────────────────────────────────────────────┤
  │ Attack: SQL injection in enrollment_no field         │
  │ preg_replace('/[^0-9]/', '') strips all non-digits   │
  │ → Nothing reaches the query ← BLOCKED                │
  ├──────────────────────────────────────────────────────┤
  │ Attack: Spam lookup to enumerate roll numbers        │
  │ throttle:10,1 → 429 after 10 req/min/IP ← BLOCKED   │
  └──────────────────────────────────────────────────────┘
```

---

## Part 6 — Agent Prompt to Implement Everything

```
Implement the following security hardening and new admin features.
These changes fix a critical bug (wrong students getting free tickets)
and add admin-controlled prefix-based pricing.

--- STEP 1: Fix the free ticket bug ---

In GetEnrollmentLookupActionPublic.php:
- Remove ALL batch-year range logic (range_start, range_end)
- Add sanitization: strip non-digits from enrollment_no input
- Add LAYER 1: check student_roster for this event_id + enrollment_no
  If not found → return 404 with generic "not found" message
- Add LAYER 2: query free_ticket_prefixes table for this event_id
  Loop prefixes, check str_starts_with(enrollmentNo, prefix)
  If match → is_free = true
  Else → is_free = false, price from event_settings.ticket_price_paise
- Return: { status, name, masked_email, email, is_free, price_paise, price_display }

In ClaimFreeTicketAction.php:
- Add same prefix re-validation INSIDE DB::transaction() before issuing ticket
- If prefix check fails → abort(402, 'Payment required')
- Keep lockForUpdate() on student_roster row

--- STEP 2: New migration ---
Create migration: 2026_05_26_000001_create_free_ticket_prefixes_table.php
CREATE TABLE free_ticket_prefixes (
  id BIGSERIAL PRIMARY KEY,
  event_id BIGINT NOT NULL,
  prefix VARCHAR(20) NOT NULL,
  label VARCHAR(100),
  created_by BIGINT,
  created_at TIMESTAMP DEFAULT NOW(),
  UNIQUE(event_id, prefix)
);

Create migration: 2026_05_26_000002_add_price_columns_to_event_settings.php
ALTER TABLE event_settings
  ADD COLUMN IF NOT EXISTS ticket_price_paise INT DEFAULT 20000,
  ADD COLUMN IF NOT EXISTS price_updated_at TIMESTAMP,
  ADD COLUMN IF NOT EXISTS price_updated_by BIGINT;

--- STEP 3: Admin endpoints ---

Create these 4 files under Actions/Organisers/Events/:

1. ListFreeTicketPrefixesAction.php
   GET /api/auth/organiser/{org}/events/{event}/free-prefixes
   Returns all prefixes for this event

2. CreateFreeTicketPrefixAction.php
   POST /api/auth/organiser/{org}/events/{event}/free-prefixes
   Body: { prefix: "220", label: "Final Year 2022" }
   Validate: prefix is numeric string, 3–10 chars
   Check overlap with existing prefixes before inserting

3. DeleteFreeTicketPrefixAction.php
   DELETE /api/auth/organiser/{org}/events/{event}/free-prefixes/{id}
   Soft-delete by event ownership check first

4. UpdateTicketPriceAction.php
   PATCH /api/auth/organiser/{org}/events/{event}/ticket-price
   Body: { price: 250 } (rupees, convert to paise × 100)
   Update event_settings.ticket_price_paise

Register all 4 routes in backend/routes/api.php under auth organiser group.

--- STEP 4: Seed initial free prefix ---
In EnrollmentTestSeeder.php, add:
DB::table('free_ticket_prefixes')->upsert([
  ['event_id' => 1, 'prefix' => '220', 'label' => 'Final Year 2022 Batch',
   'created_at' => now()]
], ['event_id', 'prefix'], ['label']);

--- STEP 5: Tighten rate limits ---
In routes/api.php:
- enrollment-lookup, update-email, resend-ticket: throttle:10,1
- claim-free, initiate-order: throttle:5,1 (tighter)

Run php artisan migrate --force
Run php artisan test — all tests must pass
Report any test failures with the exact error message.
```

---

## Part 7 — Testing After Implementation

```bash
# 1. Test a junior student (should be ₹200, NOT free)
curl -X POST https://vic.codepode.in/api/public/events/5/enrollment-lookup \
  -H "Content-Type: application/json" \
  -d '{"enrollment_no": "2401126897963"}'
# Expected: { "is_free": false, "price_paise": 20000, "price_display": "₹200" }

# 2. Test a final year student (should be free)
curl -X POST https://vic.codepode.in/api/public/events/5/enrollment-lookup \
  -H "Content-Type: application/json" \
  -d '{"enrollment_no": "2201126897963"}'
# Expected: { "is_free": true, "price_paise": 0 }

# 3. Test a roll no not in roster (should be blocked)
curl -X POST https://vic.codepode.in/api/public/events/5/enrollment-lookup \
  -H "Content-Type: application/json" \
  -d '{"enrollment_no": "9999999999"}'
# Expected: 404 { "status": "not_found" }

# 4. Test tampered free claim (roll no exists but is NOT a free prefix)
curl -X POST https://vic.codepode.in/api/public/events/5/enrollment/claim-free \
  -H "Content-Type: application/json" \
  -d '{"enrollment_no": "2401126897963", "email": "test@test.com"}'
# Expected: 402 { "message": "Payment required" }

# 5. Test rate limit
for i in {1..6}; do
  echo -n "Request $i: "
  curl -s -o /dev/null -w "%{http_code}\n" \
    -X POST https://vic.codepode.in/api/public/events/5/enrollment/claim-free \
    -H "Content-Type: application/json" \
    -d '{"enrollment_no":"2201126897963","email":"x@x.com"}'
done
# Expected: first 5 = 200 or 409, 6th = 429
```

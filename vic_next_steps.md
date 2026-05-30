# VIC Farewell — Implementation Next Steps
**Base:** Hi.Events fork | **Deploy target:** `vic.codepode.in` via Coolify + Cloudflare Tunnel

---

## ✅ Already Done (Phase 1 — Backend Foundation)

| Item | File | Status |
|---|---|---|
| Student roster DB table | `database/migrations/..._create_student_roster_table.php` | ✅ Done |
| Enrollment pricing rules table | `database/migrations/..._create_enrollment_pricing_rules_table.php` | ✅ Done |
| Roll-number extraction config in event_settings | `database/migrations/..._add_rollno_config_to_event_settings.php` | ✅ Done |
| Public enrollment lookup endpoint | `app/Http/Actions/Enrollment/Public/GetEnrollmentLookupActionPublic.php` | ✅ Done |
| Route registration | `routes/api.php` | ✅ Done |

**What the lookup endpoint does:**
- Accepts `enrollment_no` + `event_id`
- Extracts batch year using configurable prefix length from `event_settings`
- Matches against `enrollment_pricing_rules` (server-side price, never client)
- Returns masked email (`ay***@gmail.com`), student name, `is_free` flag, computed price
- Blocks if `has_purchased = true` with resend option

---

## 🔲 Phase 2 — Razorpay Payment Wiring
**Priority: CRITICAL | Estimated: 2–3 days**

### 2.1 — Razorpay Payment Driver (Backend)
**File to create:** `backend/app/Services/Payments/Drivers/RazorpayDriver.php`

Must implement the existing `PaymentDriverInterface`. Key methods:
```php
public function createOrder(int $amountPaise, string $enrollmentNo, int $eventId): array
// POST https://api.razorpay.com/v1/orders
// Store razorpay_order_id in orders table before returning to frontend

public function verifyPayment(array $webhookPayload, string $signature): bool
// HMAC-SHA256 verify: hash_hmac('sha256', $payload, $webhookSecret)
// NEVER trust client-reported payment success

public function handleWebhook(Request $request): Response
// Listen for payment.captured event only
// On success → set has_purchased = true (inside DB transaction)
//           → trigger QR generation
//           → trigger ticket email via Brevo
```

**File to modify:** `backend/app/Providers/AppServiceProvider.php`
```php
// Register the driver
$this->app->bind('razorpay', RazorpayDriver::class);
```

**New DB columns needed on `orders` table:**
```sql
ALTER TABLE orders ADD COLUMN razorpay_order_id VARCHAR(100);
ALTER TABLE orders ADD COLUMN razorpay_payment_id VARCHAR(100);
ALTER TABLE orders ADD COLUMN payment_verified_at TIMESTAMP;
```

**New migration:** `database/migrations/..._add_razorpay_columns_to_orders_table.php`

---

### 2.2 — Razorpay Webhook Route
**File to modify:** `backend/routes/api.php`
```php
// Add outside auth middleware group (Razorpay calls this, not the student)
Route::post('/webhooks/razorpay', RazorpayWebhookAction::class)
     ->middleware('throttle:60,1');
```

**File to create:** `backend/app/Http/Actions/Webhooks/RazorpayWebhookAction.php`

Logic:
1. Verify `X-Razorpay-Signature` header — reject immediately if invalid (return 400)
2. Parse `payment.captured` event
3. Look up `orders` row by `razorpay_order_id`
4. Inside `DB::transaction()`:
   - Set `orders.payment_verified_at = now()`
   - Set `student_roster.has_purchased = true` for this `enrollment_no`
   - Generate QR token (Hi.Events native UUID — do not change this)
5. Dispatch `SendTicketEmailJob` (reuse Hi.Events existing mail job)
6. Return `200 OK` (Razorpay retries on anything else)

---

### 2.3 — Free Ticket Fast Path (Seniors)
**File to create:** `backend/app/Http/Actions/Enrollment/Public/ClaimFreeTicketAction.php`

When `is_free = true`:
1. Re-validate pricing rule server-side (don't trust the frontend's claim)
2. Re-check `has_purchased = false` (race condition guard)
3. Inside `DB::transaction()`:
   - Insert into `orders` with `amount = 0`, `payment_method = 'complimentary'`
   - Set `has_purchased = true`
   - Generate QR token
4. Dispatch `SendTicketEmailJob`

Route: `POST /api/pub/events/{event}/enrollment/claim-free`

---

### 2.4 — Razorpay Checkout UI (Frontend)
**File to create:** `frontend/src/components/enrollment/RazorpayCheckout.tsx`

```tsx
// Load Razorpay script dynamically (not bundled — PCI compliance)
// On mount: call backend POST /enrollment/initiate-order → get razorpay_order_id
// Open Razorpay modal with: key, order_id, amount, name, email (from lookup)
// On payment success handler: show "Payment received, sending your ticket..." UI
// Do NOT call any backend endpoint on client success — webhook handles everything
```

**Important:** The success handler in the Razorpay modal is UX-only (spinner + message). The actual ticket issuance happens via webhook, not the client callback. This prevents payment bypass.

---

## 🔲 Phase 3 — Admin: Excel Import & Roster Management
**Priority: HIGH | Estimated: 2 days**

### 3.1 — Excel Import API (Backend)
**File to create:** `backend/app/Http/Actions/Organisers/Events/ImportStudentRosterAction.php`

```
POST /api/auth/organiser/{organiser}/events/{event}/roster/import
Content-Type: multipart/form-data
Body: file (xlsx/csv), event_id
```

Logic:
1. Validate file: must be `.xlsx` or `.csv`, max 5MB
2. Parse with `PhpSpreadsheet` — expected columns: `enrollment_no`, `name`, `email`, `phone`
3. Validate each row: enrollment_no required, email format if present
4. **Upsert** (not blind insert): `INSERT ... ON CONFLICT (event_id, enrollment_no) DO UPDATE`
5. Return import summary: `{ imported: 950, updated: 30, skipped: 20, errors: [{row: 5, reason: "..."}] }`

**Composer dependency to add:**
```bash
composer require phpoffice/phpspreadsheet
```

---

### 3.2 — Pricing Rule Manager API (Backend)
**Files to create:**
```
app/Http/Actions/Organisers/Events/Pricing/
  ├── GetPricingRulesAction.php       GET  /pricing-rules
  ├── CreatePricingRuleAction.php     POST /pricing-rules
  ├── UpdatePricingRuleAction.php     PUT  /pricing-rules/{rule}
  └── DeletePricingRuleAction.php     DELETE /pricing-rules/{rule}
```

Each write action must:
- Check organiser owns the event (existing Hi.Events policy middleware)
- Write an audit log row: `who`, `action`, `old_value (JSON)`, `new_value (JSON)`, `timestamp`

**New table:**
```sql
CREATE TABLE pricing_rule_audit_log (
  id         BIGSERIAL PRIMARY KEY,
  rule_id    BIGINT,
  event_id   BIGINT,
  user_id    BIGINT,
  action     VARCHAR(20),      -- 'created' | 'updated' | 'deleted'
  old_value  JSONB,
  new_value  JSONB,
  created_at TIMESTAMP
);
```

---

### 3.3 — Admin UI (Frontend)

**Files to create:**
```
frontend/src/pages/organiser/events/
  ├── StudentRoster/
  │   ├── ImportRosterPage.tsx       -- drag-drop xlsx upload + import summary table
  │   └── RosterTablePage.tsx        -- searchable table: 🔴 Not purchased | 🟢 Purchased | 🔵 Scanned
  └── PricingRules/
      └── PricingRulesPage.tsx       -- CRUD table for pricing rules + audit log viewer
```

**ImportRosterPage** must show:
- Drag-drop zone for xlsx/csv
- Column mapping preview before final import
- After import: row-by-row error report (highlight failed rows)
- Re-upload updates existing records (upsert — safe to run multiple times)

**RosterTablePage** must show:
- Columns: Enrollment No | Name | Email | Batch | Ticket Status | Scan Count
- Color coding: 🔴 Not purchased | 🟢 Purchased | 🔵 Checked in
- Export button → CSV with all columns including `razorpay_payment_id`

---

## 🔲 Phase 4 — Student Lookup Portal (Frontend)
**Priority: HIGH | Estimated: 1.5 days**

**File to create:** `frontend/src/pages/public/EnrollmentLookup/`
```
EnrollmentLookupPage.tsx     -- the main page at vic.codepode.in/
EnrollmentLookupForm.tsx     -- enrollment no input + submit
StudentDetailsCard.tsx       -- shows name, batch, masked email + editable email field
PricingBadge.tsx             -- "🎉 Senior — Free Entry" or "🎟 Student Ticket — ₹200"
```

**Flow states the UI must handle:**

```
IDLE          → User sees enrollment no input box
LOADING       → Spinner (API call in flight)
FOUND_FREE    → StudentDetailsCard + PricingBadge (free) + "Confirm & Get Ticket" button
FOUND_PAID    → StudentDetailsCard + PricingBadge (₹200) + "Pay & Get Ticket" button
ALREADY_DONE  → "Ticket already issued. Didn't get email? [Resend]" + disabled form
NOT_FOUND     → "Enrollment number not found. Contact VIC." (no details exposed)
ERROR         → Generic error with retry
```

**Editable email field rules:**
- Pre-filled from roster data (if available)
- Always editable — student can correct before confirming
- On change: frontend calls `PATCH /enrollment/update-email` with new email
- Backend updates `student_roster.email` only if ticket not yet purchased

---

## 🔲 Phase 5 — QR Scan Limit Enforcement
**Priority: HIGH | Estimated: 1 day**

### 5.1 — Scan Counter in Check-in API (Backend)
**File to modify:** existing Hi.Events check-in action (locate via `grep -r "check.in" backend/routes/`)

Add before recording a scan:
```php
$scanCount = CheckIn::where('ticket_id', $ticket->id)->count();
$maxScans  = $event->settings->max_scans_per_ticket ?? 1;

if ($scanCount >= $maxScans) {
    return response()->json([
        'status'  => 'exhausted',
        'message' => 'All scans used for this ticket',
        'scanned' => $scanCount,
        'max'     => $maxScans,
    ], 409);
}

// Record scan with slot label
CheckIn::create([
    'ticket_id'  => $ticket->id,
    'scan_slot'  => $scanCount + 1,             // 1, 2, 3
    'slot_label' => $scanLabels[$scanCount],     // "Entry", "Food Counter"
    'scanned_at' => now(),
    'scanned_by' => auth()->id(),
]);
```

**New event_settings columns:**
```sql
ALTER TABLE event_settings ADD COLUMN max_scans_per_ticket INT DEFAULT 1;
ALTER TABLE event_settings ADD COLUMN scan_slot_labels JSONB DEFAULT '["Entry"]';
-- Example: '["Entry Gate", "Food Counter", "Dessert Counter"]'
```

### 5.2 — Check-in Portal UI (Frontend)
**File to modify:** existing Hi.Events check-in scanner page

After scan, overlay must show one of:
```
✅ VALID    — "Scan 2 of 3 · Food Counter"    [green full-screen flash]
❌ EXHAUSTED — "All 3 scans used"              [red full-screen flash]
⛔ INVALID  — "Unknown or fake QR code"        [red full-screen flash]
```
Flash must auto-dismiss after 2.5 seconds so gate staff can scan the next person quickly.

---

## 🔲 Phase 6 — Security Hardening
**Priority: HIGH | Estimated: 0.5 day**

All items below must be done before go-live:

```php
// Rate limiting (add to routes/api.php)
Route::middleware(['throttle:10,1'])   // 10 req/min per IP
     ->group(function() {
         Route::post('/enrollment/lookup', ...);
         Route::post('/enrollment/claim-free', ...);
     });

// Razorpay webhook: signature verify before ANY processing
$sig = $request->header('X-Razorpay-Signature');
$expected = hash_hmac('sha256', $request->getContent(), config('razorpay.webhook_secret'));
abort_if(!hash_equals($expected, $sig), 400);

// Free ticket race condition guard (optimistic lock)
DB::transaction(function() use ($enrollmentNo, $eventId) {
    $student = StudentRoster::where('enrollment_no', $enrollmentNo)
                            ->where('event_id', $eventId)
                            ->lockForUpdate()   // ← prevents double-claim
                            ->firstOrFail();
    abort_if($student->has_purchased, 409, 'Already purchased');
    $student->update(['has_purchased' => true]);
});
```

**Checklist before go-live:**
- [ ] `APP_KEY`, `JWT_SECRET` rotated from defaults in `.env`
- [ ] `RAZORPAY_WEBHOOK_SECRET` set in `.env` (from Razorpay dashboard)
- [ ] Enrollment lookup rate-limited (10 req/min/IP)
- [ ] `has_purchased` only set inside `lockForUpdate()` transaction
- [ ] Razorpay webhook signature verified before processing
- [ ] Free-ticket re-validated server-side at claim time (not just lookup)
- [ ] Admin import route protected by organiser auth middleware
- [ ] QR tokens are Hi.Events native UUIDs (never expose sequential IDs)
- [ ] HTTPS enforced via Cloudflare Tunnel (already in place)
- [ ] Webhook route excluded from CSRF middleware (add to `VerifyCsrfToken` exceptions)

---

## 🔲 Phase 7 — Deployment
**Priority: FINAL | Estimated: 0.5 day**

```bash
# 1. Build custom Docker image from fork
docker build -f Dockerfile.all-in-one -t vic-hi-events:latest .

# 2. Push to local registry or use Coolify GitHub integration
# Coolify → Source → point to your forked repo → auto-build

# 3. Run migrations inside container
docker exec -it <container> php artisan migrate --force

# 4. Set environment variables in Coolify UI
APP_URL=https://vic.codepode.in
RAZORPAY_KEY_ID=rzp_live_xxxx
RAZORPAY_KEY_SECRET=xxxx
RAZORPAY_WEBHOOK_SECRET=xxxx
MAIL_HOST=smtp.brevo.com          # already configured at VIC
MAIL_PORT=587

# 5. Add Cloudflare Tunnel rule
# vic.codepode.in → 192.168.29.200:8123 (or whatever port Coolify assigns)

# 6. Register vic.codepode.in in Razorpay dashboard
# Account & Settings → Business Website Details → Add Additional Website
```

---

## Summary Table

| Phase | What | Days | Blocker? |
|---|---|---|---|
| ✅ 1 | Backend foundation (lookup endpoint, migrations) | Done | — |
| 🔲 2 | Razorpay driver + webhook + free-ticket path | 2–3 | Razorpay account ready |
| 🔲 3 | Admin Excel import + pricing rule manager | 2 | — |
| 🔲 4 | Student lookup portal (frontend) | 1.5 | Phase 2 API must exist |
| 🔲 5 | QR scan limit enforcement | 1 | — |
| 🔲 6 | Security hardening | 0.5 | Must be before go-live |
| 🔲 7 | Deployment to vic.codepode.in | 0.5 | Razorpay domain approved |
| | **Total remaining** | **~8–9 days** | |

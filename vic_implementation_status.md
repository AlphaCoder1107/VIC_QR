# VIC Farewell — Implementation Status & Remaining Work
**Stack:** Hi.Events fork (Laravel + React/TypeScript) | **Target:** `vic.codepode.in`
**Last updated after:** Razorpay webhook slice completed

---

## Legend
| Symbol | Meaning |
|---|---|
| ✅ | Complete |
| 🟡 | Partially done — schema/backend in, but missing pieces |
| 🔲 | Not started |

---

## ✅ Complete — Phase 1: Backend Foundation

| File | What It Does |
|---|---|
| `migrations/..._create_student_roster_table.php` | Stores imported student data, `has_purchased` flag |
| `migrations/..._create_enrollment_pricing_rules_table.php` | Batch-year-to-price rules, priority ordering |
| `migrations/..._add_rollno_config_to_event_settings.php` | Configurable prefix-length for batch extraction |
| `Actions/Enrollment/Public/GetEnrollmentLookupActionPublic.php` | Public lookup: masks email, computes price server-side, blocks repeat purchases |
| `routes/api.php` | Lookup route registered |

---

## 🟡 Partial — Phase 2: Razorpay Payment Wiring

### ✅ Done in this session

| File | What It Does |
|---|---|
| `Actions/Webhooks/RazorpayWebhookAction.php` | Verifies `X-Razorpay-Signature`, processes `payment.captured` only, marks order `COMPLETED`, flips `has_purchased = true`, dispatches existing ticket-email job |
| `config/services.php` | Razorpay `key_id`, `key_secret`, `webhook_secret` config keys added |
| `migrations/..._add_razorpay_columns_to_orders_table.php` | `razorpay_order_id`, `razorpay_payment_id`, `payment_verified_at` columns |
| `migrations/..._add_scan_limit_settings_to_event_settings.php` | `max_scans_per_ticket`, `scan_slot_labels` columns added |
| `routes/api.php` | Webhook route registered (outside CSRF + auth middleware) |

---

### 🔲 Still Remaining in Phase 2

#### 2A — Razorpay Order Initiation Endpoint
**File to create:** `Actions/Enrollment/Public/InitiateRazorpayOrderAction.php`
**Route:** `POST /api/pub/events/{event}/enrollment/initiate-order`

This is what the frontend calls **before** opening the Razorpay modal. Without it the student has no `order_id` to pass to Razorpay's JS.

```php
// Logic:
// 1. Accept enrollment_no + event_id
// 2. Re-validate pricing rule server-side (never trust frontend price)
// 3. Abort if has_purchased = true (duplicate purchase guard)
// 4. Call Razorpay Orders API → POST https://api.razorpay.com/v1/orders
//    Body: { amount: $pricePaise, currency: "INR", receipt: "VIC-{$enrollmentNo}" }
// 5. Store razorpay_order_id in a pending orders row
// 6. Return { razorpay_order_id, amount_paise, key_id } to frontend
// NEVER return key_secret to frontend
```

#### 2B — Free Ticket Fast Path
**File to create:** `Actions/Enrollment/Public/ClaimFreeTicketAction.php`
**Route:** `POST /api/pub/events/{event}/enrollment/claim-free`

For seniors (batch 22) where `is_free = true`. Bypasses Razorpay entirely.

```php
// Logic:
// 1. Re-validate pricing rule server-side — is_free must still be true
// 2. DB::transaction() with lockForUpdate() on student_roster row
// 3. Abort if has_purchased = true (race condition guard)
// 4. Insert order row: amount = 0, payment_method = 'complimentary'
// 5. Set has_purchased = true
// 6. Generate QR (reuse Hi.Events native UUID token path)
// 7. Dispatch existing ticket-email job
// 8. Return { status: 'issued', message: 'Check your email' }
```

#### 2C — Razorpay Checkout UI
**File to create:** `frontend/src/components/enrollment/RazorpayCheckout.tsx`

```
Flow:
  Mount → call POST /enrollment/initiate-order → receive razorpay_order_id
  Open Razorpay modal (load script dynamically, not bundled)
  On modal success → show spinner + "Payment received, sending your ticket…"
  ⚠️  Do NOT call any backend on client success — webhook handles issuance
  On modal failure → show retry UI
```

Key: the client success handler is **UX only**. The webhook does the real work. This is the anti-bypass architecture.

---

## 🔲 Phase 3 — Admin: Excel Import & Pricing Rules
**Estimated: 2 days**

### 3A — Excel Import API
**File to create:** `Actions/Organisers/Events/ImportStudentRosterAction.php`
**Route:** `POST /api/auth/organiser/{organiser}/events/{event}/roster/import`

```
Steps:
1. Validate: .xlsx or .csv only, max 5 MB
2. Parse with PhpSpreadsheet (add to composer.json)
3. Expected columns: enrollment_no | name | email | phone
4. Upsert by (event_id, enrollment_no) — safe to re-upload
5. Return: { imported: N, updated: N, skipped: N, errors: [{row, reason}] }
```

Add dependency:
```bash
composer require phpoffice/phpspreadsheet
```

### 3B — Pricing Rule CRUD API
**Files to create:**
```
Actions/Organisers/Events/Pricing/
  ├── GetPricingRulesAction.php       GET    /events/{event}/pricing-rules
  ├── CreatePricingRuleAction.php     POST   /events/{event}/pricing-rules
  ├── UpdatePricingRuleAction.php     PUT    /events/{event}/pricing-rules/{rule}
  └── DeletePricingRuleAction.php     DELETE /events/{event}/pricing-rules/{rule}
```

Every write must append to `pricing_rule_audit_log`:
```sql
CREATE TABLE pricing_rule_audit_log (
  id         BIGSERIAL PRIMARY KEY,
  rule_id    BIGINT,
  event_id   BIGINT,
  user_id    BIGINT,
  action     VARCHAR(20),   -- 'created' | 'updated' | 'deleted'
  old_value  JSONB,
  new_value  JSONB,
  created_at TIMESTAMP
);
```

### 3C — Admin UI Pages
**Files to create:**
```
frontend/src/pages/organiser/events/
  ├── StudentRoster/
  │   ├── ImportRosterPage.tsx      drag-drop upload + column preview + error report
  │   └── RosterTablePage.tsx       searchable table + status badges + CSV export
  └── PricingRules/
      └── PricingRulesPage.tsx      CRUD table + audit log viewer
```

**RosterTablePage status badges:**
- 🔴 Not purchased
- 🟢 Purchased (shows `razorpay_payment_id`)
- 🔵 Checked in (shows scan count `2/3`)

---

## 🔲 Phase 4 — Student Lookup Portal (Frontend)
**Estimated: 1.5 days**

**Files to create:** `frontend/src/pages/public/EnrollmentLookup/`

```
EnrollmentLookupPage.tsx   — route: vic.codepode.in/
EnrollmentLookupForm.tsx   — enrollment no input field
StudentDetailsCard.tsx     — name, batch year, masked + editable email
PricingBadge.tsx           — "🎉 Senior — Free Entry" | "🎟 Student Ticket — ₹200"
```

**All UI states to implement:**

| State | Trigger | What student sees |
|---|---|---|
| `IDLE` | Page load | Enrollment number input box only |
| `LOADING` | After submit | Spinner |
| `FOUND_FREE` | `is_free = true` | Details card + free badge + "Confirm & Get Ticket" |
| `FOUND_PAID` | `is_free = false` | Details card + price badge + Razorpay checkout |
| `ALREADY_DONE` | `has_purchased = true` | "Ticket already issued. [Resend Email]" |
| `NOT_FOUND` | No roster match | "Not found. Contact VIC." — no details exposed |
| `ERROR` | Network/server error | Generic retry message |

**Editable email logic:**
- Pre-filled from roster, always editable
- On change → `PATCH /enrollment/update-email` (only allowed if `has_purchased = false`)
- Backend updates `student_roster.email` before payment proceeds

---

## 🟡 Partial — Phase 5: QR Scan Limit Enforcement
**Schema done. API + UI not started. Estimated: 1 day**

### ✅ Done
- `max_scans_per_ticket` column on `event_settings`
- `scan_slot_labels` JSONB column on `event_settings`

### 🔲 Remaining

#### 5A — Enforce Limit in Check-in API
**File to modify:** existing Hi.Events check-in action
```
Find with: grep -r "check.in\|CheckIn\|check_in" backend/routes/api.php
```

Add before recording scan:
```php
$scanCount = CheckIn::where('ticket_id', $ticket->id)->count();
$maxScans  = $event->settings->max_scans_per_ticket ?? 1;
$labels    = $event->settings->scan_slot_labels ?? ['Entry'];

if ($scanCount >= $maxScans) {
    return response()->json([
        'status'  => 'exhausted',
        'scanned' => $scanCount,
        'max'     => $maxScans,
    ], 409);
}

CheckIn::create([
    'ticket_id'  => $ticket->id,
    'scan_slot'  => $scanCount + 1,
    'slot_label' => $labels[$scanCount] ?? 'Scan ' . ($scanCount + 1),
    'scanned_by' => auth()->id(),
]);
```

Also add `scan_slot` and `slot_label` columns to `check_ins` table:
```sql
ALTER TABLE check_ins ADD COLUMN scan_slot  INT DEFAULT 1;
ALTER TABLE check_ins ADD COLUMN slot_label VARCHAR(100) DEFAULT 'Entry';
```

#### 5B — Check-in Portal UI
**File to modify:** existing Hi.Events check-in scanner page

Replace current result overlay with:
```
✅ green flash  "Scan 2 of 3 · Food Counter"    (auto-dismiss 2.5s)
❌ red flash    "All scans used"                 (auto-dismiss 2.5s)
⛔ red flash    "Invalid QR"                     (auto-dismiss 2.5s)
```

---

## 🔲 Phase 6 — Security Hardening
**Estimated: 0.5 day — Must complete before go-live**

```php
// routes/api.php — rate limit enrollment routes
Route::middleware(['throttle:10,1'])->group(function () {
    Route::post('/enrollment/lookup',      ...);
    Route::post('/enrollment/initiate-order', ...);
    Route::post('/enrollment/claim-free',  ...);
});

// app/Http/Middleware/VerifyCsrfToken.php — exclude webhook
protected $except = [
    'api/webhooks/razorpay',
];

// ClaimFreeTicketAction — race condition guard
$student = StudentRoster::where('enrollment_no', $enrollmentNo)
    ->where('event_id', $eventId)
    ->lockForUpdate()       // ← prevents simultaneous double-claim
    ->firstOrFail();
abort_if($student->has_purchased, 409);
```

**Go-live checklist:**
- [ ] `APP_KEY` and `JWT_SECRET` rotated from dev defaults
- [ ] `RAZORPAY_WEBHOOK_SECRET` set in `.env` (copy from Razorpay dashboard)
- [ ] `RAZORPAY_KEY_ID` switched from `rzp_test_` to `rzp_live_` prefix
- [ ] Enrollment lookup rate-limited (10 req/min/IP)
- [ ] `has_purchased` always set inside `lockForUpdate()` transaction
- [ ] Razorpay webhook signature verified before any DB write
- [ ] Free-ticket rule re-validated at claim time (not just at lookup)
- [ ] `api/webhooks/razorpay` excluded from CSRF middleware
- [ ] Admin import routes protected by organiser auth middleware
- [ ] `vic.codepode.in` registered in Razorpay dashboard as additional website
- [ ] HTTPS confirmed active on Cloudflare Tunnel

---

## 🔲 Phase 7 — Deployment to vic.codepode.in
**Estimated: 0.5 day**

```bash
# Build custom image from fork
docker build -f Dockerfile.all-in-one -t vic-hi-events:latest .

# Coolify: point deployment source to forked repo
# or push to local Docker registry and reference from Coolify

# Run migrations after deploy
docker exec -it <container_id> php artisan migrate --force

# Environment variables to set in Coolify UI
APP_URL=https://vic.codepode.in
RAZORPAY_KEY_ID=rzp_live_xxxx
RAZORPAY_KEY_SECRET=xxxx
RAZORPAY_WEBHOOK_SECRET=xxxx
MAIL_MAILER=smtp
MAIL_HOST=smtp.brevo.com
MAIL_PORT=587
MAIL_USERNAME=<brevo-login>
MAIL_PASSWORD=<brevo-smtp-key>
MAIL_FROM_ADDRESS=events@vic.college

# Cloudflare Tunnel rule
vic.codepode.in → 192.168.29.200:<coolify-assigned-port>

# Razorpay dashboard
Account & Settings → Business Website Details
→ Add Additional Website → vic.codepode.in
→ Submit for review (1–2 business days)
```

---

## Full Status Summary

| Phase | Description | Status | Days Left |
|---|---|---|---|
| 1 | Backend foundation (lookup, roster, pricing schema) | ✅ Done | 0 |
| 2A | Razorpay webhook + order columns | ✅ Done | 0 |
| 2B | Razorpay order initiation endpoint | ✅ Done | 0 |
| 2C | Free ticket fast path | ✅ Done | 0 |
| 2D | Razorpay checkout UI (frontend) | ✅ Done | 0 |
| 3 | Admin: Excel import + pricing rule manager | 🔲 Not started | 2 |
| 4 | Student lookup portal (frontend) | ✅ Done | 0 |
| 5A | Scan limit check-in API enforcement | 🟡 Schema only | 0.5 |
| 5B | Check-in portal scan UI | 🔲 Not started | 0.5 |
| 6 | Security hardening | 🔲 Not started | 0.5 |
| 7 | Deployment + Razorpay domain approval | 🔲 Not started | 0.5 |
| | **Total remaining** | | **~4.0 days** |

---

## Recommended Next Action

The critical path is now **Phase 3 (Admin: Excel Import & Pricing Rules)** and **Phase 5 (QR Scan Limit Enforcement)**. Since the student ticketing flow is fully verified and connected end-to-end, adding the roster importing capabilities and configuring scan limits will complete the core portal capabilities.

# VIC Farewell — Hi.Events Customization Specification
**Vidya Innovation Centre | Vidya University, Kerala**
**Base Repo:** `HiEventsDev/Hi.Events` (Laravel + React/TypeScript, AGPL-3.0)

---

## 1. What Hi.Events Already Gives You (Out of the Box)

| Requirement | Native Support | Notes |
|---|---|---|
| QR code ticket generation | ✅ | PDF tickets with embedded QR |
| QR check-in portal | ✅ | Mobile-optimised check-in tool |
| Scan logs per attendee | ✅ | Full audit trail |
| Admin dashboard (sales, tickets) | ✅ | Real-time analytics |
| CSV/XLSX export of attendees | ✅ | Full attendee data export |
| Email ticket delivery | ✅ | Configurable SMTP (Brevo works) |
| Capacity limits | ✅ | Per-ticket-type limits |
| Custom checkout questions | ✅ | Can add enrollment no field |
| Multi-role access (Admin / Check-in Staff) | ✅ | Admin, Organiser, Check-in Staff roles |
| Self-hosted Docker deployment | ✅ | Already running on your Coolify server |

---

## 2. Gaps — Features That Need Custom Development

### GAP 1 — Razorpay Payment Gateway
**Priority: CRITICAL**
Hi.Events natively supports **Stripe and offline payments only**. Razorpay is not built in.

**What to build:**
- A custom Razorpay payment driver inside Hi.Events' `PaymentDriver` abstraction (`backend/app/Services/Payments/`)
- Implement `createOrder` → Razorpay Order API, `verifyPayment` → Razorpay signature verification (HMAC-SHA256)
- Webhook handler for `payment.captured` and `payment.failed` events
- Store `razorpay_order_id` and `razorpay_payment_id` in the `orders` table (migration needed)

**Files to modify:**
```
backend/app/Services/Payments/     ← add RazorpayPaymentDriver.php
backend/app/Providers/             ← register driver
backend/routes/api.php             ← add /webhook/razorpay route
frontend/src/components/checkout/  ← replace Stripe Elements with Razorpay Checkout.js
```

---

### GAP 2 — Enrollment Number Allowlist & Student Lookup Portal
**Priority: CRITICAL**
This is the most significant custom module. Hi.Events has open public registration. You need a **closed, enrollment-gated** flow.

**What to build:**

#### 2a. Admin: Excel Import of Student Roster
- New admin page: **"Import Attendee Allowlist"**
- Accepts `.xlsx` / `.csv` upload (columns: `enrollment_no`, `name`, `email`, `phone`, `branch`, `year`)
- Parses with PhpSpreadsheet (already a Laravel-ecosystem library)
- Stores in a new `student_roster` table (event-scoped)
- Duplicate enrollment_no check on import, shows conflict report
- Supports re-upload / update (upsert by enrollment_no)

**New DB table:**
```sql
CREATE TABLE student_roster (
  id            BIGSERIAL PRIMARY KEY,
  event_id      BIGINT REFERENCES events(id),
  enrollment_no VARCHAR(50) NOT NULL,
  name          VARCHAR(200),
  email         VARCHAR(200),
  phone         VARCHAR(20),
  metadata      JSONB,          -- branch, year, etc.
  has_purchased BOOLEAN DEFAULT false,
  created_at    TIMESTAMP,
  UNIQUE(event_id, enrollment_no)
);
```

#### 2b. Student-Facing Lookup Portal
- Separate public route: `/e/{event_slug}/enroll-lookup`
- Input: Enrollment Number only (no account needed, like an exam portal)
- On submit → backend validates enrollment_no against `student_roster`
- If found → returns masked record: name, branch, year, and **partially masked email** (e.g., `ay***@gmail.com`)
- If already purchased → shows "Ticket already purchased" with a resend option
- If not found → "Enrollment number not found. Contact VIC."

#### 2c. Editable Email Field
- After enrollment lookup, the student sees their pre-filled details
- Email field is **editable** (pre-filled from roster, but changeable)
- On change → student enters new email → backend updates `student_roster.email` for that record before proceeding to payment
- This handles cases where your imported data has wrong/missing emails

---

### GAP 3 — One-Ticket-Per-Enrollment Enforcement
**Priority: CRITICAL (Security)**
Without this, a student could theoretically purchase multiple tickets.

**What to build:**
- Before order creation, backend checks: `student_roster WHERE enrollment_no = ? AND event_id = ? AND has_purchased = true`
- If true → block with HTTP 409 "Ticket already issued to this enrollment number"
- Set `has_purchased = true` atomically inside a DB transaction on successful Razorpay webhook (not on payment initiation)
- The QR token is generated **only after** payment confirmation webhook — never on order creation

**Security notes:**
- Enrollment lookup endpoint must be rate-limited (max 10 requests/IP/minute via Laravel's `throttle` middleware)
- QR tokens must be signed (Hi.Events already uses UUIDs; keep that, don't expose sequential IDs)
- Razorpay signature must be verified server-side before `has_purchased` is set — client cannot self-report success

---

### GAP 4 — Roll Number Based Dynamic Pricing
**Priority: CRITICAL**
Hi.Events has fixed prices per ticket type. You need a rule engine that assigns a price — including ₹0 — based on the student's roll number range, configurable by admin at any time, **even mid-event**.

#### 4a. Pricing Rules Table
```sql
CREATE TABLE enrollment_pricing_rules (
  id            BIGSERIAL PRIMARY KEY,
  event_id      BIGINT REFERENCES events(id),
  rule_name     VARCHAR(100),          -- e.g. "Seniors (220+)", "2nd Year"
  range_start   INT NOT NULL,          -- e.g. 220
  range_end     INT,                   -- NULL = open-ended (220 and above)
  price_paise   INT NOT NULL,          -- stored in paise: 0 = free, 20000 = ₹200
  is_free       BOOLEAN DEFAULT false, -- true = skip payment entirely
  priority      INT DEFAULT 0,         -- higher = evaluated first (for overlapping ranges)
  active        BOOLEAN DEFAULT true,
  updated_at    TIMESTAMP
);
```
Example rows for your current setup:
| rule_name | range_start | range_end | price | is_free |
|---|---|---|---|---|
| Seniors (Batch 220+) | 220 | NULL | ₹0 | true |
| 2nd/3rd Year | 1 | 219 | ₹200 | false |

#### 4b. Rule Evaluation at Lookup Time
When a student enters their enrollment number:
1. Backend extracts the **numeric prefix** from enrollment_no (e.g. `VIC22045` → `220`, or `22045` → `220` — configurable extraction pattern per event)
2. Queries `enrollment_pricing_rules` for the active rule whose range covers that number, ordered by `priority DESC`
3. Returns matched rule: `{ price: 0, is_free: true, label: "Senior — Free Entry" }` or `{ price: 200, is_free: false, label: "Student Ticket — ₹200" }`
4. The checkout UI renders accordingly:
   - If `is_free = true` → shows **"🎉 Free Entry — Seniors Batch"**, a confirm button, **no payment step at all**, ticket issued directly
   - If `is_free = false` → shows Razorpay payment for the exact computed amount

#### 4c. Admin Pricing Rule Manager
New admin section: **"Pricing Rules"** (per event)
- Table of current rules with Edit / Disable / Add buttons
- Fields: Rule Name, Roll No Range Start, Range End (leave blank for open-ended), Price (₹), Free entry toggle
- **Changes take effect immediately** — no redeploy, no cache flush needed (DB-driven, not config-driven)
- Audit log: every rule change records who changed it, old value, new value, timestamp

#### 4d. Mid-Event Price Change Handling
- Students who already initiated a Razorpay order but haven't paid yet: their order holds the **price at time of order creation** (safe, Razorpay order amount is locked)
- New sessions after the rule change will get the new price
- Admin dashboard shows a warning banner: **"Pricing rule updated X minutes ago — outstanding unpaid orders use old price"**
- Optionally: admin can invalidate all unpaid orders older than N minutes (forces students to restart)

#### 4e. Security Guards
- Price is **always computed server-side** from the pricing rule — the client never sends a price
- Razorpay order is created by the backend with the server-computed amount; frontend passes only `enrollment_no` and `event_id`
- Free-ticket issuance (`is_free = true`) has its own server-side guard: re-validates the rule right before issuing QR, not just at lookup time

---

### GAP 5 — Configurable QR Scan Limit (Entry + Food Voucher)
**Priority: HIGH**
Hi.Events records scan logs but does not enforce or count scan limits per ticket.

**What to build:**

#### 4a. Admin Setting
- New event-level setting: **"Maximum scans per ticket"**
- Options: `1` (entry only) | `2` (entry + exit or entry + food) | `3` (entry + food + dessert counter) | `Unlimited`
- Stored in `event_settings` JSON column

#### 4b. Check-in Enforcement
- Modify the check-in API (`POST /api/check-in/`) to:
  1. Count existing scans for this ticket from `check_ins` table
  2. Compare against `max_scans` setting
  3. If `scan_count >= max_scans` → return `{"status": "exhausted", "message": "All scans used for this ticket"}`
  4. Otherwise → record scan with a `scan_type` label (configurable: "Entry", "Food", "Exit")
- Check-in portal UI shows scan count: **"Scan 2 of 3 — Food Counter"**

#### 4c. Scan Type Labels (Optional but useful)
- Admin can label each scan slot: Scan 1 = "Entry Gate", Scan 2 = "Food Counter", Scan 3 = "Dessert Counter"
- Check-in app shows which slot is being consumed in real-time

---

### GAP 6 — Admin Roster & Sales Dashboard Enhancements
**Priority: MEDIUM**
The native analytics are good but need roster-specific additions.

**What to add:**
- **Roster coverage view:** Table showing all imported students, color-coded: 🔴 Not purchased | 🟢 Purchased | 🔵 Ticket scanned
- **Bulk export** with roster data joined to order data (enrollment_no, name, payment_id, scan count, scan timestamps)
- **Manual override:** Admin can mark a student as "Complimentary" (bypasses payment, directly issues QR) — useful for student org members

---

## 3. Architecture Overview

```
┌────────────────────────────────────────────────────────────┐
│                    PUBLIC STUDENT FLOW                     │
│                                                            │
│  /enroll-lookup  →  Enrollment No Input                    │
│       ↓                                                    │
│  Student Details + Editable Email Shown                    │
│       ↓                                                    │
│  Razorpay Checkout (client JS)                             │
│       ↓                                                    │
│  Razorpay Webhook → Signature Verify → Mark Purchased      │
│       ↓                                                    │
│  QR Ticket Email sent via Brevo SMTP                       │
└────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────┐
│                    ADMIN FLOW                              │
│                                                            │
│  Import Excel → student_roster table                       │
│  Monitor sales dashboard (native Hi.Events + enhancements) │
│  Export full data (CSV with enrollment data joined)        │
│  Set max_scans per event                                   │
└────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────┐
│                    GATE / FOOD COUNTER FLOW                │
│                                                            │
│  Check-in Staff login (Hi.Events native role)              │
│  Scan QR → API validates + counts scans                    │
│  Shows: ✅ VALID (Scan 2/3 - Food Counter)                  │
│       or ❌ USED (All 3 scans exhausted)                    │
│       or ⛔ INVALID (Fake/tampered QR)                      │
└────────────────────────────────────────────────────────────┘
```

---

## 4. Tech Stack Summary

| Layer | Technology |
|---|---|
| Backend | PHP 8.2 / Laravel (existing Hi.Events) |
| Frontend | React + TypeScript (existing Hi.Events) |
| Database | PostgreSQL (existing Hi.Events) |
| Payment | Razorpay (custom driver) |
| Email | Brevo SMTP (already configured at VIC) |
| Hosting | Coolify on `192.168.29.200` (existing VIC server) |
| Domain | Cloudflare Tunnel → `events.vic.college` (existing) |
| Excel Parsing | PhpSpreadsheet (Laravel) |

---

## 5. Development Effort Estimate

| Module | Effort | Who Builds |
|---|---|---|
| Razorpay Payment Driver (backend) | 2–3 days | Backend dev / you |
| Razorpay Checkout UI (frontend) | 1 day | Frontend dev / you |
| Student Roster DB + Import API | 1–2 days | Backend dev |
| Admin Import UI (Excel upload) | 1 day | Frontend dev |
| Enrollment Lookup Portal (public) | 1–2 days | Full-stack |
| Editable email + security guards | 0.5 day | Backend |
| Pricing rule engine (backend) | 1 day | Backend |
| Pricing rule admin UI | 1 day | Frontend |
| Free-ticket fast path (no payment) | 0.5 day | Full-stack |
| Scan limit enforcement | 1 day | Backend + Frontend |
| Roster-joined admin dashboard | 1 day | Full-stack |
| **Total** | **~11–15 days** | |

---

## 6. Critical Security Checklist

- [ ] Price always computed server-side — client never sends an amount
- [ ] Free-ticket guard re-validated at issuance, not just at lookup
- [ ] Pricing rule audit log (who changed, when, old vs new value)
- [ ] Razorpay order amount locked at creation — mid-event changes don't affect in-flight payments
- [ ] Razorpay webhook signature verified server-side (never trust client)
- [ ] `has_purchased` set inside DB transaction, only on confirmed payment
- [ ] Enrollment lookup endpoint rate-limited
- [ ] QR tokens are Hi.Events native UUIDs (not guessable)
- [ ] No enrollment lookup endpoint exposes full email (mask it)
- [ ] Admin import restricted to `ADMIN` role only
- [ ] Scan count check is atomic (race condition on simultaneous scans at gate)
- [ ] HTTPS enforced via Cloudflare Tunnel (already in place)

---

## 7. Deployment Note

Since Hi.Events is already running on your Coolify server at `events.vic.college` via the `daveearley/hi.events-all-in-one` image, you'll need to **fork the repo**, make the above modifications, and **build a custom Docker image** to replace the upstream one. Coolify supports pointing to a custom Dockerfile or a private registry — you can build and push to a local Docker registry or directly reference your forked repo via Coolify's GitHub integration.

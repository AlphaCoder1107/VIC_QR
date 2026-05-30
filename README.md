# 🎟️ VIC Farewell & Event Ticketing Portal

A high-performance, roster-gated event ticketing, payment, and QR-based multi-scan check-in system. Built on a hardened, custom fork of **Hi.Events** (Laravel + React + TypeScript + PostgreSQL + Redis) specifically tailored for college farewells and large institutional events.

---

## 🌟 Key Custom Features & Architecture

This portal extends the open-source Hi.Events core with custom enterprise features designed to govern ticketing for secure environments:

*   **🎓 Student Roster Gatekeeping:** Upload student lists via Excel/CSV. Only students registered on the roster can look up details, register, or initiate ticket purchases.
*   **💳 Automated Batch-Based Pricing:** Define rules matching student enrollment prefix patterns (e.g. enrollment numbers starting with `22` get complimentary senior tickets; patterns like `19`, `20`, `21` map to paid junior tickets).
*   **⚡ Razorpay E2E Wiring:** 
    *   **Frontend Checkout:** Open-source Razorpay JS loaded dynamically.
    *   **Order Initiation:** Server-side API validates calculations, checks double-purchase limits, calls the Razorpay Orders API (`POST /orders`), and returns the secure order ID to the client.
    *   **Secure Webhook confirmation:** Webhook listens for `payment.captured` directly from Razorpay, cryptographically checks the signature via `X-Razorpay-Signature`, updates database state, generates a ticket QR UUID, and queues email delivery.
*   **🍔 Multi-Scan QR Gating:** Configurable scanner portal allowing tickets to be scanned multiple times with custom labels (e.g. Scan 1: "Entry Gate", Scan 2: "Food Counter", Scan 3: "Dessert Counter") with visual state checks (Valid, Invalid, Exhausted).
*   **🔒 Rate Limiting & Lock-for-Update:** Lookups are limited to 10 requests/minute per IP to prevent directory harvesting. Free ticket claims execute inside a `DB::transaction()` with `lockForUpdate()` on the roster row to block race conditions.

---

## 📁 Repository Structure

Below is the directory mapping highlighting the customized logic files:

```
VIC_QR/
├── DEPLOYMENT_GUIDE.md                         # Detailed system setup guide
├── README.md                                   # This repository readme
├── checkin_backfill_fix.md                     # Maintenance scripts & prompts
├── hardened_pricing_impl.md                    # Core logic walkthroughs
├── roster_upload_workflow.md                   # Roster details and guidelines
├── vic_farewell_spec.md                        # Product requirements
├── vic_implementation_status.md                # Task tracking sheets
├── vic_next_steps.md                           # Operational next steps
│
└── Hi.Events/                                  # Main Application Source Code
    ├── Dockerfile.all-in-one                   # Production Docker image compiler
    │
    ├── docker/
    │   ├── all-in-one/                         # Production Compose files
    │   │   ├── docker-compose.yml
    │   │   └── scripts/startup.sh              # Container startup & auto-migration
    │   └── development/                        # Dev environment Compose files
    │       ├── docker-compose.dev.yml
    │       └── start-dev.sh                    # SSL & local runtime launcher
    │
    ├── backend/                                # Laravel PHP API
    │   ├── app/Http/Actions/
    │   │   ├── Enrollment/Public/              # Custom student checkout APIs
    │   │   │   ├── ClaimFreeTicketAction.php        # Complimentary tickets
    │   │   │   ├── GetEnrollmentLookupActionPublic.php # Lookup portal backend
    │   │   │   ├── InitiateRazorpayOrderAction.php  # Razorpay order creator
    │   │   │   ├── ResendEnrollmentTicketAction.php # Ticket email resender
    │   │   │   ├── UpdateEnrollmentEmailAction.php # Email updater
    │   │   │   └── VerifyRazorpayPaymentAction.php  # Signature verification
    │   │   │
    │   │   ├── Organisers/Events/               # Custom Admin Roster APIs
    │   │   │   ├── ImportStudentRosterAction.php   # Excel parser & upsert
    │   │   │   ├── GetStudentRosterAction.php      # Roster lists & statistics
    │   │   │   └── DeleteStudentRosterAction.php   # Roster resets
    │   │   │
    │   │   └── Webhooks/
    │   │       └── RazorpayWebhookAction.php        # Live payment listener
    │   │
    │   ├── app/Console/Commands/
    │   │   ├── BackfillMissingAttendeesCommand.php  # Attendee sync tool
    │   │   └── CompleteRazorpayOrderCommand.php     # Manual order completion
    │   │
    │   ├── database/migrations/                     # Roster, pricing, and scan database extensions
    │   │   ├── 2026_05_25_000001_create_student_rosters_table.php
    │   │   ├── 2026_05_25_000002_create_enrollment_pricing_rules_table.php
    │   │   ├── 2026_05_25_000004_add_razorpay_columns_to_orders_table.php
    │   │   └── 2026_05_25_000005_add_scan_limit_settings_to_event_settings.php
    │   │
    │   └── tests/Feature/Http/Actions/              # Custom PHPUnit test suites
    │       ├── StudentRosterTest.php
    │       ├── PricingRulesTest.php
    │       └── VerifyRazorpayPaymentTest.php
    │
    └── frontend/                               # React + Vite + TypeScript Client
        └── src/
            ├── components/enrollment/
            │   └── RazorpayCheckout.tsx        # Razorpay UI component
            ├── pages/
            │   ├── public/EnrollmentLookup/    # Student lookup interface
            │   └── organiser/events/StudentRoster/ # Admin roster table & excel importer
            └── router.tsx                      # Client-side routes
```

---

## 📈 System Working Status

| Module | Features | Status | Notes |
|---|---|---|---|
| **Phase 1: Backend Foundation** | Student lookup, pricing rules db schema, roll extraction logic. | **✅ Complete** | Covered by `GetEnrollmentLookupActionPublic.php`. |
| **Phase 2: Razorpay E2E** | Order generation, signature checks, checkout component, webhook dispatcher. | **✅ Complete** | Covered by `InitiateRazorpayOrderAction` and `RazorpayWebhookAction`. |
| **Phase 3: Admin Roster** | Spreadsheet parser, SQL Upsert command, Admin table. | **✅ Complete** | UI at `StudentRoster/index.tsx`, backend in `ImportStudentRosterAction`. |
| **Phase 4: Lookup Portal** | Public landing form, masked emails, conditional action buttons. | **✅ Complete** | Component located in `public/EnrollmentLookup/index.tsx`. |
| **Phase 5: Scan Limits** | Configurable check-in caps, label logging, scan warnings. | **✅ Complete** | Database support added (`max_scans_per_ticket`). API enforces scan limits. |
| **Phase 6: Hardening** | IP throttlers, lock-for-update transitions, signature check safeguards. | **✅ Complete** | Rate limiters applied on enrollment routes. Webhook verifies signature. |
| **Phase 7: Deployment** | Docker config, Cloudflare daemon setup, production caches. | **✅ Documented**| Detailed runbook provided in [DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md). |

---

## 🛠️ System Requirements & Dependencies

*   **Operating System:** macOS (macOS 14+ recommended), Linux (Ubuntu 22.04 LTS+ recommended), or Windows 11 with WSL2.
*   **Containers:** Docker Engine 24.0.0+ / Docker Compose 2.20.0+.
*   **Composer (Local Dev only):** PHP 8.3+ and Composer 2.6+.
*   **Node (Local Dev only):** Node.js v22.0.0+ and Yarn package manager.
*   **Third-party accounts required:**
    *   **Razorpay Account:** To retrieve API keys (Live/Test) and register webhooks.
    *   **Brevo (SMTP):** SMTP credentials for sending HTML tickets.
    *   **Cloudflare Account:** Required for setup of free SSL / Cloudflare Tunnel.

---

## 🚀 Step-by-Step Deployment Instructions

### 1. Configure the Environmental Variables
Navigate to `/Hi.Events/docker/all-in-one` and copy the env layout:
```bash
cd Hi.Events/docker/all-in-one
cp .env.example .env
```
Fill out the variables inside `.env`:
*   `APP_KEY`: Create a 32-character key using `openssl rand -base64 32`.
*   `JWT_SECRET`: Create a strong unique token for admin logins.
*   `APP_URL`: Domain target (e.g. `https://vic.codepode.in`).
*   `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD`: Database auth details.
*   `RAZORPAY_KEY_ID` & `RAZORPAY_KEY_SECRET`: Razorpay keys.
*   `RAZORPAY_WEBHOOK_SECRET`: Key to sign incoming Razorpay payment hooks.
*   `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`: Brevo credentials.

### 2. Launch the Application Containers
Spin up the compilation and application containers:
```bash
docker compose up --build -d
```
Verify all containers (Postgres, Redis, and All-in-One) are healthy:
```bash
docker compose ps
```

### 3. Initialize & Seed Database
Ensure migrations are executed. The `startup.sh` script handles this, but to manually trigger and verify:
```bash
# Force migration of custom tables
docker compose exec all-in-one php artisan migrate --force

# Confirm our custom tables (student_rosters, pricing_rules, etc.) show as "Ran"
docker compose exec all-in-one php artisan migrate:status
```

---

### 4. Setup Cloudflare Tunnel (`cloudflared`)
To securely expose the application running on port `8123` to `https://vic.codepode.in`:

1.  **Install Cloudflared:**
    *   *macOS:* `brew install cloudflare/cloudflare/cloudflared`
    *   *Linux:* Download `.deb` or `.rpm` package from Cloudflare's release page and install.
2.  **Authenticate Client:**
    ```bash
    cloudflared tunnel login
    ```
3.  **Create your Tunnel:**
    ```bash
    cloudflared tunnel create vic-farewell
    ```
4.  **Edit the Tunnel Configuration (`~/.cloudflared/config.yml`):**
    ```yaml
    tunnel: vic-farewell
    credentials-file: /Users/<user>/.cloudflared/<tunnel-uuid>.json

    ingress:
      - hostname: vic.codepode.in
        service: http://localhost:8123
      - service: http_status:404
    ```
5.  **Route DNS:**
    ```bash
    cloudflared tunnel route dns vic-farewell vic.codepode.in
    ```
6.  **Run as background service:**
    ```bash
    sudo cloudflared service install
    # Or start in background
    nohup cloudflared tunnel run vic-farewell > ~/cloudflared.log 2>&1 &
    ```

---

### 5. Webhook Setup on Razorpay
1.  Navigate to **Razorpay Dashboard → Account & Settings → Webhooks**.
2.  Add Webhook URL: `https://vic.codepode.in/api/webhooks/razorpay`.
3.  Set Webhook Secret to match `RAZORPAY_WEBHOOK_SECRET` in `.env`.
4.  Check `payment.captured` as the active event. Click save.
5.  Register `vic.codepode.in` under **Business Website Details → Add Additional Website**.

---

### 6. Administrative Configurations
Once deployed:
1.  Go to `https://vic.codepode.in/auth/register` to register your administrator account.
2.  Create your main farewell event in the dashboard.
3.  Go to **Student Roster** → **Import Roster** and upload your `.xlsx` student file.
4.  Go to **Pricing Rules** and add rules:
    *   Pattern `22` (Seniors) → Price: `0`
    *   Patterns `19, 20, 21` (Juniors/Seniors) → Price: `200`
5.  Go to **Event Settings** → Configure `max_scans_per_ticket` (e.g. `2` for Gate + Food) and write custom scan slot labels: `["Entry Gate", "Food Counter"]`.

---

## 🛠️ Maintenance & Troubleshooting Commands

### Clear/Cache Config changes:
```bash
docker compose exec all-in-one php artisan config:cache
docker compose exec all-in-one php artisan route:cache
```

### Inspect Runtime logs:
```bash
# Docker console logs
docker compose logs all-in-one -f

# Laravel specific logs
docker compose exec all-in-one tail -f /app/backend/storage/logs/laravel.log
```

### Complete a Pending Payment Manually:
If a webhook fails due to networks, complete it via Artisan CLI:
```bash
docker compose exec all-in-one php artisan razorpay:complete <order_id>
```

# 🎟️ VIC Farewell & Event Ticketing Portal

A high-performance, roster-gated event ticketing, payment, and QR-based multi-scan check-in system. Built on a hardened, custom fork of **Hi.Events** (Laravel + React + TypeScript + PostgreSQL + Redis) specifically tailored for college farewells and large institutional events.

---

## 🌟 Key Custom Features

This portal extends the open-source Hi.Events core with enterprise-grade features designed for student ticketing:

*   **🎓 Student Roster Gatekeeping:** Upload student lists via Excel/CSV. Only students on the roster can register, look up their details, or purchase tickets.
*   **💳 Automated Batch-Based Pricing:** Configure pricing rules using student enrollment prefix patterns (e.g., Seniors get free entry, while juniors are automatically redirected to checkout).
*   **⚡ Razorpay Integration:** End-to-end payment wiring. Frontend initiates order, Razorpay checkout modal opens, and a secure signature-verified backend webhook manages the real ticket generation and database state on success.
*   **🍔 Multi-Scan QR Gating:** Tickets can be scanned multiple times for separate items/activities (e.g., Scan 1: Entry Gate, Scan 2: Food Counter, Scan 3: Dessert Bar) with customizable labels.
*   **🔒 Security Hardened:** 
    *   Lookups and claims are rate-limited to prevent brute-forcing student directories.
    *   Database transactions utilize optimistic lock-for-update queries to prevent double-claiming free tickets via simultaneous requests.
    *   All payment processing is strictly signature-validated on the backend server.

---

## 📁 Repository Structure

```
VIC_QR/
├── DEPLOYMENT_GUIDE.md        # Full production & development setup guide
├── Hi.Events/                 # Main application source code
│   ├── backend/               # Laravel PHP API backend & queue workers
│   ├── frontend/              # React (Vite & TypeScript) single-page app
│   └── docker/                # Docker compose configurations (Dev & Production)
├── checkin_backfill_fix.md    # Script prompts for backend corrections
├── hardened_pricing_impl.md   # Architectural overview of pricing logic
├── roster_upload_workflow.md  # Detailed admin import guide
└── vic_farewell_spec.md       # Product specifications and wireframe flows
```

---

## 🚀 Quick Start (Production)

The quickest way to get the portal running is using our **All-in-One Docker image** which compiles the React frontend and runs PHP-FPM, Nginx, and Supervisor inside a single container, linked with PostgreSQL and Redis.

### 1. Clone the Repository
```bash
git clone https://github.com/AlphaCoder1107/VIC_QR.git
cd VIC_QR
```

### 2. Configure Environment
Navigate to the Docker deployment directory:
```bash
cd Hi.Events/docker/all-in-one
cp .env.example .env
```
Open `.env` and fill out your database settings, mail servers, and Razorpay API credentials:
*   `APP_KEY`: Base64 encryption key (run `openssl rand -base64 32`)
*   `RAZORPAY_KEY_ID` & `RAZORPAY_KEY_SECRET`: Live Razorpay credentials
*   `MAIL_USERNAME` & `MAIL_PASSWORD`: Brevo/SMTP login credentials

### 3. Spin up Containers
```bash
docker compose up --build -d
```

### 4. Verify Services
The backend database migrations will run automatically on startup. You can check the container logs and verify migration success:
```bash
docker compose logs all-in-one -f
docker compose exec all-in-one php artisan migrate:status
```
The application will be running locally at `http://localhost:8123`.

For deep-dive setup instructions (including SSL certificates, production reverse proxy configurations, and **Cloudflare Tunnels**), refer to the [DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md).

---

## 🛠️ Tech Stack

*   **Backend Framework:** Laravel (PHP 8.3)
*   **Frontend Framework:** React 18, TypeScript, Vite
*   **Database:** PostgreSQL 17 (relational storage)
*   **Queue & Cache:** Redis 7 (processing ticket email queues)
*   **Process Manager:** Supervisor (manages PHP-FPM, Nginx, and Queue Workers)
*   **Domain & SSL:** Cloudflare Tunnel (`cloudflared` client)
*   **Payments:** Razorpay API v1
*   **Transactional Emails:** Brevo SMTP (via Laravel Mail)

---

## 📖 Detailed Guides & Spec Documentation

If you are developing or maintaining this project, check out these detailed technical documentations included in this repository:
*   **[Deployment Guide](DEPLOYMENT_GUIDE.md):** Complete documentation on production deployment, development scripts, and Cloudflare setup.
*   **[Roster Upload & Gating Specs](roster_upload_workflow.md):** Information regarding importing rosters, Excel layouts, and student validation.
*   **[Hardened Pricing & Razorpay Logic](hardened_pricing_impl.md):** Details on payment flows, webhook signature checking, and ticket claim security.
*   **[Checkin & QR Scan Gating](checkin_backfill_fix.md):** Documentation detailing how QR codes are limited, checked, and scanned.

---

## 📄 License
This project is built as a custom implementation on top of the open-source **Hi.Events** platform. See the [Hi.Events/LICENCE](Hi.Events/LICENCE) file for original license details.

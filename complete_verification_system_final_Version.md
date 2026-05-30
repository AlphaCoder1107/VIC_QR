# VIC Farewell — Native Verification System Final Version
**Built natively into Hi.Events — no n8n, no external tools, no extra Cloudflare tunnels**
**Status: FULLY IMPLEMENTED ✅**

---

## 🚀 Final Architecture (Native, Unified)

```
Faculty Excel Files (CSE, AIML, DS)         Google Form Responses (CSV/Excel)
          │                                              │
          ▼                                              ▼
   Student Roster Tab → Import                Verification Tab → Import
(Populates `student_rosters` table)         (Populates `payment_verifications` table)
          │                                              │
          └──────────────────────┬───────────────────────┘
                                 │
                                 ▼
                     Real-Time Cross-Validation (Laravel DB join)
             ✅ VALID / ❌ NOT IN ROSTER    (enrollment check)
             ✅ MATCHES / ⚠️ CHECK NAME     (first name fuzzy check)
             ⚠️ ALREADY ISSUED              (duplicate prevention)
                                 │
                        ┌────────┴────────┐
                      VERIFY            REJECT
                        │                  │
         Creates Order + Attendee    Marks REJECTED
         Sets has_purchased = true   Saves rejection note
                        │
             Brevo sends QR ticket email
                        │
             Gate scan at vic.codepode.in/check-in ✅
```

---

## What Was Built vs What Was Planned

| Component | Originally Planned | What Was Actually Built | Status |
|---|---|---|---|
| **Faculty Master Roster** | Merge into Google Sheet | Upload directly via Roster Import tab → `student_rosters` table | **✅ Better** |
| **B.Tech Form Responses** | Google Sheets + formulas | Upload CSV/Excel via Verification Dashboard tab | **✅ Better** |
| **BCA Form Responses** | Google Sheets + formulas | Same — separate upload, same dashboard | **✅ Better** |
| **Cross-validation** | VLOOKUP formulas in Sheets | Real-time Laravel DB join (backend, not spreadsheet) | **✅ Better** |
| **n8n Container** | New Docker service on port 5679 | Replaced by native Laravel controllers | **✅ Bypassed** |
| **Verification Dashboard** | Static HTML page on port 8090 | Native tab inside Student Roster admin page | **✅ Better** |
| **Email Dispatch** | n8n HTTP request to Hi.Events | Native `OrderStatusChangedEvent` → Brevo SMTP | **✅ Better** |
| **Extra Cloudflare Tunnel** | `verify.vic.codepode.in` | Not needed — lives inside existing admin panel | **✅ Bypassed** |

---

## 🗄️ Database Tables Schema

### 1. `student_rosters` Table
Stores the official faculty roster records used to validate student details.
* `id` (BIGINT PRIMARY KEY)
* `event_id` (BIGINT NOT NULL REFERENCES events)
* `enrollment_no` (VARCHAR(50))
* `name` (VARCHAR(200))
* `email` (VARCHAR(200) NULL)
* `phone` (VARCHAR(100) NULL) -- *Increased from 20 to 100 to prevent truncation errors*
* `has_purchased` (BOOLEAN DEFAULT false)
* `purchased_at` (TIMESTAMP NULL)

### 2. `payment_verifications` Table
Stages the uploads of Google Form responses for manual review.
* `id` (BIGINT PRIMARY KEY)
* `event_id` (BIGINT NOT NULL INDEX)
* `source` (VARCHAR(20)) -- `'btech'` or `'bca'`
* `name` (VARCHAR(200))
* `enrollment_no` (VARCHAR(50))
* `email` (VARCHAR(200))
* `phone` (VARCHAR(100) NULL)
* `transaction_id` (VARCHAR(100) NULL)
* `payment_method` (VARCHAR(50) NULL)
* `screenshot_url` (TEXT NULL)
* `status` (VARCHAR(20) DEFAULT `'PENDING'`) -- `'PENDING'`, `'VERIFIED'`, `'REJECTED'`
* `notes` (TEXT NULL)

---

## 🛠️ Backend Architecture (Actions)

Registered in `backend/routes/api.php`:
```
backend/app/Http/Actions/Organisers/Events/
  ├── ImportStudentRosterAction.php
  │     Imports the official faculty excel roster files.
  │
  ├── GetStudentRosterAction.php
  │     Lists roster entries with purchase status.
  │
  ├── DeleteStudentRosterAction.php
  │     DELETE /roster/{id}
  │     Deletes roster entries instantly on a single click.
  │
  └── PaymentVerifications/
        ├── ImportPaymentVerificationsAction.php
        │     POST /payment-verifications/import
        │     Parses Google Form CSV/Excel and auto-maps headers.
        │
        ├── ListPaymentVerificationsAction.php
        │     GET /payment-verifications
        │     Performs a real-time join with `student_rosters` to return validation badges.
        │
        ├── VerifyPaymentSubmissionAction.php
        │     POST /payment-verifications/{id}/verify
        │     Natively creates order, attendees, and marks student as purchased.
        │
        └── RejectPaymentSubmissionAction.php
              POST /payment-verifications/{id}/reject
              Saves rejection note and flags entry as rejected.
```

---

## 💻 Frontend Client & Dashboard

All routes registered in `frontend/src/api/roster.client.ts`.

Implemented in `frontend/src/pages/organiser/events/StudentRoster/index.tsx`:
* **Tab 1: Import Roster**: For uploading official Excel roster files.
* **Tab 2: View Roster**: Lists students with a **Single-Click Delete Button** to clean up database records.
* **Tab 3: Pricing & Prefixes**: To change ticket prices and configure free ticket prefixes.
* **Tab 4: Verification Dashboard**:
  * Segments: **B.Tech CSE/AIML/DS** and **BCA**.
  * Auto-maps Google Form responses (Google Drive CSV export).
  * Real-time cross-validation badge:
    * `✅ VALID` or `❌ NOT IN ROSTER`
    * `✅ MATCHES` or `⚠️ CHECK NAME`
    * `⚠️ ALREADY ISSUED` (to prevent duplicates)
  * Action blocks: One-click **Verify** or **Reject** with custom reasons.

---

## 📋 Complete Event-Week Workflow

1. **Roster Setup**:
   * Go to **Import Roster** tab.
   * Upload CSE, AIML, and DS roster files.
   
2. **Importing Google Form Responses**:
   * Go to **Verification Dashboard** tab.
   * Select sub-tab (B.Tech or BCA).
   * Export the Google Sheet responses as CSV, then upload.

3. **Verifying Payments**:
   * Filter list by `PENDING`.
   * Check validation badges.
   * Open your UPI app and verify the `Transaction ID`.
   * Click **Verify** (Ticket emailed via Brevo instantly).

4. **Gate Entry**:
   * Gate volunteers scan students using `vic.codepode.in/check-in` on any device.

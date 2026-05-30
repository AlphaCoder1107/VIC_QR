# Student Roster Upload — Full Workflow & Implementation Guide
**Feature:** Admin Excel/CSV import for enrollment-gated ticket purchasing
**Supports:** Unlimited students (N rows), safe re-uploads, mid-event updates

---

## 1. Where It Lives

### Sidebar Entry (Hi.Events Admin)
```
GUEST MANAGEMENT
  👥 Attendees          [2]
  ☑  Check-In Lists
  📋 Student Roster      ← NEW — add here
  💬 Messages
  ⏳ Waitlist
  🧑‍🤝‍🧑 Capacity Management
```

**Route:** `/manage/event/{event_id}/student-roster`
**Access:** Organiser role and above only (existing Hi.Events auth middleware)

---

## 2. The Exact File Format Students' Data Must Follow

### Required Schema
```
File types accepted: .xlsx  |  .xls  |  .csv
Max file size:       50 MB  (supports 100,000+ rows comfortably)
Encoding:            UTF-8
First row:           Column headers (exact names below)
```

### Column Reference Table

| Column Name | Required | Example Value | Validation Rule |
|---|---|---|---|
| `enrollment_no` | ✅ YES | `22045` | Numeric string, min 4 digits, unique per event |
| `name` | ✅ YES | `Rahul Sharma` | Non-empty string, max 200 chars |
| `email` | ❌ Optional | `rahul@vidya.edu` | Valid email format if present; student can correct at checkout |
| `phone` | ❌ Optional | `9876543210` | 10-digit number if present |

### Batch Year Extraction Rule
```
Enrollment No:  2  2  0  4  5
                ↑  ↑
         First 2 digits → Batch year 22 → 2022 Batch
```
| First 2 Digits | Admitted | Current Year | Auto-Assigned Price |
|---|---|---|---|
| `22` | 2022 | Final Year (4th) | ₹0 — Free entry |
| `23` | 2023 | 3rd Year | ₹200 |
| `24` | 2024 | 2nd Year | ₹200 |
| `25` | 2025 | 1st Year | ₹200 |

This mapping comes from `enrollment_pricing_rules` table — admin can change prices anytime.

### Sample File Content (download from portal)
```csv
enrollment_no,name,email,phone
22045,Ananya Krishnan,ananya@vidya.edu,9876543210
22103,Rahul Sharma,rahul@vidya.edu,9123456780
21067,Priya Nair,priya@vidya.edu,9988776655
21200,Arun Menon,,9871234560
20034,Sneha Das,sneha@vidya.edu,9765432100
```
> Email intentionally blank for row 4 — student corrects it during checkout.

---

## 3. Upload Portal UI — Screen by Screen

### Screen 1: Landing State (no file yet)
```
┌─────────────────────────────────────────────────┐
│  📋 Student roster import                        │
│  Upload Excel/CSV to enable enrollment-gated     │
│  ticket purchasing                               │
├─────────────────────────────────────────────────┤
│  ℹ️  Only students in this roster can buy tickets │
│     First 2 digits of enrollment = batch year   │
├─────────────────────────────────────────────────┤
│                                                  │
│   ┌──────────────────────────────────────┐       │
│   │   ⬆                                 │       │
│   │   Drag & drop your file here         │       │
│   │   or click to browse                 │       │
│   │   .xlsx .xls .csv · max 50MB         │       │
│   │   [  Choose file  ]                  │       │
│   └──────────────────────────────────────┘       │
├─────────────────────────────────────────────────┤
│  Required file format            [⬇ Download     │
│                                   template]      │
│  ┌────────────┬──────────┬───────────┬────────┐  │
│  │ Column     │ Example  │ Note      │ Status │  │
│  ├────────────┼──────────┼───────────┼────────┤  │
│  │enrollment_ │ 22045    │ First 2   │Required│  │
│  │no          │          │ = batch   │        │  │
│  │name        │ Rahul S. │ Full name │Required│  │
│  │email       │ r@v.edu  │ Editable  │Optional│  │
│  │phone       │ 9876..   │ 10 digit  │Optional│  │
│  └────────────┴──────────┴───────────┴────────┘  │
│                                                  │
│  Preview — first 5 rows of template              │
│  ┌──────────┬─────────────┬──────────┬──────┐    │
│  │22045 🟢  │Ananya K.    │ananya@.. │98765 │    │
│  │22103 🟢  │Rahul S.     │rahul@.. │91234 │    │
│  │21067 🔵  │Priya N.     │priya@.. │99887 │    │
│  │21200 🔵  │Arun M.      │—        │98712 │    │
│  │20034 🔵  │Sneha D.     │sneha@.. │97654 │    │
│  └──────────┴─────────────┴──────────┴──────┘    │
│  🟢 Free (batch 22)  🔵 ₹200                     │
└─────────────────────────────────────────────────┘
```

### Screen 2: File Selected — Preview Before Import
```
┌─────────────────────────────────────────────────┐
│  📄 VIC_students_2024.xlsx            [✕ Remove] │
│     2.3 MB · 1,042 rows detected · Ready        │
├─────────────────────────────────────────────────┤
│  Preview — first 5 rows from your file          │
│  ┌──────────┬────────────┬───────────┬────────┐  │
│  │22045 🟢  │Ananya K.   │ananya@..  │9876..  │  │
│  │22103 🟢  │Rahul S.    │rahul@..   │9123..  │  │
│  │21067 🔵  │Priya N.    │priya@..   │9988..  │  │
│  │...       │            │           │        │  │
│  └──────────┴────────────┴───────────┴────────┘  │
│                                                  │
│  [  Choose different file  ]  [ Import roster →] │
└─────────────────────────────────────────────────┘
```

### Screen 3: Import In Progress
```
┌─────────────────────────────────────────────────┐
│                                                  │
│              ◌  (spinner)                        │
│         Importing roster…                        │
│   Validating enrollment numbers and              │
│   upserting records                              │
│                                                  │
└─────────────────────────────────────────────────┘
```

### Screen 4: Import Complete
```
┌─────────────────────────────────────────────────┐
│  ✅ Import complete                              │
│  Students can now purchase tickets using their   │
│  enrollment number.                              │
├─────────────────────────────────────────────────┤
│  ┌──────────┐ ┌──────────┐ ┌────────┐ ┌──────┐ │
│  │   847    │ │   23     │ │   3    │ │  2   │ │
│  │ Imported │ │ Updated  │ │Skipped │ │Errors│ │
│  └──────────┘ └──────────┘ └────────┘ └──────┘ │
├─────────────────────────────────────────────────┤
│  ⚠️  2 rows had errors — fix and re-upload       │
│  ┌──────────────────────────────────────────┐   │
│  │ Row 12  │ Invalid enrollment number       │   │
│  │ Row 47  │ Missing enrollment number       │   │
│  └──────────────────────────────────────────┘   │
├─────────────────────────────────────────────────┤
│  [ Import another file ]  [ View student roster ]│
└─────────────────────────────────────────────────┘
```

---

## 4. Backend Processing Logic — Step by Step

```
Admin uploads file
        │
        ▼
┌─────────────────────────────────────────────────────┐
│  STEP 1: File Validation                            │
│  • Extension must be .xlsx / .xls / .csv            │
│  • File size max 50 MB (enforced at Laravel level)  │
│  • File must not be empty                           │
│  If fails → return 422 with reason                  │
└───────────────────────────┬─────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────┐
│  STEP 2: Parse with PhpSpreadsheet                  │
│  • Read first row as headers (case-insensitive)     │
│  • Map columns: enrollment_no, name, email, phone   │
│  • Stream-read for large files (chunked iteration)  │
│  • Collect all rows into validated array            │
└───────────────────────────┬─────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────┐
│  STEP 3: Row-Level Validation                       │
│  For each row:                                      │
│  • enrollment_no: required, numeric, min 4 chars    │
│  • name: required, non-empty                        │
│  • email: optional, valid format if present         │
│  • phone: optional, 10 digits if present            │
│  → Collect errors with { row_number, reason }       │
│  → Valid rows proceed; error rows collected only    │
└───────────────────────────┬─────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────┐
│  STEP 4: Upsert into student_roster                 │
│  For each valid row:                                │
│  INSERT INTO student_roster                         │
│    (event_id, enrollment_no, name, email, phone)    │
│  ON CONFLICT (event_id, enrollment_no)              │
│  DO UPDATE SET                                      │
│    name = EXCLUDED.name,                            │
│    email = EXCLUDED.email,                          │
│    phone = EXCLUDED.phone                           │
│  WHERE has_purchased = false                        │
│  ← NEVER overwrite a student who already has        │
│     a ticket — their record is frozen               │
└───────────────────────────┬─────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────┐
│  STEP 5: Return Import Summary                      │
│  {                                                  │
│    imported:          N,   ← new rows created       │
│    updated:           N,   ← existing rows changed  │
│    skipped_purchased: N,   ← frozen (ticket issued) │
│    errors: [                                        │
│      { row: 12, enrollment_no: "21abc",             │
│        reason: "Invalid enrollment number" },       │
│      { row: 47, enrollment_no: "",                  │
│        reason: "Missing enrollment number" }        │
│    ]                                                │
│  }                                                  │
└─────────────────────────────────────────────────────┘
```

---

## 5. Re-Upload Rules (N-Times Import Safety)

The system is designed to be **re-uploaded as many times as needed** before and during the event:

| Scenario | What Happens |
|---|---|
| Upload same file twice | Upsert runs, counts as `updated` — no duplicates |
| Upload with corrected emails | Emails update for students who haven't paid yet |
| Upload with new students added | New rows inserted, existing untouched |
| Upload after some tickets issued | Paid students' rows are SKIPPED (frozen) — safe |
| Upload different file (full replacement) | Only new/changed data is affected — never deletes |
| Upload 5,000 rows | Processed in streaming chunks — no timeout |
| Upload 50,000 rows | Same — PhpSpreadsheet streams row-by-row |

> **Key rule:** The system NEVER deletes existing roster rows. It only adds or updates. Deletion must be done manually through the DB if ever needed.

---

## 6. What Happens After Upload — Student Purchase Flow

```
Roster uploaded with 1,000 students
          │
          ▼
Student visits vic.codepode.in
          │
          ▼
Enters enrollment number: "22045"
          │
          ▼
Backend checks student_roster:
  → Found? ✅ → Show student details
  → Not found? ❌ → "Contact VIC"
          │
          ▼
Batch year extracted: "22" → Seniors
Pricing rule matched: is_free = true
          │
     ┌────┴────┐
     │         │
  is_free    is_paid
  = true     = false
     │         │
     ▼         ▼
"Free Entry" "₹200 — Pay Now"
  button      Razorpay modal
     │         │
     └────┬────┘
          │
          ▼
Ticket issued → QR sent to email
student_roster.has_purchased = true
```

---

## 7. Large-Scale Import Performance

For events with 5,000–50,000 students:

### Laravel Backend Config
```php
// ImportStudentRosterAction.php

// Stream rows in chunks — never load all into memory
$spreadsheet->getActiveSheet()
    ->toArray(null, true, true, true);

// For very large files: use chunk reading
$reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
$reader->setReadDataOnly(true);

// DB: batch upsert in groups of 500
$chunks = array_chunk($validRows, 500);
foreach ($chunks as $chunk) {
    DB::table('student_roster')->upsert(
        $chunk,
        ['event_id', 'enrollment_no'],   // conflict keys
        ['name', 'email', 'phone']        // update columns
    );
}
```

### PHP / Server Config (set in Docker container)
```ini
; php.ini inside container
upload_max_filesize = 50M
post_max_size       = 52M
max_execution_time  = 300      ; 5 min for very large files
memory_limit        = 512M
```

```bash
# Apply inside running container
docker exec -it <backend_container> bash -c "
echo 'upload_max_filesize = 50M' >> /etc/php/8.2/cli/php.ini
echo 'post_max_size = 52M'       >> /etc/php/8.2/cli/php.ini
echo 'max_execution_time = 300'  >> /etc/php/8.2/cli/php.ini
echo 'memory_limit = 512M'       >> /etc/php/8.2/cli/php.ini
"
```

### Estimated Processing Times
| Rows | Estimated Time | Notes |
|---|---|---|
| 100 | < 1 second | Instant |
| 1,000 | 2–4 seconds | Normal event size |
| 5,000 | 10–20 seconds | Large event |
| 20,000 | 45–90 seconds | Show progress bar |
| 50,000 | 3–5 minutes | Queue job recommended |

For 20,000+ rows, offload to a **Laravel Queue Job** and show a "processing" state with email notification when done.

---

## 8. Agent Implementation Prompt

Paste this to the coding agent to implement the sidebar button and page:

```
Add "Student Roster" to the Hi.Events organiser sidebar and wire the page.

--- Step 1: Find the sidebar ---
grep -r "Check-In Lists\|CheckInLists\|check.in.lists" \
  frontend/src --include="*.tsx" -l

Open the file and locate the Guest Management section of the nav.
Add a new item between "Attendees" and "Check-In Lists":

  label: "Student Roster"
  icon: use the existing TableIcon or equivalent from the icon set in use
  href: `/manage/event/${eventId}/student-roster`

--- Step 2: Create the page ---
File: frontend/src/pages/organiser/events/StudentRoster/index.tsx

The page has two tabs:
  Tab 1 — "Import"   → renders the RosterUpload component
  Tab 2 — "View roster" → renders a searchable paginated table

Tab 2 table calls:
  GET /api/auth/organiser/{organiser}/events/{event}/roster
  Columns: Enrollment No | Name | Email | Batch | Status | Scans

Status color coding:
  🔴 Not purchased  (has_purchased = false)
  🟢 Purchased      (has_purchased = true, no check_ins)
  🔵 Checked in     (has check_in records)

--- Step 3: Register the route ---
Find the organiser event routes in frontend router and add:
  /manage/event/:eventId/student-roster → StudentRoster page

--- Step 4: Backend chunked upsert ---
In backend/app/Http/Actions/Organisers/Events/ImportStudentRosterAction.php
Replace any single DB insert with chunked upsert:

  $chunks = array_chunk($validRows, 500);
  foreach ($chunks as $chunk) {
      DB::table('student_roster')->upsert(
          $chunk,
          ['event_id', 'enrollment_no'],
          ['name', 'email', 'phone']
      );
  }

This handles N students safely without memory issues.

Run php artisan test — all 402 tests must still pass.
```

---

## 9. Error Handling Reference

| Error | Cause | How Admin Fixes It |
|---|---|---|
| `Invalid enrollment number format` | Letters/symbols in enrollment_no | Clean the column in Excel — numbers only |
| `Missing enrollment number` | Empty cell in enrollment_no column | Fill in all enrollment numbers |
| `Duplicate enrollment number` | Same enrollment_no appears twice in file | Remove duplicates in Excel before re-upload |
| `Invalid email format` | Malformed email (missing @, etc.) | Fix email or leave blank — student corrects it |
| `File too large` | Over 50 MB | Split into two files and import separately |
| `Unsupported file type` | Not .xlsx/.xls/.csv | Re-save from Excel as .xlsx |
| `No enrollment_no column found` | Headers missing or misspelled | First row must have exact column names |

---

## 10. Security Notes for Roster Data

- Import endpoint is protected by `auth:api` + organiser ownership check
- Roster data (emails, phones) is never returned to students — only the student's own record after enrollment lookup
- Enrollment lookup endpoint only returns masked email (`ay***@gmail.com`)
- `has_purchased = true` rows are permanently frozen from updates via import
- Full import audit: every import logs `{ user_id, event_id, timestamp, rows_imported }` for accountability

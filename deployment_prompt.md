# VIC Farewell — Deployment Agent Prompt
Paste this entire prompt into your AI coding agent to complete deployment.

---

## Context (Read First)

We have a fully built, fully tested custom fork of Hi.Events running locally in Docker on a Mac.
- 402 PHPUnit tests passing
- All backend features complete: enrollment gating, Razorpay payments, free senior tickets, QR scan limits, admin roster import, pricing rules
- Docker containers are already running locally (NOT on Coolify, NOT pushed to GitHub)
- The app needs to be exposed at `vic.codepode.in` via an existing Cloudflare Tunnel
- No GitHub push, no Coolify, no registry — direct Docker + Cloudflare only

---

## Task 1 — Discover the Running Container State

**Why:** Before touching anything we need to know the exact container name and port so every subsequent command targets the right container. Wrong container = broken app.

Run these and report the full output:
```bash
# List all running containers with their ports
docker ps --format "table {{.Names}}\t{{.Image}}\t{{.Ports}}\t{{.Status}}"

# Also check compose project if it was started with docker compose
docker compose ps 2>/dev/null || echo "No compose project detected"
```

From the output, identify:
1. The container name that is running the Laravel backend (look for php/laravel/backend in image name)
2. The host port it is mapped to (e.g. 0.0.0.0:8080->80/tcp means port 8080)
3. The container name running the frontend (if separate)

Report these clearly before proceeding.

---

## Task 2 — Run All Pending Database Migrations

**Why:** We added 7 custom migrations during development (student_roster, enrollment_pricing_rules, razorpay columns on orders, scan_slot/slot_label on check_ins, audit log table, etc.). None of these exist in the live DB yet. Without running migrations, every enrollment lookup, payment, and QR scan will crash with "table not found" errors.

```bash
# Replace <backend_container> with the name found in Task 1
docker exec -it <backend_container> php artisan migrate --force

# Verify migrations ran — last 10 should include our custom ones
docker exec -it <backend_container> php artisan migrate:status | tail -20
```

Expected to see these custom migrations marked as "Ran":
- `create_student_roster_table`
- `create_enrollment_pricing_rules_table`
- `add_rollno_config_to_event_settings`
- `add_razorpay_columns_to_orders_table`
- `add_scan_limit_settings_to_event_settings`
- `create_pricing_rule_audit_log_table`
- `add_scan_slot_slot_label_to_attendee_check_ins`

If any show as "Pending", the migrate --force command above will handle them.

---

## Task 3 — Set Production Environment Variables

**Why:** The app currently runs with development .env values (APP_DEBUG=true, test Razorpay keys, localhost URLs). Going live with debug mode on exposes stack traces to students. Wrong Razorpay keys means payments silently fail. Wrong APP_URL means ticket email links point to localhost.

Find the .env file location first:
```bash
docker exec -it <backend_container> find / -name ".env" -not -path "*/vendor/*" 2>/dev/null
```

Then open and edit it:
```bash
docker exec -it <backend_container> bash -c "cat /var/www/html/.env | grep -E 'APP_URL|APP_ENV|APP_DEBUG|RAZORPAY|MAIL'"
```

Report what is currently set for those keys. Then update the following values (ask me for the actual secret values before writing — do not invent them):

```env
# App
APP_URL=https://vic.codepode.in
APP_ENV=production
APP_DEBUG=false

# Razorpay — switch from rzp_test_ to rzp_live_ prefix
RAZORPAY_KEY_ID=           ← ask user for this
RAZORPAY_KEY_SECRET=       ← ask user for this
RAZORPAY_WEBHOOK_SECRET=   ← ask user for this (from Razorpay dashboard → Webhooks)

# Email via Brevo SMTP (already configured at VIC)
MAIL_MAILER=smtp
MAIL_HOST=smtp.brevo.com
MAIL_PORT=587
MAIL_USERNAME=             ← ask user for Brevo login email
MAIL_PASSWORD=             ← ask user for Brevo SMTP key
MAIL_FROM_ADDRESS=events@vic.college
MAIL_FROM_NAME="VIC Events"
```

After writing the .env, re-cache so Laravel picks up new values:
```bash
docker exec -it <backend_container> php artisan config:cache
docker exec -it <backend_container> php artisan route:cache
```

Verify the cache worked:
```bash
docker exec -it <backend_container> php artisan config:show app | grep -E "url|env|debug"
# Should show: url=https://vic.codepode.in, env=production, debug=false
```

---

## Task 4 — Verify App Is Accessible on Localhost

**Why:** Before routing external traffic through Cloudflare, confirm the container responds correctly on the local port. If the app is broken locally, Cloudflare cannot fix it.

```bash
# Replace PORT with the host port found in Task 1
curl -s -o /dev/null -w "HTTP Status: %{http_code}\n" http://localhost:<PORT>/

# Test the enrollment lookup API specifically
curl -X POST http://localhost:<PORT>/api/public/events/1/enrollment-lookup \
  -H "Content-Type: application/json" \
  -d '{"enrollment_no": "22045"}'
```

Expected responses:
- First: `HTTP Status: 200`
- Second: JSON with `"is_free": true` (senior batch)

If either fails, report the full error output before proceeding.

---

## Task 5 — Configure Cloudflare Tunnel

**Why:** The app runs on localhost inside Docker. Students at `vic.codepode.in` cannot reach localhost — Cloudflare Tunnel creates a secure encrypted pipe from Cloudflare's edge to your local machine without opening any firewall ports or exposing your home IP.

First check if cloudflared is installed and if an existing tunnel exists:
```bash
# Check cloudflared version
cloudflared --version

# List existing tunnels
cloudflared tunnel list
```

**If a tunnel named `vic-events` already exists** (likely — it was used for Hi.Events previously):
```bash
# Check current config
cat ~/.cloudflared/config.yml

# Add or update the ingress rule for vic.codepode.in
# Edit ~/.cloudflared/config.yml to look like this:
```

```yaml
tunnel: vic-events          # existing tunnel UUID, do not change
credentials-file: /Users/<current_user>/.cloudflared/<tunnel-uuid>.json

ingress:
  - hostname: vic.codepode.in
    service: http://localhost:<PORT>    # the port from Task 1
  - hostname: events.vic.college       # keep existing VIC rule if present
    service: http://localhost:<PORT>
  - service: http_status:404
```

Then add the DNS record and restart:
```bash
cloudflared tunnel route dns vic-events vic.codepode.in
cloudflared tunnel run vic-events
```

**If NO tunnel exists yet**, create a new one:
```bash
cloudflared tunnel create vic-farewell
cloudflared tunnel route dns vic-farewell vic.codepode.in
```

Create `~/.cloudflared/vic-farewell.yml`:
```yaml
tunnel: vic-farewell
credentials-file: /Users/<current_user>/.cloudflared/<new-uuid>.json

ingress:
  - hostname: vic.codepode.in
    service: http://localhost:<PORT>
  - service: http_status:404
```

Run it:
```bash
cloudflared tunnel --config ~/.cloudflared/vic-farewell.yml run vic-farewell
```

---

## Task 6 — Verify Live Domain Works

**Why:** Confirms Cloudflare is routing correctly to the container and the full stack (nginx/caddy → PHP → PostgreSQL) is responding end to end on the public internet.

```bash
# Public homepage
curl -s -o /dev/null -w "Homepage: %{http_code}\n" https://vic.codepode.in/

# Senior enrollment lookup (should be free)
curl -X POST https://vic.codepode.in/api/public/events/1/enrollment-lookup \
  -H "Content-Type: application/json" \
  -d '{"enrollment_no": "22045"}'

# Junior enrollment lookup (should be ₹200)
curl -X POST https://vic.codepode.in/api/public/events/1/enrollment-lookup \
  -H "Content-Type: application/json" \
  -d '{"enrollment_no": "21033"}'

# Rate limit test — run 11 times, 11th must return 429
for i in {1..11}; do
  echo -n "Request $i: "
  curl -s -o /dev/null -w "%{http_code}\n" \
    -X POST https://vic.codepode.in/api/public/events/1/enrollment-lookup \
    -H "Content-Type: application/json" \
    -d '{"enrollment_no":"22045"}'
done
```

All must pass before proceeding to Razorpay webhook registration.

---

## Task 7 — Register Razorpay Webhook

**Why:** Without the webhook, paid ticket payments are accepted by Razorpay but the ticket is never issued — the money is collected but the student gets no QR code. The webhook is what triggers ticket generation after payment confirmation.

This is a manual step in the Razorpay dashboard — provide the user these instructions:

1. Go to [Razorpay Dashboard](https://dashboard.razorpay.com) → **Account & Settings → Webhooks**
2. Click **Add New Webhook**
3. Set:
   - Webhook URL: `https://vic.codepode.in/api/webhooks/razorpay`
   - Secret: generate a strong random string (or use: `openssl rand -hex 32`)
   - Active Events: check **payment.captured** only — nothing else
4. Click Save
5. Copy the **Webhook Secret** shown
6. Add it to the container .env as `RAZORPAY_WEBHOOK_SECRET=<copied value>`
7. Re-run: `docker exec -it <backend_container> php artisan config:cache`

Also register the domain:
- **Account & Settings → Business Website Details → Add Additional Website**
- Add: `vic.codepode.in`

---

## Task 8 — Pre-Event Checklist

**Why:** Even with everything deployed, the system won't work for students until the actual event data is configured. These are the operational setup steps the VIC team must do before opening registrations.

Walk through each item and confirm or complete it:

```bash
# 1. Confirm at least one event exists in the DB
docker exec -it <backend_container> php artisan tinker \
  --execute="echo DB::table('events')->count() . ' events found';"

# 2. Confirm pricing rules exist for the event
docker exec -it <backend_container> php artisan tinker \
  --execute="print_r(DB::table('enrollment_pricing_rules')->get()->toArray());"

# 3. Confirm event_settings has max_scans configured
docker exec -it <backend_container> php artisan tinker \
  --execute="print_r(DB::table('event_settings')->first());"
```

Then confirm these are done via admin panel at `vic.codepode.in/admin`:
- [ ] Real student Excel roster imported (Admin → Student Roster → Import)
- [ ] Pricing rules set: batch 22 = free, batch 19–21 = ₹200
- [ ] `max_scans_per_ticket` set to 2 (entry + food) or 3 (entry + food + dessert)
- [ ] `scan_slot_labels` set e.g. `["Entry Gate", "Food Counter"]`
- [ ] Razorpay keys switched from `rzp_test_` to `rzp_live_` in .env
- [ ] Mac sleep disabled: System Settings → Battery → Prevent automatic sleeping
- [ ] Docker Desktop set to start on login

---

## Task 9 — Keep the Tunnel Running Persistently

**Why:** `cloudflared tunnel run` stops when the terminal closes. For event day the tunnel must survive terminal closure, Mac restarts, and sleep wake cycles.

```bash
# Install cloudflared as a system service (runs in background always)
sudo cloudflared service install

# Verify it's running as a service
sudo launchctl list | grep cloudflared

# Check tunnel status
cloudflared tunnel info vic-events   # or vic-farewell
```

If the service install fails, use a persistent terminal session instead:
```bash
# Run in background, redirect logs
nohup cloudflared tunnel run vic-events > ~/cloudflared.log 2>&1 &
echo "Tunnel PID: $!"

# Monitor logs
tail -f ~/cloudflared.log
```

---

## Final Verification

After all tasks complete, run this full system check:

```bash
echo "=== VIC Farewell System Check ==="

echo "1. Container status:"
docker ps --format "{{.Names}}: {{.Status}}" | grep -v "^$"

echo "2. Migrations:"
docker exec <backend_container> php artisan migrate:status 2>/dev/null | grep -E "Ran|Pending" | tail -5

echo "3. Live domain:"
curl -s -o /dev/null -w "vic.codepode.in → HTTP %{http_code}\n" https://vic.codepode.in/

echo "4. Enrollment API:"
curl -s -X POST https://vic.codepode.in/api/public/events/1/enrollment-lookup \
  -H "Content-Type: application/json" \
  -d '{"enrollment_no":"22045"}' | python3 -m json.tool 2>/dev/null || echo "JSON parse failed"

echo "=== Done ==="
```

All checks green = system is live and ready for students.

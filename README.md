# Schedule Reminder Handler

CiviCRM extension that does two things:

1. **Staleness guard** — prevents genuinely old scheduled reminder emails from being sent during a retry (e.g. an email supposed to go out 10 days ago should not land in someone's inbox today).
2. **Auto-retry** — automatically resets SMTP transport failures so CiviCRM retries them without manual intervention.

---

## Why this exists

CiviCRM's scheduled reminder job (`send_scheduled_reminders`) marks a failed send as `is_error=1` and never retries it. Two types of failure occur:

| Error message | Cause | Retriable? |
|---|---|---|
| `Failed to send message` | SMTP/transport error (e.g. Mailgun rate limit) | **Yes** |
| `Couldn't find recipient's email address` | Missing email in CiviCRM | No — data issue |

This extension only resets `Failed to send message` errors. Data errors are left alone.

---

## Component 1 — Staleness Guard (`alterMailParams` hook)

### What it checks

Every time CiviCRM is about to send a scheduled reminder email, the hook evaluates whether the email is still relevant.

**The golden rule: if the intended send date is TODAY, the hook does nothing.**

CiviCRM only creates the send row after verifying the timing condition is already met. So any email processed today is by definition a legitimate, on-time send. The hook exits immediately — no checks, no risk of accidental abort.

The check only activates when the intended send date is **in the past** (i.e. this is a retry of a previously failed send):

```
intended send date = today  →  hook exits immediately → ALWAYS ALLOW
intended send date = past   →  check if still within N-day retry window
    └── within window       →  ALLOW (legitimate retry)
    └── outside window      →  ABORT (genuinely stale, stop sending)
```

The retry window is controlled by the constant:

```php
define('NUMBER_OF_DAY_ALLOWED_FOR_OLD_EMAILS', 2);
```

This means: a failed send will be retried for up to 2 days after the intended send date. After that it is considered stale and blocked.

### Date-only comparison — not time

The "is this today?" check compares **dates only** (`Y-m-d`), never timestamps. This is intentional — the schedule might be set to fire at 09:00 but the cron runs at 14:00. Both are the same date, so the email is treated as on-time regardless of what hour the cron runs.

### What it does NOT check

- It does **not** affect emails going out today — those are always allowed, no matter what.
- It does **not** check `civicrm_action_log.action_date_time` — CiviCRM's own log timestamp is irrelevant here.
- It does **not** touch the database — read-only check only.
- It does **not** affect non-scheduled-reminder emails (newsletters, transactional, etc).
- It does **not** use any WordPress APIs — pure CiviCRM/PHP only.

### Schedule types supported

**Relative schedules** — e.g. "1 day before event start", "1 month after event end":
- Calculates intended send date = entity date ± offset (date only)
- If intended send date = today → bypass (normal send)
- If past → check: `now <= intended_send_date + N days` → allow or abort

**Absolute date schedules** — e.g. "Resource Pack on 2026-05-01":
- The `absolute_date` field is the intended send date
- If schedule date = today → bypass (normal send)
- If past → check: `now <= schedule_date + N days` → allow or abort

**Schedules with neither field** — bypassed (allowed). The hook only acts when it can positively confirm staleness.

### Example decisions

| Scenario | Intended send date | Today | Result |
|---|---|---|---|
| Normal send, on time | Today | Today | ALLOW — bypassed immediately |
| 1-month-after reminder, on time | Today | Today | ALLOW — bypassed immediately |
| 1-hour-before reminder, on time | Today | Today | ALLOW — bypassed immediately |
| SMTP failed yesterday, retrying today | Yesterday | Today | ALLOW — within 2-day window |
| Stale — supposed to go 10 days ago | 10 days ago | Today | ABORT — outside window |
| Absolute date 10 days ago | 10 days ago | Today | ABORT — outside window |

---

## Component 2 — Auto-retry

Two mechanisms work together to reset `Failed to send message` errors automatically:

### A) PHP shutdown handler (fast path)

Registered the first time a scheduled reminder email is detected in a cron request. Fires at the end of the PHP process — immediately after the cron run completes.

- Finds `Failed to send message` errors from the **last 10 minutes** (failures from this cron run only)
- Resets them: `action_date_time = NULL, is_error = 0`
- They are picked up on the next cron tick (seconds to minutes)

The 10-minute window is intentionally tight — it only covers failures from the current request. Older failures are handled by the job below.

### B) Scheduled job fallback (RetryFailedReminders)

Registered under `Administer → Scheduled Jobs` as **"Retry Failed Scheduled Reminders"**.

- Finds `Failed to send message` errors for active schedules within the last `max_age_days` (default: 3 days)
- Resets them to pending
- Configured to run hourly as a safety net

**Disabled by default** — enable after deployment and initial verification.

To run manually:
```bash
cv api Job.retry_failed_reminders max_age_days=3
```

### Why both?

| | Shutdown handler | Scheduled job |
|---|---|---|
| Trigger | End of the cron run that caused the failure | Hourly |
| Delay to retry | Seconds (next cron tick) | Up to 1 hour |
| Scope | Last 10 minutes only | Last N days |
| Purpose | Fast retry for transient rate limits | Safety net |

---

## Configuration

Edit the top of `schedulereminderhandler.php`:

```php
define('NUMBER_OF_DAY_ALLOWED_FOR_OLD_EMAILS', 2);
```

| Value | Meaning |
|---|---|
| `2` (default) | Retries allowed up to 2 days after intended send date |
| `3` | More lenient — allows retries up to 3 days |
| `1` | Strict — only retries within 1 day |

The `max_age_days` parameter on the scheduled job controls how far back the job looks for failures to reset. These two values can differ — the staleness guard is the final authority on whether an email actually sends.

---

## Debugging

Enable CiviCRM debug logging:
`Administer → System Settings → Debugging → Enable Debugging = Yes`

After triggering the scheduled reminders job, check:
`Administer → CiviCRM Log` — search for `ScheduleReminderHandler`

Example log output:

```
# Normal on-time send — hook exits immediately, no check performed
ScheduleReminderHandler schedule 567 (relative): intendedSend=2026-06-04 is today — bypassing check, ALLOW

# Retry within window — allowed
ScheduleReminderHandler schedule 567 (relative, retry): intendedSend=2026-06-03, maxDate=20260605100000, now=20260604100000, abort=N

# Retry outside window — aborted
ScheduleReminderHandler schedule 565 (absolute, retry): scheduleDate=2026-05-01, maxDate=20260503000000, now=20260604100000, abort=Y

# Shutdown handler reset failures for next cron
ScheduleReminderHandler shutdown: reset 3 SMTP failure(s) — will retry on next cron

# Scheduled job ran
RetryFailedReminders: reset 2 rows for retry (max_age_days=3)
```

---

## Running the logic test

A standalone test script validates all staleness decisions without touching the database or requiring CiviCRM:

```bash
php tests/test_altermailparams_logic.php
```

**Safe to run on live — no database writes, no emails sent.**

Covers 11 scenarios including: on-time sends, SMTP retry within window, boundary cases, stale aborts, absolute date schedules, and long-offset schedules (1 month after event).

---

## What this extension will NEVER do

- Abort a scheduled reminder whose intended send date is today
- Retry a `Couldn't find recipient's email address` error (permanent data issue)
- Affect any email other than CiviCRM scheduled reminders (`Scheduled Reminder Sender`)
- Write to the database from within the staleness check
- Use WordPress-specific APIs

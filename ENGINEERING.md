# CalPlan engineering notes

This document describes the 0.9.11 implementation. The README is intentionally
user-facing; this file records the architecture, scheduling model, storage, and
known technical constraints.

## System model

CalPlan is a native Nextcloud app. It does not patch the Tasks or Calendar apps.

- A task is a CalDAV `VTODO`.
- A task is selected for scheduling with the standard `CATEGORIES:calplan` value.
- A scheduled block is a derived `VEVENT` in the task's calendar.
- The task is authoritative. The event is disposable output.

Generated events use standard iCalendar properties:

```text
UID:calplan-<task UID>
RELATED-TO;RELTYPE=TODO:<task UID>
CATEGORIES:calplan-slot
SUMMARY:🔒 [! ]<task title>
DTSTART:...
DTEND:...
STATUS:CONFIRMED
```

The event also mirrors the task's description, priority, location, URL, progress,
and immediate parent/child context. No custom `X-` properties or database tables
are required.

## Reconcile flow

```text
                         ┌─────────────────────────────┐
 task create/edit/delete │ synchronous VTODO listener │
                         └──────────────┬──────────────┘
                                        │
 calendar change ── background job ─────┤
 manual button / OCS request ────────────┤
                                        ▼
                         ReconcileService::reconcileUser
                                        │
                 ┌──────────────────────┼──────────────────────┐
                 ▼                      ▼                      ▼
          ConfigService         CalendarRepository     review expired slots
       timezone and policy      tasks and events       update task categories
                 │                      │                      │
                 └──────────────────────┼──────────────────────┘
                                        ▼
                      filter ignored calendars/titles
                                        │
                                        ▼
                        build user-wide busy event set
                                        │
                                        ▼
                 per active task calendar: Reconciler::reconcile
                                        │
                       ┌────────────────┴────────────────┐
                       ▼                                 ▼
              SchedulerService                   existing slot map
              desired placements                 and content check
                       └────────────────┬────────────────┘
                                        ▼
                  Reconciliation { put, delete, unchanged, unscheduled }
                                        │
                         ┌──────────────┴───────────────┐
                         ▼                              ▼
                 best-effort CalDAV writes      diagnostics / optional history
```

Reconciliation is designed to be idempotent. If task data, busy time, policy,
and generated events are unchanged, a second run produces no writes.

Each slot write and delete is isolated. A calendar that rejects a `VEVENT` does
not prevent other calendars from being reconciled.

## Triggers and transaction behavior

### Task changes

`CalendarObjectChangeListener` listens for the public Nextcloud calendar object
create, update, delete, and trash events. It filters the payload to `VTODO` and
runs reconciliation synchronously.

Nextcloud dispatches these events inside the CalDAV write transaction. The
listener therefore:

- uses a recursion guard for CalPlan's own writes;
- catches every reconciliation failure; and
- never lets scheduling roll back the user's original task write.

### Calendar changes

Ordinary `VEVENT` writes do not run a synchronous full-user schedule. They are
picked up by the background job or a manual reconcile. This avoids making meeting
saves wait on calendar scanning and scheduling.

### Background and manual runs

`ReconcileJob` is eligible every five minutes and visits users for whom CalPlan is
enabled. Actual timing depends on the instance's background-job runner.

The manual OCS endpoint is:

```text
POST /ocs/v2.php/apps/calplan/api/v1/reconcile
```

The Tasks and Calendar frontend action calls this endpoint directly.

## Calendar reads

For each non-ignored active calendar, CalPlan reads:

- all `VTODO` objects;
- `VEVENT` objects overlapping one day behind now through the end of the 14-day
  planning horizon; and
- every CalPlan-owned event, including out-of-window events needed for cleanup.

Busy time is the union of ordinary events from all non-ignored calendars.
`TRANSP:TRANSPARENT` events and CalPlan-owned slots are removed from busy input.
Title exclusions are applied only to ordinary events.

The calendar containing the task remains the destination for its generated slot.

## Scheduling pipeline

Scheduling is deterministic and non-preemptive. Time is represented as free
intervals on a 15-minute grid.

### 1. Eligibility

A task is eligible when it is selected for auto-scheduling and is not complete,
cancelled, or at 100 percent completion. Paused review tasks are omitted until
the user explicitly enables Auto-schedule again.

### 2. Busy subtraction

`FreeSlotsService` creates working-hour windows and subtracts overlapping busy
intervals. Event buffers expand ordinary busy intervals before subtraction.

### 3. Ranking

Tasks are sorted by:

1. urgency tier:
   - missed `DUE`;
   - missed `DTSTART`;
   - other tasks;
2. descending deadline/priority score; and
3. ascending duration as a shortest-processing-time tiebreaker.

The score is:

```text
priority_weight / (whole_days_until_due + 1)
```

A task without a due date uses the planning horizon as its due-distance value.

### 4. Placement

Each task takes the earliest free interval that satisfies:

- it does not start in the past;
- it does not start before `DTSTART`;
- it fits its duration;
- it finishes by a future `DUE`;
- it stays within the daily minute limit;
- it stays within the daily block-count limit; and
- required task gaps are preserved.

A future `DTSTART` outside working hours creates a task-specific window from that
start through the end of its local day. A past `DTSTART` does not reopen past time.

If no placement fits before a future due date, the task remains unscheduled. If
the task is already overdue or has no due date, the due check may be relaxed and
the scheduler tries the earliest remaining capacity in the horizon.

The daily block-count ledger is shared across calendar passes for the user. The
daily minute ledger is currently calculated inside each calendar's scheduling
pass.

### 5. Outcome

The scheduler returns either a slot or a stable reason:

- `starts_after_horizon`
- `no_working_hours`
- `duration_exceeds_daily_cap`
- `duration_exceeds_openings`
- `no_fit_before_due`
- `no_capacity_within_horizon`
- `daily_task_count_reached`

`Reconciler` compares the desired placement and mirrored content with the current
event, then produces puts, deletes, unchanged objects, and unscheduled outcomes.

## Scheduling research

CalPlan borrows ideas from classical single-machine scheduling, but its combined
policy is a product heuristic rather than a published optimal algorithm.

### Earliest deadline first

Deadline-driven or earliest-deadline-first scheduling prioritizes the job with
the most imminent deadline. Liu and Layland's 1973 real-time scheduling paper is
a standard reference:

- C. L. Liu and James W. Layland, “Scheduling Algorithms for Multiprogramming in
  a Hard-Real-Time Environment,” *Journal of the ACM* 20(1), 1973.
  DOI: `10.1145/321738.321743`.

CalPlan is not a preemptive real-time EDF scheduler. It uses a deadline-influenced
score, modified by task priority, within hard urgency tiers.

### Shortest processing time

Shortest-processing-time ordering is a classical rule for reducing average
completion time. CalPlan uses duration only as the final tiebreaker, so it does
not let a stream of short tasks override missed deadlines or stronger deadline/
priority scores.

### Moore–Hodgson

J. Michael Moore's 1968 single-machine algorithm minimizes the number of late
jobs by considering jobs in due-date order and removing the longest job when the
current sequence becomes late. This scheduling rule is commonly referred to as
the Moore–Hodgson algorithm; the primary paper cited here is Moore's:

- J. Michael Moore, “An n Job, One Machine Sequencing Algorithm for Minimizing
  the Number of Late Jobs,” *Management Science* 15(1), 1968.
  DOI: `10.1287/mnsc.15.1.102`.

CalPlan does **not** implement the exact Moore–Hodgson algorithm: it does not
construct an earliest-due-date sequence and eject the longest late job. Its
future-DUE rule and overdue/no-due rollover policy are inspired by the same
on-time-versus-late-job distinction, adapted to calendar availability, release
times, priorities, user limits, and existing busy intervals.

### Why a heuristic

The practical problem includes release times, deadlines, variable durations,
calendar holes, multiple daily capacities, per-task windows, and user policy.
CalPlan chooses a predictable greedy plan rather than claiming exact global
optimality. The pure scheduling cost is roughly task sorting plus task-by-slot
search; in practice CalDAV reads and writes dominate runtime.

## Slot content

`IcalCodec::slotContent()` is the single source for both writing and comparison.
This prevents content-only task edits from being missed during reconciliation.

The live event summary is intentionally short:

```text
🔒 [! if late] Task title
```

The description contains a structured `---Task---` section and an optional
`---Notes---` section. Depending on available data it includes title, progress,
priority, overdue state, parent/child titles, task URL, and `👣 First step:`.

## Configuration and storage

Per-user policy is stored with Nextcloud's standard `OCP\IConfig` user values:

- working hours;
- pacing, daily limits, duration fallback, task gap, and event buffer;
- review interval and paused task UIDs;
- ignored calendars and event-title rules;
- history consent and retention; and
- the latest ten aggregate reconcile diagnostics.

Optional scheduling history is stored as monthly JSONL files in per-user appdata.
Task UIDs are converted to salted HMAC keys. Plaintext titles, notes, locations,
URLs, and raw iCalendar bodies are not written to the history. Users can export a
snapshot to Files or delete the history.

## Frontend integration

`Application::boot()` loads CalPlan's script and styles only on Tasks and Calendar
routes. The script adds:

- the Tasks sidebar Auto-schedule switch;
- task-list badges;
- Reconcile and help actions in Tasks and Calendar; and
- latest-run status in the help dialog.

The integration is runtime DOM/Vuex integration. It has no build step and does
not modify the Tasks or Calendar source trees.

Nextcloud app-management icon discovery uses `img/app.svg` and
`img/app-dark.svg`. The smaller task/sidebar SVGs are separate runtime assets.

## Main source areas

| Area | Responsibility |
|---|---|
| `lib/Service/ReconcileService.php` | Per-user orchestration, review, metrics, apply |
| `lib/Service/CalendarRepository.php` | CalDAV reads and writes |
| `lib/Service/SchedulerService.php` | Ranking, constraints, placement, reasons |
| `lib/Service/FreeSlotsService.php` | Working windows and busy subtraction |
| `lib/Service/Reconciler.php` | Desired/current diff and idempotency |
| `lib/Service/IcalCodec.php` | iCalendar parsing and generated event content |
| `lib/Service/ConfigService.php` | Per-user policy and diagnostics |
| `lib/Service/BehavioralHistoryService.php` | Optional privacy-minimal observations |
| `lib/Listener/CalendarObjectChangeListener.php` | Immediate task-change trigger |
| `lib/BackgroundJob/ReconcileJob.php` | Periodic reconcile and review trigger |
| `js/calplan-tasks.js` | Tasks/Calendar runtime integration |

## Testing and packaging

The PHPUnit suite covers scheduling, reconciliation, free-slot subtraction,
iCalendar parsing/serialization, exclusions, review behavior, history, and scale
fixtures. Browser and live CalDAV tests run against a disposable Nextcloud 34
instance.

The App Store archive is built from a clean Git export. It excludes tests,
Composer development metadata, screenshots, the public roadmap, and this
engineering document. The standalone GitHub repository keeps those source and
documentation files.

## Known engineering limitations

- Recurring `VTODO` rules are not expanded into independent occurrences.
- `STATUS:CANCELLED` ordinary events and declined attendee state are not yet
  removed from busy time automatically.
- Calendar-event changes depend on background/manual reconciliation.
- Generated event moves are not interpreted as user pins.
- The synchronous task listener performs a full-user reconcile inside the open
  CalDAV transaction; failures are swallowed for safety, but a deferred per-user
  queue is the intended scale improvement.
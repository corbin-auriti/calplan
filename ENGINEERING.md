# CalPlan engineering notes

This document describes the current implementation. The README is intentionally
user-facing; this file records the architecture, scheduling model, storage, and
known technical constraints.

## System model

CalPlan is a native Nextcloud app. It does not patch the Tasks or Calendar apps.

- A task is a CalDAV `VTODO`.
- A task is selected for scheduling with the standard `CATEGORIES:calplan` value
  (matched like the Tasks UI: case-insensitive, surrounding spaces ignored).
- A scheduled block is a derived `VEVENT` in the task's calendar; a split task
  has one block per part.
- The task is authoritative. The event is disposable output.

Generated events:

```text
UID:calplan-<block key>            (<task UID>, or <task UID>~p<n> for part n)
X-CALPLAN-PART:<n>/<count>         (parts only)
RELATED-TO;RELTYPE=TODO:<task UID>
CATEGORIES:calplan-slot
SUMMARY:🔒 [! ]<task title>[ (n/count)]
DTSTART:...Z                       (UTC: no VTIMEZONE needed)
DTEND:...Z
STATUS:CONFIRMED
CLASS:PRIVATE|CONFIDENTIAL         (when the task has it)
```

The event also mirrors the task's description, priority, location, URL
(commas percent-encoded, which survives Sabre's round trip), progress, and
parent/child context.

A task's own settings live on its `VTODO`:

| Property | Meaning |
|---|---|
| `ESTIMATED-DURATION` | Block length (draft-ietf-calext-ical-tasks), set by the sidebar Duration field |
| `X-CALPLAN-SPLITTABLE:TRUE` | Split if needed, for the task and its subtasks |
| `X-CALPLAN-DAILY-MAX-TIME` / `X-CALPLAN-DAILY-MAX-BLOCKS` | Daily limit for the task and its subtasks together |
| `X-APPLE-SORT-ORDER` | Tasks' manual order, the order of a tree's subtasks |

No database tables are required.

## Reconcile flow

```text
 task / event create, edit, delete, move ── listener ── DeferredReplans (end of request)
 cron (ReconcileJob, every 5 min) ───────────────────────┤
 Reconcile button, settings saves (OCS) ─────────────────┤
                                                         ▼
                                   ReconcileService::reconcileUser (per-user lock)
                                                         │
                       ┌─────────────────────────────────┼──────────────────────────┐
                       ▼                                 ▼                          ▼
               ConfigService                    CalendarRepository          review expired blocks
          timezone, policy, hours        tasks (own calendars), events     update task categories,
                                         (own, shared, subscribed)         release their blocks
                       └─────────────────────────────────┼──────────────────────────┘
                                                         ▼
                                     filter ignored calendars/titles, duplicate UIDs
                                                         ▼
                                Reconciler::reconcileCalendars (one pass, all calendars)
                                   pin started blocks, count time spent today
                                                         │
                                ┌────────────────────────┴───────────────────┐
                                ▼                                            ▼
                         SchedulerService                           existing block map
                         desired placements                         and content check
                                └────────────────────────┬───────────────────┘
                                                         ▼
                           Reconciliation per calendar { put, delete, unchanged, unscheduled }
                                                         │
                                       ┌─────────────────┴──────────────┐
                                       ▼                                ▼
                              best-effort CalDAV writes      diagnostics / optional history
```

Reconciliation is designed to be idempotent. If task data, busy time, policy,
and generated events are unchanged, a second run produces no writes.

Each slot write and delete is isolated. A failed write is counted in the
diagnostics and retried by the next run.

## Triggers and transaction behavior

### Calendar object changes

`CalendarObjectChangeListener` listens for the public Nextcloud calendar object
create, update, delete, trash, restore and move events (tasks and events). It
does not plan inside the event: it notes the users concerned (the calendar's
owner, and the user who made the change when that is a sharee) and
`DeferredReplans` re-plans each once when the request ends. That keeps the
re-plan out of CalDavBackend's write transaction, and turns an import or a
birthday-calendar sync into one re-plan instead of one per object. The response
still waits for the re-plan, so a client reading the plan after its write sees
the new one.

The listener skips CalPlan's own writes and blocks, users the app is not enabled
for, users whose last reconcile had nothing to plan (unless the task carries
`calplan`), users whose client holds the re-plans back (`POST
/reconcile/hold`, used by a parent's Auto-schedule switch before it writes
the whole subtree and asks for one re-plan), the birthday calendar, and a
created, deleted or restored event that is transparent or outside the planning
window.

A run that would rewrite the very task a client is saving (a paused task
restarted from another client) leaves that to the next review run, so the
client's ETag stays current.

### Background and manual runs

`ReconcileJob` is eligible every five minutes. It skips users with nothing to
plan or review and never waits for a run in progress. Background runs that
changed nothing are not recorded in the ten diagnostics entries.

```text
POST /ocs/v2.php/apps/calplan/api/v1/reconcile
GET  /ocs/v2.php/apps/calplan/api/v1/reconcile/status
POST /ocs/v2.php/apps/calplan/api/v1/reconcile/hold
```

Runs of one user never overlap (an exclusive lock through Nextcloud's locking
provider; a run waits up to 30 s, cron does not wait). Once it holds the lock, a
run rereads the user's CalPlan values, so a run that waited sees what the one
before it stored.

## Calendar reads

- Tasks: every `VTODO` of the user's **own** calendars. A calendar shared with
  the user is planned by its owner's CalPlan, never by the sharee's.
- Busy time: ordinary events of the user's own and shared calendars, of
  subscriptions and of federated calendars, one day behind now through the end
  of the planning window (7–90 days). The birthday calendar is skipped (its
  events are transparent).
- Every CalPlan-owned event of the user's own calendars, including those
  outside the window, so cleanup still works.

All-day and floating times are read in the user's timezone. Recurring events are
expanded inside the window, with a recurrence limit high enough for old daily
series. An event with `DURATION` instead of `DTEND` lasts that long; an all-day
event without `DTEND` lasts a day. An object that cannot be parsed is skipped.

`TRANSP:TRANSPARENT` events, events lasting no time and CalPlan-owned slots are
removed from busy input. Title exclusions are applied only to ordinary events.

A task's blocks go into its own calendar. A calendar that holds only tasks
(Tasks-only lists) cannot show them: its flagged tasks are reported as
`calendar_without_events`.

## Scheduling pipeline

Scheduling is deterministic and non-preemptive. Free time starts and ends on a
15-minute grid and blocks follow each other without gaps (a `DTSTART` off the
grid is rounded up to it), in the user's days (all times are converted to the
user's timezone first); block lengths use elapsed time, so DST changes do not
stretch them.

### 1. Eligibility and task trees

A task is eligible when it is selected for auto-scheduling and is not complete,
cancelled, or at 100 percent completion. Paused review tasks are omitted until
the user explicitly enables Auto-schedule again. A task with a subtask still to
schedule or awaiting review is a container and gets no block itself.

`TaskLimits::resolve` attaches what each task inherits along its `RELATED-TO`
parent chain within the calendar: limit groups, splittability, the top task's due
date, start and priority, the tree position, and the parent path. A parent link
closing a cycle is ignored; a completed ancestor ends the chain.

### 2. Busy subtraction

`FreeSlotsService` creates working-hour windows and subtracts overlapping busy
intervals. Event buffers expand ordinary busy intervals before subtraction.

### 3. Ranking

A task tree is one unit, ranked by its top task; a task without parent is a unit
of its own. Units are sorted by:

1. urgency tier: missed `DUE`, missed `DTSTART`, other;
2. descending deadline/priority score;
3. the exact deadline; and
4. ascending total duration as a shortest-processing-time tiebreaker.

The score is `priority_weight / (calendar_days_until_due + 1)`. Priority 1 weighs
5, falling to 0.2 for 9; no priority weighs like medium (5). An undated task is
ranked as if due ten years ahead. Within a unit, tasks follow the tree's
depth-first order.

### 4. Placement

Blocks that have already started stay as they are while their task is still to
be scheduled (until the expired-block review releases them), hold their time and
count against their day's limits. So does block time spent today by blocks that
were removed or moved (remembered per day). A started block that a real event
now overlaps did not happen: it is neither kept nor reviewed, its task is
planned again from now, and only its time before the event counts as spent.

Each task then takes the earliest free interval that satisfies:

- it does not start in the past, before its own or its top task's `DTSTART`, or
  before the end of the blocks already placed for its tree;
- it fits its duration;
- it finishes by a future `DUE`;
- it stays within the daily minute and block limits and the task's limit groups;
- required task gaps are preserved.

A timed `DTSTART` on a working day opens a task-specific window from it (or from
now) through the end of that day when it lies outside working hours, or when the
working time left after it is too short for the task.

A task that may be split fills the earliest free time: whole where it fits at
its first possible start, otherwise in parts of at least the minimum part, each
day used up to its limits.

If no placement fits before a future due date, the task remains unscheduled. If
the task is already overdue or has no due date, the due check is relaxed and the
scheduler tries the earliest remaining capacity in the horizon.

### 5. Outcome

The scheduler returns the blocks or a stable reason: `starts_after_horizon`,
`no_working_hours`, `duration_exceeds_daily_cap`, `duration_exceeds_openings`
(not for tasks that may be split), `no_fit_before_due`,
`no_capacity_within_horizon`, `daily_task_count_reached`,
`task_daily_limit_reached`. ReconcileService adds `calendar_without_events` and
`duplicate_uid`.

`Reconciler` compares the desired blocks and mirrored content with the current
events, then produces puts, deletes, unchanged objects, and unscheduled outcomes.

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
🔒 [! if late] Task title[ (n/count)]
```

The description contains a structured `---Task---` section and an optional
`---Notes---` section. Depending on available data it includes title, progress,
priority, overdue state, subtask titles, the parent path up to the top task,
task URL, and `👣 First step:`.

## Configuration and storage

Per-user state is stored with Nextcloud's standard `OCP\IConfig` user values:

- working hours (else the user's Nextcloud availability, else 09:00–17:00);
- pacing, daily limits, duration fallback, task gap, event buffer, planning
  window and minimum part;
- review interval, paused and restarted task UIDs;
- ignored calendars and event-title rules;
- history consent and retention;
- the latest ten aggregate reconcile diagnostics;
- the last run's unscheduled tasks and planned blocks, tasks CalPlan rewrote in
  the last hour (for the Tasks page to reload), today's spent block time, and
  a re-plan hold.

Optional scheduling history is stored as monthly JSONL files in per-user appdata.
Task UIDs are converted to salted HMAC keys. Plaintext titles, notes, locations,
URLs, and raw iCalendar bodies are not written to the history. Nothing is
computed while it is off. Users can export a snapshot to Files or delete the
history; months past the retention are pruned on every write, on settings saves
and before an export, and a deleted account's history is deleted with it.

## Frontend integration

`Application::boot()` loads CalPlan's script and styles only on Tasks and Calendar
routes (not their public pages), for logged-in users the app is enabled for. The
script adds:

- the Tasks sidebar Auto-schedule switch and the Duration, Split if needed and
  Daily limit rows (disabled for tasks Tasks cannot save);
- task-list badges with the time of each task's next block (a parent's: its
  subtasks' next one), a "Planned:" line in the sidebar, and not-scheduled
  warnings;
- Reconcile and help actions in Tasks and Calendar;
- latest-run status in the help dialog; and
- in Calendar, a refresh after an edit whose re-plan moved blocks.

The integration is runtime DOM/Vuex/Pinia integration. It has no build step and
does not modify the Tasks or Calendar source trees. Strings go through
Nextcloud's `t()`/`n()`.

Nextcloud app-management icon discovery uses `img/app.svg` and
`img/app-dark.svg`. The smaller task/sidebar SVGs are separate runtime assets.

## Main source areas

| Area | Responsibility |
|---|---|
| `lib/Service/ReconcileService.php` | Per-user orchestration, lock, review, metrics, apply |
| `lib/Service/CalendarRepository.php` | CalDAV reads and writes |
| `lib/Service/TaskLimits.php` | Task trees: relationships, inheritance, containers |
| `lib/Service/SchedulerService.php` | Ranking, constraints, placement, splitting, reasons |
| `lib/Service/PlanState.php` | Free pool and daily usage of one scheduling pass |
| `lib/Service/FreeSlotsService.php` | Working windows and busy subtraction |
| `lib/Service/Reconciler.php` | Pins, desired/current diff and idempotency |
| `lib/Service/IcalCodec.php` | iCalendar parsing and generated event content |
| `lib/Service/ConfigService.php` | Per-user policy, state and diagnostics |
| `lib/Service/DeferredReplans.php` | End-of-request re-plans |
| `lib/Service/BehavioralHistoryService.php` | Optional privacy-minimal observations |
| `lib/Listener/CalendarObjectChangeListener.php` | Immediate task and event trigger |
| `lib/BackgroundJob/ReconcileJob.php` | Periodic reconcile and review trigger |
| `js/calplan-tasks.js` | Tasks/Calendar runtime integration |
| `js/calplan-settings.js` | Personal settings page |

## Testing and packaging

The PHPUnit suite covers scheduling, reconciliation, free-slot subtraction,
iCalendar parsing/serialization, task trees, exclusions, review behavior,
history, and scale fixtures. Browser and live CalDAV tests run against a
disposable Nextcloud 34 instance.

The App Store archive is built from a clean Git export. It excludes tests,
Composer development metadata, screenshots, the public roadmap, and this
engineering document. The standalone GitHub repository keeps those source and
documentation files.

## Known engineering limitations

- Recurring `VTODO` rules are not expanded into independent occurrences.
- `STATUS:CANCELLED` ordinary events and declined attendee state are not yet
  removed from busy time automatically.
- Generated event moves are not interpreted as user pins.
- Working hours hold one window per weekday (Nextcloud availability with several
  slots a day becomes one window from the first to the last).
- An event update cannot be filtered by its old time (Nextcloud passes only the
  new data), so every update of a plan-active user re-plans.

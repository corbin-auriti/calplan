# CalPlan — Roadmap / TODO

> Public roadmap, grounded in the Tasks/Calendar source. Status as of
> **0.9.11** (2026-08-24).

## 0.9.x hard feature freeze

**0.9.x is feature-complete.** No new scheduling policies, settings, workflows,
Calendar/Tasks interactions, behavioral features, or convenience features may be
added to the 0.9.x line. New ideas belong in this roadmap for a later phase.

Allowed 0.9.x work is limited to correctness/compatibility fixes; security,
privacy, or data-loss prevention; severe performance regressions; tests proving
those fixes; and release documentation, signing, packaging, or App Store work.
A user-facing capability or scheduling-semantic change is a feature even if it is
described as small polish or hardening. Ship and dogfood 0.9.11 before reopening
feature scope.

## Release blockers / hardening

- **Personal settings — core done** — `/settings/user/calplan` controls the daily
  task-time cap, fallback task duration, pacing preset, task-to-task recovery
  gap, buffer around real calendar events, complete calendar exclusions, and
  case-insensitive exact/contains busy-event title exclusions. Presets populate
  explicit values; the scheduler never relies on a vague hidden "relaxed" branch.
- **Visible unscheduled reasons — core done** — explain why a flagged task has no
  opening before DUE, daily cap reached, starts beyond the horizon, duration too
  large, or no working hours. Next: expose event-incompatible calendar and slot
  write-failure reasons at the orchestration layer and show per-task details.
- **Operator diagnostics switch — done** — privacy-safe reconcile timings are gated behind
  the standard `occ config:app:set calplan performance_logging ...` app setting;
  disabled by default and separate from personal behavioral-history consent.
- **Non-blocking reconcile** — VTODO changes remain immediate, but ordinary
  VEVENT writes must not run a full-user schedule inside the CalDAV transaction.
  Follow up with a deduplicated per-user deferred queue so event changes are
  near-real-time without blocking the save request.
- **Scale profiling** — pure scheduler fixtures now cover 60/250 tasks and
  120/500 busy events, and each live reconcile logs privacy-safe phase timings.
  Next: orchestration fixtures across calendars, then later many-user load tests.

## Beta usability and observability

- **Reconcile CalPlan — done:** the action is at the top of Tasks navigation,
  above smart collections and calendar lists.
- **Phase X observation history — first slice done:** separate explicit per-user opt-in,
  privacy-minimal monthly JSONL segments in appdata, bounded retention, export,
  delete controls, pseudonymous task keys, and changed `scheduled`,
  `unscheduled`, and `slot_removed` facts. Next: distinguish completion/unflag
  cleanup and add progress/missed attribution; no scoring yet.
- Later Calendar interaction: an explicit **Mark linked task done** action and an
  optional advanced `calplan-done` category command for capable third-party
  CalDAV clients. Do not implicitly complete a task by deleting its derived
  event.
- Later drag-and-pin placement must use a reconciler placement snapshot to tell
  user moves from CalPlan's own writes; a valid pin is respected only while it
  satisfies hard DTSTART/DUE and conflict constraints.

## Phase 2 — deferred scale & features

- **1.5.4 / 1.6.1** — deduplicated per-user deferred reconcile trigger. Keep
  task completion/date edits immediate, but coalesce calendar-event changes and
  rapid task edits outside the DAV write transaction. Must work with documented
  Nextcloud cron/AJAX background-job modes and retain on-demand recovery.
- **1.5.2** — canceled / declined events stop counting as busy (`STATUS:CANCELLED`
  + `ATTENDEE`/`PARTSTAT=DECLINED`).
- **1.5.12** — recurring (repeat) tasks: expand + place each occurrence (semantics
  to confirm).
- **2.1** — habit modality (a distinct visual — sine/wiggly — for auto-scheduled
  regular blocks).
- **2.2** — subtasks increase the parent's time block (roll up child `DURATION`s).
- **2.3** — Calendar "Unscheduled tasks" sidebar reflects CalPlan's actual
  schedule (filter scheduled tasks out).
- **2.4** — Calendar-frontend runtime injection: drag-prevention on `calplan-slot`
  events, a lock icon, "Due:" + bold/red urgency styling. (Never a Calendar
  source patch — self-contained injection.)
- **2.5** — Calendar actions for a slot, backed by one permission-aware resolver
  from the slot's related task UID to its calendar URI + VTODO object URI:
  **Open linked task** and explicit **Mark task complete** (confirmation/undo,
  recurrence-aware). Do not treat deleting the derived event as completion. The
  0.9.6 DESCRIPTION deep-link variant was cut pre-release.
- **2.6 — done in 0.9.11** — per-user **maximum task blocks per day**, separate from the existing
  maximum task minutes per day. Unlimited by default. Each CalPlan-managed block
  counts once on the user's local day where it starts; the count is shared across
  calendars, and remaining tasks roll to later eligible days without relaxing
  DTSTART/DUE. `daily_task_count_reached` explains a blocked rollover. Recurring
  occurrences will count individually once recurring-task expansion exists.
- **2.7 — deferred by the 0.9.x freeze** — per-weekday task-placement rules:
  schedule all tasks, schedule no tasks, deny tasks matching selected tags, or
  allow only tasks matching selected tags. Start with standard task tags and
  match any selected tag. A later advanced layer may accept intentional notes
  directives such as `CalPlan exclude: Friday` and `CalPlan only: Tuesday,
  Thursday`; do not fuzzy-match arbitrary notes text. These rules restrict slot
  eligibility rather than skipping reconciliation, so existing violating slots
  move/remove and an unscheduled task receives a typed reason such as
  `excluded_by_day_rules`. One-off blocked dates are a separate later extension.

## Phase 3 — Calendar UX

- **1.5.7** — calendar refetch hook after reconcile (so new/removed slots show
  without a manual refresh) + a README note explaining the sync interval.

## Phase X — behavioral scheduling model (the long-term goal)

The weighted-EDF placement shipping today is the **current approximation** of a
longer-term objective, not the end state.

**Objective:** place each task `t` at the slot `s`, under the approach `a` (work
the full block, a smaller first step, or a tiny "just open it" trigger), that
maximizes the probability it actually gets worked on —

```
max_{s,a}  V(t) · P(start | t, s) · P(progress | t, s, a)
```

The placement pipeline becomes `task → choose approach → choose slot`.

— subject to the **hard constraints** that are never relaxed for "fit": DUE,
DTSTART, the daily cap, real busy, and working hours.

**Locked design choices:**

- **Approach, not avoidance.** Repeated failure to start/finish a task *raises*
  the scheduler's interest; it never suppresses the task. No avoidance-penalty
  term — `initiation_fit` replaces `(1 - avoidance_penalty)`.
- **`P(progress)` over `P(finish)`** — avoids optimizing toward trivially
  completable mini-tasks.
- **`behavioral_fit = time_fit × duration_fit × context_fit × initiation_fit`** —
  four multiplicative factors, no avoidance term.
- **Plaintext & modular.** The behavioral model's learned data lives in **two
  plaintext artifacts** in the per-user Nextcloud appdata dir (via
  `IAppDataFactory` — on disk, human-readable, wiped with the app, NOT surfaced
  as a live file in the user's Files UI): **Profile** (`behavioral_profile.json`
  — learned stats; every learned entry carries `source`, an `observations` count
  and a last-updated time so it stays auditable) and **History**
  (`behavioral_history.jsonl` — append-only lifecycle event log: observability
  before optimization). **Policy** (static rules + priors) is not a file — it
  lives in ordinary Nextcloud app settings (`OCP\IConfig` user values; the
  **Tunable** knobs below). JSON/JSONL, no DB schema, no composer deps (TOML
  rejected: no native PHP parser, and a vendor dep would break the vendorless
  store package). The model is CalPlan-internal state, never written onto the
  user's tasks or slots (mirrors the "no custom `X-` iCal props" stance). Human
  readability / transparency / portability come from an on-demand **"Export my
  model"** snapshot of profile + history into your Files (plus an optional
  **Import** for a server migration) — the live store deliberately stays out of
  Files, where a daily-appended file would attract sync conflicts, Versions
  bloat, activity-feed noise and accidental deletion.
- **Single scoring boundary.** `BehavioralModel::score(task, slot, profile)` is
  the only coupling between the model and `SchedulerService::place()` — no DB, no
  HTTP, no DAV on either side. Cold start returns 1.0 for every factor, which is
  today's earliest-feasible behavior exactly.
- **Explainable.** The slot carries `👣 First step:` (preferred `First step:`
  notes syntax, with legacy `Next:` compatibility); Phase X adds a `Why:` line.
- **Tunable.** The pipeline exposes **settings** to tweak it (factor on/off — the
  `P = 1` toggle that recovers today's behavior — individual factor weights, the
  daily cap, the lookahead horizon). Defaults preserve today's behavior; the
  behavioral model is opt-in.

**Planned evolution:** efficient packing (today, `P = 1`) → behaviorally-informed
placement (`argmax SCORE` over slot *and approach*, earliest-feasible tiebreak,
`urgencyTier` kept as the hard outer sort) → adaptive task decomposition
(suggest/place the `👣 First step:` sub-step when a task repeatedly fails to start
— the approach axis `a`; ties into **2.2 subtasks**).

**Sub-phases:**

- **X.1** — observability/history layer (`behavioral_history.jsonl` lifecycle
  event log: `scheduled → started/progressed | moved | missed`, `completed`;
  user moves distinguished from our own re-placement via a placement snapshot
  written from the reconciler's plan; slot rejections + deletes recorded). Plus
  an on-demand **"Export my model"** (a timestamped profile+history snapshot
  into your Files) and an optional **Import** after a server migration.
  Gating dependency for the rest.
- **X.2** — heuristic, explainable, cold-started model (rule-based factor buckets
  from the history — hour-of-day, day-of-week, context-switch recency,
  duration-vs-estimate — each gated `P = 1` until `observations >= threshold`;
  policy priors like `prefer_smaller_first_action`; energy/mood priors deferred
  until a data source exists; no ML in the app).
- **X.3** — `SCORE`-based placement in `SchedulerService::place()` via the single
  `score()` boundary, choosing the approach `a` in the same `argmax` (after
  **2.2**, so a next-action-sized block is possible).
- **X.4** — `Why:` placement-reason line in the slot DESCRIPTION (assembled from
  the same auditable profile entries the scorer used).
- **X.5** — pipeline-tuning settings (reuse the OCS settings endpoint; these
  settings + the static priors ARE the Policy — standard `OCP\IConfig` user
  values, no plaintext policy file).

Phase X scoring starts only after dogfooding 0.9.11 and gathering enough opt-in
X.1 observations; it stays opt-in and explainable throughout.
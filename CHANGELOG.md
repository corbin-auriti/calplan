# Changelog

All notable changes to CalPlan are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/) and the project adheres to
[Semantic Versioning](https://semver.org/).

**Release status:** 0.9.11 is the first version published as a standalone source
repository and Git tag. Version 0.9.12 is the `v0.9.11_1` correctness hotfix.
The Nextcloud App Store release is still pending signing certificate approval.
Versions 0.1.0 through 0.9.10 were internal development milestones and were not
public App Store releases.

## 0.9.12 — 2026-08-26

Published under the requested Git tag `v0.9.11_1`. Nextcloud app metadata uses
0.9.12 because the App Store schema requires a three-part semantic version and an
upgrade must sort after 0.9.11.

### Fixed
- Fixed Nextcloud 34 background cron startup by injecting the public
  `OCP\App\IAppManager` interface instead of the nonexistent
  `OCP\IAppManager`. The invalid dependency previously aborted the entire cron
  tick before CalPlan's job could run and prevented `core:lastcron` from advancing.
- Slot deletion no longer swallows every backend exception. Missing slots remain
  harmless no-ops in Nextcloud's CalDAV backend, while real permission, database,
  transaction, or trash-conflict failures now reach CalPlan's per-object failure
  isolation and diagnostics instead of being falsely reported as removed.
- Added live CalDAV regression coverage proving that completing a flagged task
  removes its generated event from the server. An already-open Calendar page may
  still need its normal refresh/sync before the removed block disappears visually.

## 0.9.11 — 2026-08-24

First published source snapshot and `v0.9.11` tag. App Store publication is
pending the CalPlan signing certificate.

### Fixed
- Added Nextcloud's conventional `img/app.svg` and `img/app-dark.svg` assets so
  app management and App Store surfaces use the CalPlan calendar-check icon
  instead of the generic fallback.
- **Blocking busy-time regression:** Nextcloud 32–34
  `CalDavBackend::calendarQuery()` returns matching object URI strings, but
  CalPlan treated them as full calendar-object rows. Ordinary events were
  silently omitted from busy input, allowing task blocks to overlap meetings.
  CalPlan now fetches each matching event body by URI before parsing it.
- Owned-slot detection now requires the reserved UID plus `calplan-slot` or a
  TODO relationship. An unrelated event whose UID begins with `calplan-` still
  counts as ordinary busy time.

### Added
- Added `ENGINEERING.md` with the current architecture, reconcile diagram,
  scheduling model, research references, storage, testing, and limitations.
- Per-user **Maximum task blocks per day**, separate from maximum task minutes.
  `0` means unlimited. The count is user-wide across calendars, uses the local
  start day, rolls excess tasks forward without relaxing DTSTART/DUE, and exposes
  `daily_task_count_reached` when a due/horizon boundary prevents rollover.
- The top Tasks/Calendar `?` help now shows when the latest Reconcile ran, its
  trigger, successful placements/removals, unchanged/could-not-fit totals,
  objects read, title exclusions, duration, and calendar-write failures.
- Privacy-safe diagnostics for the latest ten runs in Personal settings, including
  aggregate/per-calendar counts, unscheduled reasons, timings, and write failures.
  No task/event titles, notes, URLs, UIDs, or raw iCalendar bodies are stored.

### Changed
- Personal settings are organized into accessible collapsible sections:
  scheduling limits and pace, calendar/event exclusions, scheduling history, and
  Reconcile diagnostics. Only the primary scheduling section starts expanded.

## 0.9.10 — 2026-08-24

### Added
- Personal **Completely ignore these calendars** checklist. An ignored calendar
  is a hard boundary: CalPlan does not read its events or tasks and does not
  create, update, review, or remove blocks there.
- Personal **Ignore ordinary calendar events with these titles** list, with one
  title per line and case-insensitive **Exact title** or **Contains / partial
  title** matching. This supports booking placeholders such as `Busy` that
  remain visible to other people but do not block CalPlan task placement.

### Safety
- Existing `calplan` task tags and derived blocks in a completely ignored
  calendar are deliberately left untouched. The settings page warns about this;
  remove the calendar from the ignore list before Reconcile can act there again.
- Event-title rules affect ordinary VEVENT busy time only. They never match task
  titles or hide CalPlan-owned blocks from update and cleanup.

### Changed
- Ordinary VEVENT summaries are now parsed for title-rule matching. Other event
  content remains unread unless it belongs to a CalPlan-owned block.

### Fixed
- Deleted-calendar tombstones returned by Nextcloud’s CalDAV backend are no
  longer reconciled or shown as active calendar choices in personal settings.
- The Reconcile navigation action and its frontend assets are now scoped strictly
  to Tasks and Calendar. They no longer appear in Files, Personal settings,
  Administration settings, or other apps that share Nextcloud’s navigation DOM.

## 0.9.9 — 2026-08-23

### Added
- Expired incomplete task blocks enter an explicit review workflow using the
  standard, filterable `calplan-review` category (“Is complete?”).
- Personal **How often to check and auto-reschedule unfinished tasks** setting:
  Off, 5, 15, 30, or 60 minutes. Manual Reconcile always checks immediately;
  timed checks require a functioning Nextcloud background-job runner.
- Pacing-derived grace after a slot ends: Compact 15 minutes, Balanced 30,
  Relaxed 60, and Custom uses at least 15 minutes or the configured task gap.
- General help beside Reconcile in Tasks and Calendar now mirrors the ELI5 store
  description and explains exactly what Reconcile reads, recomputes, writes, and
  removes, plus the review workflow and background-job requirement.

### Behavior
- With auto-rescheduling enabled, CalPlan keeps `calplan`, adds
  `calplan-review`, and tries another time.
- With auto-rescheduling Off, CalPlan adds `calplan-review`, removes `calplan`,
  deletes the expired derived slot, and pauses. Removing the review tag alone
  does not restart it; explicitly enabling Auto-schedule does and also clears the
  review tag.
- Category rewrites preserve every unrelated user task tag and use only standard
  iCalendar `CATEGORIES` values.

## 0.9.8 — 2026-08-23

### Added
- Native **Personal settings → CalPlan** controls for daily task cap, fallback
  duration, task recovery gaps, event buffers, and explicit pacing presets.
- A top-navigation **Reconcile CalPlan** action in Tasks and Calendar, with
  placed/removed/could-not-fit feedback.
- Machine-readable unscheduled reasons and bounded scheduling-window reads.
- Opt-in Phase X scheduling history: monthly appdata JSONL, pseudonymous task
  keys, retention, deduplication, export, and delete controls.
- A **CalPlan help** button beside Auto-schedule explaining `Duration:`, the
  optional `Next:` smallest-step helper, derived slots, and calendar support.
- Help buttons beside the top Reconcile action in both Tasks and Calendar.
- Renamed the preferred helper to `First step:` and calendar output to
  `👣 First step:`; legacy `Next:` notes remain compatible.
- Parent and immediate subtask titles in slot descriptions, retaining `↳`/`↴`
  before each related task title and bounding long child lists.
- Privacy-preserving title-shape metadata for new opt-in observations: coarse
  length, word-count, average-word-length and character-entropy buckets plus
  structural flags. No title text, token hashes, n-grams, or embeddings.

### Fixed
- Completing a task through `PERCENT-COMPLETE:100` now removes its slot even if
  a CalDAV client has not also written `STATUS:COMPLETED`.
- Ordinary VEVENT saves no longer synchronously trigger a full-user reconcile.

### Performance
- VEVENT reads use a one-day lookback through the 14-day planning horizon while
  retaining out-of-window owned slots for cleanup.
- Added privacy-safe opt-in performance metrics, scheduler scale fixtures, and a
  repeatable benchmark.

## 0.9.6 — 2026-08-21

### Added
- **`Title:` + `Next action:` in the slot DESCRIPTION's `---Task---` block.**
  Write a `Next:` line anywhere in a task's notes and CalPlan promotes it to a
  `Next action:` line in the slot's `---Task---` block (and drops it from
  `---Notes---` so it isn't shown twice) — the smallest first step, surfaced
  where you'll see it. The block now also always leads with a `Title:` line
  carrying the full task title, which stays readable even when narrow calendar
  blocks clip the SUMMARY. A first hook for upcoming subtask-driven scheduling.

### Removed
- **The "Open in Tasks" deep-link prototype.** Prototyped in-tree earlier in
  the 0.9.6 development cycle and then cut: making a link correct across
  every instance address, port and pretty-URL configuration was more complexity
  than it's worth. Opening the linked task will return properly as a
  Calendar-popover action button (plan 2.5).

### Validated
- PHPUnit 106/106 (195 assertions, 9 environment-skipped).

## 0.9.5 — 2026-08-20

Internal beta milestone. CalPlan became a self-contained Nextcloud app that
automatically time-blocks flagged Tasks onto free Calendar slots. This version
was not published to the Nextcloud App Store.

### Added
- **Auto-scheduling engine.** Weighted-EDF ranking
  (`weight / (days_until_due + 1)`) with a missed-deadline precedence
  (missed DUE > missed DTSTART > on-track), shortest-job (SPT) tiebreak,
  Moore–Hodgson-inspired late-job handling, a daily cap, and DTSTART that
  overrides working hours when it falls outside them.
- **Two-app, one-source-of-truth model.** Tag a task `calplan` (a standard
  iCal `CATEGORIES` value that round-trips to every CalDAV client) and CalPlan
  writes exactly one derived `calplan-slot` VEVENT per flagged, incomplete task —
  nothing else. The task is the source of truth; the slot is strictly one-way
  (task → slot, never slot → task), so editing the slot directly in Calendar is
  futile (the next reconcile re-derives it).
- **Reconcile triggers.** On demand (the Tasks-sidebar "Auto-schedule" toggle),
  synchronously on every VTODO create/update/delete/trash via a backend
  dav-event listener (client-agnostic — fires inside the CalDAV write
  transaction), and as a background job on cron. Reconcile is idempotent.
- **Cross-calendar busy.** Busy is the union across all the user's calendars;
  `TRANSPARENT` (free) events don't block.
- **Slot content mirroring.** The slot mirrors the task's title, notes,
  priority, % complete, location and URL. The SUMMARY carries a `🔒` lock
  marker and a single `!` when the task is past its start or due (glance cue,
  title stays visible in narrow calendar blocks); the overdue `⏰` glyph, the
  `↴` / `↳` subtask marker and the priority level live in a structured
  DESCRIPTION block (`---Task---` / `---Notes---`).
- **Per-task estimate** via the RFC-5545 `DURATION` property on the VTODO
  (default 30 min — the bundled Tasks app exposes no estimate field).
- **Self-contained UI injection.** A native "Auto-schedule" toggle in the
  Tasks sidebar plus a `🔒` task-icon badge, injected at runtime — no patches
  to the Tasks or Calendar apps. CalPlan injects its own SVG icons.
- **Settings** via the OCS API (`/ocs/v2.php/apps/calplan/api/v1/...`); per-user
  working hours are read from Nextcloud availability (7-day 09:00–17:00 default),
  with the user's timezone respected for all placement.

### Validated
- PHPUnit 97/97 (180 assertions, 9 environment-skipped). The pure
  scheduling / reconciler / codec core is fully unit-tested; the CalDAV I/O
  layer, the synchronous dav-event listener and the OCS endpoints are
  validated against a live Nextcloud 34.

### Notes
- `info.xml` targets `nextcloud min-version="31" max-version="34"` (live-tested
  on 34; NC 35 is unreleased, so it is capped at 34 until tested).
- No runtime Composer dependencies — the store package ships without a
  `vendor/` directory (uses Nextcloud's bundled `Sabre\VObject`).

## 0.9.0 — 2026-08-20

Internal beta-preparation milestone.

### Added
- Missed-start and missed-due urgency tiers.
- The first locked `🔒` generated-event summary.
- Initial App Store metadata and beta documentation.

## 0.1.0 — 2026-08-19

Initial internal native Nextcloud implementation after the project moved away
from its earlier prototype approach.

### Added
- CalDAV task and event parsing.
- Greedy task ranking and free-slot placement.
- Derived `calplan-slot` events linked to `calplan` tasks.
- Working-hours settings, background reconciliation, and initial Tasks UI
  integration.
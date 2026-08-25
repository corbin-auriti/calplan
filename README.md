# CalPlan

**Automatic task scheduling for Nextcloud — organizing your day.**

CalPlan brings the automatic time-blocking idea behind tools such as Motion and
Reclaim to a self-hosted Nextcloud app. It does not send your tasks or calendar
data anywhere, and it does not patch the Tasks or Calendar apps.

Time-blocking is useful, but doing it manually for every task is work of its own.
CalPlan turns a task into a calendar block, then keeps that block aligned with
your deadlines, working hours, and real calendar commitments.

Add the `calplan` tag to a task—or use the **Auto-schedule** switch added to
Nextcloud Tasks—and CalPlan creates a matching calendar block. As the task or
calendar changes, CalPlan moves, updates, or removes that block.

![CalPlan in Nextcloud Calendar](https://raw.githubusercontent.com/corbin-auriti/calplan/v0.9.11/img/screenshots/calplan-hero.png)

## Status

**0.9.11 is a pre-release beta.** The source and `v0.9.11` tag are public, but
CalPlan is not in the Nextcloud App Store yet. The signing-certificate request is
pending.

- Nextcloud 32–34
- PHP 8.2 or newer
- Nextcloud Tasks and Calendar

## Important setup

CalPlan works with Nextcloud calendars that contain both:

- `VTODO` objects for tasks; and
- `VEVENT` objects for calendar blocks.

A task/event calendar is the safest choice. Nextcloud itself can store the
generated event in a calendar that Tasks presents as task-focused, but some
third-party clients do not display mixed task/event calendars reliably. If a
client does not show the generated blocks, try the same calendar in Nextcloud
Calendar or use a normal calendar with task support.

Nextcloud background jobs should also be configured. Task edits are handled
immediately, but calendar-event changes and timed review checks depend on the
background job or a manual **Reconcile CalPlan** run.

## Using CalPlan

1. Create or open a task in Nextcloud Tasks.
2. Turn on **Auto-schedule**, or add the standard `calplan` category from another
   CalDAV client.
3. Optionally set a start date, due date, priority, duration, or notes.
4. CalPlan creates a `🔒 Task title` event in the same calendar.

The task is always the source of truth. The calendar event is generated from it.
Editing or dragging the event does not edit the task; a later reconcile can
overwrite that change.

Completing, cancelling, or untagging the task removes its generated event.
`PERCENT-COMPLETE:100` also counts as complete.

### Task duration

CalPlan uses the first available value:

1. the task's standard iCalendar `DURATION`;
2. a `Duration:` line in the notes; or
3. your default duration setting, initially 30 minutes.

Examples:

```text
Duration: 15min
Duration: 1hr
Duration: 1 hour 30 minutes
```

### First step

This optional notes line is copied into the calendar block:

```text
First step: open the document and write the heading
```

It is display-only and does not affect scheduling. The older `Next:` form is
still accepted.

## How scheduling works

CalPlan plans up to 14 days ahead on a 15-minute grid.

- Busy time comes from all of your active calendars.
- `TRANSPARENT` events do not block time.
- Ignored calendars are not read or changed at all.
- Selected ordinary event titles can be ignored as busy time.
- A task is never placed before its start date.
- A future due date is a hard latest finish. If the task cannot fit before it,
  the task is left unscheduled.
- An overdue task, or a task without a due date, may roll forward and is tried in
  the earliest available slot.
- A future start time outside normal working hours may open time for that task
  from its start time through the end of that day.
- Daily task minutes and daily block count are both enforced.
- Task gaps and buffers around ordinary events are applied before placement.

Tasks with a missed due date are considered first, followed by tasks with a
missed start date, then other tasks. Within those groups, due date and priority
affect order, with shorter tasks used as a tiebreaker.

The scheduler is informed by established scheduling rules: earliest-deadline-
first, shortest-processing-time, and Moore–Hodgson late-job scheduling. CalPlan
combines those ideas in a practical greedy heuristic; it is not an exact
implementation of any one textbook algorithm. See [ENGINEERING.md](ENGINEERING.md)
for the model, references, and current trade-offs.

Running Reconcile twice with unchanged input should produce no additional
writes.

## Long-term direction

The current scheduler is deliberately simple: make a predictable, earliest-
feasible plan that respects hard constraints. The longer-term goal is to make
placement more useful to the person doing the work—not just more tightly packed.

Future research may use observed scheduling outcomes to choose better times and
better approaches to a task, such as a smaller first step. It should improve the
chance that a task gets started and makes progress without hiding difficult work.
Deadlines, start dates, busy time, working hours, and user limits remain hard
constraints. This is a future direction, not behavior implemented in 0.9.11.

## When CalPlan updates

- **Task changes:** task create, edit, completion, deletion, or trash triggers an
  immediate reconcile.
- **Calendar-event changes:** picked up by the background job or manual
  Reconcile. Saving an ordinary meeting does not run a full schedule inside the
  save request.
- **Manual:** **Reconcile CalPlan** is available in Tasks and Calendar.
- **Background:** the registered job is eligible every five minutes. Actual run
  time depends on Nextcloud's background-job runner.

Calendar clients refresh on their own schedule, so a newly written block may not
appear until Calendar syncs or is refreshed.

## Personal settings

Open **Personal settings → CalPlan** to configure:

- Compact, Balanced, Relaxed, or Custom pacing;
- maximum task minutes per day;
- maximum task blocks per day (`0` means unlimited);
- fallback task duration;
- recovery time between CalPlan tasks;
- buffer before and after ordinary events;
- expired-task review and automatic rescheduling;
- calendars CalPlan must completely ignore;
- ordinary event titles that should not count as busy time;
- optional scheduling-history collection; and
- recent privacy-safe Reconcile diagnostics.

Working hours come from CalPlan's saved working-hours configuration, then
Nextcloud availability when present. Otherwise CalPlan defaults to 09:00–17:00
on every day of the week.

## Expired blocks

After a block ends and its grace period passes, an incomplete task receives the
standard `calplan-review` category.

- With automatic rescheduling enabled, CalPlan keeps the task scheduled and
  tries another time.
- With automatic rescheduling off, CalPlan removes `calplan`, deletes the block,
  and pauses the task. Turn **Auto-schedule** on again to restart it.

## Privacy

Scheduling happens inside Nextcloud. CalPlan does not send task or calendar data
to an external service.

Reconcile diagnostics store aggregate counts and timings, not task titles,
notes, URLs, UIDs, or raw iCalendar data.

Optional scheduling history is off by default. It stores pseudonymous task keys
and coarse scheduling observations in Nextcloud appdata. It can be exported to
Files or deleted from Personal settings.

## Current limitations

- Recurring tasks are not expanded into separate scheduled occurrences.
- Cancelled meetings and invitations you declined can still count as busy unless
  they are transparent or excluded by title.
- Moving a generated block does not pin it.
- Calendar-event changes are not reconciled immediately without a working
  background-job runner or a manual Reconcile.
- A Tasks-only calendar cannot hold generated blocks.

## Install from source

Until the App Store release is available, clone the tagged source into your
Nextcloud `custom_apps` directory and enable it with `occ`:

```sh
git clone --branch v0.9.11 https://github.com/corbin-auriti/calplan.git calplan
php occ app:enable calplan
```

## Development

```sh
composer install
composer lint
composer test:unit
composer benchmark:scheduler
```

The test suite covers the scheduling, reconciliation, iCalendar, exclusion,
review, history, and scale-sensitive core. Integration behavior has also been
validated against Nextcloud 34.

## Credits

Developed by **Corbin Auriti** with help from
[Cline](https://cline.bot), using **GLM-5.2** and other models.

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE).

CalPlan is independent of Nextcloud GmbH. Nextcloud and other product names are
used only to identify compatible software and services.

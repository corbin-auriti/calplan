<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$minutesLabel = fn (int $minutes): string => $l->n('%n minute', '%n minutes', $minutes);
?>
<div id="calplan-personal-settings" class="section"
	data-daily-cap="<?php p($_['dailyCapMinutes']); ?>"
	data-daily-task-count="<?php p($_['dailyTaskCount']); ?>"
	data-default-duration="<?php p($_['defaultDurationMinutes']); ?>"
	data-task-gap="<?php p($_['taskGapMinutes']); ?>"
	data-event-buffer="<?php p($_['eventBufferMinutes']); ?>"
	data-pace-preset="<?php p($_['pacePreset']); ?>"
	data-auto-reschedule="<?php p($_['autoRescheduleMinutes']); ?>"
	data-history-enabled="<?php p($_['enabled'] ? '1' : '0'); ?>"
	data-history-retention="<?php p($_['retentionDays']); ?>">
	<h2>CalPlan</h2>
	<p class="settings-hint"><?php p($l->t('Choose how much work CalPlan schedules and how much breathing room it leaves.')); ?></p>

	<details class="calplan-settings-group" open>
		<summary><?php p($l->t('Scheduling limits and pace')); ?></summary>
		<div class="calplan-settings-group__content" id="calplan-policy-fields">
	<div class="calplan-setting-row">
		<label for="calplan-pace"><?php p($l->t('Pace')); ?></label>
		<select id="calplan-pace">
			<option value="compact"><?php p($l->t('Compact — no automatic gaps')); ?></option>
			<option value="balanced"><?php p($l->t('Balanced — 15 minute breathing room')); ?></option>
			<option value="relaxed"><?php p($l->t('Relaxed — 30 minute breathing room')); ?></option>
			<option value="custom"><?php p($l->t('Custom')); ?></option>
		</select>
	</div>
	<div class="calplan-setting-row">
		<label for="calplan-daily-cap"><?php p($l->t('Maximum task time per day')); ?></label>
		<div><input id="calplan-daily-cap" type="number" required min="<?php p($_['dailyCapMin']); ?>" max="<?php p($_['dailyCapMax']); ?>" step="15"> <?php p($l->t('minutes')); ?></div>
	</div>
	<div class="calplan-setting-row">
		<label for="calplan-daily-task-count"><?php p($l->t('Maximum task blocks per day')); ?></label>
		<div><input id="calplan-daily-task-count" type="number" required min="0" max="<?php p($_['dailyTaskCountMax']); ?>" step="1"> <?php p($l->t('blocks')); ?></div>
		<p class="settings-hint"><?php p($l->t('Use 0 for unlimited. This is separate from maximum task time; reaching either limit rolls remaining work to a later eligible day. Blocks of today that have already started or ended count too.')); ?></p>
	</div>
	<div class="calplan-setting-row">
		<label for="calplan-default-duration"><?php p($l->t('Default task duration')); ?></label>
		<div><input id="calplan-default-duration" type="number" required min="<?php p($_['defaultDurationMin']); ?>" max="<?php p($_['defaultDurationMax']); ?>" step="15"> <?php p($l->t('minutes')); ?></div>
		<p class="settings-hint"><?php p($l->t('Used only when a task has neither an iCalendar DURATION nor a Duration: line in its notes.')); ?></p>
	</div>
	<div class="calplan-setting-row">
		<label for="calplan-task-gap"><?php p($l->t('Recovery time between CalPlan tasks')); ?></label>
		<select id="calplan-task-gap">
			<?php foreach ($_['gapChoices'] as $minutes): ?>
				<option value="<?php p($minutes); ?>"><?php p($minutesLabel($minutes)); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<div class="calplan-setting-row">
		<label for="calplan-event-buffer"><?php p($l->t('Buffer before and after calendar events')); ?></label>
		<select id="calplan-event-buffer">
			<?php foreach ($_['gapChoices'] as $minutes): ?>
				<option value="<?php p($minutes); ?>"><?php p($minutesLabel($minutes)); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<div class="calplan-setting-row">
		<label for="calplan-auto-reschedule"><?php p($l->t('How often to check and auto-reschedule unfinished tasks')); ?></label>
		<select id="calplan-auto-reschedule">
			<option value="0"><?php p($l->t('Off — tag for review and stop scheduling')); ?></option>
			<option value="5"><?php p($l->t('Every 5 minutes')); ?></option>
			<option value="15"><?php p($l->t('Every 15 minutes')); ?></option>
			<option value="30"><?php p($l->t('Every 30 minutes')); ?></option>
			<option value="60"><?php p($l->t('Hourly')); ?></option>
		</select>
		<p class="settings-hint"><?php p($l->t('Requires Nextcloud background jobs. Manual Reconcile always checks immediately. Expired incomplete tasks receive the filterable calplan-review (“Is complete?”) tag; a block stays where it is from its start until it is checked.')); ?></p>
	</div>
	<button id="calplan-save-policy" class="primary" type="button"><?php p($l->t('Save limits and pace')); ?></button>
	<span id="calplan-settings-status" class="calplan-save-status" role="status" aria-live="polite"></span>
		</div>
	</details>

	<details class="calplan-settings-group">
		<summary><?php p($l->t('Calendar and event exclusions')); ?></summary>
		<div class="calplan-settings-group__content">
	<p class="settings-hint"><?php p($l->t('These exclusions change what counts as busy and where CalPlan is allowed to work. CalPlan plans and writes only in your own calendars; the events of calendars shared with you, subscriptions and federated calendars count as busy time.')); ?></p>
	<div class="calplan-warning" role="note">
		<strong><?php p($l->t('Ignored calendars are completely outside CalPlan.')); ?></strong> <?php p($l->t('Reconcile will not read their tasks or events and will not create, update, review, or remove blocks there. Existing calplan tags and blocks are left untouched until you stop ignoring the calendar.')); ?>
	</div>
	<fieldset class="calplan-calendar-exclusions">
		<legend><?php p($l->t('Completely ignore these calendars')); ?></legend>
		<?php if ($_['calendarOptions'] === []): ?>
			<p class="settings-hint"><?php p($l->t('No calendars are currently available.')); ?></p>
		<?php endif; ?>
		<?php foreach ($_['calendarOptions'] as $calendar): ?>
			<label class="calplan-calendar-option">
				<input type="checkbox" name="calplan-ignored-calendar" value="<?php p($calendar['uri']); ?>" <?php if (in_array($calendar['uri'], $_['ignoredCalendars'], true)): ?>checked<?php endif; ?>>
				<span><?php p($calendar['name']); ?><?php if (!$calendar['available']): ?> <em><?php p($l->t('(currently unavailable)')); ?></em><?php elseif ($calendar['kind'] === 'shared'): ?> <em><?php p($l->t('(shared with you: busy time only)')); ?></em><?php elseif ($calendar['kind'] === 'subscription'): ?> <em><?php p($l->t('(subscription: busy time only)')); ?></em><?php endif; ?></span>
				<small><?php p($calendar['uri']); ?></small>
			</label>
		<?php endforeach; ?>
	</fieldset>
	<div class="calplan-setting-row">
		<label for="calplan-ignored-event-titles"><?php p($l->t('Ignore ordinary calendar events with these titles')); ?></label>
		<textarea id="calplan-ignored-event-titles" rows="5" placeholder="Busy&#10;Booking hold"><?php p(implode("\n", $_['ignoredEventTitles'])); ?></textarea>
		<p class="settings-hint"><?php p($l->t('One title per line. Matching is case-insensitive. Only ordinary events are ignored as busy time; task titles and CalPlan’s own blocks are never filtered.')); ?></p>
	</div>
	<div class="calplan-setting-row">
		<label for="calplan-event-title-match-mode"><?php p($l->t('Title matching')); ?></label>
		<select id="calplan-event-title-match-mode">
			<option value="exact" <?php if ($_['eventTitleMatchMode'] === 'exact'): ?>selected<?php endif; ?>><?php p($l->t('Exact title')); ?></option>
			<option value="contains" <?php if ($_['eventTitleMatchMode'] === 'contains'): ?>selected<?php endif; ?>><?php p($l->t('Contains / partial title')); ?></option>
		</select>
	</div>
	<button id="calplan-save-exclusions" type="button"><?php p($l->t('Save exclusions')); ?></button>
	<span id="calplan-exclusions-status" class="calplan-save-status" role="status" aria-live="polite"></span>
		</div>
	</details>

	<details class="calplan-settings-group">
		<summary><?php p($l->t('Personal scheduling history')); ?></summary>
		<div class="calplan-settings-group__content">
	<p class="settings-hint"><?php p($l->t('Optional, privacy-minimal observations for future personalization. Disabled by default; task titles and notes are never recorded.')); ?></p>
	<div class="calplan-setting-row calplan-checkbox-row">
		<input id="calplan-history-enabled" type="checkbox">
		<label for="calplan-history-enabled"><?php p($l->t('Collect scheduling history')); ?></label>
	</div>
	<div class="calplan-setting-row">
		<label for="calplan-history-retention"><?php p($l->t('Keep history for')); ?></label>
		<div><input id="calplan-history-retention" type="number" required min="30" max="730" step="30"> <?php p($l->t('days')); ?></div>
	</div>
	<button id="calplan-save-history" type="button"><?php p($l->t('Save history settings')); ?></button>
	<button id="calplan-export-history" type="button"><?php p($l->t('Export history to Files')); ?></button>
	<button id="calplan-delete-history" class="warning" type="button"><?php p($l->t('Delete my history')); ?></button>
	<span id="calplan-history-status" class="calplan-save-status" role="status" aria-live="polite"></span>
		</div>
	</details>

	<details class="calplan-settings-group calplan-diagnostics">
		<summary><?php p($l->t('Reconcile diagnostics')); ?></summary>
		<div class="calplan-settings-group__content">
			<p class="settings-hint"><?php p($l->t('The latest 10 runs (background runs only when they changed something). Privacy-safe counts and timings only: no task/event titles, notes, URLs, UIDs, or raw calendar data.')); ?></p>
			<?php if ($_['reconcileDiagnostics'] === []): ?>
				<p><?php p($l->t('No Reconcile run has been recorded yet.')); ?></p>
			<?php endif; ?>
			<?php foreach ($_['reconcileDiagnostics'] as $index => $run): ?>
				<details class="calplan-diagnostic-run" <?php if ($index === 0): ?>open<?php endif; ?>>
					<summary>
						<?php p($run['timestamp'] ?? $l->t('Unknown time')); ?> —
						<?php p($run['trigger'] ?? 'other'); ?> —
						<?php p($l->t('%1$s placed, %2$s removed, %3$s could not fit', [(int)($run['placed'] ?? 0), (int)($run['removed'] ?? 0), (int)($run['unscheduled'] ?? 0)])); ?>
					</summary>
					<dl class="calplan-diagnostic-grid">
						<dt><?php p($l->t('Calendars')); ?></dt><dd><?php p($l->t('%1$s active / %2$s ignored', [(int)($run['calendars'] ?? 0), (int)($run['ignoredCalendars'] ?? 0)])); ?></dd>
						<dt><?php p($l->t('Read')); ?></dt><dd><?php p($l->t('%1$s events, %2$s tasks', [(int)($run['eventsRead'] ?? 0), (int)($run['tasksRead'] ?? 0)])); ?></dd>
						<dt><?php p($l->t('Title exclusions')); ?></dt><dd><?php p($l->t('%s events ignored', [(int)($run['eventsIgnoredByTitle'] ?? 0)])); ?></dd>
						<dt><?php p($l->t('Plan')); ?></dt><dd><?php p($l->t('%1$s unchanged, %2$s eligible tasks', [(int)($run['unchanged'] ?? 0), (int)($run['scheduledTasks'] ?? 0)])); ?></dd>
						<dt><?php p($l->t('Write failures')); ?></dt><dd><?php p($l->t('%1$s put, %2$s delete', [(int)($run['writeFailures']['put'] ?? 0), (int)($run['writeFailures']['delete'] ?? 0)])); ?></dd>
						<dt><?php p($l->t('Total time')); ?></dt><dd><?php p((string)($run['timingsMs']['total'] ?? 0)); ?> ms</dd>
					</dl>
					<?php if (($run['unscheduledReasons'] ?? []) !== []): ?>
						<p><strong><?php p($l->t('Could not fit:')); ?></strong> <?php p(implode(', ', array_map(fn ($reason, $count) => $reason . ': ' . $count, array_keys($run['unscheduledReasons']), $run['unscheduledReasons']))); ?></p>
					<?php endif; ?>
					<details class="calplan-diagnostic-calendars">
						<summary><?php p($l->t('Per-calendar counts')); ?></summary>
						<ul>
							<?php foreach (($run['byCalendar'] ?? []) as $calendar): ?>
								<li><strong><?php p($calendar['calendar'] ?? $l->t('Calendar')); ?>:</strong> <?php p($l->t('%1$s events, %2$s tasks, %3$s placed, %4$s removed, %5$s unchanged, %6$s could not fit', [(int)($calendar['eventsRead'] ?? 0), (int)($calendar['tasksRead'] ?? 0), (int)($calendar['placed'] ?? 0), (int)($calendar['removed'] ?? 0), (int)($calendar['unchanged'] ?? 0), (int)($calendar['unscheduled'] ?? 0)])); ?></li>
							<?php endforeach; ?>
						</ul>
					</details>
				</details>
			<?php endforeach; ?>
		</div>
	</details>
</div>

<?php
/** @var array $_ */
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
	<p class="settings-hint">Choose how much work CalPlan schedules and how much breathing room it leaves.</p>

	<details class="calplan-settings-group" open>
		<summary>Scheduling limits and pace</summary>
		<div class="calplan-settings-group__content">
	<div class="calplan-setting-row">
		<label for="calplan-pace">Pace</label>
		<select id="calplan-pace">
			<option value="compact">Compact — no automatic gaps</option>
			<option value="balanced">Balanced — 15 minute breathing room</option>
			<option value="relaxed">Relaxed — 30 minute breathing room</option>
			<option value="custom">Custom</option>
		</select>
	</div>
	<div class="calplan-setting-row">
		<label for="calplan-daily-cap">Maximum task time per day</label>
		<div><input id="calplan-daily-cap" type="number" min="30" max="720" step="15"> minutes</div>
	</div>
	<div class="calplan-setting-row">
		<label for="calplan-daily-task-count">Maximum task blocks per day</label>
		<div><input id="calplan-daily-task-count" type="number" min="0" max="100" step="1"> blocks</div>
		<p class="settings-hint">Use 0 for unlimited. This is separate from maximum task time; reaching either limit rolls remaining work to a later eligible day.</p>
	</div>
	<div class="calplan-setting-row">
		<label for="calplan-default-duration">Default task duration</label>
		<div><input id="calplan-default-duration" type="number" min="15" max="480" step="15"> minutes</div>
		<p class="settings-hint">Used only when a task has neither an iCalendar DURATION nor a Duration: line in its notes.</p>
	</div>
	<div class="calplan-setting-row">
		<label for="calplan-task-gap">Recovery time between CalPlan tasks</label>
		<select id="calplan-task-gap">
			<?php foreach ([0, 15, 30, 45, 60, 90, 120] as $minutes): ?>
				<option value="<?php p($minutes); ?>"><?php p($minutes); ?> minutes</option>
			<?php endforeach; ?>
		</select>
	</div>
	<div class="calplan-setting-row">
		<label for="calplan-event-buffer">Buffer before and after calendar events</label>
		<select id="calplan-event-buffer">
			<?php foreach ([0, 15, 30, 45, 60, 90, 120] as $minutes): ?>
				<option value="<?php p($minutes); ?>"><?php p($minutes); ?> minutes</option>
			<?php endforeach; ?>
		</select>
	</div>
	<div class="calplan-setting-row">
		<label for="calplan-auto-reschedule">How often to check and auto-reschedule unfinished tasks</label>
		<select id="calplan-auto-reschedule">
			<option value="0">Off — tag for review and stop scheduling</option>
			<option value="5">Every 5 minutes</option>
			<option value="15">Every 15 minutes</option>
			<option value="30">Every 30 minutes</option>
			<option value="60">Hourly</option>
		</select>
		<p class="settings-hint">Requires Nextcloud background jobs. Manual Reconcile always checks immediately. Expired incomplete tasks receive the filterable calplan-review (“Is complete?”) tag.</p>
	</div>
	<button id="calplan-save-policy" class="primary" type="button">Save CalPlan settings</button>
	<span id="calplan-settings-status" role="status" aria-live="polite"></span>
		</div>
	</details>

	<details class="calplan-settings-group">
		<summary>Calendar and event exclusions</summary>
		<div class="calplan-settings-group__content">
	<p class="settings-hint">These exclusions change what counts as busy and where CalPlan is allowed to work.</p>
	<div class="calplan-warning" role="note">
		<strong>Ignored calendars are completely outside CalPlan.</strong> Reconcile will not read their tasks or events and will not create, update, review, or remove blocks there. Existing <code>calplan</code> tags and blocks are left untouched until you stop ignoring the calendar.
	</div>
	<fieldset class="calplan-calendar-exclusions">
		<legend>Completely ignore these calendars</legend>
		<?php if ($_['calendarOptions'] === []): ?>
			<p class="settings-hint">No calendars are currently available.</p>
		<?php endif; ?>
		<?php foreach ($_['calendarOptions'] as $calendar): ?>
			<label class="calplan-calendar-option">
				<input type="checkbox" name="calplan-ignored-calendar" value="<?php p($calendar['uri']); ?>" <?php if (in_array($calendar['uri'], $_['ignoredCalendars'], true)): ?>checked<?php endif; ?>>
				<span><?php p($calendar['name']); ?><?php if (!$calendar['available']): ?> <em>(currently unavailable)</em><?php endif; ?></span>
				<small><?php p($calendar['uri']); ?></small>
			</label>
		<?php endforeach; ?>
	</fieldset>
	<div class="calplan-setting-row">
		<label for="calplan-ignored-event-titles">Ignore ordinary calendar events with these titles</label>
		<textarea id="calplan-ignored-event-titles" rows="5" placeholder="Busy&#10;Booking hold"><?php p(implode("\n", $_['ignoredEventTitles'])); ?></textarea>
		<p class="settings-hint">One title per line. Matching is case-insensitive. Only ordinary events are ignored as busy time; task titles and CalPlan’s own blocks are never filtered.</p>
	</div>
	<div class="calplan-setting-row">
		<label for="calplan-event-title-match-mode">Title matching</label>
		<select id="calplan-event-title-match-mode">
			<option value="exact" <?php if ($_['eventTitleMatchMode'] === 'exact'): ?>selected<?php endif; ?>>Exact title</option>
			<option value="contains" <?php if ($_['eventTitleMatchMode'] === 'contains'): ?>selected<?php endif; ?>>Contains / partial title</option>
		</select>
	</div>
	<button id="calplan-save-exclusions" type="button">Save exclusions</button>
	<span id="calplan-exclusions-status" role="status" aria-live="polite"></span>
		</div>
	</details>

	<details class="calplan-settings-group">
		<summary>Personal scheduling history</summary>
		<div class="calplan-settings-group__content">
	<p class="settings-hint">Optional, privacy-minimal observations for future Phase X personalization. Disabled by default; task titles and notes are never recorded.</p>
	<div class="calplan-setting-row calplan-checkbox-row">
		<input id="calplan-history-enabled" type="checkbox">
		<label for="calplan-history-enabled">Collect scheduling history</label>
	</div>
	<div class="calplan-setting-row">
		<label for="calplan-history-retention">Keep history for</label>
		<div><input id="calplan-history-retention" type="number" min="30" max="730" step="30"> days</div>
	</div>
	<button id="calplan-save-history" type="button">Save history settings</button>
	<button id="calplan-export-history" type="button">Export history to Files</button>
	<button id="calplan-delete-history" class="warning" type="button">Delete my history</button>
	<span id="calplan-history-status" role="status" aria-live="polite"></span>
		</div>
	</details>

	<details class="calplan-settings-group calplan-diagnostics">
		<summary>Reconcile diagnostics</summary>
		<div class="calplan-settings-group__content">
			<p class="settings-hint">The latest 10 runs. Privacy-safe counts and timings only: no task/event titles, notes, URLs, UIDs, or raw calendar data.</p>
			<?php if ($_['reconcileDiagnostics'] === []): ?>
				<p>No Reconcile run has been recorded yet.</p>
			<?php endif; ?>
			<?php foreach ($_['reconcileDiagnostics'] as $index => $run): ?>
				<details class="calplan-diagnostic-run" <?php if ($index === 0): ?>open<?php endif; ?>>
					<summary>
						<?php p($run['timestamp'] ?? 'Unknown time'); ?> —
						<?php p($run['trigger'] ?? 'other'); ?> —
						<?php p((int)($run['placed'] ?? 0)); ?> placed,
						<?php p((int)($run['removed'] ?? 0)); ?> removed,
						<?php p((int)($run['unscheduled'] ?? 0)); ?> could not fit
					</summary>
					<dl class="calplan-diagnostic-grid">
						<dt>Calendars</dt><dd><?php p((int)($run['calendars'] ?? 0)); ?> active / <?php p((int)($run['ignoredCalendars'] ?? 0)); ?> ignored</dd>
						<dt>Read</dt><dd><?php p((int)($run['eventsRead'] ?? 0)); ?> events, <?php p((int)($run['tasksRead'] ?? 0)); ?> tasks</dd>
						<dt>Title exclusions</dt><dd><?php p((int)($run['eventsIgnoredByTitle'] ?? 0)); ?> events ignored</dd>
						<dt>Plan</dt><dd><?php p((int)($run['unchanged'] ?? 0)); ?> unchanged, <?php p((int)($run['scheduledTasks'] ?? 0)); ?> eligible tasks</dd>
						<dt>Write failures</dt><dd><?php p((int)($run['writeFailures']['put'] ?? 0)); ?> put, <?php p((int)($run['writeFailures']['delete'] ?? 0)); ?> delete</dd>
						<dt>Total time</dt><dd><?php p((string)($run['timingsMs']['total'] ?? 0)); ?> ms</dd>
					</dl>
					<?php if (($run['unscheduledReasons'] ?? []) !== []): ?>
						<p><strong>Could not fit:</strong> <?php p(implode(', ', array_map(fn ($reason, $count) => $reason . ': ' . $count, array_keys($run['unscheduledReasons']), $run['unscheduledReasons']))); ?></p>
					<?php endif; ?>
					<details class="calplan-diagnostic-calendars">
						<summary>Per-calendar counts</summary>
						<ul>
							<?php foreach (($run['byCalendar'] ?? []) as $calendar): ?>
								<li><strong><?php p($calendar['calendar'] ?? 'Calendar'); ?>:</strong> <?php p((int)($calendar['eventsRead'] ?? 0)); ?> events, <?php p((int)($calendar['tasksRead'] ?? 0)); ?> tasks, <?php p((int)($calendar['placed'] ?? 0)); ?> placed, <?php p((int)($calendar['removed'] ?? 0)); ?> removed, <?php p((int)($calendar['unchanged'] ?? 0)); ?> unchanged, <?php p((int)($calendar['unscheduled'] ?? 0)); ?> could not fit</li>
							<?php endforeach; ?>
						</ul>
					</details>
				</details>
			<?php endforeach; ?>
		</div>
	</details>
</div>

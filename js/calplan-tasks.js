/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * CalPlan Tasks-frontend injection (runtime, no build step).
 *
 * Loaded by OCA\AutoSchedule\AppInfo\Application::boot() via
 * OCP\Util::addScript('calplan', 'calplan-tasks'), for logged-in users the
 * app is enabled for. If calplan is disabled, nothing loads and the Tasks app
 * is unchanged (the `calplan` category degrades to a plain text tag in CalDAV
 * clients). Nothing breaks.
 *
 * What it does, only on the Tasks and Calendar app routes:
 *   1. Both apps — adds the Reconcile CalPlan navigation action and shared
 *      help; in Calendar, refreshes the view when an edit re-planned blocks.
 *
 * Tasks-only behavior:
 *   2. Task list — renders a small SVG "CalPlan" icon next to any task that
 *      carries the `calplan` category, read from the rendered tag chips, the
 *      time of its next block (for a parent whose subtasks get the blocks,
 *      theirs), and a warning icon next to tasks CalPlan could not schedule.
 *   3. Sidebar — injects an "Auto-schedule" switch after the "All day" row,
 *      bound to whether the open task has the `calplan` category, plus the
 *      Duration, Split if needed and Daily limit rows. Changes go through
 *      Tasks' own Vuex actions (so they persist through Tasks' CalDAV sync);
 *      the server re-plans after each write.
 *
 * Everything is guarded: if the Tasks app, its Vuex store, the Vue router, or
 * the expected DOM is missing or shaped differently across versions, the
 * script no-ops silently rather than throwing.
 */
(function () {
	'use strict'

	// Capture this script's URL while it's the executing script so we can
	// derive the app's img/ base (see imgBaseUrl). Done up here because
	// document.currentScript is only valid during synchronous execution.
	var SCRIPT_SRC = ''
	try { if (document.currentScript && document.currentScript.src) SCRIPT_SRC = document.currentScript.src } catch (e) {}
	if (!SCRIPT_SRC) { var _s = document.querySelector('script[src*="calplan-tasks"]'); if (_s) SCRIPT_SRC = _s.src }

	var FLAG = 'calplan'
	var REVIEW_FLAG = 'calplan-review'
	// When this page loaded its tasks: rewrites before that are already in them
	// (a minute earlier, in case the server's clock is behind).
	var PAGE_LOADED_AT = Math.floor(Date.now() / 1000) - 60

	/** Only Tasks and Calendar own the CalPlan navigation action (not Calendar's public pages). */
	function isSupportedAppPath() {
		var path = String(window.location && window.location.pathname || '')
		return /\/(?:index\.php\/)?apps\/(?:tasks|calendar)(?:\/(?!p\/|embed\/|appointment\/)|$)/.test(path)
	}

	function isCalendarPath() {
		return /\/apps\/calendar(?:\/|$)/.test(String(window.location && window.location.pathname || ''))
	}

	function warn() {
		var a = Array.prototype.slice.call(arguments)
		a.unshift('[CalPlan]')
		try { console.warn.apply(console, a) } catch (e) {}
	}

	// --- Translation and server access ---------------------------------------

	function fill(text, vars) {
		return String(text).replace(/\{(\w+)\}/g, function (match, key) {
			return vars && Object.prototype.hasOwnProperty.call(vars, key) ? String(vars[key]) : match
		})
	}

	/** Nextcloud's t() for this app; placeholders are filled unescaped (callers use textContent or escape). */
	function t(text, vars) {
		try {
			if (typeof window.t === 'function') return window.t('calplan', text, vars || {}, undefined, { escape: false })
		} catch (e) {}
		return fill(text, vars)
	}

	/** Nextcloud's n() for this app: singular or plural by *count* ({count} is filled in). */
	function n(singular, plural, count, vars) {
		var all = Object.assign({ count: count }, vars || {})
		try {
			if (typeof window.n === 'function') return window.n('calplan', singular, plural, count, all, { escape: false })
		} catch (e) {}
		return fill(count === 1 ? singular : plural, all)
	}

	function escapeHtml(text) {
		return String(text).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
		})
	}

	/** The URL of CalPlan's OCS API *path* ("/reconcile"), webroot-aware and working without URL rewriting. */
	function apiUrl(path) {
		var base = ''
		try {
			if (window.OC && typeof OC.linkToOCS === 'function') base = OC.linkToOCS('apps/calplan/api/v1', 2)
		} catch (e) {}
		if (!base) base = ((window.OC && OC.webroot) || '') + '/ocs/v2.php/apps/calplan/api/v1/'
		return base.replace(/\/+$/, '') + path
	}

	/** An OCS request; resolves to the OCS data payload, rejects with the server's error message. */
	function api(method, path, body) {
		var headers = {
			'OCS-APIRequest': 'true',
			'Accept': 'application/json',
			'requesttoken': (window.OC && OC.requestToken) || window.oc_requesttoken || '',
		}
		if (body !== undefined) headers['Content-Type'] = 'application/json'
		return fetch(apiUrl(path), {
			method: method,
			credentials: 'same-origin',
			headers: headers,
			body: body !== undefined ? JSON.stringify(body) : undefined,
		}).then(function (response) {
			return response.json().catch(function () { return null }).then(function (json) {
				var data = json && json.ocs ? json.ocs.data : json
				if (!response.ok) throw new Error((data && data.error) || ('HTTP ' + response.status))
				return data
			})
		})
	}

	function notify(message, isError) {
		try {
			if (window.OCP && OCP.Toast) {
				if (isError) OCP.Toast.error(message)
				else OCP.Toast.info(message)
				return
			}
		} catch (e) {}
		warn(message)
	}

	// --- Tasks app access --------------------------------------------------

	/** @returns {object|null} the Tasks Vue app instance, or null when not on Tasks */
	function tasksApp() {
		return (window.OCA && OCA.Tasks && OCA.Tasks.App) ? OCA.Tasks.App : null
	}

	/** The Vuex store Tasks registers on its Vue app. */
	function vuexStore() {
		var app = tasksApp()
		if (!app) return null
		return app.$store || null
	}

	/**
	 * The Vue router, for reading the open task's :taskId route param.
	 *
	 * OCA.Tasks.App is the root component PROXY returned by createApp().mount()
	 * (NOT the app instance), so $router lives at app.$router — exactly like the
	 * Vuex store lives at app.$store (see vuexStore()). The
	 * app.config.globalProperties.$router path is always undefined on the
	 * proxy; keep it only as a fallback for non-standard mounts.
	 */
	function vueRouter() {
		var app = tasksApp()
		if (!app) return null
		if (app.$router) return app.$router
		if (app.config && app.config.globalProperties && app.config.globalProperties.$router) {
			return app.config.globalProperties.$router
		}
		return null
	}

	/** The object URI (.ics filename) of the task open in the sidebar, or null. */
	function openTaskUri() {
		var router = vueRouter()
		try {
			var route = router && router.currentRoute && (router.currentRoute.value || router.currentRoute)
			var params = route && route.params
			if (params && params.taskId) return String(params.taskId)
		} catch (e) { /* fall through to URL parsing */ }
		var path = location.pathname + location.hash
		var matches = path.match(/\/tasks\/([^/?#]+)/g)
		return matches && matches.length
			? decodeURIComponent(matches[matches.length - 1].slice('/tasks/'.length))
			: null
	}

	/** The open task object from the Vuex store, or null. */
	function openTask() {
		var store = vuexStore()
		if (!store || !store.getters) return null

		// 1. Try getter with route
		var router = vueRouter()
		var route = router && router.currentRoute && (router.currentRoute.value || router.currentRoute)
		if (route && typeof store.getters.getTaskByRoute === 'function') {
			try {
				var t1 = store.getters.getTaskByRoute(route)
				if (t1) return t1
			} catch (e) {}
		}

		// 2. Try getter with URI
		var uri = openTaskUri()
		if (uri && typeof store.getters.getTaskByUri === 'function') {
			try {
				var t2 = store.getters.getTaskByUri(uri)
				if (t2) return t2
			} catch (e) {}
		}

		// 3. Store state scan fallback
		try {
			var state = store.state && store.state.tasks
			if (state && state.selectedTask) return state.selectedTask
		} catch (e) {}
		return null
	}

	function sameTag(tag, name) {
		return String(tag).trim().toLowerCase() === name
	}

	function isFlagged(task) {
		if (!task || !Array.isArray(task.tags)) return false
		for (var i = 0; i < task.tags.length; i++) {
			if (sameTag(task.tags[i], FLAG)) return true
		}
		return false
	}

	/** Whether Tasks refuses to save *task* (read-only calendar, or a non-public task shared with the user). */
	function isReadOnly(task) {
		var calendar = task && task.calendar
		return !!calendar && (!!calendar.readOnly || (!!calendar.isSharedWithMe && task.class !== 'PUBLIC'))
	}

	/** Every subtask below *task*, depth first (Tasks keeps them in task.subTasks, keyed by UID); cycle-safe. */
	function descendants(task) {
		var out = []
		var seen = {}
		if (task && task.uid) seen[task.uid] = true
		function walk(node) {
			var subs = node && node.subTasks ? node.subTasks : {}
			for (var key in subs) {
				var sub = subs[key]
				if (!Object.prototype.hasOwnProperty.call(subs, key) || !sub || seen[sub.uid]) continue
				seen[sub.uid] = true
				out.push(sub)
				walk(sub)
			}
		}
		walk(task)
		return out
	}

	/**
	 * Call *cb* once Tasks has finished the CalDAV PUT for *task*: Tasks'
	 * setTags/updateTask do not return the write, and updateTask only sets
	 * updateScheduled when one is in flight, so poll task.updateRunning /
	 * task.updateScheduled. *cb* gets false when the save failed (Tasks shows
	 * its own error or conflict on the task).
	 */
	function whenTaskWritten(task, cb) {
		if (!task) { cb(true); return }
		var seenRunning = false, tries = 0
		function done() {
			var status = task.syncStatus && task.syncStatus.status
			cb(status !== 'error' && status !== 'conflict')
		}
		function tick() {
			if (task.updateRunning || task.updateScheduled) seenRunning = true
			if (seenRunning && !task.updateRunning && !task.updateScheduled) return done()
			// If no write was ever observed in flight after ~2s, assume it already
			// completed (or was not queued) and proceed regardless.
			if (++tries > 20 && !seenRunning && !task.updateRunning && !task.updateScheduled) return done()
			if (tries > 200) return cb(false) // 20s hard cap
			setTimeout(tick, 100)
		}
		tick()
	}

	/** Call *cb* once every task in *tasks* has finished its write (they run in parallel); false if one failed. */
	function whenTasksWritten(tasks, cb) {
		var pending = tasks.length
		var ok = true
		if (pending === 0) { cb(true); return }
		tasks.forEach(function (task) {
			whenTaskWritten(task, function (written) {
				ok = ok && written
				if (--pending === 0) cb(ok)
			})
		})
	}

	// --- 1. Task-list icon ------------------------------------------------
	//
	// The CalPlan badge/sidebar icons live as standalone SVG files in the
	// app's img/ dir (calplan-task-icon.svg, calplan-sidebar-icon.svg) so the
	// artwork is editable/viewable outside the JS and ships as app assets. They
	// are fetched once at init and cached as inline markup, then injected via
	// innerHTML so `fill="currentColor"` still follows the surrounding text
	// color (an <img src> would lose that theming). The script's own src gives
	// us the app's base URL without guessing NC's asset/theming paths.

	var iconTaskSvg = ''
	var iconSidebarSvg = ''

	function imgBaseUrl() {
		// ".../apps/calplan/js/calplan-tasks.js?v=..." -> ".../apps/calplan/img/"
		return SCRIPT_SRC ? SCRIPT_SRC.replace(/\/js\/.*$/, '/img/') : ''
	}

	function fetchIcon(name, cb) {
		var base = imgBaseUrl()
		if (!base) { cb(''); return }
		try {
			fetch(base + name, { credentials: 'same-origin' })
				.then(function (r) { return r.ok ? r.text() : '' })
				.then(function (txt) { cb(txt || '') })
				.catch(function () { cb('') })
		} catch (e) { cb('') }
	}

	function loadIcons(cb) {
		var pending = 2, settled = false
		function finish() { if (!settled) { settled = true; cb() } }
		function one() { if (--pending === 0) finish() }
		fetchIcon('calplan-task-icon.svg', function (svg) { iconTaskSvg = svg; one() })
		fetchIcon('calplan-sidebar-icon.svg', function (svg) { iconSidebarSvg = svg; one() })
		// Don't block the UI forever if an asset 404s.
		setTimeout(finish, 3000)
	}

	/** Read the flag purely from the rendered tag chips in one task body. */
	function taskHasFlagChip(body) {
		var labels = body.querySelectorAll('.tags-list .tag .tag-label')
		for (var i = 0; i < labels.length; i++) {
			if (sameTag(labels[i].textContent || '', FLAG)) return true
		}
		return false
	}

	function decorateTaskItem(li) {
		var body = li.querySelector('.task-item__body')
		if (!body) return
		var hasFlag = taskHasFlagChip(body)
		// The row's own body only: a nested subtask row has its own icon.
		var existingIcon = body.querySelector('.calplan-task-icon')

		if (hasFlag) {
			if (!existingIcon && iconTaskSvg) {
				var icons = body.querySelector('.task-body__icons')
				if (icons) {
					var span = document.createElement('span')
					span.className = 'calplan-task-icon'
					span.innerHTML = iconTaskSvg
					icons.insertBefore(span, icons.firstChild)
				}
			}
			if (li.getAttribute('data-calplan-marked') !== '1') li.setAttribute('data-calplan-marked', '1')
		} else {
			if (existingIcon) existingIcon.remove()
			if (li.hasAttribute('data-calplan-marked')) li.removeAttribute('data-calplan-marked')
		}

		// Not-scheduled warning, scoped to this row's own body (not its subtasks).
		// DOM writes only on change: the page-wide observer re-runs this.
		var entry = unscheduledByUri(body.getAttribute('task-id'))
		var warning = body.querySelector('.calplan-unscheduled-icon')
		if (isWarning(entry)) {
			var text = t('Not scheduled: {reason}', { reason: reasonText(entry.reason, null) })
			var iconBox = body.querySelector('.task-body__icons')
			if (!warning && iconBox) {
				warning = document.createElement('span')
				warning.className = 'calplan-unscheduled-icon'
				warning.innerHTML = ICON_ALERT
				iconBox.insertBefore(warning, iconBox.firstChild)
			}
			if (warning && warning.title !== text) warning.title = text
		} else if (warning) {
			warning.remove()
		}

		// When the row's next block is planned, after the CalPlan icon; for a
		// parent whose subtasks get the blocks (a course, a module), theirs.
		var uri = body.getAttribute('task-id')
		var planned = plannedByUri(uri)
		var label = null
		var title = null
		if (planned && planned.blocks && planned.blocks.length > 0) {
			label = shortWhen(nextBlock(planned.blocks)[0])
			title = plannedText(planned.blocks)
		} else if (anyPlanned) {
			var plan = subtaskPlan(taskByUri(uri))
			if (plan) {
				label = shortWhen(plan.block[0])
				title = subtaskPlanText(plan)
			}
		}
		var when = body.querySelector('.calplan-planned')
		var icons2 = body.querySelector('.task-body__icons')
		if (label !== null && icons2) {
			if (!when) {
				when = document.createElement('span')
				when.className = 'calplan-planned'
				var icon = icons2.querySelector('.calplan-task-icon')
				icons2.insertBefore(when, icon ? icon.nextSibling : icons2.firstChild)
			}
			if (when.textContent !== label) when.textContent = label
			if (when.title !== title) when.title = title
		} else if (when) {
			when.remove()
		}
	}

	function scanTaskList(root) {
		var items = root.querySelectorAll('li.task-item')
		for (var i = 0; i < items.length; i++) {
			decorateTaskItem(items[i])
		}
	}

	// --- Reconcile status and not-scheduled warnings -------------------------

	/*
	 * After every reconcile the server keeps the tasks it could not place
	 * (task UID => {reason, uri}). Tasks marks them: a warning icon in the list
	 * and a "Not scheduled" line with the reason in the sidebar; a parent says
	 * how many of its subtasks are not scheduled. Nothing is written to tasks.
	 */
	var unscheduledTasks = {}
	var unscheduledUris = {}
	// Task UID => {uri, blocks: [[start, end], ...]} (Unix seconds): when each task is planned.
	var plannedTasks = {}
	var plannedUris = {}
	var anyPlanned = false
	var latestReconcileRun = null
	var statusSeq = 0
	var appliedStatusSeq = 0
	var seenRewrites = {}
	var ICON_ALERT = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M12,2L1,21H23M12,6L19.53,19H4.47M11,10V14H13V10M11,16V18H13V16"/></svg>'

	function reasonTexts() {
		return {
			no_fit_before_due: t('no free time before the deadline'),
			task_daily_limit_reached: t('no room in the planning window within its daily limit (its own or a parent task’s)'),
			daily_task_count_reached: t('no room in the planning window within your maximum blocks per day'),
			duration_exceeds_daily_cap: t('longer than your maximum task time per day'),
			duration_exceeds_openings: t('longer than your longest working-hours window'),
			no_capacity_within_horizon: t('no free time in the planning window'),
			no_working_hours: t('no working hours set'),
			starts_after_horizon: t('it starts after the planning window'),
			calendar_without_events: t('its list holds only tasks, so no calendar can show its block: move it to a calendar that also holds events'),
			duplicate_uid: t('another calendar has a task with the same UID; only that one is planned'),
		}
	}
	// Reasons that "Split if needed" can fix.
	var SPLIT_HELPS = { task_daily_limit_reached: true, duration_exceeds_daily_cap: true, duration_exceeds_openings: true }

	function loadReconcileStatus() {
		var seq = ++statusSeq
		return api('GET', '/reconcile/status').then(function (data) {
			applyStatus(data, seq)
			return latestReconcileRun
		})
	}

	/** Apply a status/reconcile response unless a newer one was applied already. */
	function applyStatus(data, seq) {
		if (!data || seq < appliedStatusSeq) return
		appliedStatusSeq = seq
		if (data.lastRun) latestReconcileRun = data.lastRun
		if (data.unscheduledTasks) setUnscheduled(data.unscheduledTasks)
		if (data.plannedTasks) setPlanned(data.plannedTasks)
		if (data.rewrittenTasks) reloadRewrittenTasks(data.rewrittenTasks)
	}

	/**
	 * Tasks CalPlan rewrote on the server (the expired-block review) since
	 * this page loaded its copy: reload them, or their next save would be
	 * refused as a conflict.
	 */
	function reloadRewrittenTasks(map) {
		var store = vuexStore()
		if (!store || typeof store.dispatch !== 'function' || !store.getters || typeof store.getters.getTaskByUri !== 'function') return
		Object.keys(map).forEach(function (uid) {
			var entry = map[uid]
			if (!entry || !entry.uri || entry.at < PAGE_LOADED_AT || seenRewrites[uid] === entry.at) return
			var task = null
			try { task = store.getters.getTaskByUri(entry.uri) } catch (e) {}
			if (!task || task.uid !== uid || task.updateRunning || task.updateScheduled) return
			seenRewrites[uid] = entry.at
			store.dispatch('fetchFullTask', { task: task }).catch(function (e) { warn('could not reload a task', e) })
		})
	}

	function setUnscheduled(map) {
		var next = map && typeof map === 'object' && !Array.isArray(map) ? map : {}
		if (JSON.stringify(next) === JSON.stringify(unscheduledTasks)) return
		unscheduledTasks = next
		// Object URI => entry, for the task-list rows (which carry the URI).
		unscheduledUris = {}
		for (var uid in next) {
			if (Object.prototype.hasOwnProperty.call(next, uid) && next[uid] && next[uid].uri) unscheduledUris[next[uid].uri] = next[uid]
		}
		scanTaskList(document)
		ensureSidebarCheckbox()
	}

	function setPlanned(map) {
		var next = map && typeof map === 'object' && !Array.isArray(map) ? map : {}
		if (JSON.stringify(next) === JSON.stringify(plannedTasks)) return
		plannedTasks = next
		plannedUris = {}
		anyPlanned = false
		for (var uid in next) {
			if (Object.prototype.hasOwnProperty.call(next, uid) && next[uid] && next[uid].uri) plannedUris[next[uid].uri] = next[uid]
			anyPlanned = true
		}
		scanTaskList(document)
		ensureSidebarCheckbox()
	}

	function plannedByUri(uri) {
		return uri && Object.prototype.hasOwnProperty.call(plannedUris, uri) ? plannedUris[uri] : null
	}

	/** The planned blocks of *task* itself, or null. */
	function ownPlanned(task) {
		var entry = task && Object.prototype.hasOwnProperty.call(plannedTasks, task.uid) ? plannedTasks[task.uid] : null
		return entry && entry.blocks && entry.blocks.length > 0 && (!entry.uri || !task.uri || entry.uri === task.uri) ? entry : null
	}

	/** The block still to come or under way, else the last one. */
	function nextBlock(blocks) {
		var now = Date.now() / 1000
		for (var i = 0; i < blocks.length; i++) {
			if (blocks[i][1] > now) return blocks[i]
		}
		return blocks[blocks.length - 1]
	}

	function pad2(n) { return (n < 10 ? '0' : '') + n }

	function isoDay(date) { return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate()) }

	function hhmm(date) { return pad2(date.getHours()) + ':' + pad2(date.getMinutes()) }

	/** A block start for the task list: "today 16:15", "tomorrow 09:00", "Mon 16:15", else "2026-10-25 09:00". */
	function shortWhen(seconds) {
		var date = new Date(seconds * 1000)
		var today = new Date()
		today.setHours(0, 0, 0, 0)
		var days = Math.round((new Date(date.getFullYear(), date.getMonth(), date.getDate()) - today) / 86400000)
		if (days === 0) return t('today {time}', { time: hhmm(date) })
		if (days === 1) return t('tomorrow {time}', { time: hhmm(date) })
		if (days > 1 && days < 7) {
			var weekday = ''
			try {
				var locale = (window.OC && typeof OC.getLanguage === 'function' && OC.getLanguage()) || undefined
				weekday = date.toLocaleDateString(locale, { weekday: 'short' })
			} catch (e) {}
			if (weekday) return weekday + ' ' + hhmm(date)
		}
		return isoDay(date) + ' ' + hhmm(date)
	}

	/** "2026-10-18 16:15–16:27", with the end's day when it is another one. */
	function blockText(block) {
		var start = new Date(block[0] * 1000)
		var end = new Date(block[1] * 1000)
		return isoDay(start) + ' ' + hhmm(start) + '–' + (isoDay(end) === isoDay(start) ? '' : isoDay(end) + ' ') + hhmm(end)
	}

	/** "Planned: 2026-10-18 16:15–16:27; 2026-10-19 09:00–09:45". */
	function plannedText(blocks) {
		return t('Planned: {blocks}', { blocks: blocks.map(blockText).join('; ') })
	}

	/** The Tasks store's task stored at object URI *uri*, or null. */
	function taskByUri(uri) {
		var store = vuexStore()
		if (!uri || !store || !store.getters || typeof store.getters.getTaskByUri !== 'function') return null
		try { return store.getters.getTaskByUri(uri) || null } catch (e) { return null }
	}

	/**
	 * For a parent whose subtasks get the blocks: the subtask whose block is
	 * under way or comes next (else the one that ended last) with that block,
	 * how many subtasks are planned, and when the last block ends. Null when
	 * no subtask is planned. Display only.
	 */
	function subtaskPlan(task) {
		if (!task) return null
		var now = Date.now() / 1000
		var next = null
		var latest = null
		var count = 0
		var subs = descendants(task)
		for (var i = 0; i < subs.length; i++) {
			var entry = ownPlanned(subs[i])
			if (!entry) continue
			count++
			for (var j = 0; j < entry.blocks.length; j++) {
				var block = entry.blocks[j]
				if (block[1] > now && (!next || block[0] < next.block[0])) next = { task: subs[i], block: block }
				if (!latest || block[1] > latest.block[1]) latest = { task: subs[i], block: block }
			}
		}
		if (count === 0) return null
		var shown = next || latest
		return { task: shown.task, block: shown.block, count: count, lastEnd: latest.block[1] }
	}

	/** "Next subtask: Lesson 1, 2026-11-01 09:00–10:00 · 24 subtasks planned, the last on 2026-11-14". */
	function subtaskPlanText(plan) {
		return t('Next subtask: {title}, {block}', { title: plan.task.summary || '', block: blockText(plan.block) }) + ' · ' +
			n('{count} subtask planned, the last on {day}', '{count} subtasks planned, the last on {day}', plan.count, { day: isoDay(new Date(plan.lastEnd * 1000)) })
	}

	/** A real problem, not just a task that starts after the planning window. */
	function isWarning(entry) {
		return !!entry && entry.reason !== 'starts_after_horizon'
	}

	function unscheduledByUri(uri) {
		return uri && Object.prototype.hasOwnProperty.call(unscheduledUris, uri) ? unscheduledUris[uri] : null
	}

	/** The entry of *task* itself (a duplicate UID elsewhere is matched by its object URI). */
	function ownEntry(task) {
		var entry = task && Object.prototype.hasOwnProperty.call(unscheduledTasks, task.uid) ? unscheduledTasks[task.uid] : null
		return entry && (!entry.uri || !task.uri || entry.uri === task.uri) ? entry : null
	}

	function reasonText(reason, task) {
		var text = reasonTexts()[reason] || String(reason).replace(/_/g, ' ')
		if (SPLIT_HELPS[reason] && task && !flagOn(task, SPLIT_PROP) && !ancestorFlagOn(task, SPLIT_PROP)) {
			text = t('{reason} (turn on Split if needed)', { reason: text })
		}
		return text
	}

	/** The sidebar notice for *task*, or null. */
	function noticeText(task) {
		if (!task) return null
		var own = ownEntry(task)
		if (isWarning(own)) return t('Not scheduled: {reason}', { reason: reasonText(own.reason, task) })
		var count = descendants(task).filter(function (sub) { return isWarning(ownEntry(sub)) }).length
		if (count > 0) return n('{count} subtask is not scheduled', '{count} subtasks are not scheduled', count)
		if (own) return t('Not planned yet: {reason}', { reason: reasonText(own.reason, task) })
		return null
	}

	function renderReconcileStatus(container, run) {
		if (!container) return
		container.textContent = ''
		if (!run) {
			container.textContent = t('No Reconcile run has been recorded yet.')
			return
		}
		var time = run.timestamp || t('unknown time')
		try { time = new Date(time).toLocaleString() } catch (e) {}
		var summary = document.createElement('p')
		summary.appendChild(document.createTextNode(time + ' · ' + (run.trigger || 'other') + ': '))
		var strong = document.createElement('strong')
		strong.textContent = t('{placed} placed, {removed} removed, {unchanged} unchanged, {unscheduled} could not fit', {
			placed: run.placed || 0, removed: run.removed || 0, unchanged: run.unchanged || 0, unscheduled: run.unscheduled || 0,
		})
		summary.appendChild(strong)
		container.appendChild(summary)
		var detail = document.createElement('p')
		detail.textContent = t('{events} events and {tasks} tasks read; {ignored} events ignored by title. Total {ms} ms.', {
			events: run.eventsRead || 0, tasks: run.tasksRead || 0, ignored: run.eventsIgnoredByTitle || 0, ms: (run.timingsMs && run.timingsMs.total) || 0,
		})
		container.appendChild(detail)
		var failures = ((run.writeFailures && run.writeFailures.put) || 0) + ((run.writeFailures && run.writeFailures.delete) || 0)
		if (failures > 0) {
			var warning = document.createElement('p')
			warning.className = 'calplan-help-warning'
			warning.textContent = n('{count} calendar write failed; see Reconcile diagnostics in Personal settings.', '{count} calendar writes failed; see Reconcile diagnostics in Personal settings.', failures)
			container.appendChild(warning)
		}
	}

	// --- Calendar page ---------------------------------------------------

	/** A Pinia store of the Calendar app (mounted on #content), or null. */
	function calendarStore(id) {
		var content = document.querySelector('#content')
		var app = content && content.__vue_app__
		var pinia = app && app.config && app.config.globalProperties && app.config.globalProperties.$pinia
		return pinia && pinia._s && typeof pinia._s.get === 'function' ? pinia._s.get(id) || null : null
	}

	/** The current sync token of the CalDAV collection *url*, or null. */
	function fetchSyncToken(url) {
		return fetch(url, {
			method: 'PROPFIND',
			credentials: 'same-origin',
			headers: { 'Depth': '0', 'Content-Type': 'application/xml; charset=utf-8', 'requesttoken': (window.OC && OC.requestToken) || '' },
			body: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:sync-token/></d:prop></d:propfind>',
		}).then(function (response) {
			return response.ok ? response.text() : ''
		}).then(function (xml) {
			var doc = xml ? new DOMParser().parseFromString(xml, 'application/xml') : null
			var node = doc && doc.getElementsByTagNameNS('DAV:', 'sync-token')[0]
			return node ? node.textContent : null
		}).catch(function () { return null })
	}

	/**
	 * On the Calendar page, show a new plan now instead of at Calendar's next
	 * 60-second background sync. Like that sync, ask each calendar for its
	 * current sync token and let Calendar's own Pinia action syncCalendar()
	 * refetch the calendars whose token changed (it drops their cached time
	 * ranges and bumps modificationCount, which makes FullCalendar refetch)
	 * and remember the new token. Guarded: a no-op when Calendar's app/store
	 * is shaped differently.
	 */
	function refreshCalendarApp() {
		if (!isCalendarPath()) return
		try {
			var store = calendarStore('calendars')
			if (!store || typeof store.syncCalendar !== 'function' || !Array.isArray(store.calendars)) return
			store.calendars.forEach(function (calendar) {
				var url = calendar && (calendar.url || (calendar.dav && calendar.dav.url))
				if (!url || !calendar.supportsEvents || calendar.readOnly) return
				fetchSyncToken(url).then(function (token) {
					if (token) store.syncCalendar({ calendar: { id: calendar.id, url: url, dav: { syncToken: token } }, skipIfUnchangedSyncToken: true })
				})
			})
		} catch (e) {
			warn('calendar refresh failed', e)
		}
	}

	function runKey(run) {
		return run ? (run.runId || run.timestamp || '') : ''
	}

	/** Whether any run in *runs* (newest first) up to the one keyed *since* placed or removed a block. */
	function movedSince(runs, since) {
		for (var i = 0; i < runs.length; i++) {
			if (since && runKey(runs[i]) === since) return false
			if ((runs[i].placed || 0) > 0 || (runs[i].removed || 0) > 0) return true
		}
		return false
	}

	// Calendar's own actions that write an event or a task (save, drag/resize, delete, move).
	var CALENDAR_EDIT_ACTIONS = { updateCalendarObject: true, createCalendarObjectFromFork: true, deleteCalendarObject: true, moveCalendarObject: true }
	var calendarEditsWatched = false
	var lastSeenRun = null

	/**
	 * Saving an event or a task re-plans on the server before the save
	 * request returns (CalendarObjectChangeListener). When Calendar's write
	 * action finishes, refresh the view if a re-plan since the last check
	 * moved blocks, so they never sit on top of what was just saved.
	 */
	function watchCalendarEdits() {
		if (calendarEditsWatched || !isCalendarPath()) return
		var store = calendarStore('calendarObjects')
		if (!store || typeof store.$onAction !== 'function') return
		calendarEditsWatched = true
		loadReconcileStatus().then(function (run) { lastSeenRun = runKey(run) }).catch(function () {})
		store.$onAction(function (context) {
			if (!CALENDAR_EDIT_ACTIONS[context.name]) return
			context.after(function () {
				var seq = ++statusSeq
				api('GET', '/reconcile/status').then(function (data) {
					applyStatus(data, seq)
					var runs = (data && data.recentRuns) || []
					if (runs.length === 0 || runKey(runs[0]) === lastSeenRun) return
					var moved = movedSince(runs, lastSeenRun)
					lastSeenRun = runKey(runs[0])
					if (moved) refreshCalendarApp()
				}).catch(function () {})
			})
		})
	}

	// --- Navigation action and help ------------------------------------------

	var reconcileButton = null
	var reconcileRunning = false

	/** Run a reconcile now; resolves to the server's report of that run. */
	function triggerReconcile() {
		var seq = ++statusSeq
		return api('POST', '/reconcile').then(function (data) {
			applyStatus(data, seq)
			return data
		})
	}

	function buildReconcileButton() {
		var button = document.createElement('button')
		button.type = 'button'
		button.id = 'calplanReconcileNow'
		button.className = 'calplan-reconcile-now'
		button.innerHTML = '<span class="calplan-reconcile-icon" aria-hidden="true">↻</span><span class="calplan-reconcile-label"></span>'
		var label = button.querySelector('.calplan-reconcile-label')
		label.textContent = t('Reconcile CalPlan')
		button.addEventListener('click', function () {
			if (reconcileRunning) return
			reconcileRunning = true
			button.disabled = true
			button.classList.add('is-running')
			label.textContent = t('Reconciling…')
			triggerReconcile().then(function (data) {
				if (data && data.ran === false) {
					notify(t('CalPlan is still busy with another reconcile; try again in a moment.'), true)
					return
				}
				var placed = (data && data.placed) || 0
				var removed = (data && data.removed) || 0
				var unscheduled = (data && data.unscheduled) || 0
				if (placed > 0 || removed > 0) refreshCalendarApp()
				notify(unscheduled > 0
					? n('CalPlan reconciled: {placed} placed, {removed} removed, {count} could not fit', 'CalPlan reconciled: {placed} placed, {removed} removed, {count} could not fit', unscheduled, { placed: placed, removed: removed })
					: t('CalPlan reconciled: {placed} placed, {removed} removed', { placed: placed, removed: removed }))
			}).catch(function (error) {
				notify(t('CalPlan reconcile failed: {error}', { error: error && error.message ? error.message : '' }), true)
			}).then(function () {
				reconcileRunning = false
				button.disabled = false
				button.classList.remove('is-running')
				label.textContent = t('Reconcile CalPlan')
			})
		})
		return button
	}

	function buildNavigationHelpButton() {
		var button = document.createElement('button')
		button.type = 'button'
		button.className = 'calplan-navigation-help'
		button.setAttribute('aria-label', t('CalPlan help'))
		button.setAttribute('title', t('CalPlan help'))
		button.textContent = '?'
		button.addEventListener('click', function (event) {
			event.preventDefault()
			event.stopPropagation()
			showHelp('general')
		})
		return button
	}

	function ensureReconcileButton() {
		if (!isSupportedAppPath()) {
			var staleItem = document.querySelector('.calplan-reconcile-item')
			if (staleItem) staleItem.remove()
			reconcileButton = null
			return
		}
		if (reconcileButton && reconcileButton.isConnected) return
		// Put the manual action before the first collection/list in Tasks or Calendar.
		// Use semantic elements rather than @nextcloud/vue's generated classes.
		var navigation = document.querySelector('#app-navigation-vue')
		var list = navigation && navigation.querySelector('nav ul, ul')
		if (!list) {
			reconcileButton = null
			return
		}
		var item = document.createElement('li')
		item.className = 'calplan-reconcile-item'
		var button = buildReconcileButton()
		item.appendChild(button)
		item.appendChild(buildNavigationHelpButton())
		list.insertBefore(item, list.firstChild)
		reconcileButton = button
	}

	function showHelp(mode) {
		var existing = document.getElementById('calplanHelpDialog')
		if (existing) existing.remove()
		var backdrop = document.createElement('div')
		backdrop.id = 'calplanHelpDialog'
		backdrop.className = 'calplan-help-backdrop'
		var taskHelpers = mode === 'task'
			? '  <h3>' + escapeHtml(t('Optional task-note helpers')) + '</h3>' +
			  '  <p>' + t('The <strong>Duration</strong> field under Auto-schedule sets the block length (saved as the task’s <code>ESTIMATED-DURATION</code>). Leave it empty to use your personal default. Clients without the field can still use a notes line such as <code>Duration: 1 hour 30 minutes</code>.') + '</p>' +
			  '  <p>' + t('<code>First step: open the report and write the heading</code> adds a <strong>👣 First step</strong> line to the calendar block. It is optional and does not change scheduling. Legacy <code>Next:</code> lines still work.') + '</p>'
			: ''
		backdrop.innerHTML =
			'<div class="calplan-help-dialog" role="dialog" aria-modal="true" aria-labelledby="calplanHelpTitle">' +
			'  <div class="calplan-help-header"><h2 id="calplanHelpTitle">' + escapeHtml(t('CalPlan help')) + '</h2><button type="button" class="calplan-help-close" aria-label="' + escapeHtml(t('Close')) + '">×</button></div>' +
			'  <p>' + t('<strong>CalPlan finds time for your Nextcloud tasks.</strong> Auto-schedule adds the standard <code>calplan</code> category and creates a derived locked event in the same calendar.') + '</p>' +
			'  <p>' + t('On a task with subtasks, the switch turns Auto-schedule on or off for all its subtasks. While some of them are still to be scheduled or awaiting review, they get the blocks, not the parent; once they are all done, or none has Auto-schedule on, the parent is planned like any task. Its <strong>Daily limit</strong> caps the block time and/or the number of blocks per day for itself and all its subtasks together (limits of nested parents all apply).') + '</p>' +
			'  <p>' + t('<strong>Split if needed</strong>: CalPlan fills the earliest free time with the task and splits it where it does not fit whole, in parts named “Title (1/2)”, “Title (2/2)”… (minimum part length in CalPlan settings, 30 minutes by default). On a task with subtasks it applies to all of them.') + '</p>' +
			'  <h3>' + escapeHtml(t('What Reconcile does')) + '</h3>' +
			'  <p>' + t('Reconcile rereads your flagged tasks and busy calendar events, recomputes the current plan, writes new or changed task blocks, and removes blocks for completed, paused, or no-longer-fitting tasks. A block that has already started stays where it is.') + '</p>' +
			'  <p>' + t('After a block ends and its pacing grace period passes, an incomplete task receives the filterable <code>calplan-review</code> tag — CalPlan’s <strong>“Is complete?”</strong> review marker. Depending on your setting, CalPlan either tries another time or removes Auto-schedule and pauses.') + '</p>' +
			'  <p>' + t('Personal settings can completely exclude calendars or make ordinary events with selected titles stop counting as busy. CalPlan does nothing at all in an excluded calendar, including cleanup; title rules affect ordinary events only.') + '</p>' +
			'  <h3>' + escapeHtml(t('Last Reconcile')) + '</h3><div class="calplan-last-run" aria-live="polite">' + escapeHtml(t('Loading…')) + '</div>' +
			taskHelpers +
			'  <h3>' + escapeHtml(t('Source of truth')) + '</h3>' +
			'  <p>' + t('Edit the task, not the derived calendar block. Use a calendar that supports both tasks and events; a Tasks-only list cannot show CalPlan blocks. Tasks in a calendar someone shared with you are planned by its owner’s CalPlan. Automatic timed checks require Nextcloud background jobs; the Reconcile button always runs immediately.') + '</p>' +
			'  <p><a class="calplan-help-settings" href="#">' + escapeHtml(t('Open CalPlan personal settings')) + '</a></p>' +
			'</div>'
		document.body.appendChild(backdrop)
		var statusContainer = backdrop.querySelector('.calplan-last-run')
		if (latestReconcileRun) renderReconcileStatus(statusContainer, latestReconcileRun)
		loadReconcileStatus().then(function (run) { renderReconcileStatus(statusContainer, run) }).catch(function () { statusContainer.textContent = t('Could not load the latest Reconcile status.') })
		function close() { backdrop.remove() }
		backdrop.querySelector('.calplan-help-close').addEventListener('click', close)
		backdrop.addEventListener('click', function (event) { if (event.target === backdrop) close() })
		backdrop.querySelector('.calplan-help-settings').addEventListener('click', function (event) {
			event.preventDefault()
			var url = window.OC && typeof OC.generateUrl === 'function' ? OC.generateUrl('/settings/user/calplan') : '/settings/user/calplan'
			window.location.href = url
		})
		backdrop.addEventListener('keydown', function (event) { if (event.key === 'Escape') close() })
		backdrop.querySelector('.calplan-help-close').focus()
	}

	// --- 2. Sidebar switches -----------------------------------------------

	/**
	 * A switch row: *switchEl* (role=switch) toggles on a click anywhere in
	 * the row and on Space/Enter while it has focus. Controls of their own in
	 * the row (the help button) sit outside *switchEl* and keep their keys.
	 */
	function bindSwitch(row, switchEl, onToggle) {
		row.addEventListener('click', function (e) {
			if (e.target.closest && e.target.closest('.calplan-help-button')) return
			if (row.classList.contains('is-disabled')) return
			onToggle()
		})
		switchEl.addEventListener('keydown', function (e) {
			if (e.target !== switchEl || (e.key !== ' ' && e.key !== 'Enter')) return
			e.preventDefault()
			if (!row.classList.contains('is-disabled')) onToggle()
		})
	}

	/** Show a switch row as on/off, and disabled when the open task is read-only. */
	function renderSwitch(row, on, disabled) {
		var switchEl = row.querySelector('[role="switch"]')
		var value = on ? 'true' : 'false'
		if (switchEl && switchEl.getAttribute('aria-checked') !== value) switchEl.setAttribute('aria-checked', value)
		if (switchEl && switchEl.getAttribute('aria-disabled') !== String(!!disabled)) switchEl.setAttribute('aria-disabled', String(!!disabled))
		if (row.classList.contains('is-active') !== on) row.classList.toggle('is-active', on)
		if (row.classList.contains('is-disabled') !== !!disabled) row.classList.toggle('is-disabled', !!disabled)
		var box = row.querySelector('.calplan-switch-input')
		if (box && box.checked !== on) box.checked = on
	}

	function switchOn(row) {
		var switchEl = row.querySelector('[role="switch"]')
		return !!switchEl && switchEl.getAttribute('aria-checked') === 'true'
	}

	/** The tags *task* should carry with the flag on/off (on also clears a stale review tag). */
	function flaggedTags(task, on) {
		var tags = []
		var current = Array.isArray(task.tags) ? task.tags : []
		for (var i = 0; i < current.length; i++) {
			if (sameTag(current[i], FLAG) || (on && sameTag(current[i], REVIEW_FLAG))) continue
			tags.push(current[i])
		}
		if (on) tags.push(FLAG)
		return tags
	}

	function sameTags(a, b) {
		if (a.length !== b.length) return false
		for (var i = 0; i < a.length; i++) {
			if (String(a[i]) !== String(b[i])) return false
		}
		return true
	}

	/**
	 * Add/remove the flag via Tasks' own Vuex actions (reactive + CalDAV-synced).
	 * A parent's switch applies to its whole subtree: while subtasks are to be
	 * scheduled the parent is a container (SchedulerService places them, not
	 * it), so they carry the flag that actually schedules them. Completed and
	 * read-only subtasks are left alone. For a subtree the per-write re-plans
	 * are held back, the subtasks are written before the parent, and one
	 * re-plan runs once every write has settled.
	 */
	function toggleFlag(task, on) {
		var store = vuexStore()
		if (!store || !task) { warn('toggleFlag: no store/task'); return }
		var subtree = descendants(task).filter(function (sub) {
			return !(on && sub.completed) && !isReadOnly(sub) && !sameTags(flaggedTags(sub, on), Array.isArray(sub.tags) ? sub.tags : [])
		})
		if (subtree.length === 0) {
			store.dispatch('setTags', { task: task, tags: flaggedTags(task, on) })
			// The PUT re-plans on the server; then refresh the warnings.
			whenTaskWritten(task, function (ok) {
				if (!ok) notify(t('CalPlan: the task could not be saved; reload it in Tasks and try again.'), true)
				loadReconcileStatus().catch(function () {})
			})
			return
		}
		var targets = subtree.reverse().concat([task]) // deepest first, the parent last
		api('POST', '/reconcile/hold').catch(function () {}).then(function () {
			targets.forEach(function (target) {
				store.dispatch('setTags', { task: target, tags: flaggedTags(target, on) })
			})
			whenTasksWritten(targets, function (ok) {
				triggerReconcile().then(function () {
					notify(on
						? n('Auto-schedule on for {count} subtask', 'Auto-schedule on for {count} subtasks', subtree.length)
						: n('Auto-schedule off for {count} subtask', 'Auto-schedule off for {count} subtasks', subtree.length))
					refreshCalendarApp()
				}).catch(function () {})
				if (!ok) notify(t('CalPlan: some tasks could not be saved; reload them in Tasks and try again.'), true)
			})
		})
	}

	/**
	 * Toggle the calplan flag for the open task. The desired state is derived
	 * from the STORE (isFlagged(task)), NOT from the switch's visual state,
	 * so every click/keypress flips exactly once regardless of sync lag. The
	 * visual switch is updated immediately for responsive feedback; the store
	 * mutation + MutationObserver-driven sync confirm it afterwards.
	 */
	function doToggle(row) {
		var task = openTask()
		if (!task || isReadOnly(task)) return
		var wantOn = !isFlagged(task)
		renderSwitch(row, wantOn, false)
		toggleFlag(task, wantOn)
	}

	function buildCheckboxRow() {
		var row = document.createElement('div')
		row.className = 'property__item calplan-toggle-row calplan-autoschedule-row'
		row.innerHTML =
			'<div class="item__content" role="switch" tabindex="0" aria-checked="false">' +
			'  <span class="content__icon">' + iconSidebarSvg + '</span>' +
			'  <span class="content__name"></span>' +
			'  <span class="calplan-switch-wrapper">' +
			'    <input type="checkbox" id="calplanToggle" class="calplan-switch-input" name="calplanToggle" tabindex="-1" aria-hidden="true" />' +
			'    <span class="calplan-switch-slider"></span>' +
			'  </span>' +
			'</div>' +
			'<button type="button" class="calplan-help-button">?</button>'
		var switchEl = row.querySelector('[role="switch"]')
		switchEl.setAttribute('aria-label', t('Auto-schedule'))
		row.querySelector('.content__name').textContent = t('Auto-schedule')
		var help = row.querySelector('.calplan-help-button')
		help.setAttribute('aria-label', t('CalPlan help'))
		help.setAttribute('title', t('CalPlan help'))
		help.addEventListener('click', function (event) {
			event.preventDefault()
			event.stopPropagation()
			showHelp('task')
		})
		bindSwitch(row, switchEl, function () { doToggle(row) })
		// Keep the checkbox `change` listener for the Playwright `flipSwitch`
		// helper, which dispatches a synthetic `change` on #calplanToggle. In
		// real browser usage this never fires (the input is non-interactive), so
		// there is no double-toggle risk with the row click handler above.
		row.querySelector('#calplanToggle').addEventListener('change', function () { doToggle(row) })
		return row
	}

	// --- 3. Sidebar Duration, Split and Daily limit rows ------------------

	/*
	 * The Tasks app has no duration field. This one stores the block length in
	 * the task's ESTIMATED-DURATION property (draft-ietf-calext-ical-tasks: the
	 * estimated effort, independent of DTSTART/DUE), which IcalCodec reads
	 * first. It edits the VTODO held by Tasks' own store and saves it through
	 * Tasks' `updateTask` action, so etags stay consistent. The field shows the
	 * length CalPlan schedules: ESTIMATED-DURATION, else the standard DURATION,
	 * else a legacy `Duration:` notes line (removed on the next save).
	 */
	var ESTIMATE_PROP = 'estimated-duration'
	var DURATION_PROP = 'duration'
	// RFC 5545 durations, as IcalCodec::durationToMinutes reads them.
	var ISO_DURATION = /^\+?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/i
	var DURATION_LINE = /^[ \t]*Duration[ \t]*:[ \t]*(.*?)[ \t]*$/im
	var DURATION_VALUE = /^(?:(\d+(?:\.\d+)?)\s*(?:h|hr|hrs|hour|hours))?(?:\s*(\d+(?:\.\d+)?)\s*(?:m|min|mins|minute|minutes))?$/i
	var ICON_TIMER = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M12,20A7,7 0 0,1 5,13A7,7 0 0,1 12,6A7,7 0 0,1 19,13A7,7 0 0,1 12,20M19.03,7.39L20.45,5.97C20,5.46 19.55,5 19.04,4.56L17.62,6C16.07,4.74 14.12,4 12,4A9,9 0 0,0 3,13A9,9 0 0,0 12,22C17,22 21,17.97 21,13C21,10.88 20.26,8.93 19.03,7.39M11,14H13V8H11M15,1H9V3H15V1Z"/></svg>'

	var ICON_MINUS = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M19,13H5V11H19V13Z"/></svg>'
	var ICON_PLUS = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M19,13H13V19H11V13H5V11H11V5H13V11H19V13Z"/></svg>'
	var STEP_COMMIT_DELAY_MS = 700

	var defaultDurationMinutes = 30

	/** Minutes from the notes' first `Duration:` line, or 0 when absent/invalid (same grammar as the server). */
	function noteDurationMinutes(note) {
		var line = String(note || '').match(DURATION_LINE)
		if (!line || line[1] === '') return 0
		var parts = line[1].match(DURATION_VALUE)
		if (!parts) return 0
		var total = Math.round(parseFloat(parts[1] || '0') * 60 + parseFloat(parts[2] || '0'))
		return total > 0 ? total : 0
	}

	function formatDuration(minutes) {
		var h = Math.floor(minutes / 60)
		var m = minutes % 60
		if (h && m) return t('{h}h {m}min', { h: h, m: m })
		return h ? t('{h}h', { h: h }) : t('{m}min', { m: m })
	}

	/** *note* without its first `Duration:` line - only when that line is a duration CalPlan reads. */
	function noteWithoutDuration(note) {
		note = String(note || '')
		if (noteDurationMinutes(note) <= 0) return note
		var line = note.match(DURATION_LINE)
		var start = line.index
		var end = start + line[0].length
		// The line and its own line break; the rest of the note is untouched.
		if (note.charAt(end) === '\r' && note.charAt(end + 1) === '\n') end += 2
		else if (note.charAt(end) === '\n') end += 1
		else if (start > 0 && note.charAt(start - 1) === '\n') start -= (start > 1 && note.charAt(start - 2) === '\r') ? 2 : 1
		return note.slice(0, start) + note.slice(end)
	}

	/** Minutes from an RFC 5545 duration value ("PT1H30M", "P2D"), or 0 (negative and invalid values too). */
	function isoDurationMinutes(value) {
		var parts = String(value || '').trim().match(ISO_DURATION)
		if (!parts) return 0
		var num = function (i) { return parseInt(parts[i] || '0', 10) }
		return num(1) * 7 * 24 * 60 + num(2) * 24 * 60 + num(3) * 60 + num(4) + Math.floor((num(5) + 30) / 60)
	}

	function isoDuration(minutes) {
		var h = Math.floor(minutes / 60)
		var m = minutes % 60
		return 'PT' + (h ? h + 'H' : '') + (m || !h ? m + 'M' : '')
	}

	function propValue(task, name) {
		var vtodo = task && task.vtodo
		var value = vtodo && typeof vtodo.getFirstPropertyValue === 'function' ? vtodo.getFirstPropertyValue(name) : null
		return value == null ? null : String(value)
	}

	/** Block length in minutes CalPlan schedules (0 = personal default): ESTIMATED-DURATION, DURATION, then a notes line. */
	function taskDurationMinutes(task) {
		var estimate = isoDurationMinutes(propValue(task, ESTIMATE_PROP))
		if (estimate > 0) return estimate
		var duration = propValue(task, DURATION_PROP)
		if (duration !== null) return isoDurationMinutes(duration)
		return noteDurationMinutes(task && task.note)
	}

	function loadDefaultDuration() {
		api('GET', '/settings').then(function (data) {
			var minutes = data && data.scheduling && data.scheduling.defaultDurationMinutes
			if (typeof minutes === 'number' && minutes > 0) {
				defaultDurationMinutes = minutes
				if (durationRowSpec.row) durationRowSpec.placeholders(durationRowSpec.row)
			}
		}).catch(function () {})
	}

	/*
	 * Sidebar rows made of − [value] + steppers. A row may hold a time pair
	 * (inputs with data-part="hours" and "minutes", read as one total) and/or a
	 * count (data-part="count"). Each row spec says when the row shows, how to
	 * read the open task's value and how to write a new one into its VTODO; the
	 * shared code below steps, saves (debounced) and never overwrites a value
	 * that is being edited. A pending save stays bound to the task it was made
	 * on, and is saved at once when another task opens.
	 */

	/** A − [value] + control. Time parts step the row's total by *step* minutes; a count steps by *step*. */
	function stepperHtml(id, part, step, label) {
		var hint = part === 'count' ? String(step) : (step === 60 ? t('1 h') : t('{n} min', { n: step }))
		return '    <span class="calplan-stepper">' +
			'<button type="button" class="calplan-stepper-button" data-part="' + part + '" data-step="-' + step + '" aria-label="' + escapeHtml(t('Less: {label}', { label: label })) + '" title="−' + escapeHtml(hint) + '">' + ICON_MINUS + '</button>' +
			'<input type="number" id="' + id + '" class="calplan-duration-input" data-part="' + part + '" min="0" step="' + (part === 'hours' ? 'any' : '1') + '" inputmode="decimal" aria-label="' + escapeHtml(label) + '" />' +
			'<button type="button" class="calplan-stepper-button" data-part="' + part + '" data-step="' + step + '" aria-label="' + escapeHtml(t('More: {label}', { label: label })) + '" title="+' + escapeHtml(hint) + '">' + ICON_PLUS + '</button>' +
			'</span>'
	}

	function partInput(row, part) { return row.querySelector('input[data-part="' + part + '"]') }

	/** The number in a part's field: hours may be fractional (1.5 h), minutes and counts are whole. */
	function partNumber(row, part) {
		var input = partInput(row, part)
		var raw = input ? String(input.value).replace(',', '.') : ''
		var num = part === 'hours' ? parseFloat(raw) : parseInt(raw, 10)
		return isNaN(num) ? 0 : Math.max(0, num)
	}

	function rowMinutes(row) { return Math.round(partNumber(row, 'hours') * 60) + partNumber(row, 'minutes') }

	function showRowValue(row, value) {
		if ('minutes' in value) {
			partInput(row, 'hours').value = value.minutes > 0 ? String(Math.floor(value.minutes / 60)) : ''
			partInput(row, 'minutes').value = value.minutes > 0 ? String(value.minutes % 60) : ''
		}
		if ('count' in value) partInput(row, 'count').value = value.count > 0 ? String(value.count) : ''
	}

	function rowValue(row, spec) {
		var value = {}
		if (spec.time) value.minutes = rowMinutes(row)
		if (spec.count) value.count = partNumber(row, 'count')
		if (spec.flag) value.on = switchOn(row)
		return value
	}

	/** Set (TRUE) or remove a flag property; true when it changed. */
	function setFlagProp(task, name, on) {
		var current = task.vtodo.getFirstPropertyValue(name)
		var changed = on ? String(current || '').toUpperCase() !== 'TRUE' : current != null
		if (changed) {
			task.vtodo.removeAllProperties(name)
			if (on) task.vtodo.addPropertyWithValue(name, 'TRUE')
		}
		return changed
	}

	function flagOn(task, name) {
		return ['TRUE', 'YES', '1'].indexOf(String(propValue(task, name) || '').trim().toUpperCase()) !== -1
	}

	/** Whether any ancestor of *task* has the flag (Tasks' getParentTask getter; cycle-safe). */
	function ancestorFlagOn(task, name) {
		var store = vuexStore()
		var getParent = store && store.getters && store.getters.getParentTask
		if (typeof getParent !== 'function') return false
		var seen = [task]
		for (var node = getParent(task); node && seen.indexOf(node) === -1; node = getParent(node)) {
			if (flagOn(node, name)) return true
			seen.push(node)
		}
		return false
	}

	/** Set (minutes > 0) or remove a DURATION-valued property; true when it changed. */
	function setDurationProp(task, name, minutes) {
		var current = task.vtodo.getFirstPropertyValue(name)
		var changed = minutes > 0 ? String(current || '') !== isoDuration(minutes) : current != null
		if (changed) {
			task.vtodo.removeAllProperties(name)
			if (minutes > 0) task.vtodo.addPropertyWithValue(name, isoDuration(minutes))
		}
		return changed
	}

	/** Set (n > 0) or remove an integer property; true when it changed. */
	function setCountProp(task, name, count) {
		var current = task.vtodo.getFirstPropertyValue(name)
		var changed = count > 0 ? String(current || '') !== String(count) : current != null
		if (changed) {
			task.vtodo.removeAllProperties(name)
			if (count > 0) task.vtodo.addPropertyWithValue(name, String(count))
		}
		return changed
	}

	function hasSubtasks(task) {
		return !!(task && task.subTasks && Object.keys(task.subTasks).length > 0)
	}

	var LIMIT_TIME_PROP = 'x-calplan-daily-max-time'
	var LIMIT_BLOCKS_PROP = 'x-calplan-daily-max-blocks'
	var ICON_GAUGE = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M12,16A3,3 0 0,1 9,13C9,11.88 9.61,10.9 10.5,10.39L20.21,4.77L14.68,14.35C14.18,15.33 13.17,16 12,16M12,3C13.81,3 15.5,3.5 16.97,4.32L14.87,5.53C14,5.19 13,5 12,5A8,8 0 0,0 4,13C4,15.21 4.89,17.21 6.34,18.65H6.35C6.74,19.04 6.74,19.67 6.35,20.06C5.96,20.45 5.32,20.45 4.93,20.07V20.07C3.12,18.26 2,15.76 2,13A10,10 0 0,1 12,3M22,13C22,15.76 20.88,18.26 19.07,20.07V20.07C18.68,20.45 18.05,20.45 17.66,20.06C17.27,19.67 17.27,19.04 17.66,18.65V18.65C19.11,17.2 20,15.21 20,13C20,12 19.81,11 19.46,10.1L20.67,8C21.5,9.5 22,11.18 22,13Z"/></svg>'

	var durationRowSpec = {
		className: 'calplan-duration-row',
		time: true,
		html: function () {
			return '  <span class="content__icon">' + ICON_TIMER + '</span>' +
				'  <label class="content__name" for="calplanDurationHours">' + escapeHtml(t('Duration')) + '</label>' +
				'  <div class="item__actions">' +
				stepperHtml('calplanDurationHours', 'hours', 60, t('Duration hours')) +
				'    <span class="calplan-duration-unit">' + escapeHtml(t('h')) + '</span>' +
				stepperHtml('calplanDurationMinutes', 'minutes', 15, t('Duration minutes')) +
				'    <span class="calplan-duration-unit">' + escapeHtml(t('min')) + '</span>' +
				'  </div>'
		},
		// A task without a duration shows "–": it gets the default, named in the tooltip.
		placeholders: function (row) {
			partInput(row, 'hours').placeholder = '–'
			partInput(row, 'minutes').placeholder = '–'
			row.title = t('Length of the calendar block. Leave empty to use your default ({duration}).', { duration: formatDuration(defaultDurationMinutes) })
		},
		visible: function (task) { return !!task },
		read: function (task) { return { minutes: taskDurationMinutes(task) } },
		/** ESTIMATED-DURATION; a legacy `Duration:` notes line is dropped on save. Null when nothing changed. */
		write: function (task, value) {
			var note = noteWithoutDuration(task.note)
			var noteChanged = note !== String(task.note || '')
			var propChanged = setDurationProp(task, ESTIMATE_PROP, value.minutes)
			if (!propChanged && !noteChanged) return null
			return noteChanged ? { note: note } : {}
		},
	}

	var limitRowSpec = {
		className: 'calplan-duration-row calplan-limit-row',
		time: true,
		count: true,
		// A foldable section: a header with a summary, and a body whose two lines
		// share a label column so the blocks stepper sits under the hours one.
		wrapped: true,
		html: function () {
			return '<div class="item__content calplan-limit-header" role="button" tabindex="0" aria-expanded="false" aria-controls="calplanLimitBody">' +
				'  <span class="content__icon">' + ICON_GAUGE + '</span>' +
				'  <span class="content__name">' + escapeHtml(t('Daily limit')) + '</span>' +
				'  <div class="item__actions"><span class="calplan-limit-summary"></span><span class="calplan-fold-chevron">' + ICON_CHEVRON + '</span></div>' +
				'</div>' +
				'<div class="calplan-limit-body" id="calplanLimitBody">' +
				'  <div class="calplan-limit-line"><label class="calplan-limit-label" for="calplanLimitHours">' + escapeHtml(t('Time')) + '</label>' +
				stepperHtml('calplanLimitHours', 'hours', 60, t('Daily limit hours')) +
				'    <span class="calplan-duration-unit">' + escapeHtml(t('h')) + '</span>' +
				stepperHtml('calplanLimitMinutes', 'minutes', 15, t('Daily limit minutes')) +
				'    <span class="calplan-duration-unit">' + escapeHtml(t('min')) + '</span></div>' +
				'  <div class="calplan-limit-line"><label class="calplan-limit-label" for="calplanLimitBlocks">' + escapeHtml(t('Blocks')) + '</label>' +
				stepperHtml('calplanLimitBlocks', 'count', 1, t('Daily limit blocks')) +
				'    <span class="calplan-duration-unit">' + escapeHtml(t('blocks')) + '</span></div>' +
				'</div>'
		},
		placeholders: function (row) {
			partInput(row, 'hours').placeholder = '–'
			partInput(row, 'minutes').placeholder = '–'
			partInput(row, 'count').placeholder = '–'
			row.title = t('At most this much block time and/or this many blocks per day for this task and all its subtasks together. Leave empty for no limit.')
		},
		setup: function (row) {
			var header = row.querySelector('.calplan-limit-header')
			setLimitRowOpen(row, limitRowOpenPreference())
			function toggle() {
				var open = !row.classList.contains('is-open')
				setLimitRowOpen(row, open)
				try { window.localStorage.setItem(LIMIT_OPEN_KEY, open ? '1' : '0') } catch (e) {}
			}
			header.addEventListener('click', toggle)
			header.addEventListener('keydown', function (e) {
				if (e.target === header && (e.key === ' ' || e.key === 'Enter')) { e.preventDefault(); toggle() }
			})
		},
		/** Header summary of what the fields currently say. */
		afterShow: function (row) {
			var minutes = rowMinutes(row)
			var blocks = partNumber(row, 'count')
			var parts = []
			if (minutes > 0) parts.push(formatDuration(minutes))
			if (blocks > 0) parts.push(n('{count} block', '{count} blocks', blocks))
			// Write only on change: replacing the text is a DOM mutation, and the
			// page-wide MutationObserver that drives syncRow would loop forever.
			var summary = row.querySelector('.calplan-limit-summary')
			var text = parts.length ? parts.join(' · ') : t('No limit')
			if (summary.textContent !== text) summary.textContent = text
		},
		// Limits bind a task and its subtasks; a lone task shows the row only
		// while it has a limit of its own (left from removed subtasks, or set
		// elsewhere), so that it can be cleared.
		visible: function (task) {
			return hasSubtasks(task) || isoDurationMinutes(propValue(task, LIMIT_TIME_PROP)) > 0 || parseInt(propValue(task, LIMIT_BLOCKS_PROP), 10) > 0
		},
		read: function (task) {
			var blocks = parseInt(propValue(task, LIMIT_BLOCKS_PROP), 10)
			return { minutes: isoDurationMinutes(propValue(task, LIMIT_TIME_PROP)), count: isNaN(blocks) ? 0 : blocks }
		},
		write: function (task, value) {
			var timeChanged = setDurationProp(task, LIMIT_TIME_PROP, value.minutes)
			var blocksChanged = setCountProp(task, LIMIT_BLOCKS_PROP, value.count)
			return timeChanged || blocksChanged ? {} : null
		},
	}

	var SPLIT_PROP = 'x-calplan-splittable'
	var ICON_SPLIT = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M14,4L16.29,6.29L13.41,9.17L14.83,10.59L17.71,7.71L20,10V4M10,4H4V10L6.29,7.71L11,12.41V20H13V11.59L7.71,6.29"/></svg>'

	var splitRowSpec = {
		// Styled like the Auto-schedule switch row.
		className: 'calplan-toggle-row calplan-split-row',
		flag: true,
		wrapped: true,
		html: function () {
			return '<div class="item__content" role="switch" tabindex="0" aria-checked="false" aria-label="' + escapeHtml(t('Split if needed')) + '">' +
				'  <span class="content__icon">' + ICON_SPLIT + '</span>' +
				'  <span class="content__name">' + escapeHtml(t('Split if needed')) + '</span>' +
				'  <span class="calplan-split-inherited">' + escapeHtml(t('on for a parent')) + '</span>' +
				'  <span class="calplan-switch-wrapper"><input type="checkbox" class="calplan-switch-input" tabindex="-1" aria-hidden="true" /><span class="calplan-switch-slider"></span></span>' +
				'</div>'
		},
		placeholders: function (row) {
			row.title = t('When no single free slot fits this task, CalPlan fills the earliest free time with it and splits it where it does not fit whole, in parts named "Title (1/2)", "Title (2/2)"… (minimum part length in CalPlan settings). On a task with subtasks it applies to all of them.')
		},
		setup: function (row) {
			bindSwitch(row, row.querySelector('[role="switch"]'), function () {
				var task = openTask()
				if (!task || isReadOnly(task)) return
				splitRowSpec.render(row, { on: !switchOn(row), inherited: ancestorFlagOn(task, SPLIT_PROP) }, false)
				commitRow(row, splitRowSpec, task)
			})
		},
		visible: function (task) { return !!task },
		read: function (task) { return { on: flagOn(task, SPLIT_PROP), inherited: ancestorFlagOn(task, SPLIT_PROP) } },
		/** The switch shows the task's own flag; "on for a parent" tells when an ancestor's applies anyway. */
		render: function (row, value, disabled) {
			renderSwitch(row, value.on, disabled)
			var inherited = !!value.inherited && !value.on
			if (row.classList.contains('is-inherited') !== inherited) row.classList.toggle('is-inherited', inherited)
		},
		write: function (task, value) {
			return setFlagProp(task, SPLIT_PROP, value.on) ? {} : null
		},
	}

	var noticeRowSpec = {
		className: 'calplan-notice-row',
		html: function () {
			return '  <span class="content__icon">' + ICON_ALERT + '</span>' +
				'  <span class="content__name calplan-notice-text"></span>'
		},
		placeholders: function () {},
		visible: function (task) { return noticeText(task) !== null },
		read: function (task) { return { text: noticeText(task) || '' } },
		render: function (row, value) {
			var el = row.querySelector('.calplan-notice-text')
			if (el.textContent !== value.text) el.textContent = value.text
			if (row.title !== value.text) row.title = value.text
		},
		write: function () { return null },
	}

	var ICON_CALENDAR_CLOCK = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M15,13H16.5V15.82L18.94,17.23L18.19,18.53L15,16.69V13M19,8H5V19H9.67C9.24,18.09 9,17.07 9,16A7,7 0 0,1 16,9C17.07,9 18.09,9.24 19,9.67V8M5,21C3.89,21 3,20.1 3,19V5C3,3.89 3.89,3 5,3H6V1H8V3H16V1H18V3H19A2,2 0 0,1 21,5V11.1C22.24,12.36 23,14.09 23,16A7,7 0 0,1 16,23C14.09,23 12.36,22.24 11.1,21H5M16,11.15A4.85,4.85 0 0,0 11.15,16C11.15,18.68 13.32,20.85 16,20.85A4.85,4.85 0 0,0 20.85,16C20.85,13.32 18.68,11.15 16,11.15Z"/></svg>'

	var plannedRowSpec = {
		className: 'calplan-planned-row',
		html: function () {
			return '  <span class="content__icon">' + ICON_CALENDAR_CLOCK + '</span>' +
				'  <span class="content__name calplan-planned-text"></span>'
		},
		placeholders: function () {},
		visible: function (task) { return ownPlanned(task) !== null || subtaskPlan(task) !== null },
		read: function (task) {
			var own = ownPlanned(task)
			return { text: own ? plannedText(own.blocks) : subtaskPlanText(subtaskPlan(task)) }
		},
		render: function (row, value) {
			var el = row.querySelector('.calplan-planned-text')
			if (el.textContent !== value.text) el.textContent = value.text
			if (row.title !== value.text) row.title = value.text
		},
		write: function () { return null },
	}

	var SIDEBAR_ROWS = [noticeRowSpec, plannedRowSpec, durationRowSpec, splitRowSpec, limitRowSpec]

	// Whether the Daily limit section is unfolded: a per-browser preference, closed by default.
	var LIMIT_OPEN_KEY = 'calplan.dailyLimitOpen'
	var ICON_CHEVRON = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M7.41,8.58L12,13.17L16.59,8.58L18,10L12,16L6,10L7.41,8.58Z"/></svg>'

	function limitRowOpenPreference() {
		try { return window.localStorage.getItem(LIMIT_OPEN_KEY) === '1' } catch (e) { return false }
	}

	function setLimitRowOpen(row, open) {
		row.classList.toggle('is-open', open)
		row.querySelector('.calplan-limit-header').setAttribute('aria-expanded', open ? 'true' : 'false')
	}

	/** Write the row's value into *task* (spec.write) and save it through Tasks' Vuex store. */
	function commitRow(row, spec, task) {
		var store = vuexStore()
		if (!store || !task || !task.vtodo) { warn('sidebar row: no store/task'); return }
		if (isReadOnly(task)) return
		var result = spec.write(task, rowValue(row, spec))
		if (!result) return
		if (typeof task.updateLastModified === 'function') task.updateLastModified()
		// setNote also runs updateTask, which serializes the whole VTODO.
		if (result.note !== undefined) {
			store.dispatch('setNote', { task: task, note: result.note })
		} else {
			store.dispatch('updateTask', task)
		}
		// The PUT re-plans on the server; then refresh the warnings.
		whenTaskWritten(task, function (ok) {
			if (!ok) notify(t('CalPlan: the task could not be saved; reload it in Tasks and try again.'), true)
			loadReconcileStatus().catch(function () {})
		})
	}

	/** Save the row's pending change (made on its task) now. */
	function flushPending(row, spec) {
		var pending = row.calplanPending
		if (!pending) return
		clearTimeout(pending.timer)
		row.calplanPending = null
		row.removeAttribute('data-pending')
		commitRow(row, spec, pending.task)
	}

	/**
	 * Save the row's value for *task* after STEP_COMMIT_DELAY_MS, so that
	 * quick steps or an hours-then-minutes edit are saved once; meanwhile
	 * syncRow leaves the row alone (data-pending). The save waits while the
	 * user is still typing in the row.
	 */
	function scheduleCommit(row, spec, task) {
		if (!task) return
		var pending = row.calplanPending
		if (pending && pending.task !== task) flushPending(row, spec)
		if (row.calplanPending) clearTimeout(row.calplanPending.timer)
		function fire() {
			var active = document.activeElement
			if (active && active.tagName === 'INPUT' && row.contains(active)) {
				row.calplanPending.timer = setTimeout(fire, STEP_COMMIT_DELAY_MS)
				return
			}
			flushPending(row, spec)
		}
		row.calplanPending = { task: task, timer: setTimeout(fire, STEP_COMMIT_DELAY_MS) }
		row.setAttribute('data-pending', '1')
	}

	/**
	 * Step a part by *delta*: time parts move the row's total and snap minute
	 * steps to CalPlan's 15-minute grid (45 min + 15 = 1 h 0 min); a count
	 * moves by one. An empty time starts from zero.
	 */
	function stepRow(row, spec, part, delta) {
		var task = openTask()
		if (row.calplanPending && row.calplanPending.task !== task) {
			// Another task opened since the last step: save that one, show this one.
			flushPending(row, spec)
			syncRow(row, spec, task)
		}
		if (!task || isReadOnly(task)) return
		if (part === 'count') {
			showRowValue(row, { count: Math.max(0, partNumber(row, 'count') + delta) })
		} else {
			var current = rowMinutes(row)
			if (part === 'hours') {
				// Whole hours, keeping the minutes (1 h 15 + 1 h = 2 h 15); under an
				// hour, − has no hour to take and leaves the value alone.
				if (current + delta < 0) return
				showRowValue(row, { minutes: current + delta })
			} else {
				var grid = Math.abs(delta)
				var next = delta > 0 ? Math.floor(current / grid) * grid + grid : Math.ceil(current / grid) * grid - grid
				showRowValue(row, { minutes: Math.max(0, next) })
			}
		}
		if (spec.afterShow) spec.afterShow(row)
		scheduleCommit(row, spec, task)
	}

	function createRow(spec) {
		var row = document.createElement('div')
		row.className = 'property__item ' + spec.className
		row.innerHTML = spec.wrapped ? spec.html() : '<div class="item__content">' + spec.html() + '</div>'
		spec.placeholders(row)
		if (spec.setup) spec.setup(row)
		var inputs = row.querySelectorAll('.calplan-duration-input')
		for (var i = 0; i < inputs.length; i++) {
			// The task being edited is the one open when typing started.
			inputs[i].addEventListener('focus', function () { row.calplanEditTask = openTask() })
			inputs[i].addEventListener('change', function () {
				scheduleCommit(row, spec, (row.calplanPending && row.calplanPending.task) || row.calplanEditTask || openTask())
			})
			inputs[i].addEventListener('input', function () {
				if (spec.afterShow) spec.afterShow(row)
			})
			inputs[i].addEventListener('keydown', function (e) {
				if (e.key === 'Enter') { e.preventDefault(); e.target.blur() }
			})
		}
		// Leaving the row saves at once.
		row.addEventListener('focusout', function (e) {
			if (row.calplanPending && !(e.relatedTarget && row.contains(e.relatedTarget))) {
				setTimeout(function () {
					var active = document.activeElement
					if (!(active && row.contains(active))) flushPending(row, spec)
				}, 0)
			}
		})
		var buttons = row.querySelectorAll('.calplan-stepper-button')
		for (var j = 0; j < buttons.length; j++) {
			buttons[j].addEventListener('click', function (e) {
				e.preventDefault()
				stepRow(row, spec, e.currentTarget.getAttribute('data-part'), parseInt(e.currentTarget.getAttribute('data-step'), 10))
			})
		}
		return row
	}

	/** Show *task*'s value; never overwrite a value being edited or a pending save of the same task. */
	function syncRow(row, spec, task) {
		// A change still pending for another task is saved before this one shows.
		if (row.calplanPending && row.calplanPending.task !== task) flushPending(row, spec)
		var show = !!task && spec.visible(task)
		if (row.hidden === show) row.hidden = !show
		if (!show || row.calplanPending) return
		var readOnly = isReadOnly(task)
		var controls = row.querySelectorAll('input, .calplan-stepper-button')
		for (var i = 0; i < controls.length; i++) {
			if (controls[i].disabled !== readOnly) controls[i].disabled = readOnly
		}
		var active = document.activeElement
		if (active && active.tagName === 'INPUT' && row.contains(active)) return
		if (spec.render) {
			spec.render(row, spec.read(task), readOnly)
		} else {
			showRowValue(row, spec.read(task))
		}
		if (spec.afterShow) spec.afterShow(row)
	}

	/** Keep the extra rows right after the Auto-schedule row, in SIDEBAR_ROWS order. */
	function ensureExtraRows(afterRow, task) {
		var anchor = afterRow
		SIDEBAR_ROWS.forEach(function (spec) {
			if (!spec.row || !spec.row.isConnected || spec.row.previousElementSibling !== anchor) {
				if (spec.row && spec.row.isConnected) spec.row.remove()
				spec.row = createRow(spec)
				anchor.parentNode.insertBefore(spec.row, anchor.nextSibling)
			}
			syncRow(spec.row, spec, task)
			anchor = spec.row
		})
	}

	var injectedRow = null

	function ensureSidebarCheckbox() {
		var sidebar = document.querySelector('.app-sidebar')
		if (!sidebar) {
			injectedRow = null
			return
		}
		// Anchor on the "All day" checkbox Tasks renders in the sidebar header.
		var anchor = sidebar.querySelector('#allDayToggle')
		if (!anchor) return
		var anchorRow = anchor.closest('.property__item') || anchor.parentElement
		if (!anchorRow) return
		// One lookup per pass: every row shows the same task.
		var task = openTask()

		if (!(injectedRow && injectedRow.isConnected && injectedRow.closest('.app-sidebar') === sidebar)) {
			injectedRow = buildCheckboxRow()
			anchorRow.parentNode.insertBefore(injectedRow, anchorRow.nextSibling)
		}
		renderSwitch(injectedRow, isFlagged(task), isReadOnly(task))
		ensureExtraRows(injectedRow, task)
	}

	// --- Driver ----------------------------------------------------------

	// Elements CalPlan renders itself; changes inside them never need a pass.
	var OWN_UI = '.calplan-toggle-row, .calplan-duration-row, .calplan-split-row, .calplan-notice-row, .calplan-planned-row, .calplan-task-icon, .calplan-planned, .calplan-unscheduled-icon, .calplan-reconcile-item, .calplan-help-backdrop'

	function isOwnMutation(record) {
		var node = record.target
		var el = node && (node.nodeType === 1 ? node : node.parentElement)
		if (el && typeof el.closest === 'function' && el.closest(OWN_UI)) return true
		// Inserting or removing one of CalPlan's own icons.
		var changed = Array.prototype.slice.call(record.addedNodes || []).concat(Array.prototype.slice.call(record.removedNodes || []))
		return changed.length > 0 && changed.every(function (child) {
			return child.nodeType === 1 && typeof child.matches === 'function' && child.matches(OWN_UI)
		})
	}

	function onMutate(records) {
		// Safety net against feedback loops: a pass that only touched CalPlan's
		// own UI (e.g. a summary text) must not trigger another pass.
		if (records && records.length > 0 && records.every(isOwnMutation)) return
		scanTaskList(document)
		ensureSidebarCheckbox()
		ensureReconcileButton()
		watchCalendarEdits()
	}

	function init() {
		// #app-navigation-vue is shared by Files and Settings too. Never fetch assets,
		// scan their DOM, or install a global observer outside Tasks/Calendar.
		if (!isSupportedAppPath()) return
		// The icons come first, so that no row or badge is ever built without
		// them; the status and the default duration follow, and the observer
		// takes over from the first full pass.
		loadIcons(function () {
			scanTaskList(document)
			ensureSidebarCheckbox()
			ensureReconcileButton()
			watchCalendarEdits()
			var obs = new MutationObserver(onMutate)
			obs.observe(document.body, { childList: true, subtree: true })
			if (isCalendarPath()) return // Calendar asks for the status after each edit
			loadDefaultDuration()
			// Not-scheduled warnings: now, then every minute while the tab is
			// visible (reconciles from cron, other clients or event saves).
			loadReconcileStatus().catch(function () {})
			setInterval(function () {
				if (!document.hidden) loadReconcileStatus().catch(function () {})
			}, 60000)
		})
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init)
	} else {
		init()
	}
})()

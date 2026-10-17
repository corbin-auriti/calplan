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
 *   1. Both apps — adds the Reconcile CalPlan navigation action and shared help.
 *
 * Tasks-only behavior:
 *   2. Task list — renders a small SVG "CalPlan" icon next to any task that
 *      carries the `calplan` category, read from the rendered tag chips.
 *   3. Sidebar — injects an "Auto-schedule" switch after the "All day" row,
 *      bound to whether the open task has the `calplan` category. Changes go
 *      through Tasks' own Vuex actions (so they persist through Tasks' CalDAV
 *      sync); the server re-plans after each write.
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
	}

	function scanTaskList(root) {
		var items = root.querySelectorAll('li.task-item')
		for (var i = 0; i < items.length; i++) {
			decorateTaskItem(items[i])
		}
	}

	// --- Reconcile status ----------------------------------------------------

	var latestReconcileRun = null
	var statusSeq = 0
	var appliedStatusSeq = 0
	var seenRewrites = {}
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
			  '  <p>' + t('<code>Duration: 15min</code>, <code>Duration: 1hr</code>, or <code>Duration: 1 hour 30 minutes</code> sets the block length. A standard iCalendar DURATION takes priority; otherwise your personal default is used.') + '</p>' +
			  '  <p>' + t('<code>First step: open the report and write the heading</code> adds a <strong>👣 First step</strong> line to the calendar block. It is optional and does not change scheduling. Legacy <code>Next:</code> lines still work.') + '</p>'
			: ''
		backdrop.innerHTML =
			'<div class="calplan-help-dialog" role="dialog" aria-modal="true" aria-labelledby="calplanHelpTitle">' +
			'  <div class="calplan-help-header"><h2 id="calplanHelpTitle">' + escapeHtml(t('CalPlan help')) + '</h2><button type="button" class="calplan-help-close" aria-label="' + escapeHtml(t('Close')) + '">×</button></div>' +
			'  <p>' + t('<strong>CalPlan finds time for your Nextcloud tasks.</strong> Auto-schedule adds the standard <code>calplan</code> category and creates a derived locked event in the same calendar.') + '</p>' +
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

	/** Add/remove the flag via Tasks' own Vuex actions (reactive + CalDAV-synced). */
	function toggleFlag(task, on) {
		var store = vuexStore()
		if (!store || !task) { warn('toggleFlag: no store/task'); return }
		store.dispatch('setTags', { task: task, tags: flaggedTags(task, on) })
		// The PUT re-plans on the server; then refresh the status.
		whenTaskWritten(task, function (ok) {
			if (!ok) notify(t('CalPlan: the task could not be saved; reload it in Tasks and try again.'), true)
			loadReconcileStatus().catch(function () {})
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
		var task = openTask()

		if (!(injectedRow && injectedRow.isConnected && injectedRow.closest('.app-sidebar') === sidebar)) {
			injectedRow = buildCheckboxRow()
			anchorRow.parentNode.insertBefore(injectedRow, anchorRow.nextSibling)
		}
		renderSwitch(injectedRow, isFlagged(task), isReadOnly(task))
	}

	// --- Driver ----------------------------------------------------------

	// Elements CalPlan renders itself; changes inside them never need a pass.
	var OWN_UI = '.calplan-toggle-row, .calplan-task-icon, .calplan-reconcile-item, .calplan-help-backdrop'

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
	}

	function init() {
		// #app-navigation-vue is shared by Files and Settings too. Never fetch assets,
		// scan their DOM, or install a global observer outside Tasks/Calendar.
		if (!isSupportedAppPath()) return
		// The icons come first, so that no row or badge is ever built without
		// them; the status follows, and the observer takes over from the first
		// full pass.
		loadIcons(function () {
			scanTaskList(document)
			ensureSidebarCheckbox()
			ensureReconcileButton()
			var obs = new MutationObserver(onMutate)
			obs.observe(document.body, { childList: true, subtree: true })
			if (isCalendarPath()) return // the rest serves the Tasks page
			// The reconcile status (tasks CalPlan rewrote, to reload): now, then
			// every minute while the tab is visible (reconciles from cron, other
			// clients or event saves).
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

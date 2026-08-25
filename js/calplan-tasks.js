/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * CalPlan Tasks-frontend injection (runtime, no build step).
 *
 * Loaded by OCA\AutoSchedule\AppInfo\Application::boot() via
 * OCP\Util::addScript('calplan', 'calplan-tasks'). Nextcloud only loads an
 * enabled app's assets, so this whole script is "conditional on app-enabled":
 * if calplan is disabled, nothing loads and the Tasks app is unchanged (the
 * `calplan` category degrades to a plain text tag in CalDAV clients). Nothing
 * breaks.
 *
 * What it does, only on the Tasks and Calendar app routes:
 *   1. Both apps — adds the Reconcile CalPlan navigation action and shared help.
 *
 * Tasks-only behavior:
 *   2. Task list — renders a small SVG "CalPlan" icon next to any task that
 *      carries the `calplan` category. The flag is read purely from the rendered
 *      tag chips (`.tags-list .tag .tag-label`), dynamically decorating and
 *      undecorating on changes without fighting Vue's reactivity.
 *   3. Sidebar — injects a sleek "Auto-schedule" toggle switch after the "All day"
 *      row, bound to whether the open task has the `calplan` category. Toggling it
 *      dispatches Tasks' own Vuex `addTag` / `setTags` actions (so the flag
 *      persists through Tasks' native CalDAV sync) and triggers an immediate
 *      reconciliation pass so calendar events update immediately.
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

	/** Only Tasks and Calendar own the CalPlan navigation action. */
	function isSupportedAppPath() {
		var path = String(window.location && window.location.pathname || '')
		return /\/(?:index\.php\/)?apps\/(?:tasks|calendar)(?:\/|$)/.test(path)
	}

	function warn() {
		var a = Array.prototype.slice.call(arguments)
		a.unshift('[CalPlan]')
		try { console.warn.apply(console, a) } catch (e) {}
	}

	/** @returns {object|null} the Tasks Vue app instance, or null when not on Tasks */
	function tasksApp() {
		return (window.OCA && OCA.Tasks && OCA.Tasks.App) ? OCA.Tasks.App : null
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
		fetchIcon('calplan-task-icon.svg', function (t) { iconTaskSvg = t; one() })
		fetchIcon('calplan-sidebar-icon.svg', function (t) { iconSidebarSvg = t; one() })
		// Don't block the UI forever if an asset 404s.
		setTimeout(finish, 3000)
	}

	/** Read the flag purely from the rendered tag chips in one task body. */
	function taskHasFlagChip(body) {
		var labels = body.querySelectorAll('.tags-list .tag .tag-label')
		for (var i = 0; i < labels.length; i++) {
			if ((labels[i].textContent || '').trim().toLowerCase() === FLAG) {
				return true
			}
		}
		return false
	}

	function decorateTaskItem(li) {
		var body = li.querySelector('.task-item__body')
		if (!body) return
		var hasFlag = taskHasFlagChip(body)
		var existingIcon = li.querySelector('.calplan-task-icon')

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
			li.setAttribute('data-calplan-marked', '1')
		} else {
			if (existingIcon) {
				existingIcon.remove()
			}
			li.removeAttribute('data-calplan-marked')
		}
	}

	function scanTaskList(root) {
		var items = root.querySelectorAll('li.task-item')
		for (var i = 0; i < items.length; i++) {
			decorateTaskItem(items[i])
		}
	}

	// --- 2. Sidebar toggle -----------------------------------------------

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
				var t = store.getters.getTaskByRoute(route)
				if (t) return t
			} catch (e) {}
		}

		// 2. Try getter with URI
		var uri = openTaskUri()
		if (uri) {
			if (typeof store.getters.getTaskByUri === 'function') {
				try {
					var t2 = store.getters.getTaskByUri(uri)
					if (t2) return t2
				} catch (e) {}
			}
			if (typeof store.getters.getTask === 'function') {
				try {
					var t3 = store.getters.getTask(uri)
					if (t3) return t3
				} catch (e) {}
			}
		}

		// 3. Store state scan fallback
		try {
			var state = store.state && store.state.tasks
			if (state && state.selectedTask) return state.selectedTask
			if (uri && state && state.tasks) {
				for (var k in state.tasks) {
					var candidate = state.tasks[k]
					if (candidate && (candidate.uri === uri || candidate.id === uri || candidate.uid === uri)) {
						return candidate
					}
				}
			}
		} catch (e) {}

		return null
	}

	function isFlagged(task) {
		if (!task || !Array.isArray(task.tags)) return false
		for (var i = 0; i < task.tags.length; i++) {
			if (String(task.tags[i]).toLowerCase() === FLAG) return true
		}
		return false
	}

	/** Trigger an on-demand reconciliation; resolves to the OCS data payload. */
	function triggerReconcile() {
		try {
			var token = (window.OC && OC.requestToken) || (window.oc_requesttoken) || ''
			// Build the OCS endpoint URL with OC.generateUrl (the standard,
			// webroot-aware helper). Do NOT use OC.linkToOCS here: empirically, on
			// NC 34 `OC.linkToOCS('calplan', 'api/v1/reconcile', 2)` drops the file
			// argument and yields `/ocs/v2.php/calplan/` (404), so the trigger
			// silently no-ops and no slot is placed. The relative OCS path below is
			// the exact route registered in appinfo/routes.php.
			var url = (window.OC && typeof OC.generateUrl === 'function')
				? OC.generateUrl('/ocs/v2.php/apps/calplan/api/v1/reconcile')
				: '/ocs/v2.php/apps/calplan/api/v1/reconcile'
			return fetch(url, {
				method: 'POST',
				headers: {
					'requesttoken': token,
					'OCS-APIRequest': 'true',
					'Accept': 'application/json',
				},
			}).then(function (response) {
				if (!response.ok) throw new Error('HTTP ' + response.status)
				return response.json()
			}).then(function (body) {
				return body && body.ocs ? body.ocs.data : body
			}).catch(function (err) {
				warn('reconcile fetch failed', err)
				throw err
			})
		} catch (e) {
			warn('reconcile error', e)
			return Promise.reject(e)
		}
	}

	function notify(message, isError) {
		try {
			if (window.OC && OC.Notification) {
				if (isError && typeof OC.Notification.showTemporary === 'function') {
					OC.Notification.showTemporary(message)
					return
				}
				if (typeof OC.Notification.showTemporary === 'function') {
					OC.Notification.showTemporary(message)
					return
				}
			}
		} catch (e) {}
	}

	var reconcileButton = null
	var reconcileRunning = false
	var latestReconcileRun = null

	function reconcileStatusUrl() {
		return (window.OC && typeof OC.generateUrl === 'function')
			? OC.generateUrl('/ocs/v2.php/apps/calplan/api/v1/reconcile/status')
			: '/ocs/v2.php/apps/calplan/api/v1/reconcile/status'
	}

	function loadReconcileStatus() {
		return fetch(reconcileStatusUrl(), {
			headers: { 'OCS-APIRequest': 'true', 'Accept': 'application/json' },
		}).then(function (response) {
			if (!response.ok) throw new Error('HTTP ' + response.status)
			return response.json()
		}).then(function (body) {
			var data = body && body.ocs ? body.ocs.data : body
			latestReconcileRun = data && data.lastRun ? data.lastRun : null
			return latestReconcileRun
		})
	}

	function renderReconcileStatus(container, run) {
		if (!container) return
		container.textContent = ''
		if (!run) {
			container.textContent = 'No Reconcile run has been recorded yet.'
			return
		}
		var time = run.timestamp || 'unknown time'
		try { time = new Date(time).toLocaleString() } catch (e) {}
		var summary = document.createElement('p')
		summary.appendChild(document.createTextNode(time + ' · ' + (run.trigger || 'other') + ': '))
		var strong = document.createElement('strong')
		strong.textContent = (run.placed || 0) + ' placed, ' + (run.removed || 0) + ' removed, ' + (run.unchanged || 0) + ' unchanged, ' + (run.unscheduled || 0) + ' could not fit'
		summary.appendChild(strong)
		container.appendChild(summary)
		var detail = document.createElement('p')
		detail.textContent = (run.eventsRead || 0) + ' events and ' + (run.tasksRead || 0) + ' tasks read; ' + (run.eventsIgnoredByTitle || 0) + ' events ignored by title. Total ' + ((run.timingsMs && run.timingsMs.total) || 0) + ' ms.'
		container.appendChild(detail)
		var failures = ((run.writeFailures && run.writeFailures.put) || 0) + ((run.writeFailures && run.writeFailures.delete) || 0)
		if (failures > 0) {
			var warning = document.createElement('p')
			warning.className = 'calplan-help-warning'
			warning.textContent = failures + ' calendar write operation(s) failed; see Reconcile diagnostics in Personal settings.'
			container.appendChild(warning)
		}
	}

	function buildReconcileButton() {
		var button = document.createElement('button')
		button.type = 'button'
		button.id = 'calplanReconcileNow'
		button.className = 'calplan-reconcile-now'
		button.innerHTML = '<span class="calplan-reconcile-icon" aria-hidden="true">↻</span><span class="calplan-reconcile-label">Reconcile CalPlan</span>'
		button.addEventListener('click', function () {
			if (reconcileRunning) return
			reconcileRunning = true
			button.disabled = true
			button.classList.add('is-running')
			button.querySelector('.calplan-reconcile-label').textContent = 'Reconciling…'
			triggerReconcile().then(function (data) {
				if (data && data.lastRun) latestReconcileRun = data.lastRun
				var placed = data && typeof data.placed === 'number' ? data.placed : 0
				var removed = data && typeof data.removed === 'number' ? data.removed : 0
				var unscheduled = data && typeof data.unscheduled === 'number' ? data.unscheduled : 0
				var message = 'CalPlan reconciled: ' + placed + ' placed, ' + removed + ' removed'
				if (unscheduled > 0) message += ', ' + unscheduled + ' could not fit'
				notify(message)
			}).catch(function () {
				notify('CalPlan reconcile failed', true)
			}).then(function () {
				reconcileRunning = false
				button.disabled = false
				button.classList.remove('is-running')
				button.querySelector('.calplan-reconcile-label').textContent = 'Reconcile CalPlan'
			})
		})
		return button
	}

	function buildNavigationHelpButton() {
		var button = document.createElement('button')
		button.type = 'button'
		button.className = 'calplan-navigation-help'
		button.setAttribute('aria-label', 'CalPlan help')
		button.setAttribute('title', 'CalPlan help')
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

	/**
	 * Resolve once the Tasks store has finished the CalDAV PUT for *task*, so the
	 * VTODO (incl. the calplan flag) is committed server-side.
	 *
	 * Tasks' addTag/setTags dispatch updateTask, but that action (a) is NOT
	 * returned from addTag (so store.dispatch('addTag') resolves before the PUT)
	 * and (b) returns undefined when a write is already in flight (it just sets
	 * updateScheduled). Awaiting the dispatch is therefore not enough — the
	 * reconcile would read the still-unflagged VTODO and place no slot. Instead
	 * we poll task.updateRunning / task.updateScheduled, which updateTask toggles
	 * around its `await task.dav.update()` PUT, and reconcile only once the write
	 * has settled.
	 */
	function whenTaskWritten(task, cb) {
		if (!task) { cb(); return }
		var seenRunning = false, tries = 0
		function tick() {
			if (task.updateRunning || task.updateScheduled) seenRunning = true
			if (seenRunning && !task.updateRunning && !task.updateScheduled) return cb()
			// If no write was ever observed in flight after ~2s, assume it already
			// completed (or was not queued) and proceed regardless.
			if (++tries > 20 && !seenRunning && !task.updateRunning && !task.updateScheduled) return cb()
			if (tries > 200) return cb() // 20s hard cap
			setTimeout(tick, 100)
		}
		tick()
	}

	/** Add/remove the flag via Tasks' own Vuex actions (reactive + CalDAV-synced). */
	function toggleFlag(task, on) {
		var store = vuexStore()
		if (!store || !task) { warn('toggleFlag: no store/task'); return }
		if (on) {
			if (!Array.isArray(task.tags)) task.tags = []
			var enabledTags = []
			for (var j = 0; j < task.tags.length; j++) {
				var enabledTag = String(task.tags[j])
				if (enabledTag.toLowerCase() !== REVIEW_FLAG && enabledTag.toLowerCase() !== FLAG) enabledTags.push(task.tags[j])
			}
			enabledTags.push(FLAG)
			store.dispatch('setTags', { task: task, tags: enabledTags })
		} else {
			var keep = []
			for (var i = 0; i < (task.tags || []).length; i++) {
				if (String(task.tags[i]).toLowerCase() !== FLAG) keep.push(task.tags[i])
			}
			store.dispatch('setTags', { task: task, tags: keep })
		}
		// Reconcile only after the flag-PUT has settled (see whenTaskWritten),
		// otherwise the engine reads the old, un-flagged VTODO and places no slot.
		whenTaskWritten(task, function () {
			triggerReconcile().catch(function () {})
		})
	}

	function applyToggleState(row, on) {
		var box = row.querySelector('#calplanToggle')
		if (box) box.checked = on
		row.setAttribute('aria-checked', on ? 'true' : 'false')
		if (on) {
			row.classList.add('is-active')
		} else {
			row.classList.remove('is-active')
		}
	}

	/**
	 * Toggle the calplan flag for the open task. The desired state is derived
	 * from the STORE (isFlagged(task)), NOT from the checkbox's visual state,
	 * so every click/keypress flips exactly once regardless of sync lag. The
	 * visual switch is updated immediately for responsive feedback; the store
	 * mutation + MutationObserver-driven syncCheckbox() confirm it afterwards.
	 */
	function doToggle(row) {
		var task = openTask()
		if (!task) {
			warn('toggle: no open task')
			return
		}
		var wantOn = !isFlagged(task)
		applyToggleState(row, wantOn)
		toggleFlag(task, wantOn)
	}

	function buildCheckboxRow() {
		var row = document.createElement('div')
		row.className = 'property__item calplan-toggle-row'
		row.setAttribute('role', 'switch')
		row.setAttribute('tabindex', '0')
		row.setAttribute('aria-checked', 'false')
		row.setAttribute('aria-label', 'Auto-schedule')
		row.innerHTML =
			'<div class="item__content">' +
			'  <span class="content__icon">' + iconSidebarSvg + '</span>' +
			'  <span class="content__name">Auto-schedule</span>' +
			'  <div class="item__actions">' +
			'    <button type="button" class="calplan-help-button" aria-label="CalPlan help" title="CalPlan help">?</button>' +
			'    <span class="calplan-switch-wrapper">' +
			'      <input type="checkbox" id="calplanToggle" class="calplan-switch-input" name="calplanToggle" tabindex="-1" aria-hidden="true" />' +
			'      <span class="calplan-switch-slider"></span>' +
			'    </span>' +
			'  </div>' +
			'</div>'
		return row
	}

	function showHelp(mode) {
		var existing = document.getElementById('calplanHelpDialog')
		if (existing) existing.remove()
		var backdrop = document.createElement('div')
		backdrop.id = 'calplanHelpDialog'
		backdrop.className = 'calplan-help-backdrop'
		var taskHelpers = mode === 'task'
			? '  <h3>Optional task-note helpers</h3>' +
			  '  <p><code>Duration: 15min</code>, <code>Duration: 1hr</code>, or <code>Duration: 1 hour 30 minutes</code> sets the block length. A standard iCalendar DURATION takes priority; otherwise your personal default is used.</p>' +
			  '  <p><code>First step: open the report and write the heading</code> adds a <strong>👣 First step</strong> line to the calendar block. It is optional and does not change scheduling. Legacy <code>Next:</code> lines still work.</p>'
			: ''
		backdrop.innerHTML =
			'<div class="calplan-help-dialog" role="dialog" aria-modal="true" aria-labelledby="calplanHelpTitle">' +
			'  <div class="calplan-help-header"><h2 id="calplanHelpTitle">CalPlan help</h2><button type="button" class="calplan-help-close" aria-label="Close">×</button></div>' +
			'  <p><strong>CalPlan finds time for your Nextcloud tasks.</strong> Auto-schedule adds the standard <code>calplan</code> category and creates a derived locked event in the same calendar.</p>' +
			'  <h3>What Reconcile does</h3>' +
			'  <p>Reconcile rereads your flagged tasks and busy calendar events, recomputes the current plan, writes new or changed task blocks, and removes blocks for completed, paused, or no-longer-fitting tasks.</p>' +
			'  <p>After a block ends and its pacing grace period passes, an incomplete task receives the filterable <code>calplan-review</code> tag — CalPlan’s <strong>“Is complete?”</strong> review marker. Depending on your setting, CalPlan either tries another time or removes Auto-schedule and pauses.</p>' +
			'  <p>Personal settings can completely exclude calendars or make ordinary events with selected titles stop counting as busy. CalPlan does nothing at all in an excluded calendar, including cleanup; title rules affect ordinary events only.</p>' +
			'  <h3>Last Reconcile</h3><div class="calplan-last-run" aria-live="polite">Loading…</div>' +
			taskHelpers +
			'  <h3>Source of truth</h3>' +
			'  <p>Edit the task, not the derived calendar block. Use a calendar that supports both tasks and events; a Tasks-only list cannot store CalPlan blocks. Automatic timed checks require Nextcloud background jobs; the Reconcile button always runs immediately.</p>' +
			'  <p><a class="calplan-help-settings" href="#">Open CalPlan personal settings</a></p>' +
			'</div>'
		document.body.appendChild(backdrop)
		var statusContainer = backdrop.querySelector('.calplan-last-run')
		if (latestReconcileRun) renderReconcileStatus(statusContainer, latestReconcileRun)
		loadReconcileStatus().then(function (run) { renderReconcileStatus(statusContainer, run) }).catch(function () { statusContainer.textContent = 'Could not load the latest Reconcile status.' })
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

	function syncCheckbox(row, task) {
		applyToggleState(row, isFlagged(task))
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

		if (injectedRow && injectedRow.isConnected && injectedRow.closest('.app-sidebar') === sidebar) {
			syncCheckbox(injectedRow, openTask())
			return
		}

		var row = buildCheckboxRow()
		var help = row.querySelector('.calplan-help-button')
		help.addEventListener('click', function (event) {
			event.preventDefault()
			event.stopPropagation()
			showHelp('task')
		})

		// Click anywhere on the row toggles the flag — the whole line is the hit
		// target, not just the tiny switch. The checkbox input is
		// pointer-events:none + zero-size so it never intercepts the click; clicks
		// on the slider/icon/label all bubble up to here.
		row.addEventListener('click', function () {
			doToggle(row)
		})

		// Keyboard support: Space/Enter on the focused row toggles.
		row.addEventListener('keydown', function (e) {
			if (e.key === ' ' || e.key === 'Enter' || e.keyCode === 32 || e.keyCode === 13) {
				e.preventDefault()
				doToggle(row)
			}
		})

		// Keep the checkbox `change` listener for the Playwright `flipSwitch`
		// helper, which dispatches a synthetic `change` on #calplanToggle. In
		// real browser usage this never fires (the input is non-interactive), so
		// there is no double-toggle risk with the row click handler above.
		var box = row.querySelector('#calplanToggle')
		box.addEventListener('change', function () {
			doToggle(row)
		})

		anchorRow.parentNode.insertBefore(row, anchorRow.nextSibling)
		injectedRow = row
		syncCheckbox(row, openTask())
	}

	// --- Driver ----------------------------------------------------------

	function onMutate() {
		scanTaskList(document)
		ensureSidebarCheckbox()
		ensureReconcileButton()
	}

	function init() {
		// #app-navigation-vue is shared by Files and Settings too. Never fetch assets,
		// scan their DOM, or install a global observer outside Tasks/Calendar.
		if (!isSupportedAppPath()) return
		// Load the icon assets first so the first decoration pass has them; the
		// MutationObserver is set up only after, but the initial scan covers the
		// state at that point and catches anything that rendered during the load.
		loadIcons(function () {
			scanTaskList(document)
			ensureSidebarCheckbox()
			ensureReconcileButton()
			var obs = new MutationObserver(onMutate)
			obs.observe(document.body, { childList: true, subtree: true })
		})
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init)
	} else {
		init()
	}
})()

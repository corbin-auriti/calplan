/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
(function () {
	'use strict'

	/** Nextcloud's t() for this app, placeholders filled unescaped (used with textContent). */
	function t(text, vars) {
		try {
			if (typeof window.t === 'function') return window.t('calplan', text, vars || {}, undefined, { escape: false })
		} catch (e) {}
		return String(text).replace(/\{(\w+)\}/g, function (match, key) { return vars && key in vars ? String(vars[key]) : match })
	}

	/** The URL of CalPlan's OCS API *path*, webroot-aware and working without URL rewriting. */
	function apiUrl(path) {
		var base = ''
		try {
			if (window.OC && typeof OC.linkToOCS === 'function') base = OC.linkToOCS('apps/calplan/api/v1', 2)
		} catch (e) {}
		if (!base) base = ((window.OC && OC.webroot) || '') + '/ocs/v2.php/apps/calplan/api/v1/'
		return base.replace(/\/+$/, '') + path
	}

	/** An OCS request; resolves to the OCS data payload, rejects with the server's error message. */
	function apiRequest(path, method, body) {
		var headers = {
			'Accept': 'application/json',
			'OCS-APIRequest': 'true',
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

	function init() {
		var root = document.getElementById('calplan-personal-settings')
		if (!root) return
		var pace = document.getElementById('calplan-pace')
		var dailyCap = document.getElementById('calplan-daily-cap')
		var dailyTaskCount = document.getElementById('calplan-daily-task-count')
		var defaultDuration = document.getElementById('calplan-default-duration')
		var taskGap = document.getElementById('calplan-task-gap')
		var eventBuffer = document.getElementById('calplan-event-buffer')
		var autoReschedule = document.getElementById('calplan-auto-reschedule')
		var save = document.getElementById('calplan-save-policy')
		var status = document.getElementById('calplan-settings-status')
		var ignoredTitles = document.getElementById('calplan-ignored-event-titles')
		var titleMatchMode = document.getElementById('calplan-event-title-match-mode')
		var saveExclusions = document.getElementById('calplan-save-exclusions')
		var exclusionsStatus = document.getElementById('calplan-exclusions-status')
		var historyEnabled = document.getElementById('calplan-history-enabled')
		var historyRetention = document.getElementById('calplan-history-retention')
		var saveHistory = document.getElementById('calplan-save-history')
		var exportHistory = document.getElementById('calplan-export-history')
		var deleteHistory = document.getElementById('calplan-delete-history')
		var historyStatus = document.getElementById('calplan-history-status')

		/** Show *value* in a select, adding it as an option when the list lacks it (never a blank select). */
		function selectValue(select, value) {
			var known = Array.prototype.some.call(select.options, function (option) { return option.value === String(value) })
			if (!known) {
				var option = document.createElement('option')
				option.value = String(value)
				option.textContent = t('{n} minutes', { n: value })
				select.appendChild(option)
			}
			select.value = String(value)
		}

		pace.value = root.dataset.pacePreset || 'compact'
		dailyCap.value = root.dataset.dailyCap || '300'
		dailyTaskCount.value = root.dataset.dailyTaskCount || '0'
		defaultDuration.value = root.dataset.defaultDuration || '30'
		selectValue(taskGap, root.dataset.taskGap || '0')
		selectValue(eventBuffer, root.dataset.eventBuffer || '0')
		autoReschedule.value = root.dataset.autoReschedule || '15'
		historyEnabled.checked = root.dataset.historyEnabled === '1'
		historyRetention.value = root.dataset.historyRetention || '180'

		/**
		 * Each section saves on its own button; until then its status says the
		 * section has unsaved changes, so no edit is lost by saving another one.
		 */
		function trackChanges(section, statusEl) {
			if (!section || !statusEl) return
			function mark(event) {
				if (event.target && event.target.matches && event.target.matches('input, select, textarea')) {
					statusEl.textContent = t('Unsaved changes')
					statusEl.classList.add('is-unsaved')
				}
			}
			section.addEventListener('input', mark)
			section.addEventListener('change', mark)
		}
		function markSaved(statusEl) {
			statusEl.classList.remove('is-unsaved')
		}

		var presets = {
			compact: { taskGap: 0, eventBuffer: 0 },
			balanced: { taskGap: 15, eventBuffer: 15 },
			relaxed: { taskGap: 30, eventBuffer: 30 },
		}
		pace.addEventListener('change', function () {
			var preset = presets[pace.value]
			if (!preset) return
			selectValue(taskGap, preset.taskGap)
			selectValue(eventBuffer, preset.eventBuffer)
		})
		function markCustom() {
			var preset = presets[pace.value]
			if (!preset || +taskGap.value !== preset.taskGap || +eventBuffer.value !== preset.eventBuffer) pace.value = 'custom'
		}
		taskGap.addEventListener('change', markCustom)
		eventBuffer.addEventListener('change', markCustom)

		/** The whole number in *input*, or an error naming its label when it is empty, invalid or out of range. */
		function numberValue(input, label) {
			var raw = String(input.value).trim()
			var value = Number(raw)
			if (raw === '' || !Number.isInteger(value) || !input.checkValidity()) {
				throw new Error(t('{label}: enter a whole number from {min} to {max}', { label: label, min: input.min, max: input.max }))
			}
			return value
		}

		function labelOf(input) {
			var label = root.querySelector('label[for="' + input.id + '"]')
			return label ? label.textContent.trim() : input.id
		}

		trackChanges(document.getElementById('calplan-policy-fields'), status)
		save.addEventListener('click', function () {
			var body
			try {
				body = {
					dailyCapMinutes: numberValue(dailyCap, labelOf(dailyCap)),
					dailyTaskCount: numberValue(dailyTaskCount, labelOf(dailyTaskCount)),
					defaultDurationMinutes: numberValue(defaultDuration, labelOf(defaultDuration)),
					taskGapMinutes: +taskGap.value,
					eventBufferMinutes: +eventBuffer.value,
					pacePreset: pace.value,
					autoRescheduleMinutes: +autoReschedule.value,
				}
			} catch (error) {
				status.textContent = error.message
				return
			}
			save.disabled = true
			status.textContent = t('Saving…')
			apiRequest('/settings/scheduling', 'PUT', body).then(function () {
				markSaved(status)
				status.textContent = t('Saved')
			}).catch(function (error) {
				status.textContent = t('Could not save: {error}', { error: error.message })
			}).then(function () {
				save.disabled = false
			})
		})

		trackChanges(saveExclusions.closest('.calplan-settings-group__content'), exclusionsStatus)
		saveExclusions.addEventListener('click', function () {
			saveExclusions.disabled = true
			exclusionsStatus.textContent = t('Saving…')
			var ignoredCalendars = Array.from(root.querySelectorAll('input[name="calplan-ignored-calendar"]:checked')).map(function (box) { return box.value })
			var titles = ignoredTitles.value.split(/\r?\n/).map(function (title) { return title.trim() }).filter(Boolean)
			apiRequest('/settings/exclusions', 'PUT', {
				ignoredCalendars: ignoredCalendars,
				ignoredEventTitles: titles,
				eventTitleMatchMode: titleMatchMode.value,
			}).then(function () {
				markSaved(exclusionsStatus)
				exclusionsStatus.textContent = t('Saved. Run Reconcile to apply the new exclusions.')
			}).catch(function (error) {
				exclusionsStatus.textContent = t('Could not save: {error}', { error: error.message })
			}).then(function () { saveExclusions.disabled = false })
		})

		function historyRequest(path, method, body) {
			return apiRequest('/history' + path, method, body)
		}

		trackChanges(saveHistory.closest('.calplan-settings-group__content'), historyStatus)
		saveHistory.addEventListener('click', function () {
			var retention
			try {
				retention = numberValue(historyRetention, labelOf(historyRetention))
			} catch (error) {
				historyStatus.textContent = error.message
				return
			}
			saveHistory.disabled = true
			historyStatus.textContent = t('Saving…')
			historyRequest('/settings', 'PUT', {
				enabled: historyEnabled.checked,
				retentionDays: retention,
			}).then(function () {
				markSaved(historyStatus)
				historyStatus.textContent = historyEnabled.checked ? t('History collection enabled') : t('History collection disabled')
			}).catch(function (error) {
				historyStatus.textContent = t('Could not save: {error}', { error: error.message })
			}).then(function () { saveHistory.disabled = false })
		})

		exportHistory.addEventListener('click', function () {
			exportHistory.disabled = true
			historyStatus.textContent = t('Exporting…')
			historyRequest('/export', 'POST').then(function (data) {
				historyStatus.textContent = t('Exported {records} records to {path}', { records: data.records, path: data.path })
			}).catch(function (error) {
				historyStatus.textContent = t('Could not export: {error}', { error: error.message })
			}).then(function () { exportHistory.disabled = false })
		})

		deleteHistory.addEventListener('click', function () {
			if (!window.confirm(t('Delete all CalPlan scheduling history? This cannot be undone.'))) return
			deleteHistory.disabled = true
			historyStatus.textContent = t('Deleting…')
			historyRequest('', 'DELETE').then(function () {
				historyStatus.textContent = t('Scheduling history deleted')
			}).catch(function (error) {
				historyStatus.textContent = t('Could not delete: {error}', { error: error.message })
			}).then(function () { deleteHistory.disabled = false })
		})
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init)
	else init()
})()

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
(function () {
	'use strict'
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
		pace.value = root.dataset.pacePreset || 'compact'
		dailyCap.value = root.dataset.dailyCap || '300'
		dailyTaskCount.value = root.dataset.dailyTaskCount || '0'
		defaultDuration.value = root.dataset.defaultDuration || '30'
		taskGap.value = root.dataset.taskGap || '0'
		eventBuffer.value = root.dataset.eventBuffer || '0'
		autoReschedule.value = root.dataset.autoReschedule || '15'
		historyEnabled.checked = root.dataset.historyEnabled === '1'
		historyRetention.value = root.dataset.historyRetention || '180'

		var presets = {
			compact: { taskGap: 0, eventBuffer: 0 },
			balanced: { taskGap: 15, eventBuffer: 15 },
			relaxed: { taskGap: 30, eventBuffer: 30 },
		}
		pace.addEventListener('change', function () {
			var preset = presets[pace.value]
			if (!preset) return
			taskGap.value = String(preset.taskGap)
			eventBuffer.value = String(preset.eventBuffer)
		})
		function markCustom() {
			var preset = presets[pace.value]
			if (!preset || +taskGap.value !== preset.taskGap || +eventBuffer.value !== preset.eventBuffer) pace.value = 'custom'
		}
		taskGap.addEventListener('change', markCustom)
		eventBuffer.addEventListener('change', markCustom)

		save.addEventListener('click', function () {
			save.disabled = true
			status.textContent = 'Saving…'
			var url = window.OC && OC.generateUrl ? OC.generateUrl('/ocs/v2.php/apps/calplan/api/v1/settings/scheduling') : '/ocs/v2.php/apps/calplan/api/v1/settings/scheduling'
			fetch(url, {
				method: 'PUT',
				headers: {
					'Content-Type': 'application/json',
					'Accept': 'application/json',
					'OCS-APIRequest': 'true',
					'requesttoken': (window.OC && OC.requestToken) || '',
				},
				body: JSON.stringify({
					dailyCapMinutes: +dailyCap.value,
					dailyTaskCount: +dailyTaskCount.value,
					defaultDurationMinutes: +defaultDuration.value,
					taskGapMinutes: +taskGap.value,
					eventBufferMinutes: +eventBuffer.value,
					pacePreset: pace.value,
					autoRescheduleMinutes: +autoReschedule.value,
				}),
			}).then(function (response) {
				if (!response.ok) return response.json().then(function (body) { throw new Error(body.ocs?.data?.error || 'HTTP ' + response.status) })
				return response.json()
			}).then(function () {
				status.textContent = 'Saved'
			}).catch(function (error) {
				status.textContent = 'Could not save: ' + error.message
			}).then(function () {
				save.disabled = false
			})
		})

		saveExclusions.addEventListener('click', function () {
			saveExclusions.disabled = true
			exclusionsStatus.textContent = 'Saving…'
			var ignoredCalendars = Array.from(root.querySelectorAll('input[name="calplan-ignored-calendar"]:checked')).map(function (box) { return box.value })
			var titles = ignoredTitles.value.split(/\r?\n/).map(function (title) { return title.trim() }).filter(Boolean)
			var url = window.OC && OC.generateUrl ? OC.generateUrl('/ocs/v2.php/apps/calplan/api/v1/settings/exclusions') : '/ocs/v2.php/apps/calplan/api/v1/settings/exclusions'
			fetch(url, {
				method: 'PUT',
				headers: {
					'Content-Type': 'application/json',
					'Accept': 'application/json',
					'OCS-APIRequest': 'true',
					'requesttoken': (window.OC && OC.requestToken) || '',
				},
				body: JSON.stringify({
					ignoredCalendars: ignoredCalendars,
					ignoredEventTitles: titles,
					eventTitleMatchMode: titleMatchMode.value,
				}),
			}).then(function (response) {
				return response.json().then(function (body) {
					if (!response.ok) throw new Error(body.ocs?.data?.error || 'HTTP ' + response.status)
				})
			}).then(function () {
				exclusionsStatus.textContent = 'Saved. Run Reconcile to apply the new exclusions.'
			}).catch(function (error) {
				exclusionsStatus.textContent = 'Could not save: ' + error.message
			}).then(function () { saveExclusions.disabled = false })
		})

		function historyRequest(path, method, body) {
			var url = window.OC && OC.generateUrl ? OC.generateUrl('/ocs/v2.php/apps/calplan/api/v1/history' + path) : '/ocs/v2.php/apps/calplan/api/v1/history' + path
			return fetch(url, {
				method: method,
				headers: {
					'Content-Type': 'application/json',
					'Accept': 'application/json',
					'OCS-APIRequest': 'true',
					'requesttoken': (window.OC && OC.requestToken) || '',
				},
				body: body ? JSON.stringify(body) : undefined,
			}).then(function (response) {
				return response.json().then(function (data) {
					if (!response.ok) throw new Error(data.ocs?.data?.error || 'HTTP ' + response.status)
					return data.ocs ? data.ocs.data : data
				})
			})
		}

		saveHistory.addEventListener('click', function () {
			saveHistory.disabled = true
			historyStatus.textContent = 'Saving…'
			historyRequest('/settings', 'PUT', {
				enabled: historyEnabled.checked,
				retentionDays: +historyRetention.value,
			}).then(function () {
				historyStatus.textContent = historyEnabled.checked ? 'History collection enabled' : 'History collection disabled'
			}).catch(function (error) {
				historyStatus.textContent = 'Could not save: ' + error.message
			}).then(function () { saveHistory.disabled = false })
		})

		exportHistory.addEventListener('click', function () {
			exportHistory.disabled = true
			historyStatus.textContent = 'Exporting…'
			historyRequest('/export', 'POST').then(function (data) {
				historyStatus.textContent = 'Exported ' + data.records + ' records to ' + data.path
			}).catch(function (error) {
				historyStatus.textContent = 'Could not export: ' + error.message
			}).then(function () { exportHistory.disabled = false })
		})

		deleteHistory.addEventListener('click', function () {
			if (!window.confirm('Delete all CalPlan scheduling history? This cannot be undone.')) return
			deleteHistory.disabled = true
			historyStatus.textContent = 'Deleting…'
			historyRequest('', 'DELETE').then(function () {
				historyStatus.textContent = 'Scheduling history deleted'
			}).catch(function (error) {
				historyStatus.textContent = 'Could not delete: ' + error.message
			}).then(function () { deleteHistory.disabled = false })
		})
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init)
	else init()
})()

<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<!--
  RetentionSettings: how long integriq keeps each kind of log. Each value is
  shown in days, or hours for successful logs, with its default beside it,
  and saved through the admin-only settings route. A saved value applies to
  logs written afterwards; "Apply to existing logs" queues a background job
  that brings the logs already there in line, and its progress shows here.

  @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
  @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
-->
<template>
	<section class="integriq-admin__section" data-testid="admin-retention-section">
		<h3>{{ t('integriq', 'Log retention') }}</h3>
		<p class="integriq-admin__hint">
			{{ t('integriq', 'How long integriq keeps each kind of log. 0 keeps it indefinitely. A change applies to logs written afterwards.') }}
		</p>

		<div v-if="error" class="integriq-admin__action-error" role="alert">
			{{ error }}
		</div>

		<p v-if="loading" class="integriq-admin__hint">
			{{ t('integriq', 'Loading the setting…') }}
		</p>
		<div v-else class="retention-settings__form">
			<div
v-for="field in fields"
				:key="field.key"
				class="retention-settings__row">
				<NcTextField
					v-model="values[field.key]"
					type="number"
					min="0"
					step="any"
					:data-testid="'admin-retention-' + field.key"
					:label="labelOf(field)" />
				<span class="retention-settings__default">
					{{ defaultOf(field) }}
				</span>
			</div>
			<NcButton
				variant="primary"
				data-testid="admin-retention-save"
				:disabled="busy"
				@click="save">
				{{ t('integriq', 'Save') }}
			</NcButton>

			<h4>{{ t('integriq', 'Existing logs') }}</h4>
			<p class="integriq-admin__hint">
				{{ t('integriq', 'Recompute when the logs already stored expire, from when each was written. This runs in the background and can take a while on a large instance.') }}
			</p>
			<NcButton
				data-testid="admin-retention-rebase"
				:disabled="busy || rebaseRunning"
				@click="rebase">
				{{ t('integriq', 'Apply to existing logs') }}
			</NcButton>
			<p
v-if="rebaseStatus"
				class="integriq-admin__hint"
				data-testid="admin-retention-rebase-status">
				{{ rebaseStatus }}
			</p>
		</div>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcTextField } from '@nextcloud/vue'
import adminSetting from '../../mixins/adminSetting.js'
import { RETENTION_FIELDS, toMilliseconds, toUnit } from './retentionUnits.js'

const URL = '/apps/integriq/api/settings'

export default {
	name: 'RetentionSettings',

	components: { NcButton, NcTextField },

	mixins: [adminSetting],

	data() {
		return {
			fields: RETENTION_FIELDS,
			values: {},
			defaults: {},
			progress: {},
		}
	},

	computed: {
		/**
		 * @return {string} The setting endpoint.
		 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
		 */
		settingUrl() {
			return URL
		},

		/**
		 * @return {boolean} Whether a rebase is queued or running.
		 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
		 */
		rebaseRunning() {
			return ['queued', 'running'].includes(this.progress.status)
		},

		/**
		 * @return {string} The rebase's progress in words, empty when none ran.
		 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
		 */
		rebaseStatus() {
			const counts = { processed: this.progress.processed || 0, updated: this.progress.updated || 0 }
			switch (this.progress.status) {
			case 'queued':
				return t('integriq', 'Queued: the background job starts with the next cron run.')
			case 'running':
				return t('integriq', 'Running: {processed} logs read, {updated} given a new expiry.', counts)
			case 'done':
				return t('integriq', 'Done: {processed} logs read, {updated} given a new expiry.', counts)
			default:
				return ''
			}
		},
	},

	methods: {
		t,

		/**
		 * The label of a field, in its unit.
		 *
		 * @param {{key: string, unit: string}} field The field.
		 * @return {string} The label.
		 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
		 */
		labelOf(field) {
			const labels = {
				callLogRetention: t('integriq', 'Call logs with an error, in days'),
				jobLogRetention: t('integriq', 'Job logs with an error, in days'),
				syncLogRetention: t('integriq', 'Synchronization logs with an error, in days'),
				syncContractLogRetention: t('integriq', 'Synchronization contract logs with an error, in days'),
				eventMessageRetention: t('integriq', 'Event messages, in days'),
				executionTraceRetention: t('integriq', 'Execution traces, in days'),
				successLogRetention: t('integriq', 'Successful logs, in hours'),
			}
			return labels[field.key]
		},

		/**
		 * The default of a field, in its unit.
		 *
		 * @param {{key: string, unit: string}} field The field.
		 * @return {string} The default in words.
		 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
		 */
		defaultOf(field) {
			return t('integriq', 'Default: {value}', { value: toUnit(this.defaults[field.key], field.unit) })
		},

		/**
		 * Show what the server holds.
		 *
		 * @param {object|null} data The settings.
		 * @return {void}
		 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
		 */
		applyStored(data) {
			const retention = data?.retention || {}
			const values = {}
			for (const field of this.fields) {
				values[field.key] = toUnit(retention[field.key], field.unit)
			}
			this.values = values
			this.defaults = data?.defaults || {}
			this.progress = data?.rebase || {}
		},

		/**
		 * What to store: every value in milliseconds. A value that is not a
		 * number of 0 or more is sent as typed, so the server refuses it by name.
		 *
		 * @return {object} The request body.
		 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
		 */
		settingBody() {
			const retention = {}
			for (const field of this.fields) {
				const ms = toMilliseconds(this.values[field.key], field.unit)
				retention[field.key] = ms === null ? String(this.values[field.key]) : ms
			}
			return { retention }
		},

		/**
		 * Queue the job that applies the saved retention to existing logs.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
		 */
		async rebase() {
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.post(generateUrl(URL + '/rebase'), {})
				this.progress = { status: 'queued', kinds: data?.kinds || [], processed: 0, updated: 0 }
			} catch (e) {
				this.error = e?.response?.data?.error || t('integriq', 'The rebase could not be queued.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.retention-settings__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 480px;
}

.retention-settings__row {
	display: flex;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}

.retention-settings__default {
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	padding-bottom: var(--default-grid-baseline);
}
</style>

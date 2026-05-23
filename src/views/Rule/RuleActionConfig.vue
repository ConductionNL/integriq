<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  RuleActionConfig — picks the rule action type and renders the
  parameter form for that action. Mirrors the legacy EditRule modal's
  `typeOptions` switch but only ships rich forms for the two most-
  used actions in v1:
    - synchronization → Synchronization picker + "retain response"
    - error → HTTP code + title + message + includeJsonLogicResult
  All other action types fall through to a JSON editor for the
  matching `configuration[<type>]` sub-object, matching what
  RuleService::processCustomRule actually reads at evaluation time.

  Picker pattern follows #867's JobFormFields.vue: load
  synchronizations once on demand from
  `/apps/openregister/api/objects/openconnector/synchronization`.

  Out of scope (deferred to follow-up): rich forms for fetch_file,
  write_file, save_object, extend_input, extend_external_input. Each
  has a non-trivial param surface (file paths, schema pickers,
  property maps) that warrants its own component — tracked as a
  follow-up issue alongside drag-reorder.
-->
<template>
	<div class="rule-action-config">
		<div class="rule-action-config__row">
			<label class="rule-action-config__label" :for="'rule-action-type-' + uid">
				{{ t('openconnector', 'Action type') }}
			</label>
			<NcSelect
				:input-id="'rule-action-type-' + uid"
				:value="selectedTypeOption"
				:options="typeOptions"
				:clearable="false"
				:placeholder="t('openconnector', 'Pick an action type')"
				@input="onTypePick" />
			<p class="rule-action-config__hint">
				{{ t('openconnector', 'Selecting an action type changes which configuration fields are required below. The rule engine matches on this value at evaluation time.') }}
			</p>
		</div>

		<!-- Synchronization-specific parameters -->
		<div v-if="actionType === 'synchronization'" class="rule-action-config__params">
			<label class="rule-action-config__label" :for="'rule-action-sync-' + uid">
				{{ t('openconnector', 'Synchronization') }}
			</label>
			<NcSelect
				:input-id="'rule-action-sync-' + uid"
				:value="selectedSynchronization"
				:options="synchronizationOptions"
				:loading="synchronizationsLoading"
				:placeholder="t('openconnector', 'Select a synchronization')"
				@input="onSynchronizationPick" />
			<NcCheckboxRadioSwitch
				type="switch"
				:checked="!!retainResponse"
				@update:checked="onRetainResponseToggle">
				{{ t('openconnector', 'Retain original response') }}
			</NcCheckboxRadioSwitch>
			<span class="rule-action-config__helper">
				{{ t('openconnector', 'When enabled, the synchronization runs but the rule preserves the original response body instead of replacing it.') }}
			</span>
		</div>

		<!-- Error-specific parameters -->
		<div v-else-if="actionType === 'error'" class="rule-action-config__params">
			<NcTextField
				:label="t('openconnector', 'HTTP status code')"
				type="number"
				:value="errorCodeString"
				placeholder="500"
				@update:value="(value) => updateNested('error', 'code', value === '' ? null : Number(value))" />
			<NcTextField
				:label="t('openconnector', 'Error title')"
				:value="errorTitle"
				:placeholder="t('openconnector', 'Something went wrong')"
				@update:value="(value) => updateNested('error', 'name', value)" />
			<label class="rule-action-config__label" :for="'rule-action-error-msg-' + uid">
				{{ t('openconnector', 'Error message') }}
			</label>
			<textarea
				:id="'rule-action-error-msg-' + uid"
				class="rule-action-config__textarea"
				:value="errorMessage"
				:placeholder="t('openconnector', 'We encountered an unexpected problem')"
				rows="3"
				@input="updateNested('error', 'message', $event.target.value)" />
			<NcCheckboxRadioSwitch
				type="switch"
				:checked="!!includeJsonLogicResult"
				@update:checked="(value) => updateNested('error', 'includeJsonLogicResult', value)">
				{{ t('openconnector', 'Include JSON Logic results in errors array') }}
			</NcCheckboxRadioSwitch>
		</div>

		<!-- Fallback: raw JSON editor for the matching configuration[<type>] slot -->
		<div v-else-if="actionType" class="rule-action-config__params">
			<label class="rule-action-config__label" :for="'rule-action-raw-' + uid">
				{{ rawEditorLabel }}
			</label>
			<textarea
				:id="'rule-action-raw-' + uid"
				class="rule-action-config__textarea rule-action-config__textarea--code"
				:value="rawDraft"
				:placeholder="'{ }'"
				spellcheck="false"
				rows="8"
				@input="onRawInput($event.target.value)" />
			<span
				class="rule-action-config__helper"
				:class="{ 'rule-action-config__helper--error': rawError }">
				{{ rawError || t('openconnector', 'Rich form coming in a follow-up. Edit the raw JSON for now — it is written back as configuration.{type}.', { type: actionType }) }}
			</span>
		</div>
	</div>
</template>

<script>
import {
	NcCheckboxRadioSwitch,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Canonical list of rule action types. Lifted from the legacy modal's
 * `typeOptions.options` — the rule engine in RuleService::processCustomRule
 * and EndpointService matches the `configuration.type` value against
 * these strings, so adding/removing here without a backend change
 * breaks evaluation.
 */
const ACTION_TYPES = [
	{ id: 'error', label: 'Error' },
	{ id: 'mapping', label: 'Mapping' },
	{ id: 'synchronization', label: 'Synchronization' },
	{ id: 'javascript', label: 'JavaScript' },
	{ id: 'authentication', label: 'Authentication' },
	{ id: 'download', label: 'Download' },
	{ id: 'upload', label: 'Upload' },
	{ id: 'locking', label: 'Locking' },
	{ id: 'fetch_file', label: 'Fetch File' },
	{ id: 'write_file', label: 'Write File' },
	{ id: 'fileparts_create', label: 'Fileparts Create' },
	{ id: 'filepart_upload', label: 'Filepart Upload' },
	{ id: 'save_object', label: 'Save object' },
	{ id: 'extend_input', label: 'Extend input' },
	{ id: 'extend_external_input', label: 'Extend external input' },
]

let actionUidCounter = 0

export default {
	name: 'RuleActionConfig',

	components: {
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextField,
	},

	props: {
		/**
		 * Current `configuration` blob from the rule object. The action
		 * type lives at `configuration.type`; the action-specific
		 * sub-config lives at `configuration[<type>]`.
		 * @type {object}
		 */
		configuration: { type: Object, default: () => ({}) },
	},

	data() {
		return {
			uid: ++actionUidCounter,
			synchronizationOptions: [],
			synchronizationsLoading: false,
			/** Draft JSON string for the fallback editor, keyed by action type. */
			rawDrafts: {},
			rawError: '',
		}
	},

	computed: {
		actionType() {
			return this.configuration?.type || ''
		},
		typeOptions() {
			return ACTION_TYPES.map((entry) => ({ id: entry.id, label: this.t('openconnector', entry.label) }))
		},
		selectedTypeOption() {
			return this.typeOptions.find((option) => option.id === this.actionType) || null
		},
		syncConfig() {
			const value = this.configuration?.synchronization
			return value && typeof value === 'object' ? value : {}
		},
		retainResponse() {
			return !!this.syncConfig.retainResponse
		},
		synchronizationId() {
			return this.syncConfig.synchronization || this.syncConfig.synchronizationId || ''
		},
		selectedSynchronization() {
			const id = String(this.synchronizationId)
			if (!id) return null
			return this.synchronizationOptions.find((option) => option.id === id) ?? {
				id,
				label: id,
			}
		},
		errorConfig() {
			const value = this.configuration?.error
			return value && typeof value === 'object' ? value : {}
		},
		errorCodeString() {
			return this.errorConfig.code != null ? String(this.errorConfig.code) : ''
		},
		errorTitle() {
			return this.errorConfig.name || ''
		},
		errorMessage() {
			return this.errorConfig.message || ''
		},
		includeJsonLogicResult() {
			return !!this.errorConfig.includeJsonLogicResult
		},
		rawDraft() {
			const draft = this.rawDrafts[this.actionType]
			if (draft !== undefined) return draft
			const raw = this.configuration?.[this.actionType]
			if (raw === undefined || raw === null) return ''
			if (typeof raw === 'string') return raw
			try { return JSON.stringify(raw, null, 2) } catch (_e) { return String(raw) }
		},
		rawEditorLabel() {
			return this.t('openconnector', 'Raw configuration for {type}', { type: this.actionType })
		},
	},

	watch: {
		actionType: {
			immediate: true,
			handler(value) {
				if (value === 'synchronization' && this.synchronizationOptions.length === 0) {
					this.fetchSynchronizations()
				}
			},
		},
	},

	methods: {
		onTypePick(option) {
			if (!option) return
			const next = { ...(this.configuration || {}), type: option.id }
			this.$emit('update', next)
		},

		onSynchronizationPick(option) {
			const nextSync = { ...this.syncConfig }
			if (option?.id) {
				nextSync.synchronization = String(option.id)
			} else {
				delete nextSync.synchronization
				delete nextSync.synchronizationId
			}
			const next = { ...(this.configuration || {}), synchronization: nextSync }
			this.$emit('update', next)
		},

		onRetainResponseToggle(value) {
			const nextSync = { ...this.syncConfig, retainResponse: !!value }
			const next = { ...(this.configuration || {}), synchronization: nextSync }
			this.$emit('update', next)
		},

		updateNested(section, key, value) {
			const current = this.configuration?.[section]
			const base = current && typeof current === 'object' ? current : {}
			const nextSection = { ...base, [key]: value }
			const next = { ...(this.configuration || {}), [section]: nextSection }
			this.$emit('update', next)
		},

		onRawInput(value) {
			this.$set(this.rawDrafts, this.actionType, value)
			const trimmed = value.trim()
			if (trimmed.length === 0) {
				this.rawError = ''
				const next = { ...(this.configuration || {}) }
				delete next[this.actionType]
				this.$emit('update', next)
				return
			}
			try {
				const parsed = JSON.parse(trimmed)
				this.rawError = ''
				const next = { ...(this.configuration || {}), [this.actionType]: parsed }
				this.$emit('update', next)
			} catch (parseErr) {
				this.rawError = this.t('openconnector', 'Invalid JSON: {message}', { message: parseErr.message })
			}
		},

		async fetchSynchronizations() {
			this.synchronizationsLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/objects/openconnector/synchronization'),
					{ params: { limit: 500 } },
				)
				const data = response.data
				const list = Array.isArray(data?.results)
					? data.results
					: Array.isArray(data)
						? data
						: []
				this.synchronizationOptions = list.map((sync) => ({
					id: String(sync.id || sync.uuid),
					label: sync.name || sync.title || sync.id,
				}))
			} catch (err) {
				// eslint-disable-next-line no-console
				console.warn('[RuleActionConfig] synchronization fetch failed', err)
				this.synchronizationOptions = []
			} finally {
				this.synchronizationsLoading = false
			}
		},
	},
}
</script>

<style scoped>
.rule-action-config {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.rule-action-config__row,
.rule-action-config__params {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.rule-action-config__label {
	font-weight: bold;
}

.rule-action-config__hint,
.rule-action-config__helper {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.rule-action-config__helper--error {
	color: var(--color-error);
}

.rule-action-config__textarea {
	width: 100%;
	padding: 8px;
	font-family: var(--font-face, sans-serif);
	font-size: 14px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.rule-action-config__textarea--code {
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
}
</style>

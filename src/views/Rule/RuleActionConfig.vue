<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  RuleActionConfig — picks the rule action type and renders the
  parameter form for that action.

  Action-type → bespoke-form wiring lives in ACTION_FORM_MAP below.
  Each bespoke form lives under `src/views/Rule/actionForms/` and
  owns `v-model:value` over its slice of `configuration[<type>]`,
  with the exception of `mapping` which is stored as a bare id at
  `configuration.mapping` (no nested slot) — that single irregularity
  is handled by the form's `v-model:id` contract.

  Action types without a bespoke form (currently none — every type
  enumerated by the legacy modal has a dedicated form as of #877)
  still fall through to the JSON-textarea path so unknown future
  types stay editable.

  Closes #877 (action-form half).
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

		<!-- Bespoke form for the picked action type. `mapping` is the one
		     outlier: its value lives at configuration.mapping (bare id)
		     instead of configuration.mapping.mapping. -->
		<div v-if="actionType === 'mapping'" class="rule-action-config__params">
			<MappingForm
				:id="configuration.mapping || ''"
				@update:id="onMappingIdUpdate" />
		</div>
		<div v-else-if="actionType === 'javascript'" class="rule-action-config__params">
			<JavascriptForm
				:code="typeof configuration.javascript === 'string' ? configuration.javascript : ''"
				@update:code="onJavascriptCodeUpdate" />
		</div>
		<div v-else-if="formComponent" class="rule-action-config__params">
			<component
				:is="formComponent"
				:value="slotValue"
				@update:value="onSlotUpdate" />
		</div>

		<!-- Fallback: raw JSON editor for the matching configuration[<type>] slot.
		     Only fires for action types with no entry in ACTION_FORM_MAP. -->
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
				{{ rawError || t('openconnector', 'No bespoke form yet for this action type. Edit the raw JSON for now — it is written back as configuration.{type}.', { type: actionType }) }}
			</span>
		</div>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import SynchronizationForm from './actionForms/SynchronizationForm.vue'
import ErrorForm from './actionForms/ErrorForm.vue'
import MappingForm from './actionForms/MappingForm.vue'
import JavascriptForm from './actionForms/JavascriptForm.vue'
import AuthenticationForm from './actionForms/AuthenticationForm.vue'
import DownloadForm from './actionForms/DownloadForm.vue'
import UploadForm from './actionForms/UploadForm.vue'
import LockingForm from './actionForms/LockingForm.vue'
import FetchFileForm from './actionForms/FetchFileForm.vue'
import WriteFileForm from './actionForms/WriteFileForm.vue'
import FilepartsCreateForm from './actionForms/FilepartsCreateForm.vue'
import FilepartUploadForm from './actionForms/FilepartUploadForm.vue'
import SaveObjectForm from './actionForms/SaveObjectForm.vue'
import ExtendInputForm from './actionForms/ExtendInputForm.vue'
import ExtendExternalInputForm from './actionForms/ExtendExternalInputForm.vue'

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

/**
 * Map from action-type id (as used at `configuration.type`) to the
 * component name to render. Forms that need to read/write a different
 * slot than `configuration[type]` (currently only `mapping` and
 * `javascript`) are special-cased in the template above.
 */
const ACTION_FORM_MAP = {
	synchronization: 'SynchronizationForm',
	error: 'ErrorForm',
	mapping: 'MappingForm',
	javascript: 'JavascriptForm',
	authentication: 'AuthenticationForm',
	download: 'DownloadForm',
	upload: 'UploadForm',
	locking: 'LockingForm',
	fetch_file: 'FetchFileForm',
	write_file: 'WriteFileForm',
	fileparts_create: 'FilepartsCreateForm',
	filepart_upload: 'FilepartUploadForm',
	save_object: 'SaveObjectForm',
	extend_input: 'ExtendInputForm',
	extend_external_input: 'ExtendExternalInputForm',
}

let actionUidCounter = 0

export default {
	name: 'RuleActionConfig',

	components: {
		NcSelect,
		SynchronizationForm,
		ErrorForm,
		MappingForm,
		JavascriptForm,
		AuthenticationForm,
		DownloadForm,
		UploadForm,
		LockingForm,
		FetchFileForm,
		WriteFileForm,
		FilepartsCreateForm,
		FilepartUploadForm,
		SaveObjectForm,
		ExtendInputForm,
		ExtendExternalInputForm,
	},

	props: {
		/**
		 * Current `configuration` blob from the rule object. The action
		 * type lives at `configuration.type`; the action-specific
		 * sub-config lives at `configuration[<type>]` (except for the
		 * two outliers documented in ACTION_FORM_MAP).
		 * @type {object}
		 */
		configuration: { type: Object, default: () => ({}) },
	},

	data() {
		return {
			uid: ++actionUidCounter,
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
		formComponent() {
			return ACTION_FORM_MAP[this.actionType] || null
		},
		slotValue() {
			const raw = this.configuration?.[this.actionType]
			return raw && typeof raw === 'object' ? raw : {}
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

	methods: {
		onTypePick(option) {
			if (!option) return
			const next = { ...(this.configuration || {}), type: option.id }
			this.$emit('update', next)
		},

		onSlotUpdate(next) {
			const merged = { ...(this.configuration || {}), [this.actionType]: next }
			this.$emit('update', merged)
		},

		onMappingIdUpdate(id) {
			const next = { ...(this.configuration || {}) }
			if (id) {
				next.mapping = String(id)
			} else {
				delete next.mapping
			}
			this.$emit('update', next)
		},

		onJavascriptCodeUpdate(code) {
			const next = { ...(this.configuration || {}), javascript: code }
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

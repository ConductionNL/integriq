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

  The canonical type list is `views/Rule/ruleDraft.js`, shared with
  `modals/v2/RuleEditorModal.vue`. Picking a type emits BOTH `update`
  (the `configuration` blob, carrying `configuration.type`) and
  `update:type` (the bare id) — the parent has to write the rule's
  top-level `type`, because that is the property the pipeline
  dispatches on. See the `configuration` prop docblock.

  Closes #877 (action-form half).
-->
<template>
	<div class="rule-action-config">
		<div class="rule-action-config__row">
			<label
				class="rule-action-config__label"
				:for="'rule-action-type-' + uid">
				{{ t('openconnector', 'Action type') }}
			</label>
			<NcSelect
				:inputId="'rule-action-type-' + uid"
				:aria-label-combobox="t('openconnector', 'Action type')"
				:modelValue="selectedTypeOption"
				:options="typeOptions"
				:clearable="false"
				:placeholder="t('openconnector', 'Pick an action type')"
				@update:modelValue="onTypePick" />
			<p class="rule-action-config__hint">
				{{
					t(
						'openconnector',
						'Selecting an action type changes which configuration fields are required below. The rule engine matches on this value at evaluation time.',
					)
				}}
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
		<div
			v-else-if="actionType === 'javascript'"
			class="rule-action-config__params">
			<JavascriptForm
				:code="
					typeof configuration.javascript === 'string'
						? configuration.javascript
						: ''
				"
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
				placeholder="{ }"
				spellcheck="false"
				rows="8"
				@input="onRawInput($event.target.value)" />
			<span
				class="rule-action-config__helper"
				:class="{ 'rule-action-config__helper--error': rawError }">
				{{
					rawError
					|| t(
						'openconnector',
						'No bespoke form yet for this action type. Edit the raw JSON for now — it is written back as configuration.{type}.',
						{ type: actionType },
					)
				}}
			</span>
		</div>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import ApprovalForm from './actionForms/ApprovalForm.vue'
import AuthenticationForm from './actionForms/AuthenticationForm.vue'
import DownloadForm from './actionForms/DownloadForm.vue'
import ErrorForm from './actionForms/ErrorForm.vue'
import ExtendExternalInputForm from './actionForms/ExtendExternalInputForm.vue'
import ExtendInputForm from './actionForms/ExtendInputForm.vue'
import FetchFileForm from './actionForms/FetchFileForm.vue'
import FilepartsCreateForm from './actionForms/FilepartsCreateForm.vue'
import FilepartUploadForm from './actionForms/FilepartUploadForm.vue'
import JavascriptForm from './actionForms/JavascriptForm.vue'
import LockingForm from './actionForms/LockingForm.vue'
import MappingForm from './actionForms/MappingForm.vue'
import SaveObjectForm from './actionForms/SaveObjectForm.vue'
import SynchronizationForm from './actionForms/SynchronizationForm.vue'
import UploadForm from './actionForms/UploadForm.vue'
import WebhookSignatureForm from './actionForms/WebhookSignatureForm.vue'
import WriteFileForm from './actionForms/WriteFileForm.vue'
import { ACTION_TYPES } from './ruleDraft.js'

/**
 * Map from action-type id to the component name to render. Forms that need to
 * read/write a different slot than `configuration[type]` (currently only
 * `mapping` and `javascript`) are special-cased in the template above.
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
	webhook_signature: 'WebhookSignatureForm',
	approval: 'ApprovalForm',
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
		WebhookSignatureForm,
		ApprovalForm,
	},

	props: {
		/**
		 * Current `configuration` blob from the rule object. This component
		 * tracks the action type at `configuration.type` because that is where
		 * it can keep it alongside the per-type slots; the action-specific
		 * sub-config lives at `configuration[<type>]` (except for the two
		 * outliers documented in ACTION_FORM_MAP).
		 *
		 * `configuration.type` is NOT what the pipeline dispatches on —
		 * `EndpointService::handleRuleProcessing()` reads the rule's top-level
		 * `type`, and `configuration.type` is only consulted by
		 * `RuleService::processCustomRule()` to sub-dispatch rules whose
		 * top-level type is `custom`. So the parent has to write the top-level
		 * field too: that is what the `update:type` event below is for. A rule
		 * saved with only `configuration.type` set is never executed — the
		 * dispatch `match` falls through to
		 * `throw new Exception('Unsupported rule type: ')`.
		 *
		 * @type {object}
		 */
		configuration: { type: Object, default: () => ({}) },
		/**
		 * The rule's top-level `type`, used as the fallback when
		 * `configuration.type` is absent. Rules written by the pre-manifest
		 * modal set only the top-level field, so without this the action picker
		 * renders empty for every rule created before this component existed —
		 * and re-picking would look like a change when nothing changed.
		 *
		 * @type {string}
		 */
		type: { type: String, default: '' },
	},

	emits: [
		/** Replacement `configuration` blob. */
		'update',
		/**
		 * The picked action type, for the parent to write to the rule's
		 * top-level `type` property. Emitted alongside `update`, never instead
		 * of it — see the props docblock for why both keys have to move.
		 */
		'update:type',
	],

	data() {
		return {
			uid: ++actionUidCounter,
			/** Draft JSON string for the fallback editor, keyed by action type. */
			rawDrafts: {},
			rawError: '',
		}
	},

	computed: {
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		actionType() {
			return this.configuration?.type || this.type || ''
		},

		/** @spec openspec/specs/rule-editor-ui/spec.md */
		typeOptions() {
			return ACTION_TYPES.map((entry) => ({
				id: entry.id,
				label: this.t('openconnector', entry.label),
			}))
		},

		/** @spec openspec/specs/rule-editor-ui/spec.md */
		selectedTypeOption() {
			return (
				this.typeOptions.find((option) => option.id === this.actionType)
				|| null
			)
		},

		/** @spec openspec/specs/rule-editor-ui/spec.md */
		formComponent() {
			return ACTION_FORM_MAP[this.actionType] || null
		},

		/** @spec openspec/specs/rule-editor-ui/spec.md */
		slotValue() {
			const raw = this.configuration?.[this.actionType]
			return raw && typeof raw === 'object' ? raw : {}
		},

		/** @spec openspec/specs/rule-editor-ui/spec.md */
		rawDraft() {
			const draft = this.rawDrafts[this.actionType]
			if (draft !== undefined) return draft
			const raw = this.configuration?.[this.actionType]
			if (raw === undefined || raw === null) return ''
			if (typeof raw === 'string') return raw
			try {
				return JSON.stringify(raw, null, 2)
			} catch (_e) {
				return String(raw)
			}
		},

		/** @spec openspec/specs/rule-editor-ui/spec.md */
		rawEditorLabel() {
			return this.t('openconnector', 'Raw configuration for {type}', {
				type: this.actionType,
			})
		},
	},

	methods: {
		/**
		 * Switch the action type. Within `configuration` only `type` is
		 * rewritten — the per-type parameter slots are left in place so toggling
		 * back to a type restores what was already configured for it.
		 *
		 * `update:type` carries the same value out for the parent to store on
		 * the rule's top-level `type`, which is the property the pipeline
		 * actually dispatches on. Both are emitted; see the props docblock.
		 *
		 * @param {{id: string, label: string}} option The action-type option
		 *   picked in the NcSelect; `id` is the raw action type (`mapping`,
		 *   `javascript`, …). Null/undefined when cleared, which is ignored.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onTypePick(option) {
			if (!option) return
			const next = { ...(this.configuration || {}), type: option.id }
			this.$emit('update', next)
			this.$emit('update:type', option.id)
		},

		/**
		 * Write a bespoke form's `update:value` payload back into the action's
		 * own slot, `configuration[actionType]`, leaving every other slot alone.
		 *
		 * @param {object} next The full replacement parameter object emitted by
		 *   the active action form (each form owns its whole slice).
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onSlotUpdate(next) {
			const merged = { ...(this.configuration || {}), [this.actionType]: next }
			this.$emit('update', merged)
		},

		/**
		 * Handle the `mapping` outlier: its value is a bare id stored directly
		 * at `configuration.mapping`, not in a nested slot. An empty pick drops
		 * the key entirely so no blank mapping reference is persisted.
		 *
		 * @param {string} id The picked mapping's id (stringified before it is
		 *   stored). Empty string when MappingForm's select was cleared.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onMappingIdUpdate(id) {
			const next = { ...(this.configuration || {}) }
			if (id) {
				next.mapping = String(id)
			} else {
				delete next.mapping
			}
			this.$emit('update', next)
		},

		/**
		 * Store the `javascript` action's source. Like `mapping`, it is a bare
		 * scalar at `configuration.javascript` rather than a nested slot.
		 *
		 * @param {string} code The script body emitted by JavascriptForm.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onJavascriptCodeUpdate(code) {
			const next = { ...(this.configuration || {}), javascript: code }
			this.$emit('update', next)
		},

		/**
		 * Fallback JSON-textarea path for action types with no bespoke form.
		 * The draft is kept verbatim per action type so a half-typed value
		 * survives; on valid JSON it is parsed into `configuration[actionType]`,
		 * on empty input that key is removed, and on a parse error the previous
		 * configuration is kept while `rawError` shows the message.
		 *
		 * @param {string} value The current raw textarea contents.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onRawInput(value) {
			this.rawDrafts[this.actionType] = value
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
				const next = {
					...(this.configuration || {}),
					[this.actionType]: parsed,
				}
				this.$emit('update', next)
			} catch (parseErr) {
				this.rawError = this.t('openconnector', 'Invalid JSON: {message}', {
					message: parseErr.message,
				})
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

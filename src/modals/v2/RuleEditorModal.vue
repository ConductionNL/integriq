<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  RuleEditorModal — the create/edit surface for a Rule on the Rules index.

  It restores the field set the pre-manifest modal
  (`src/modals/Rule/EditRule.vue`) offered, which the schema-driven
  CnFormDialog could not express:

      name │ description

      ┌─────────────────────────────────────────────┐
      │ When (conditions)      [visual ⇄ raw JSON]  │
      └─────────────────────────────────────────────┘

      timing │ order │ action │ type

      ┌─────────────────────────────────────────────┐
      │ Error response   (only when type = error)   │
      └─────────────────────────────────────────────┘

  What the generic dialog got wrong, and why each field is here:

    • `action`, `timing` and `type` are declared as enum-less strings on the
      `rule` schema, so `resolveWidget()` fell through to `text` and offered
      three free-text inputs for three closed vocabularies. They are selects
      here, sourced from `ruleDraft.js` — which documents, per list, the
      backend that constrains it.

    • `conditions` is `type: object`, and `fieldsFromSchema()` drops plain
      objects that carry no `widget` hint — so the single most important field
      on a rule was not on the form at all. It gets the visual JsonLogic
      builder with a raw-JSON escape hatch, the same pair RuleDetailPage
      offers.

    • `order` was in the page's `columns` but not its `includeFields`: listed,
      never editable.

    • The error response (code/title/message + the include-JsonLogic switch)
      lives at `configuration.error.*`. `fieldsFromSchema()` only walks
      top-level `schema.properties`, so no nested path is reachable
      declaratively at all.

  ## Not a reimplementation

  The condition tree is `views/Rule/RuleConditionGroup.vue` — the same
  component RuleDetailPage and SynchronizationEditorModal host. The option
  lists, the conditions normaliser and the draft defaults are
  `views/Rule/ruleDraft.js`, shared with RuleActionConfig and RuleDetailPage.
  This modal is a third host for them, not a third copy.

  ## Scope: error is the only action type configured here

  `type` can be set to any of the 17 authorable action types, but only `error`
  gets its parameters on this screen. The other 16 have bespoke forms under
  `views/Rule/actionForms/`, hosted by RuleActionConfig on the rule detail
  page, and cramming 16 conditional blocks back into a dialog is what made the
  1919-line legacy modal unmaintainable. Picking another type here creates a
  valid rule and the "Open full editor" row action finishes it.

  ## How it is mounted

  Through CnIndexPage's `form-dialog` slot, declared in the manifest as
  `pages[Rules].slots["form-dialog"]`. Not `form-fields`: CnIndexPage does not
  forward `size` to CnFormDialog, so an inner-content override can never be
  wider than NcDialog's `normal`, and RuleConditionLeaf's operator select alone
  carries `min-width: 220px`. Same reasoning as MappingEditorModal and
  SynchronizationEditorModal.

  Note the template is gated on `show` — unlike the default CnFormDialog, slot
  content always renders, so the gate has to be ours.

  ## Draft semantics

  Every edit lands in a local `draft`, seeded from `item` when the dialog
  opens; nothing is persisted until Save, which goes through the slot's
  `confirm` binding so the index's own save path (and its list refresh) runs.

  `configuration` is merged, never replaced: the rule lockdown overlay
  (`lib/Settings/register.d/99-rule-lockdown.json`) documents that
  `configuration.authentication.keys` holds live impersonation credentials, so
  a spread that dropped sibling keys would silently destroy them.

  @spec openspec/specs/rule-editor-ui/spec.md
-->
<template>
	<NcDialog v-if="show"
		:name="dialogTitle"
		size="large"
		class="cn-rule-editor-modal"
		:no-close="saving"
		@closing="onCancel">
		<div class="cn-rule-editor">
			<NcNoteCard v-if="saveError" type="error">
				<p>{{ saveError }}</p>
			</NcNoteCard>

			<!-- Identity fields — the rule's own metadata, so they sit above the
			     when/then sections rather than inside one, stacked and capped
			     short of the modal width. -->
			<div class="cn-rule-editor__identity">
				<NcTextField :model-value="draft.name"
					:label="nameLabel"
					:error="!!nameError"
					:helper-text="nameError"
					:disabled="saving"
					@update:model-value="(value) => updateDraft('name', value)"
					@blur="nameTouched = true" />
				<NcTextArea :model-value="draft.description"
					:label="t('openconnector', 'Description')"
					:disabled="saving"
					rows="1"
					resize="vertical"
					@update:model-value="(value) => updateDraft('description', value)" />
			</div>

			<!-- ── When: conditions ────────────────────────────────────── -->
			<section class="cn-rule-editor__section">
				<header class="cn-rule-editor__section-header">
					<FilterOutlineIcon :size="20" />
					<h3>{{ t('openconnector', 'When (conditions)') }}</h3>
					<NcButton type="tertiary"
						:disabled="saving"
						:aria-label="rawConditions
							? t('openconnector', 'Switch back to visual builder')
							: t('openconnector', 'Edit conditions as raw JSON')"
						@click="rawConditions = !rawConditions">
						<template #icon>
							<CodeJsonIcon :size="18" />
						</template>
						{{ rawConditions ? t('openconnector', 'Visual builder') : t('openconnector', 'Raw JSON') }}
					</NcButton>
				</header>

				<RuleConditionGroup v-if="!rawConditions"
					:node="rootConditionGroup"
					:removable="false"
					@update="onConditionsUpdate" />

				<div v-else class="cn-rule-editor__raw">
					<textarea
						class="cn-rule-editor__textarea cn-rule-editor__textarea--code"
						:value="rawConditionsDraft"
						:disabled="saving"
						:placeholder="conditionsPlaceholder"
						spellcheck="false"
						rows="8"
						:aria-label="t('openconnector', 'Conditions as JSON Logic')"
						@input="onRawConditionsInput($event.target.value)" />
					<div class="cn-rule-editor__raw-footer">
						<span class="cn-rule-editor__helper"
							:class="{ 'cn-rule-editor__helper--error': !!rawConditionsError }">
							{{ rawConditionsError || t('openconnector', 'JSON Logic, evaluated against the incoming request data. Leave empty to always match.') }}
						</span>
						<NcButton type="secondary"
							size="small"
							:disabled="saving || !!rawConditionsError || !rawConditionsDraft.trim()"
							@click="formatRawConditions">
							{{ t('openconnector', 'Format JSON') }}
						</NcButton>
					</div>
				</div>
			</section>

			<!-- ── Then: where and what ────────────────────────────────── -->
			<section class="cn-rule-editor__section">
				<header class="cn-rule-editor__section-header">
					<PlayOutlineIcon :size="20" />
					<h3>{{ t('openconnector', 'Then (action)') }}</h3>
				</header>

				<div class="cn-rule-editor__grid">
					<div class="cn-rule-editor__field">
						<label for="cn-rule-editor-timing" class="cn-rule-editor__label">
							{{ t('openconnector', 'Timing') }}
						</label>
						<NcSelect input-id="cn-rule-editor-timing"
							:model-value="selectedTiming"
							:options="timingOptions"
							:clearable="false"
							:disabled="saving"
							:aria-label-combobox="t('openconnector', 'Timing')"
							@update:model-value="(option) => updateDraft('timing', option?.id || 'before')" />
						<span class="cn-rule-editor__helper">
							{{ t('openconnector', 'Whether the rule runs before or after the endpoint handles the request.') }}
						</span>
					</div>

					<div class="cn-rule-editor__field">
						<NcInputField :model-value="orderText"
							type="number"
							:label="t('openconnector', 'Order')"
							:disabled="saving"
							placeholder="100"
							@update:model-value="onOrderInput" />
						<span class="cn-rule-editor__helper">
							{{ t('openconnector', 'Execution order within the timing slot — lower runs first.') }}
						</span>
					</div>

					<div class="cn-rule-editor__field">
						<label for="cn-rule-editor-action" class="cn-rule-editor__label">
							{{ t('openconnector', 'Action') }} *
						</label>
						<NcSelect input-id="cn-rule-editor-action"
							:model-value="selectedAction"
							:options="actionOptions"
							:clearable="false"
							:disabled="saving"
							:aria-label-combobox="t('openconnector', 'Action')"
							@update:model-value="(option) => updateDraft('action', option?.id || '')" />
						<span class="cn-rule-editor__helper">
							{{ t('openconnector', 'The request method this rule applies to.') }}
						</span>
					</div>

					<div class="cn-rule-editor__field">
						<label for="cn-rule-editor-type" class="cn-rule-editor__label">
							{{ t('openconnector', 'Type') }} *
						</label>
						<NcSelect input-id="cn-rule-editor-type"
							:model-value="selectedType"
							:options="typeOptions"
							:clearable="false"
							:disabled="saving"
							:aria-label-combobox="t('openconnector', 'Type')"
							@update:model-value="onTypePick" />
						<span class="cn-rule-editor__helper">
							{{ isErrorType
								? t('openconnector', 'The error response is configured below.')
								: t('openconnector', 'Configure this type with the Open full editor row action.') }}
						</span>
					</div>
				</div>
			</section>

			<!-- ── Error response — only this type is configurable here ── -->
			<section v-if="isErrorType" class="cn-rule-editor__section">
				<header class="cn-rule-editor__section-header">
					<AlertCircleOutlineIcon :size="20" />
					<h3>{{ t('openconnector', 'Error response') }}</h3>
				</header>

				<div class="cn-rule-editor__grid">
					<div class="cn-rule-editor__field">
						<NcInputField :model-value="errorCodeText"
							type="number"
							:min="100"
							:max="999"
							:label="t('openconnector', 'Error Code')"
							:disabled="saving"
							placeholder="500"
							@update:model-value="onErrorCodeInput" />
					</div>

					<div class="cn-rule-editor__field">
						<NcTextField :model-value="errorConfig.name"
							:label="t('openconnector', 'Error Title')"
							maxlength="255"
							:disabled="saving"
							:placeholder="t('openconnector', 'Something went wrong')"
							@update:model-value="(value) => updateErrorField('name', value)" />
					</div>
				</div>

				<NcTextArea :model-value="errorConfig.message"
					:label="t('openconnector', 'Error Message')"
					maxlength="2550"
					resize="vertical"
					rows="3"
					:disabled="saving"
					:placeholder="t('openconnector', 'We encountered an unexpected problem')"
					@update:model-value="(value) => updateErrorField('message', value)" />

				<NcCheckboxRadioSwitch type="checkbox"
					:model-value="!!errorConfig.includeJsonLogicResult"
					:disabled="saving"
					@update:model-value="(value) => updateErrorField('includeJsonLogicResult', value)">
					{{ t('openconnector', 'Include JSON Logic results in errors array') }}
				</NcCheckboxRadioSwitch>
			</section>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="onCancel">
				{{ t('openconnector', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="!canSave" @click="onSave">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<PlusIcon v-else-if="isCreate" :size="20" />
					<ContentSaveOutlineIcon v-else :size="20" />
				</template>
				{{ isCreate ? t('openconnector', 'Create') : t('openconnector', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcInputField,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import AlertCircleOutlineIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import CodeJsonIcon from 'vue-material-design-icons/CodeJson.vue'
import ContentSaveOutlineIcon from 'vue-material-design-icons/ContentSaveOutline.vue'
import FilterOutlineIcon from 'vue-material-design-icons/FilterOutline.vue'
import PlayOutlineIcon from 'vue-material-design-icons/PlayOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { showSuccess } from '@nextcloud/dialogs'

import RuleConditionGroup from '../../views/Rule/RuleConditionGroup.vue'
import {
	ACTION_OPTIONS,
	ACTION_TYPES,
	DEFAULT_ERROR_CONFIG,
	TIMING_OPTIONS,
	emptyRootGroup,
	emptyRuleDraft,
	normaliseConditions,
	serializeRuleConditions,
} from '../../views/Rule/ruleDraft.js'

/** A name has to carry at least one letter or digit — punctuation alone is not a name. */
const NAME_PATTERN = /[\p{L}\p{N}]/u

/**
 * Example condition tree shown in the raw editor. Deliberately not run through
 * `t()` — it is a code-shaped example, not prose, and a dynamic `t(variable)`
 * would not be picked up by string extraction anyway.
 */
const CONDITIONS_PLACEHOLDER = '{"and": [{"==": [{"var": "status"}, "active"]}, {">=": [{"var": "age"}, 18]}]}'

export default {
	name: 'RuleEditorModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcInputField,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
		RuleConditionGroup,
		AlertCircleOutlineIcon,
		CodeJsonIcon,
		ContentSaveOutlineIcon,
		FilterOutlineIcon,
		PlayOutlineIcon,
		PlusIcon,
	},

	props: {
		/** Slot scope: whether CnIndexPage wants the form dialog open. */
		show: {
			type: Boolean,
			default: false,
		},
		/** Slot scope: the row being edited, or `null` in create mode. */
		item: {
			type: Object,
			default: null,
		},
		/** Slot scope: the effective JSON schema. Unused — the fields are bespoke. */
		schema: {
			type: Object,
			default: null,
		},
		/**
		 * Slot scope: persists the object through CnIndexPage's own save path
		 * and refreshes the list. Saving here instead of calling this would
		 * leave the index stale until a reload — the built-in refresh only runs
		 * inside that handler — and would write to a different store than the
		 * one the list reads from.
		 */
		confirm: {
			type: Function,
			default: null,
		},
		/** Slot scope: closes the form dialog on CnIndexPage. */
		close: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			draft: emptyRuleDraft(),
			/** Serialised snapshot of the seeded draft, for the dirty check. */
			originalSignature: '',
			/**
			 * Whether the name field has been left at least once. The "required"
			 * message waits for this so a freshly-opened Create dialog does not
			 * greet the user with a red empty field.
			 */
			nameTouched: false,
			/** True while the conditions are edited as text rather than as a tree. */
			rawConditions: false,
			/** Verbatim textarea contents; only committed to the draft when it parses. */
			rawConditionsDraft: '',
			rawConditionsError: '',
			saving: false,
			saveError: '',
		}
	},

	computed: {
		/** @spec exclude static example string — presentation only */
		conditionsPlaceholder() {
			return CONDITIONS_PLACEHOLDER
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		isCreate() {
			return !this.item
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		dialogTitle() {
			return this.isCreate
				? this.t('openconnector', 'Create rule')
				: this.t('openconnector', 'Edit rule')
		},
		/**
		 * Required marker on the label. NcTextField/NcInputField has no
		 * `required` prop and renders no marker of its own, so the ` *` suffix
		 * is appended here — the same convention CnFormDialog uses for
		 * schema-required fields.
		 *
		 * @return {string} Label text.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		nameLabel() {
			return this.t('openconnector', 'Name') + ' *'
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		nameError() {
			if (!this.draft.name) {
				return this.nameTouched
					? this.t('openconnector', 'Name is required')
					: ''
			}
			return NAME_PATTERN.test(this.draft.name)
				? ''
				: this.t('openconnector', 'Name must contain at least one letter or number')
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		dirty() {
			return JSON.stringify(this.draft) !== this.originalSignature
		},
		/**
		 * Whether Save is offered. `action` joins `name` in the guard because
		 * both are `required` on the `rule` schema, and a raw-conditions draft
		 * that does not parse blocks the save rather than silently persisting
		 * the last tree that did.
		 *
		 * @return {boolean} True when the draft can be persisted.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		canSave() {
			return !this.saving
				&& !!this.draft.name
				&& !this.nameError
				&& !!this.draft.action
				&& !!this.draft.type
				&& !this.rawConditionsError
				// No `confirm` means the host did not bind the slot scope, so
				// there is nothing to save through.
				&& typeof this.confirm === 'function'
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		rootConditionGroup() {
			return normaliseConditions(this.draft?.conditions)
		},
		/** @spec exclude static option list — presentation only */
		timingOptions() {
			return TIMING_OPTIONS.map((entry) => ({ id: entry.id, label: this.t('openconnector', entry.label) }))
		},
		/** @spec exclude static option list — presentation only */
		actionOptions() {
			return ACTION_OPTIONS.map((entry) => ({ id: entry.id, label: this.t('openconnector', entry.label) }))
		},
		/** @spec exclude static option list — presentation only */
		typeOptions() {
			return ACTION_TYPES.map((entry) => ({ id: entry.id, label: this.t('openconnector', entry.label) }))
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		selectedTiming() {
			return this.timingOptions.find((option) => option.id === this.draft?.timing) || this.timingOptions[0]
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		selectedAction() {
			return this.actionOptions.find((option) => option.id === this.draft?.action) || null
		},
		/**
		 * The Type select's value. A rule carrying one of the eight types the
		 * backend accepts but no UI offers (`audit_trail`, `flow`, …) gets a
		 * synthetic option so the select shows what is stored instead of
		 * reading as unset — and so leaving the field alone cannot silently
		 * rewrite it.
		 *
		 * @return {?object} The selected option.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		selectedType() {
			const current = this.draft?.type
			if (!current) return null
			return this.typeOptions.find((option) => option.id === current)
				|| { id: current, label: current }
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		isErrorType() {
			return this.draft?.type === 'error'
		},
		/**
		 * The error block's values, with the pre-manifest modal's defaults
		 * filling any gap so the fields never render empty.
		 *
		 * @return {object} Error configuration.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		errorConfig() {
			return { ...DEFAULT_ERROR_CONFIG, ...(this.draft?.configuration?.error || {}) }
		},
		/**
		 * @return {string} `order` as input text; empty when unset.
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		orderText() {
			return this.draft?.order != null ? String(this.draft.order) : ''
		},
		/**
		 * @return {string} `configuration.error.code` as input text.
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		errorCodeText() {
			const code = this.errorConfig.code
			return code != null ? String(code) : ''
		},
	},

	watch: {
		show: {
			immediate: true,
			/**
			 * Re-seed the draft every time the dialog opens, so a cancelled edit
			 * leaves nothing behind for the next one.
			 *
			 * @param {boolean} value Whether the dialog is now open.
			 *
			 * @spec openspec/specs/rule-editor-ui/spec.md
			 */
			handler(value) {
				if (value) this.seedDraft()
			},
		},
		/**
		 * Seed the raw-JSON textarea from the current condition tree whenever
		 * the editor is switched into raw mode, so the user starts from the
		 * conditions the visual builder was showing.
		 *
		 * @param {boolean} value True when raw JSON editing has just been enabled.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		rawConditions(value) {
			if (!value) return
			this.rawConditionsError = ''
			try {
				this.rawConditionsDraft = JSON.stringify(this.rootConditionGroup, null, 2)
			} catch (_e) {
				this.rawConditionsDraft = ''
			}
		},
	},

	methods: {
		/**
		 * Copy the row being edited into the local draft, normalising the
		 * conditions tree — persisted records carry it as an object, but rows
		 * written by the pre-manifest raw editor may hold a string or an array.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		seedDraft() {
			this.saveError = ''
			this.nameTouched = false
			this.rawConditions = false
			this.rawConditionsDraft = ''
			this.rawConditionsError = ''

			const base = emptyRuleDraft()
			if (this.item) {
				for (const key of Object.keys(base)) {
					if (this.item[key] !== undefined && this.item[key] !== null) {
						base[key] = this.item[key]
					}
				}
				base.conditions = normaliseConditions(this.item.conditions)
				base.configuration = { ...(this.item.configuration || {}) }
			}
			// Only an error-typed rule gets the error defaults materialised. A
			// rule of any other type must not gain a `configuration.error` bag
			// it never had just because it passed through this dialog.
			if (base.type === 'error') {
				base.configuration = {
					...base.configuration,
					error: { ...DEFAULT_ERROR_CONFIG, ...(base.configuration.error || {}) },
				}
			}

			this.draft = base
			this.originalSignature = JSON.stringify(base)
		},

		/**
		 * Replace one draft field. Assigning a fresh object keeps the dirty
		 * signature honest for the nested configuration bag.
		 *
		 * @param {string} key   Draft field name.
		 * @param {*}      value New value.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		updateDraft(key, value) {
			this.draft = { ...this.draft, [key]: value }
		},

		/**
		 * Switch the action type.
		 *
		 * The type is written to the **top-level** `type` property, because that
		 * is what `EndpointService::handleRuleProcessing()` dispatches on. It is
		 * mirrored into `configuration.type` for the one consumer that reads it
		 * there — `RuleService::processCustomRule()`, which sub-dispatches
		 * `type: 'custom'` rules — and because RuleActionConfig on the detail
		 * page reads the type from that key.
		 *
		 * Sibling per-type slots inside `configuration` are left in place, so
		 * toggling away from a type and back restores what was configured for
		 * it.
		 *
		 * @param {?object} option The picked type option; null when cleared,
		 *   which is ignored (the select is not clearable).
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onTypePick(option) {
			if (!option) return
			const configuration = { ...(this.draft.configuration || {}), type: option.id }
			// Materialise the error defaults on first arrival at the error type,
			// so the block below renders filled rather than blank.
			if (option.id === 'error') {
				configuration.error = { ...DEFAULT_ERROR_CONFIG, ...(configuration.error || {}) }
			}
			this.draft = { ...this.draft, type: option.id, configuration }
		},

		/**
		 * Replace one key inside `configuration.error` without dropping its
		 * siblings, or any sibling of `error` inside `configuration`.
		 *
		 * @param {string} key   Error configuration key.
		 * @param {*}      value New value.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		updateErrorField(key, value) {
			this.updateDraft('configuration', {
				...(this.draft.configuration || {}),
				error: { ...this.errorConfig, [key]: value },
			})
		},

		/**
		 * `order` is an integer on the schema, so an emptied field becomes null
		 * rather than the empty string a text input would hand over.
		 *
		 * @param {string} value Raw input value.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onOrderInput(value) {
			this.updateDraft('order', value === '' ? null : Number(value))
		},

		/**
		 * Same coercion for the HTTP status code. Not clamped to 100..999 here —
		 * the input carries min/max, and silently rewriting a typed number is
		 * more confusing than letting the field show what was typed.
		 *
		 * @param {string} value Raw input value.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onErrorCodeInput(value) {
			this.updateErrorField('code', value === '' ? null : Number(value))
		},

		/**
		 * Store a replacement condition tree emitted by the visual builder.
		 *
		 * @param {object} node The new root group node.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onConditionsUpdate(node) {
			this.updateDraft('conditions', node)
		},

		/**
		 * Handle each keystroke in the raw-JSON textarea: keep the text
		 * verbatim, and only commit to `draft.conditions` when it parses. Empty
		 * input resets to an empty AND group; a parse failure surfaces in
		 * `rawConditionsError`, blocks Save, and leaves the last valid tree in
		 * place.
		 *
		 * @param {string} value Raw textarea contents, expected to be JsonLogic JSON.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onRawConditionsInput(value) {
			this.rawConditionsDraft = value
			const trimmed = value.trim()
			if (trimmed.length === 0) {
				this.rawConditionsError = ''
				this.onConditionsUpdate(emptyRootGroup())
				return
			}
			try {
				const parsed = JSON.parse(trimmed)
				this.rawConditionsError = ''
				this.onConditionsUpdate(parsed)
			} catch (parseErr) {
				this.rawConditionsError = this.t('openconnector', 'Invalid JSON: {message}', { message: parseErr.message })
			}
		},

		/**
		 * Re-indent the raw editor from the committed condition tree. Disabled
		 * while the text does not parse, so it can never discard input the user
		 * has not finished typing.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		formatRawConditions() {
			try {
				this.rawConditionsDraft = JSON.stringify(JSON.parse(this.rawConditionsDraft), null, 2)
				this.rawConditionsError = ''
			} catch (parseErr) {
				this.rawConditionsError = this.t('openconnector', 'Invalid JSON: {message}', { message: parseErr.message })
			}
		},

		/** @spec openspec/specs/rule-editor-ui/spec.md */
		onCancel() {
			if (this.saving) return
			this.close?.()
		},

		/**
		 * Persist through the slot's `confirm` binding. The item's own keys are
		 * spread underneath the draft so server-managed fields the draft does
		 * not carry (`uuid`, `slug`, `created`, `version`, …) survive the round
		 * trip.
		 *
		 * @return {Promise<void>} Resolves once the save has settled.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		async onSave() {
			if (!this.canSave) return
			this.saving = true
			this.saveError = ''
			try {
				await this.confirm({
					...(this.item || {}),
					...this.draft,
					conditions: serializeRuleConditions(this.draft.conditions),
				})
				showSuccess(this.isCreate
					? this.t('openconnector', 'Rule created')
					: this.t('openconnector', 'Rule saved'))
				this.close?.()
			} catch (err) {
				this.saveError = err?.message
					|| this.t('openconnector', 'Failed to save rule')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
/*
 * NcModal sizes `large` at a fixed 900px. The condition builder needs more
 * than that: RuleConditionLeaf lays out field + operator + value on one row and
 * its operator select alone carries `min-width: 220px`, so nested groups start
 * clipping their placeholders well before 900px. `class` on NcDialog lands on
 * NcModal's `.modal-mask` root, which carries this component's scope id even
 * though NcModal teleports to <body>, so `:deep()` from here reaches the
 * container.
 *
 * Specificity matters: NcModal's own rule is
 * `.modal-wrapper--large > .modal-container[data-v-…]` at (0,2,0). Keeping
 * `.modal-wrapper` in the selector puts this at (0,4,0), so it wins outright
 * rather than tying and depending on stylesheet order.
 */
.cn-rule-editor-modal :deep(.modal-wrapper > .modal-container) {
	width: 1000px;
	max-width: 90%;
}

.cn-rule-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	width: 100%;
	margin-block-end: 0.5rem;
}

/* Metadata, not a stage — stacked and capped so the differing control heights
   of an input next to a textarea cannot leave a gap beside the shorter one. */
.cn-rule-editor__identity {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 480px;
}

.cn-rule-editor__section {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.cn-rule-editor__section-header {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast);
}

.cn-rule-editor__section-header h3 {
	margin: 0;
	font-size: 1rem;
	font-weight: 600;
	color: var(--color-main-text);
}

/* Push the header's trailing control (the raw-JSON toggle) to the far edge. */
.cn-rule-editor__section-header > :last-child:not(h3) {
	margin-inline-start: auto;
}

/*
 * The four dispatch fields are short and read as pairs (when it runs / what it
 * does), so they sit two-up. `minmax(0, 1fr)` rather than `1fr` keeps NcSelect's
 * min-width from forcing the track wider than its share.
 */
.cn-rule-editor__grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px;
}

.cn-rule-editor__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 0;
}

.cn-rule-editor__label {
	font-weight: 600;
	font-size: 0.9rem;
}

.cn-rule-editor__helper {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.cn-rule-editor__helper--error {
	color: var(--color-error-text, var(--color-error));
}

.cn-rule-editor__raw {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

/* The helper text carries the parse error, so it takes the space and the
   Format button stays at its intrinsic width on the trailing edge. */
.cn-rule-editor__raw-footer {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
}

.cn-rule-editor__textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	resize: vertical;
}

.cn-rule-editor__textarea--code {
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.9rem;
}

/* Below roughly 680px of usable dialog width the two-up grid leaves the
   selects too narrow to read their options — stack them. */
@media (max-width: 768px) {
	.cn-rule-editor__grid {
		grid-template-columns: minmax(0, 1fr);
	}
}
</style>

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  RuleDetailPage — bespoke `type: custom` page wrapping CnDetailPage to
  give Rules the visual condition + action builder the legacy
  EditRule modal never had.

  Why a full custom page (not just an index-page slot like JobFormFields)?
  CnIndexPage's CnFormDialog gives you per-field overrides, but Rules
  need three structurally-distinct surfaces on the same edit screen:
    1. Basic fields (name/description/action/timing/order)
    2. A recursive condition tree (this is what's actually NEW vs #828)
    3. An action picker + action-specific parameter form

  Trying to cram (2) and (3) into a dialog produced an editor that was
  visually-cramped + impossible to keep open while testing the resulting
  rule against incoming data. Promoting the rule edit surface to a
  full page mirrors what the legacy 1888-LoC modal effectively was,
  just decomposed into reusable Vue components.

  `modals/v2/RuleEditorModal.vue` is a second host for (1) and (2) on the
  Rules index, so a rule can be created complete instead of as a stub.
  It stops short of (3) — only `error` gets its parameters there — and the
  shared option lists, draft defaults and conditions round-trip live in
  `ruleDraft.js` so the two surfaces cannot drift.

  Data flow:
    - Mount → register `rule` object type → fetchObject(`rule`, id)
    - The fetched object is the source of truth; local `draft` is a
      shallow clone the user edits.
    - Save → saveObject(`rule`, draft) → re-set the draft from the
      server response (handles slug/uuid/updated normalisation).

  Tracked deferrals (filed as v2 follow-ups):
    - Drag-reorder children within a group
    - Rich parameter forms for fetch_file / write_file / save_object /
      extend_input / extend_external_input (currently fall through to
      raw JSON via RuleActionConfig)
    - Operator extensions in RuleConditionLeaf (some/all/regex)

  Closes #833.
-->
<template>
	<CnDetailPage
		:title="pageTitle"
		:description="
			t(
				'openconnector',
				'Configure when this rule fires and what it does. Conditions are evaluated as JSON Logic against the incoming request/data.',
			)
		"
		icon="icon-toggle"
		:loading="loading"
		:error="!!error"
		:errorMessage="errorMessage"
		:onRetry="onRetry"
		:empty="!loading && !error && !draft">
		<template #actions>
			<NcButton
				variant="secondary"
				:disabled="saving || !dirty"
				@click="resetEdits">
				<template #icon>
					<UndoIcon :size="20" />
				</template>
				{{ t('integriq', 'Discard') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving || !dirty" @click="onSave">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<ContentSave v-else :size="20" />
				</template>
				{{ saving ? t('integriq', 'Saving…') : t('integriq', 'Save') }}
			</NcButton>
		</template>

		<!-- Deprecation context, first in the body so it reads as page-level
		     framing rather than an error against the rule being edited.
		     flow-native-synchronization task 3.2: Rules become
		     trigger-object + switch/filter steps (task 3.3); the rule
		     evaluation service and this editor both stay. -->
		<AutomationDeprecationNotice />

		<!-- Basic fields -->
		<CnDetailCard :title="t('integriq', 'Basics')" icon="icon-info">
			<div class="rule-detail-page__grid">
				<NcTextField
					:label="t('integriq', 'Name') + ' *'"
					:modelValue="draft && draft.name ? String(draft.name) : ''"
					@update:modelValue="(value) => updateField('name', value)" />
				<!-- `action` is required on the rule schema, and until now this
				     page had no editor for it at all — a rule created here was
				     saved without the field the endpoint filters on. -->
				<div>
					<label class="rule-detail-page__label" for="rule-action">
						{{ t('integriq', 'Action') }} *
					</label>
					<NcSelect
						inputId="rule-action"
						:aria-label-combobox="t('integriq', 'Action')"
						:modelValue="selectedAction"
						:options="actionOptions"
						:clearable="false"
						:placeholder="t('integriq', 'Pick a request method')"
						@update:modelValue="
							(option) => updateField('action', option?.id || '')
						" />
				</div>
				<!-- `before`/`after` are the only two values
				     EndpointService::handleRuleProcessing() ever compares
				     against, so this is a closed list, not free text. -->
				<div>
					<label class="rule-detail-page__label" for="rule-timing">
						{{ t('integriq', 'Timing') }}
					</label>
					<NcSelect
						inputId="rule-timing"
						:aria-label-combobox="t('integriq', 'Timing')"
						:modelValue="selectedTiming"
						:options="timingOptions"
						:clearable="false"
						@update:modelValue="
							(option) => updateField('timing', option?.id || 'before')
						" />
				</div>
				<NcTextField
					:label="t('integriq', 'Order')"
					type="number"
					:modelValue="
						draft && draft.order != null ? String(draft.order) : ''
					"
					placeholder="0"
					@update:modelValue="
						(value) =>
							updateField('order', value === '' ? null : Number(value))
					" />
				<div class="rule-detail-page__grid-full">
					<label class="rule-detail-page__label" for="rule-description">
						{{ t('integriq', 'Description') }}
					</label>
					<textarea
						id="rule-description"
						class="rule-detail-page__textarea"
						:value="
							draft && draft.description
								? String(draft.description)
								: ''
						"
						rows="3"
						@input="updateField('description', $event.target.value)" />
				</div>
			</div>
		</CnDetailCard>

		<!-- Visual condition builder -->
		<CnDetailCard
			id="conditions-builder"
			:title="t('integriq', 'When (conditions)')"
			icon="icon-filter">
			<template #actions>
				<NcButton
					variant="tertiary"
					:aria-label="
						rawConditions
							? t('integriq', 'Switch back to visual builder')
							: t('integriq', 'Edit conditions as raw JSON')
					"
					@click="rawConditions = !rawConditions">
					<template #icon>
						<CodeJson :size="18" />
					</template>
					{{
						rawConditions
							? t('integriq', 'Visual builder')
							: t('integriq', 'Raw JSON')
					}}
				</NcButton>
			</template>
			<RuleConditionGroup
				v-if="!rawConditions"
				:node="rootConditionGroup"
				:removable="false"
				@update="onConditionsUpdate" />
			<div v-else class="rule-detail-page__raw-conditions">
				<label class="rule-detail-page__label" for="rule-raw-conditions">
					{{ t('integriq', 'Conditions (JSON Logic)') }}
				</label>
				<textarea
					id="rule-raw-conditions"
					class="rule-detail-page__textarea rule-detail-page__textarea--code"
					:value="rawConditionsDraft"
					spellcheck="false"
					rows="10"
					@input="onRawConditionsInput($event.target.value)" />
				<span
					class="rule-detail-page__helper"
					:class="{
						'rule-detail-page__helper--error': rawConditionsError,
					}">
					{{
						rawConditionsError
						|| t(
							'openconnector',
							'Edit the JSON Logic directly. Saved into the rule conditions field exactly as typed.',
						)
					}}
				</span>
			</div>
		</CnDetailCard>

		<!-- Action picker + per-action parameter form -->
		<CnDetailCard
			id="action-config"
			:title="t('integriq', 'Then (action)')"
			icon="icon-play">
			<RuleActionConfig
				:configuration="
					draft && draft.configuration ? draft.configuration : {}
				"
				:type="draft && draft.type ? String(draft.type) : ''"
				@update="onConfigurationUpdate"
				@update:type="onActionTypeUpdate" />
		</CnDetailCard>
	</CnDetailPage>
</template>

<script>
import { CnDetailCard, CnDetailPage } from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import CodeJson from 'vue-material-design-icons/CodeJson.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import UndoIcon from 'vue-material-design-icons/Undo.vue'
import AutomationDeprecationNotice from '../../components/AutomationDeprecationNotice.vue'
import RuleActionConfig from './RuleActionConfig.vue'
import RuleConditionGroup from './RuleConditionGroup.vue'
import liveObjectSubscription from '../../mixins/liveObjectSubscription.js'
import { useObjectStore } from '../../store/objectStore.js'
import {
	ACTION_OPTIONS,
	emptyRootGroup,
	normaliseConditions,
	TIMING_OPTIONS,
} from './ruleDraft.js'

const OBJECT_TYPE = 'rule'

export default {
	name: 'RuleDetailPage',

	components: {
		AutomationDeprecationNotice,
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		CnDetailCard,
		CnDetailPage,
		CodeJson,
		ContentSave,
		UndoIcon,
		RuleConditionGroup,
		RuleActionConfig,
	},

	mixins: [liveObjectSubscription],

	props: {
		/** Route param `:id` — the rule's UUID (forwarded by CnPageRenderer). */
		id: { type: [String, Number], default: '' },
		/** Manifest `config.register` — passed through resolvedProps. */
		register: { type: String, default: 'openconnector' },
		/** Manifest `config.schema` — passed through resolvedProps. */
		schema: { type: String, default: 'rule' },
	},

	/**
	 * Bind the shared object store to this page's register/schema pair before
	 * any lifecycle hook runs, so `fetchObject`/`saveObject` on OBJECT_TYPE
	 * resolve against the right OpenRegister collection.
	 *
	 * @param {object} props Resolved component props; only `register` and `schema`
	 *   are read here, both falling back to the Integriq rule defaults.
	 *
	 * @spec openspec/specs/rule-editor-ui/spec.md
	 */
	setup(props) {
		const objectStore = useObjectStore()
		if (typeof objectStore.registerObjectType === 'function') {
			objectStore.registerObjectType(
				OBJECT_TYPE,
				props.schema || 'rule',
				props.register || 'openconnector',
			)
		}
		return { objectStore }
	},

	data() {
		return {
			/** Local working copy of the rule object. Null until first fetch resolves. */
			draft: null,
			/** Snapshot of the last persisted version — used to compute `dirty`. */
			pristine: null,
			loading: false,
			saving: false,
			error: null,
			rawConditions: false,
			rawConditionsDraft: '',
			rawConditionsError: '',
		}
	},

	computed: {
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		pageTitle() {
			if (!this.draft) return this.t('integriq', 'Rule')
			return this.draft.name
				? `${this.t('integriq', 'Rule')}: ${this.draft.name}`
				: this.t('integriq', 'Rule')
		},

		/** @spec openspec/specs/rule-editor-ui/spec.md */
		errorMessage() {
			if (!this.error) return ''
			if (typeof this.error === 'string') return this.error
			return this.error.message || this.t('integriq', 'An error occurred')
		},

		/**
		 * The conditions JsonLogic node coerced into a top-level group
		 * shape (`{and:[...]}` or `{or:[...]}`). Legacy data may have
		 * stored conditions as a string (raw-editor text), an array,
		 * or a single leaf — all of those normalise so the visual
		 * builder always has a group to render.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		rootConditionGroup() {
			return normaliseConditions(this.draft?.conditions)
		},

		/** @spec exclude static option list — presentation only */
		actionOptions() {
			return ACTION_OPTIONS.map((entry) => ({
				id: entry.id,
				label: this.t('integriq', entry.label),
			}))
		},

		/** @spec exclude static option list — presentation only */
		timingOptions() {
			return TIMING_OPTIONS.map((entry) => ({
				id: entry.id,
				label: this.t('integriq', entry.label),
			}))
		},

		/** @spec openspec/specs/rule-editor-ui/spec.md */
		selectedAction() {
			return (
				this.actionOptions.find((option) => option.id === this.draft?.action)
				|| null
			)
		},

		/** @spec openspec/specs/rule-editor-ui/spec.md */
		selectedTiming() {
			return (
				this.timingOptions.find((option) => option.id === this.draft?.timing)
				|| this.timingOptions[0]
			)
		},

		/** @spec openspec/specs/rule-editor-ui/spec.md */
		dirty() {
			if (!this.draft || !this.pristine) return false
			try {
				return JSON.stringify(this.draft) !== JSON.stringify(this.pristine)
			} catch (_e) {
				return true
			}
		},
	},

	watch: {
		id: {
			immediate: true,
			/**
			 * Reload the rule whenever the routed `:id` changes. Runs
			 * immediately on mount as well (`immediate: true`).
			 *
			 * @param {string|number} value The rule UUID from the route param;
			 *   an empty value means there is nothing to load yet.
			 *
			 * @spec openspec/specs/rule-editor-ui/spec.md
			 */
			handler(value) {
				if (value) this.load()
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
			if (value) {
				try {
					this.rawConditionsDraft = JSON.stringify(
						this.rootConditionGroup,
						null,
						2,
					)
					this.rawConditionsError = ''
				} catch (_e) {
					this.rawConditionsDraft = ''
				}
			}
		},
	},

	methods: {
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		async load() {
			this.loading = true
			this.error = null
			try {
				const fetched = await this.objectStore.fetchObject(
					OBJECT_TYPE,
					this.id,
				)
				if (!fetched) {
					const storeError = this.objectStore.errors?.[OBJECT_TYPE]
					this.error =
						storeError || new Error(this.t('integriq', 'Rule not found'))
					return
				}
				this.draft = JSON.parse(JSON.stringify(fetched))
				this.pristine = JSON.parse(JSON.stringify(fetched))
				// Live updates: or-object-{uuid} events refetch this rule and
				// applyLiveObject (dirty-guarded) refreshes the working copy.
				this.syncLiveSubscription(OBJECT_TYPE, String(this.id))
			} catch (err) {
				this.error = err
			} finally {
				this.loading = false
			}
		},

		/**
		 * Live-update bridge (liveObjectSubscription mixin): apply a fresh
		 * server-side version of the rule to the local working copy — but
		 * NEVER over unsaved edits. When the draft is dirty the refetched
		 * object stays in the store cache and the user's edits win; the
		 * next save persists them (server-side versioning arbitrates).
		 *
		 * @param {object} fresh The refetched rule from the object store
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 */
		applyLiveObject(fresh) {
			if (this.dirty || this.saving) return
			this.draft = JSON.parse(JSON.stringify(fresh))
			this.pristine = JSON.parse(JSON.stringify(fresh))
		},

		/**
		 * Write a single top-level field onto the local working copy of the
		 * rule. No-op until the first fetch has populated `draft`.
		 *
		 * @param {string} key Name of the rule property to set — one of
		 *   `name`, `timing`, `order` or `description`.
		 * @param {*} value New value for that property as produced by the bound
		 *   form control (string for text fields, number or null for `order`).
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		updateField(key, value) {
			if (!this.draft) return
			this.draft[key] = value
		},

		/**
		 * Store a replacement condition tree on the draft rule.
		 *
		 * @param {object} node The root JsonLogic group node (`{and: [...]}` or
		 *   `{or: [...]}`) emitted by RuleConditionGroup or parsed from the raw
		 *   JSON editor.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onConditionsUpdate(node) {
			if (!this.draft) return
			this.draft.conditions = node
		},

		/**
		 * Store a replacement action configuration on the draft rule.
		 *
		 * @param {object} next The complete `configuration` blob emitted by
		 *   RuleActionConfig, keyed by action type.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onConfigurationUpdate(next) {
			if (!this.draft) return
			this.draft.configuration = next
		},

		/**
		 * Mirror the picked action type onto the rule's **top-level** `type`.
		 *
		 * `EndpointService::handleRuleProcessing()` dispatches on
		 * `$ruleData['type']`, and its `match` ends in
		 * `throw new Exception('Unsupported rule type: ')` — so a rule that
		 * carried the type only inside `configuration` (which is all this page
		 * used to write) was never executed. `configuration.type` still moves
		 * too, via `onConfigurationUpdate`: `RuleService::processCustomRule()`
		 * reads it to sub-dispatch `type: 'custom'` rules, and RuleActionConfig
		 * reads it to keep its own selection.
		 *
		 * @param {string} value The picked action type id.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onActionTypeUpdate(value) {
			if (!this.draft) return
			this.draft.type = value
		},

		/**
		 * Handle each keystroke in the raw-JSON conditions textarea: keep the
		 * textarea draft verbatim, and only commit to `draft.conditions` when
		 * the text parses. Empty input resets to an empty AND group; a parse
		 * failure surfaces in `rawConditionsError` and leaves the last valid
		 * conditions in place.
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
				this.rawConditionsError = this.t(
					'openconnector',
					'Invalid JSON: {message}',
					{ message: parseErr.message },
				)
			}
		},

		/** @spec openspec/specs/rule-editor-ui/spec.md */
		async onSave() {
			if (!this.draft || this.saving) return
			this.saving = true
			this.error = null
			try {
				const saved = await this.objectStore.saveObject(
					OBJECT_TYPE,
					this.draft,
				)
				if (!saved) {
					const storeError = this.objectStore.errors?.[OBJECT_TYPE]
					this.error =
						storeError || new Error(this.t('integriq', 'Saving failed'))
					return
				}
				this.draft = JSON.parse(JSON.stringify(saved))
				this.pristine = JSON.parse(JSON.stringify(saved))
			} catch (err) {
				this.error = err
			} finally {
				this.saving = false
			}
		},

		/**
		 * Throw away unsaved edits and restore the last persisted version.
		 * Named `resetEdits` to match FlowDetailPage/SynchronizationDetailPage,
		 * which expose the same action behind the same "Discard" label — this
		 * page used to call it "Cancel", which read like "leave the page".
		 *
		 * Guarded on `dirty` as well as disabled in the template: with nothing
		 * changed there is nothing to restore, and re-stringifying the
		 * conditions tree would only churn the raw-JSON textarea.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		resetEdits() {
			if (!this.pristine || !this.dirty || this.saving) return
			this.draft = JSON.parse(JSON.stringify(this.pristine))
			this.rawConditionsError = ''
			if (this.rawConditions) {
				try {
					this.rawConditionsDraft = JSON.stringify(
						this.rootConditionGroup,
						null,
						2,
					)
				} catch (_e) {
					/* noop */
				}
			}
		},

		/** @spec openspec/specs/rule-editor-ui/spec.md */
		onRetry() {
			this.load()
		},
	},
}
</script>

<style scoped>
.rule-detail-page__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 12px;
}

.rule-detail-page__grid-full {
	grid-column: 1 / -1;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.rule-detail-page__label {
	font-weight: bold;
}

.rule-detail-page__textarea {
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

.rule-detail-page__textarea--code {
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
}

.rule-detail-page__raw-conditions {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.rule-detail-page__helper {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.rule-detail-page__helper--error {
	color: var(--color-error);
}
</style>

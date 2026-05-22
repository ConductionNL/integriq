<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  RuleDetailPage — bespoke `type: custom` page wrapping CnDetailPage to
  give Rules the visual condition + action builder the legacy
  EditRule modal never had.

  Why a full custom page (not just an index-page slot like JobFormFields)?
  CnIndexPage's CnFormDialog gives you per-field overrides, but Rules
  need three structurally-distinct surfaces on the same edit screen:
    1. Basic fields (name/description/timing/order)
    2. A recursive condition tree (this is what's actually NEW vs #828)
    3. An action picker + action-specific parameter form

  Trying to cram (2) and (3) into a dialog produced an editor that was
  visually-cramped + impossible to keep open while testing the resulting
  rule against incoming data. Promoting the rule edit surface to a
  full page mirrors what the legacy 1888-LoC modal effectively was,
  just decomposed into reusable Vue components.

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
		:description="t('openconnector', 'Configure when this rule fires and what it does. Conditions are evaluated as JSON Logic against the incoming request/data.')"
		icon="icon-toggle"
		:loading="loading"
		:error="!!error"
		:error-message="errorMessage"
		:on-retry="onRetry"
		:empty="!loading && !error && !draft">
		<template #actions>
			<NcButton
				type="secondary"
				:disabled="saving"
				@click="onCancel">
				{{ t('openconnector', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="saving || !dirty"
				@click="onSave">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<ContentSave v-else :size="20" />
				</template>
				{{ saving ? t('openconnector', 'Saving…') : t('openconnector', 'Save') }}
			</NcButton>
		</template>

		<!-- Basic fields -->
		<CnDetailCard :title="t('openconnector', 'Basics')" icon="icon-info">
			<div class="rule-detail-page__grid">
				<NcTextField
					:label="t('openconnector', 'Name')"
					:value="draft && draft.name ? String(draft.name) : ''"
					@update:value="(value) => updateField('name', value)" />
				<NcTextField
					:label="t('openconnector', 'Timing')"
					:value="draft && draft.timing ? String(draft.timing) : ''"
					:placeholder="t('openconnector', 'e.g. before, after, on_error')"
					@update:value="(value) => updateField('timing', value)" />
				<NcTextField
					:label="t('openconnector', 'Order')"
					type="number"
					:value="draft && draft.order != null ? String(draft.order) : ''"
					:placeholder="'0'"
					@update:value="(value) => updateField('order', value === '' ? null : Number(value))" />
				<div class="rule-detail-page__grid-full">
					<label class="rule-detail-page__label" :for="'rule-description'">
						{{ t('openconnector', 'Description') }}
					</label>
					<textarea
						id="rule-description"
						class="rule-detail-page__textarea"
						:value="draft && draft.description ? String(draft.description) : ''"
						rows="3"
						@input="updateField('description', $event.target.value)" />
				</div>
			</div>
		</CnDetailCard>

		<!-- Visual condition builder -->
		<CnDetailCard
			id="conditions-builder"
			:title="t('openconnector', 'When (conditions)')"
			icon="icon-filter">
			<template #actions>
				<NcButton
					type="tertiary"
					:aria-label="rawConditions ? t('openconnector', 'Switch back to visual builder') : t('openconnector', 'Edit conditions as raw JSON')"
					@click="rawConditions = !rawConditions">
					<template #icon>
						<CodeJson :size="18" />
					</template>
					{{ rawConditions ? t('openconnector', 'Visual builder') : t('openconnector', 'Raw JSON') }}
				</NcButton>
			</template>
			<RuleConditionGroup
				v-if="!rawConditions"
				:node="rootConditionGroup"
				:removable="false"
				@update="onConditionsUpdate" />
			<div v-else class="rule-detail-page__raw-conditions">
				<textarea
					class="rule-detail-page__textarea rule-detail-page__textarea--code"
					:value="rawConditionsDraft"
					spellcheck="false"
					rows="10"
					@input="onRawConditionsInput($event.target.value)" />
				<span
					class="rule-detail-page__helper"
					:class="{ 'rule-detail-page__helper--error': rawConditionsError }">
					{{ rawConditionsError || t('openconnector', 'Edit the JSON Logic directly. Saved into the rule conditions field exactly as typed.') }}
				</span>
			</div>
		</CnDetailCard>

		<!-- Action picker + per-action parameter form -->
		<CnDetailCard
			id="action-config"
			:title="t('openconnector', 'Then (action)')"
			icon="icon-play">
			<RuleActionConfig
				:configuration="draft && draft.configuration ? draft.configuration : {}"
				@update="onConfigurationUpdate" />
		</CnDetailCard>
	</CnDetailPage>
</template>

<script>
import {
	NcButton,
	NcLoadingIcon,
	NcTextField,
} from '@nextcloud/vue'
import { CnDetailCard, CnDetailPage, useObjectStore } from '@conduction/nextcloud-vue'
import CodeJson from 'vue-material-design-icons/CodeJson.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import RuleConditionGroup from './RuleConditionGroup.vue'
import RuleActionConfig from './RuleActionConfig.vue'

const OBJECT_TYPE = 'rule'

/**
 * Default empty root-group used when a rule has no conditions yet (or
 * when the persisted value is not a recognisable group). Mirrors what
 * RuleConditionGroup itself falls back to, but centralised here so the
 * "raw JSON" editor and the visual builder both round-trip the same
 * default shape.
 */
const EMPTY_ROOT_GROUP = { and: [] }

export default {
	name: 'RuleDetailPage',

	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
		CnDetailCard,
		CnDetailPage,
		CodeJson,
		ContentSave,
		RuleConditionGroup,
		RuleActionConfig,
	},

	props: {
		/** Route param `:id` — the rule's UUID (forwarded by CnPageRenderer). */
		id: { type: [String, Number], default: '' },
		/** Manifest `config.register` — passed through resolvedProps. */
		register: { type: String, default: 'openconnector' },
		/** Manifest `config.schema` — passed through resolvedProps. */
		schema: { type: String, default: 'rule' },
	},

	setup(props) {
		const objectStore = useObjectStore()
		if (typeof objectStore.registerObjectType === 'function') {
			objectStore.registerObjectType(OBJECT_TYPE, props.schema || 'rule', props.register || 'openconnector')
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
		pageTitle() {
			if (!this.draft) return this.t('openconnector', 'Rule')
			return this.draft.name ? `${this.t('openconnector', 'Rule')}: ${this.draft.name}` : this.t('openconnector', 'Rule')
		},
		errorMessage() {
			if (!this.error) return ''
			if (typeof this.error === 'string') return this.error
			return this.error.message || this.t('openconnector', 'An error occurred')
		},
		/**
		 * The conditions JsonLogic node coerced into a top-level group
		 * shape (`{and:[...]}` or `{or:[...]}`). Legacy data may have
		 * stored conditions as a string (CodeMirror text), an array,
		 * or a single leaf — all of those normalise here so the visual
		 * builder always has a group to render.
		 */
		rootConditionGroup() {
			const raw = this.draft?.conditions
			return this.normaliseConditions(raw)
		},
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
			handler(value) {
				if (value) this.load()
			},
		},
		rawConditions(value) {
			if (value) {
				try {
					this.rawConditionsDraft = JSON.stringify(this.rootConditionGroup, null, 2)
					this.rawConditionsError = ''
				} catch (_e) {
					this.rawConditionsDraft = ''
				}
			}
		},
	},

	methods: {
		/**
		 * Normalise persisted `conditions` into a root group node.
		 * Accepts:
		 *   - null/undefined → empty AND
		 *   - JSON string (legacy CodeMirror text) → parse + recurse
		 *   - Array → wrap as `{and: [...]}`
		 *   - Leaf object → wrap as `{and: [<leaf>]}`
		 *   - Group object (and/or) → returned as-is
		 *
		 * Centralising here means the visual builder gets a sane root
		 * even from older rule rows that were saved via the legacy
		 * raw-JSON editor.
		 *
		 * @param {*} raw The persisted conditions value.
		 * @return {object} A JsonLogic group node.
		 */
		normaliseConditions(raw) {
			if (raw === null || raw === undefined || raw === '') {
				return { ...EMPTY_ROOT_GROUP }
			}
			if (typeof raw === 'string') {
				try { return this.normaliseConditions(JSON.parse(raw)) } catch (_e) { return { ...EMPTY_ROOT_GROUP } }
			}
			if (Array.isArray(raw)) {
				return { and: raw }
			}
			if (typeof raw === 'object') {
				const keys = Object.keys(raw)
				if (keys.length === 1 && (keys[0] === 'and' || keys[0] === 'or') && Array.isArray(raw[keys[0]])) {
					return raw
				}
				return { and: [raw] }
			}
			return { ...EMPTY_ROOT_GROUP }
		},

		async load() {
			this.loading = true
			this.error = null
			try {
				const fetched = await this.objectStore.fetchObject(OBJECT_TYPE, this.id)
				if (!fetched) {
					const storeError = this.objectStore.errors?.[OBJECT_TYPE]
					this.error = storeError || new Error(this.t('openconnector', 'Rule not found'))
					return
				}
				this.draft = JSON.parse(JSON.stringify(fetched))
				this.pristine = JSON.parse(JSON.stringify(fetched))
			} catch (err) {
				this.error = err
			} finally {
				this.loading = false
			}
		},

		updateField(key, value) {
			if (!this.draft) return
			this.$set(this.draft, key, value)
		},

		onConditionsUpdate(node) {
			if (!this.draft) return
			this.$set(this.draft, 'conditions', node)
		},

		onConfigurationUpdate(next) {
			if (!this.draft) return
			this.$set(this.draft, 'configuration', next)
		},

		onRawConditionsInput(value) {
			this.rawConditionsDraft = value
			const trimmed = value.trim()
			if (trimmed.length === 0) {
				this.rawConditionsError = ''
				this.onConditionsUpdate({ ...EMPTY_ROOT_GROUP })
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

		async onSave() {
			if (!this.draft || this.saving) return
			this.saving = true
			this.error = null
			try {
				const saved = await this.objectStore.saveObject(OBJECT_TYPE, this.draft)
				if (!saved) {
					const storeError = this.objectStore.errors?.[OBJECT_TYPE]
					this.error = storeError || new Error(this.t('openconnector', 'Saving failed'))
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

		onCancel() {
			if (!this.pristine) return
			this.draft = JSON.parse(JSON.stringify(this.pristine))
			this.rawConditionsError = ''
			if (this.rawConditions) {
				try {
					this.rawConditionsDraft = JSON.stringify(this.rootConditionGroup, null, 2)
				} catch (_e) { /* noop */ }
			}
		},

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

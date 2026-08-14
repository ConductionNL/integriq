<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  FlowDetailPage — bespoke detail page for one `flow` (visual-flow-orchestration
  REQ-009). Wraps `CnDetailPage` and renders:

    1. Flow metadata (name/description/isEnabled) — edited inline, no
       separate Edit modal. Matches the CURRENT RuleDetailPage /
       SynchronizationDetailPage / MappingDetailPage convention: those
       custom detail pages all edit their base fields inline via a
       draft/Save/Discard flow, not through a `src/modals/<Entity>/Edit*.vue`
       modal — the legacy modal pattern (EditEndpoint.vue, EditSynchronization.vue)
       is unwired dead code post chain-C (see src/handlers/actionHandlers.js's
       own docblock). This page follows the LIVE convention.
    2. An ordered step-list editor (`FlowStepRow` per step): add/remove/
       move-up/move-down controls only — no drag-and-drop, no canvas
       (REQ-009's explicit v1 constraint).
    3. Client-side validation on save (Task 18 / design.md's branch-target
       risk mitigation): duplicate `order` values or an unresolvable
       `branch` target (`nextStepOrder`/`defaultNextStepOrder`) blocks
       save with an inline error, rather than persisting a flow the
       runner would fail on at execution time.
    4. A "Run" header action (manual trigger, REQ-007d) calling
       `POST /api/flows/{id}/run` and surfacing the resulting status.
    5. A run-log section (`FlowRunLog`) listing past flow_run/flow_run_log
       records.

  Persistence: `useObjectStore` (same store CnDetailPage subscribes to),
  mirroring SynchronizationDetailPage's draft/original/dirty/save/reset
  pattern exactly.
-->
<template>
	<CnDetailPage
		:title="title"
		:description="description"
		icon="Sitemap"
		:loading="loading"
		:error="hasError"
		:errorMessage="errorMessage"
		:onRetry="hasError ? loadObject : null"
		:objectType="schemaSlug"
		:objectId="objectIdString"
		:sidebarProps="{ register: registerSlug, schema: schemaSlug }">
		<template #actions>
			<NcButton :disabled="running || !objectIdString" @click="runFlow">
				<template #icon>
					<NcLoadingIcon v-if="running" :size="20" />
					<PlayCircleOutline v-else :size="20" />
				</template>
				{{ t('openconnector', 'Run') }}
			</NcButton>
			<NcButton
				v-if="dirty"
				variant="primary"
				:disabled="saving || !canSave"
				@click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<ContentSaveOutline v-else :size="20" />
				</template>
				{{ t('openconnector', 'Save changes') }}
			</NcButton>
			<NcButton v-if="dirty" :disabled="saving" @click="resetEdits">
				<template #icon>
					<UndoIcon :size="20" />
				</template>
				{{ t('openconnector', 'Discard') }}
			</NcButton>
		</template>

		<div v-if="!loading && draft" class="flow-detail">
			<NcNoteCard v-if="lastRunStatus" :type="lastRunNoteType">
				{{
					t('openconnector', 'Last run status: {status}', {
						status: lastRunStatus,
					})
				}}
			</NcNoteCard>
			<NcNoteCard v-if="saveError" type="error">
				{{ saveError }}
			</NcNoteCard>
			<NcNoteCard v-if="validationErrors.length > 0" type="error">
				<ul>
					<li v-for="(err, i) in validationErrors" :key="i">
						{{ err }}
					</li>
				</ul>
			</NcNoteCard>

			<section class="flow-detail__card">
				<h3>{{ t('openconnector', 'General') }}</h3>
				<NcTextField
					:label="t('openconnector', 'Name') + '*'"
					:modelValue="draft.name"
					@update:modelValue="(value) => updateDraft('name', value)" />
				<NcTextArea
					v-model="draft.description"
					resize="vertical"
					:label="t('openconnector', 'Description')" />
				<NcCheckboxRadioSwitch v-model="draft.isEnabled">
					{{
						t(
							'openconnector',
							'Enabled (cron/endpoint/event triggers run this flow; a manual Run always works)',
						)
					}}
				</NcCheckboxRadioSwitch>
			</section>

			<section class="flow-detail__card">
				<h3>{{ t('openconnector', 'Steps') }}</h3>
				<p class="flow-detail__hint">
					{{
						t(
							'openconnector',
							'Steps execute in order (top to bottom). A branch step can jump to a specific step; every other step runs in sequence.',
						)
					}}
				</p>
				<FlowStepRow
					v-for="(step, index) in draft.steps"
					:key="step._key"
					:step="step"
					:stepOrders="stepOrders"
					:sourceOptions="sourceOptions"
					:mappingOptions="mappingOptions"
					:synchronizationOptions="synchronizationOptions"
					:configRefLoading="optionsLoading"
					:isFirst="index === 0"
					:isLast="index === draft.steps.length - 1"
					@update="(value) => updateStep(index, value)"
					@remove="removeStep(index)"
					@moveUp="moveStep(index, -1)"
					@moveDown="moveStep(index, 1)" />
				<NcButton variant="secondary" @click="addStep">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('openconnector', 'Add step') }}
				</NcButton>
			</section>

			<section v-if="objectIdString" class="flow-detail__card">
				<h3>{{ t('openconnector', 'Run history') }}</h3>
				<FlowRunLog :flowId="objectIdString" />
			</section>
		</div>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import UndoIcon from 'vue-material-design-icons/Undo.vue'
import FlowRunLog from './FlowRunLog.vue'
import FlowStepRow from './FlowStepRow.vue'
import liveObjectSubscription from '../../mixins/liveObjectSubscription.js'
import { useObjectStore } from '../../store/objectStore.js'

const SCHEMA_SLUG = 'flow'
const REGISTER_SLUG = 'openconnector'

/**
 * Default empty draft for the create case.
 *
 * @return {object} An empty flow draft.
 */
function emptyDraft() {
	return {
		name: '',
		description: '',
		isEnabled: true,
		steps: [],
	}
}

/**
 * Assign each step a stable client-side `_key` (for `v-for :key`) that is
 * NOT persisted — steps are addressed by `order`, not array position or a
 * synthetic key, per design.md Decision 1.
 *
 * @param {Array} steps Raw steps array.
 * @return {Array} Steps with a `_key` added, `order`/`onError` defaulted.
 */
function keyedSteps(steps) {
	let seq = 0
	return (Array.isArray(steps) ? steps : []).map((step) => ({
		order: 0,
		type: 'mapping',
		configRef: '',
		condition: null,
		onError: 'stop',
		config: {},
		branches: [],
		defaultNextStepOrder: null,
		...step,
		_key: 'step-' + seq++ + '-' + (step.order ?? seq),
	}))
}

/**
 * Strip the client-only `_key` field before persisting.
 *
 * @param {Array} steps Draft steps (with `_key`).
 * @return {Array} Steps ready for the wire (no `_key`).
 */
function serializeSteps(steps) {
	return steps.map(({ _key, ...rest }) => rest)
}

export default {
	name: 'FlowDetailPage',

	components: {
		CnDetailPage,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcTextArea,
		NcTextField,
		ContentSaveOutline,
		PlayCircleOutline,
		Plus,
		UndoIcon,
		FlowStepRow,
		FlowRunLog,
	},

	mixins: [liveObjectSubscription],

	props: {
		id: { type: [String, Number], default: '' },
		register: { type: String, default: REGISTER_SLUG },
		schema: { type: String, default: SCHEMA_SLUG },
	},

	/**
	 * Binds the shared object store and registers the `flow` schema against it,
	 * so this page and `CnDetailPage` read the same persistence surface.
	 *
	 * @param {object} props The component props (register/schema slugs).
	 * @return {object} The store exposed to the options API.
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
	 */
	setup(props) {
		const objectStore = useObjectStore()
		if (typeof objectStore.registerObjectType === 'function') {
			objectStore.registerObjectType(
				SCHEMA_SLUG,
				props.schema || SCHEMA_SLUG,
				props.register || REGISTER_SLUG,
			)
		}
		return { objectStore }
	},

	data() {
		return {
			loading: false,
			saving: false,
			running: false,
			saveError: '',
			loadError: '',
			draft: null,
			original: null,
			sourceOptions: [],
			mappingOptions: [],
			synchronizationOptions: [],
			optionsLoading: false,
			lastRunStatus: '',
		}
	},

	computed: {
		/**
		 * The routed object id as a string — `''` when this is a create form.
		 *
		 * @return {string}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		objectIdString() {
			return this.id != null ? String(this.id) : ''
		},

		/**
		 * The register this page reads and writes `flow` objects in.
		 *
		 * @return {string}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		registerSlug() {
			return this.register || REGISTER_SLUG
		},

		/**
		 * The schema this page reads and writes.
		 *
		 * @return {string}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		schemaSlug() {
			return this.schema || SCHEMA_SLUG
		},

		/**
		 * The detail page heading — the in-flight draft name while editing, so
		 * a rename is visible before it is saved.
		 *
		 * @return {string}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		title() {
			if (this.draft?.name) return this.draft.name
			return this.original?.name || t('openconnector', 'Flow')
		},

		/**
		 * The persisted description, shown as page subtitle.
		 *
		 * @return {string}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		description() {
			return this.original?.description || ''
		},

		hasError() {
			return Boolean(this.loadError) && !this.draft
		},

		/**
		 * The load-failure message shown in place of the editor.
		 *
		 * @return {string}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		errorMessage() {
			return this.loadError || t('openconnector', 'Failed to load flow')
		},

		/**
		 * Every step's `order` value, passed to each row so move-up/move-down
		 * can compute its neighbours.
		 *
		 * @return {number[]}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		stepOrders() {
			return (this.draft?.steps || []).map((step) => step.order)
		},

		/**
		 * Save is blocked while the flow is unnamed or fails branch/order
		 * validation — REQ-009's rule that a flow the runner would fail on at
		 * execution time must not be persisted.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		canSave() {
			return (
				Boolean(this.draft?.name && this.draft.name.trim().length > 0)
				&& this.validationErrors.length === 0
			)
		},

		/**
		 * Whether the draft differs from the loaded object, comparing serialized
		 * steps so key-only differences do not read as edits.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		dirty() {
			if (!this.draft || !this.original) return false
			const originalNormalized = this.normalizeForDiff(this.original)
			return (
				JSON.stringify(serializeSteps(this.draft.steps))
					!== JSON.stringify(serializeSteps(originalNormalized.steps))
				|| this.draft.name !== originalNormalized.name
				|| this.draft.description !== originalNormalized.description
				|| this.draft.isEnabled !== originalNormalized.isEnabled
			)
		},

		/**
		 * Maps the most recent run's terminal status onto the note styling —
		 * `failed`/`stopped`/`dead_letter` are errors and `suspended` (an
		 * approval step awaiting a decision) is a warning, not a failure.
		 *
		 * @return {string}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flow-runs-are-persisted-with-a-per-step-trace-req-008
		 */
		lastRunNoteType() {
			if (
				this.lastRunStatus === 'failed'
				|| this.lastRunStatus === 'stopped'
				|| this.lastRunStatus === 'dead_letter'
			)
				return 'error'
			if (this.lastRunStatus === 'suspended') return 'warning'
			return 'success'
		},

		/**
		 * Task 18 / design.md branch-target risk mitigation: block save when
		 * step `order` values collide, or a `branch` step's `nextStepOrder`/
		 * `defaultNextStepOrder` does not resolve to an existing step.
		 *
		 * @return {Array<string>} Human-readable validation errors; empty when valid.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-branch-step-selects-the-next-step-via-jsonlogic-req-004
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		validationErrors() {
			if (!this.draft) return []
			const errors = []
			const orders = this.draft.steps.map((step) => step.order)
			const seen = new Set()
			for (const order of orders) {
				if (seen.has(order)) {
					errors.push(
						t(
							'openconnector',
							"Two or more steps share order #{order} — each step's order must be unique.",
							{ order },
						),
					)
				}
				seen.add(order)
			}

			const orderSet = new Set(orders)
			for (const step of this.draft.steps) {
				if (step.type !== 'branch') continue
				for (const branch of step.branches || []) {
					if (
						branch.nextStepOrder !== null
						&& branch.nextStepOrder !== undefined
						&& !orderSet.has(branch.nextStepOrder)
					) {
						errors.push(
							t(
								'openconnector',
								'Branch step #{order} targets step #{target}, which does not exist.',
								{ order: step.order, target: branch.nextStepOrder },
							),
						)
					}
				}
				if (
					step.defaultNextStepOrder !== null
					&& step.defaultNextStepOrder !== undefined
					&& !orderSet.has(step.defaultNextStepOrder)
				) {
					errors.push(
						t(
							'openconnector',
							"Branch step #{order}'s default target #{target} does not exist.",
							{ order: step.order, target: step.defaultNextStepOrder },
						),
					)
				}
			}

			return errors
		},
	},

	watch: {
		id: {
			immediate: true,
			/**
			 * Reloads when the routed id changes, so navigating between two
			 * flows does not leave the previous flow's draft on screen.
			 *
			 * @return {void}
			 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
			 */
			handler() {
				this.loadObject()
			},
		},
	},

	mounted() {
		this.fetchPickerOptions()
	},

	methods: {
		/**
		 * Loads the routed `flow` into `original` and seeds the editable draft,
		 * or produces an empty draft when this is a create form.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		async loadObject() {
			if (!this.objectIdString) {
				this.draft = emptyDraft()
				this.original = emptyDraft()
				return
			}
			this.loading = true
			this.loadError = ''
			try {
				const data = await this.objectStore.fetchObject(
					this.schemaSlug,
					this.objectIdString,
				)
				if (!data) {
					this.loadError =
						this.objectStore.errors?.[this.schemaSlug]
						|| t('openconnector', 'Failed to load flow')
					this.draft = null
					this.original = null
					return
				}
				this.original = data
				this.draft = this.normalizeForDiff(data)
				this.syncLiveSubscription(this.schemaSlug, this.objectIdString)
			} catch (err) {
				this.loadError =
					err?.message || t('openconnector', 'Failed to load flow')
				this.draft = null
				this.original = null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Accepts a live-subscription push, but never over an in-flight edit —
		 * overwriting a dirty draft would discard the admin's unsaved steps.
		 *
		 * @param {object} fresh The pushed `flow` object.
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		applyLiveObject(fresh) {
			if (this.dirty || this.saving) return
			this.original = fresh
			this.draft = this.normalizeForDiff(fresh)
		},

		/**
		 * Projects a persisted `flow` onto the draft shape so `dirty` compares
		 * like with like — without this, defaults absent from the stored object
		 * would read as edits the moment the page loaded.
		 *
		 * @param {object} obj The persisted `flow` object.
		 * @return {object} The normalized draft.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		normalizeForDiff(obj) {
			const base = emptyDraft()
			return {
				name: obj?.name ?? base.name,
				description: obj?.description ?? base.description,
				isEnabled: obj?.isEnabled !== false,
				steps: keyedSteps(obj?.steps),
			}
		},

		/**
		 * Loads the Source / Mapping / Synchronization option lists that back
		 * the per-step `configRef` picker. REQ-009's scenario requires the
		 * picker to be scoped to the step's own entity type, which is only
		 * possible because these three lists are fetched separately rather than
		 * as one pool.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		async fetchPickerOptions() {
			this.optionsLoading = true
			try {
				// `_limit`, NOT `limit`. OpenRegister's object API treats every
				// UNPREFIXED query parameter as a PROPERTY FILTER, so `limit=500`
				// asks for objects whose `limit` property equals 500 — of which
				// there are none. It answers HTTP 200 with `total: 0`, which is
				// indistinguishable from an empty register, and every picker on
				// this page rendered "No results" against 20 real mappings and
				// 23 real sources. OpenRegister even returns the diagnosis in the
				// response envelope and nothing was reading it:
				//   "@self": { "ignoredFilters": ["limit"], "hint": "Query
				//     returned 0 results because limit was treated as a property
				//     filter. Did you mean _limit? Control params require
				//     underscore prefix." }
				const [sources, mappings, synchronizations] = await Promise.all([
					axios.get(
						generateUrl(
							'/apps/openregister/api/objects/openconnector/source',
						),
						{ params: { _limit: 500 } },
					),
					axios.get(
						generateUrl(
							'/apps/openregister/api/objects/openconnector/mapping',
						),
						{ params: { _limit: 500 } },
					),
					axios.get(
						generateUrl(
							'/apps/openregister/api/objects/openconnector/synchronization',
						),
						{ params: { _limit: 500 } },
					),
				])
				this.sourceOptions = this.toOptions(sources.data)
				this.mappingOptions = this.toOptions(mappings.data)
				this.synchronizationOptions = this.toOptions(synchronizations.data)
			} catch (err) {
				// eslint-disable-next-line no-console
				console.warn('[FlowDetailPage] picker option fetch failed', err)
			} finally {
				this.optionsLoading = false
			}
		},

		/**
		 * Normalizes an OpenRegister list response — which arrives as an
		 * envelope, not a bare array — into `{id, label}` picker options.
		 *
		 * @param {object|Array} data The OR list response.
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		toOptions(data) {
			const list = Array.isArray(data?.results)
				? data.results
				: Array.isArray(data)
					? data
					: []
			return list.map((row) => ({
				id: String(row.id || row.uuid),
				label: row.name || row.title || row.id,
			}))
		},

		/**
		 * Writes one metadata field (name/description/isEnabled) into the
		 * draft — the inline edit flow this page uses instead of an Edit modal.
		 *
		 * @param {string} key   The draft field to set.
		 * @param {*}      value The new value.
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		updateDraft(key, value) {
			if (!this.draft) return
			this.draft[key] = value
		},

		/**
		 * The `order` for a newly added step. Steps are spaced by 10 so a step
		 * can later be moved between two neighbours without renumbering every
		 * `branches[].nextStepOrder` that references them by value.
		 *
		 * @return {number}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		nextOrder() {
			const orders = this.stepOrders
			return orders.length === 0 ? 10 : Math.max(...orders) + 10
		},

		/**
		 * Appends a step — REQ-009's "add" control.
		 *
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		addStep() {
			const steps = [
				...this.draft.steps,
				...keyedSteps([
					{ order: this.nextOrder(), type: 'mapping', onError: 'stop' },
				]),
			]
			this.draft.steps = steps
		},

		/**
		 * Merges one row's emitted changes into the draft, replacing the array
		 * rather than mutating in place so the diff against `original` is seen.
		 *
		 * @param {number} index The step's array index.
		 * @param {object} value The partial step update.
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		updateStep(index, value) {
			const steps = [...this.draft.steps]
			steps[index] = { ...steps[index], ...value }
			this.draft.steps = steps
		},

		/**
		 * Removes a step — REQ-009's "remove" control. Existing `order` values
		 * are deliberately left untouched, because branch targets reference
		 * them by value and renumbering would silently repoint them.
		 *
		 * @param {number} index The step's array index.
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		removeStep(index) {
			const steps = this.draft.steps.filter((_, i) => i !== index)
			this.draft.steps = steps
		},

		/**
		 * Move a step up/down by swapping its `order` value with its
		 * neighbour's — REQ-009's "reordering uses move controls" scenario:
		 * "the second step's order value is swapped with the first step's
		 * order value", not an array splice. The array is then re-sorted by
		 * `order` so the visual row order always matches execution order.
		 *
		 * @param {number} index    The step's current array index.
		 * @param {number} direction -1 for up, +1 for down.
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		moveStep(index, direction) {
			const neighbourIndex = index + direction
			if (neighbourIndex < 0 || neighbourIndex >= this.draft.steps.length)
				return
			const steps = [...this.draft.steps]
			const currentOrder = steps[index].order
			const neighbourOrder = steps[neighbourIndex].order
			steps[index] = { ...steps[index], order: neighbourOrder }
			steps[neighbourIndex] = { ...steps[neighbourIndex], order: currentOrder }
			steps.sort((a, b) => a.order - b.order)
			this.draft.steps = steps
		},

		/**
		 * The manual trigger — REQ-007's fourth entry point, alongside cron,
		 * endpoint rule and event. A non-2xx or a terminal failure status is
		 * surfaced to the admin rather than reported as a successful trigger.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-flow-runs-via-cron-endpoint-rule-event-or-manual-trigger-req-007
		 */
		async runFlow() {
			if (!this.objectIdString || this.running) return
			this.running = true
			try {
				const response = await axios.post(
					generateUrl(
						`/apps/openconnector/api/flows/${this.objectIdString}/run`,
					),
				)
				const status = response.data?.status || 'completed'
				this.lastRunStatus = status
				if (
					status === 'failed'
					|| status === 'stopped'
					|| status === 'dead_letter'
				) {
					showError(
						t('openconnector', 'Flow run ended with status: {status}', {
							status,
						}),
					)
				} else {
					showSuccess(t('openconnector', 'Flow run triggered'))
				}
			} catch (err) {
				showError(
					err?.response?.data?.error
						|| err?.message
						|| t('openconnector', 'Failed to run flow'),
				)
			} finally {
				this.running = false
			}
		},

		/**
		 * Persists the draft. Re-checks `canSave` rather than trusting the
		 * button's disabled state, so a flow that fails branch/order validation
		 * cannot be stored by any other path.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		async save() {
			if (!this.draft || this.saving || !this.canSave) return
			this.saving = true
			this.saveError = ''
			try {
				const payload = {
					...this.original,
					name: this.draft.name,
					description: this.draft.description,
					isEnabled: this.draft.isEnabled,
					steps: serializeSteps(this.draft.steps),
				}
				const saved = await this.objectStore.saveObject(
					this.schemaSlug,
					payload,
				)
				if (!saved) {
					this.saveError =
						this.objectStore.errors?.[this.schemaSlug]
						|| t('openconnector', 'Save failed')
					return
				}
				this.original = saved
				this.draft = this.normalizeForDiff(saved)
			} catch (err) {
				this.saveError = err?.message || t('openconnector', 'Save failed')
			} finally {
				this.saving = false
			}
		},

		/**
		 * Discards the draft back to the last persisted state — the "Discard"
		 * half of the draft/Save/Discard flow this page shares with the other
		 * custom detail pages.
		 *
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		resetEdits() {
			if (!this.original) return
			this.draft = this.normalizeForDiff(this.original)
			this.saveError = ''
		},
	},
}
</script>

<style scoped>
.flow-detail {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;
}

.flow-detail__card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
}

.flow-detail__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-bottom: 8px;
}
</style>

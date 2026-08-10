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
		:error-message="errorMessage"
		:on-retry="hasError ? loadObject : null"
		:object-type="schemaSlug"
		:object-id="objectIdString"
		:sidebar-props="{ register: registerSlug, schema: schemaSlug }">
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
				type="primary"
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
				{{ t('openconnector', 'Last run status: {status}', { status: lastRunStatus }) }}
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
				<NcTextField :label="t('openconnector', 'Name') + '*'" :model-value="draft.name" @update:model-value="(value) => updateDraft('name', value)" />
				<NcTextArea v-model="draft.description" resize="vertical" :label="t('openconnector', 'Description')" />
				<NcCheckboxRadioSwitch v-model="draft.isEnabled">
					{{ t('openconnector', 'Enabled (cron/endpoint/event triggers run this flow; a manual Run always works)') }}
				</NcCheckboxRadioSwitch>
			</section>

			<section class="flow-detail__card">
				<h3>{{ t('openconnector', 'Steps') }}</h3>
				<p class="flow-detail__hint">
					{{ t('openconnector', 'Steps execute in order (top to bottom). A branch step can jump to a specific step; every other step runs in sequence.') }}
				</p>
				<FlowStepRow
					v-for="(step, index) in draft.steps"
					:key="step._key"
					:step="step"
					:step-orders="stepOrders"
					:source-options="sourceOptions"
					:mapping-options="mappingOptions"
					:synchronization-options="synchronizationOptions"
					:config-ref-loading="optionsLoading"
					:is-first="index === 0"
					:is-last="index === draft.steps.length - 1"
					@update="(value) => updateStep(index, value)"
					@remove="removeStep(index)"
					@move-up="moveStep(index, -1)"
					@move-down="moveStep(index, 1)" />
				<NcButton type="secondary" @click="addStep">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('openconnector', 'Add step') }}
				</NcButton>
			</section>

			<section v-if="objectIdString" class="flow-detail__card">
				<h3>{{ t('openconnector', 'Run history') }}</h3>
				<FlowRunLog :flow-id="objectIdString" />
			</section>
		</div>
	</CnDetailPage>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import {
	CnDetailPage,
} from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import UndoIcon from 'vue-material-design-icons/Undo.vue'

import { useObjectStore } from '../../store/objectStore.js'
import liveObjectSubscription from '../../mixins/liveObjectSubscription.js'
import FlowStepRow from './FlowStepRow.vue'
import FlowRunLog from './FlowRunLog.vue'

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
		_key: 'step-' + (seq++) + '-' + (step.order ?? seq),
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
	 * Register the flow schema with the shared object store so this page reads
	 * and writes the same objects the index does.
	 *
	 * @param {object} props The component props.
	 * @return {object} The store exposed to the options API.
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-the-flow-detail-page-is-a-draft-editor-not-a-live-one-req-010
	 */
	setup(props) {
		const objectStore = useObjectStore()
		if (typeof objectStore.registerObjectType === 'function') {
			objectStore.registerObjectType(SCHEMA_SLUG, props.schema || SCHEMA_SLUG, props.register || REGISTER_SLUG)
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
		 * The routed flow id as a string, or '' for the create route.
		 *
		 * @return {string} The flow id.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-the-flow-detail-page-is-a-draft-editor-not-a-live-one-req-010
		 */
		objectIdString() {
			return this.id != null ? String(this.id) : ''
		},
		/**
		 * Register this page reads and writes through, defaulting to openconnector.
		 *
		 * @return {string} The register slug.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-the-flow-detail-page-is-a-draft-editor-not-a-live-one-req-010
		 */
		registerSlug() {
			return this.register || REGISTER_SLUG
		},
		/**
		 * Schema this page reads and writes, defaulting to flow.
		 *
		 * @return {string} The schema slug.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-the-flow-detail-page-is-a-draft-editor-not-a-live-one-req-010
		 */
		schemaSlug() {
			return this.schema || SCHEMA_SLUG
		},
		/**
		 * Page title: the DRAFT's name while editing, so a rename is visible
		 * before it is saved, falling back to what was loaded.
		 *
		 * @return {string} The heading.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		title() {
			if (this.draft?.name) return this.draft.name
			return this.original?.name || t('openconnector', 'Flow')
		},
		/**
		 * Page subtitle, taken from the LOADED flow — a description edit is a
		 * draft change, not a heading change.
		 *
		 * @return {string} The description.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		description() {
			return this.original?.description || ''
		},
		/**
		 * True only when the load failed AND there is nothing to edit — a load
		 * error with a draft in hand is a stale-data warning, not an error page.
		 *
		 * @return {boolean} Whether to render the error state.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-the-flow-detail-page-is-a-draft-editor-not-a-live-one-req-010
		 */
		hasError() {
			return Boolean(this.loadError) && !this.draft
		},
		/**
		 * What the error state says.
		 *
		 * @return {string} The message.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-the-flow-detail-page-is-a-draft-editor-not-a-live-one-req-010
		 */
		errorMessage() {
			return this.loadError || t('openconnector', 'Failed to load flow')
		},
		/**
		 * Every step order currently in the draft — the option set a branch
		 * target is picked from, so a target can never name a missing step.
		 *
		 * @return {Array<number>} The orders.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-the-step-row-authors-the-same-conditions-the-engine-evaluates-req-013
		 */
		stepOrders() {
			return (this.draft?.steps || []).map((step) => step.order)
		},
		/**
		 * Save is offered only for a named, valid flow.
		 *
		 * @return {boolean} Whether Save is enabled.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-flow-is-validated-before-it-can-be-saved-req-011
		 */
		canSave() {
			return Boolean(this.draft?.name && this.draft.name.trim().length > 0) && this.validationErrors.length === 0
		},
		/**
		 * Whether the draft differs from what was loaded.
		 *
		 * Compared against a NORMALISED copy of the loaded flow: the server
		 * materialises defaults and re-serialises steps, and without that
		 * normalisation a freshly-loaded flow reads as edited — an indicator
		 * that is always on is an indicator nobody reads.
		 *
		 * @return {boolean} Whether there are unsaved changes.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-the-flow-detail-page-is-a-draft-editor-not-a-live-one-req-010
		 */
		dirty() {
			if (!this.draft || !this.original) return false
			const originalNormalized = this.normalizeForDiff(this.original)
			return JSON.stringify(serializeSteps(this.draft.steps)) !== JSON.stringify(serializeSteps(originalNormalized.steps))
				|| this.draft.name !== originalNormalized.name
				|| this.draft.description !== originalNormalized.description
				|| this.draft.isEnabled !== originalNormalized.isEnabled
		},
		/**
		 * How the last run is presented. `suspended` is a WARNING, not an
		 * error: that run is waiting for an approval (REQ-005), and showing it
		 * as a failure sends an operator to debug a flow that is working.
		 *
		 * @return {string} An NcNoteCard type.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-flow-can-be-run-from-its-detail-page-and-its-last-run-is-visible-req-012
		 */
		lastRunNoteType() {
			if (this.lastRunStatus === 'failed' || this.lastRunStatus === 'stopped' || this.lastRunStatus === 'dead_letter') return 'error'
			if (this.lastRunStatus === 'suspended') return 'warning'
			return 'success'
		},
		/**
		 * Task 18 / design.md branch-target risk mitigation: block save when
		 * step `order` values collide, or a `branch` step's `nextStepOrder`/
		 * `defaultNextStepOrder` does not resolve to an existing step.
		 *
		 * @return {Array<string>} Human-readable validation errors; empty when valid.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-flow-is-validated-before-it-can-be-saved-req-011
		 */
		validationErrors() {
			if (!this.draft) return []
			const errors = []
			const orders = this.draft.steps.map((step) => step.order)
			const seen = new Set()
			for (const order of orders) {
				if (seen.has(order)) {
					errors.push(t('openconnector', 'Two or more steps share order #{order} — each step\'s order must be unique.', { order }))
				}
				seen.add(order)
			}

			const orderSet = new Set(orders)
			for (const step of this.draft.steps) {
				if (step.type !== 'branch') continue
				for (const branch of (step.branches || [])) {
					if (branch.nextStepOrder !== null && branch.nextStepOrder !== undefined && !orderSet.has(branch.nextStepOrder)) {
						errors.push(t('openconnector', 'Branch step #{order} targets step #{target}, which does not exist.', { order: step.order, target: branch.nextStepOrder }))
					}
				}
				if (step.defaultNextStepOrder !== null && step.defaultNextStepOrder !== undefined && !orderSet.has(step.defaultNextStepOrder)) {
					errors.push(t('openconnector', 'Branch step #{order}\'s default target #{target} does not exist.', { order: step.order, target: step.defaultNextStepOrder }))
				}
			}

			return errors
		},
	},

	watch: {
		id: {
			immediate: true,
			/**
			 * Reload when the route names a different flow. Without this the
			 * editor keeps flow A's draft while the route says flow B, and the
			 * next save PUTs A's steps over B.
			 *
			 * @return {void}
			 * @spec openspec/specs/flow-orchestration/spec.md#requirement-the-flow-detail-page-is-a-draft-editor-not-a-live-one-req-010
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
		 * Load the routed flow into `original` and a normalised `draft`. The
		 * create route ('' id) starts from an empty draft rather than fetching.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-the-flow-detail-page-is-a-draft-editor-not-a-live-one-req-010
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
				const data = await this.objectStore.fetchObject(this.schemaSlug, this.objectIdString)
				if (!data) {
					this.loadError = this.objectStore.errors?.[this.schemaSlug] || t('openconnector', 'Failed to load flow')
					this.draft = null
					this.original = null
					return
				}
				this.original = data
				this.draft = this.normalizeForDiff(data)
				this.syncLiveSubscription(this.schemaSlug, this.objectIdString)
			} catch (err) {
				this.loadError = err?.message || t('openconnector', 'Failed to load flow')
				this.draft = null
				this.original = null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Adopt a live update from the store — but NEVER over an unsaved edit
		 * or a save in flight. A push that overwrites the operator's draft
		 * loses work they cannot get back.
		 *
		 * @param {object} fresh The updated flow.
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-the-flow-detail-page-is-a-draft-editor-not-a-live-one-req-010
		 */
		applyLiveObject(fresh) {
			if (this.dirty || this.saving) return
			this.original = fresh
			this.draft = this.normalizeForDiff(fresh)
		},

		/**
		 * The normalised shape both sides of the dirty comparison are held in,
		 * so a server-materialised default is not read as an operator edit.
		 *
		 * @param {object} obj A flow as loaded.
		 * @return {object} The normalised draft shape.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-the-flow-detail-page-is-a-draft-editor-not-a-live-one-req-010
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
		 * Load the entities a step's `configRef` can point at, so the picker
		 * offers real Sources / Mappings / Synchronizations rather than a
		 * free-text id (REQ-009's typed config picker).
		 *
		 * A failure here is logged and swallowed: an unreachable option list
		 * must not stop an operator editing the rest of the flow.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		async fetchPickerOptions() {
			this.optionsLoading = true
			try {
				const [sources, mappings, synchronizations] = await Promise.all([
					axios.get(generateUrl('/apps/openregister/api/objects/openconnector/source'), { params: { limit: 500 } }),
					axios.get(generateUrl('/apps/openregister/api/objects/openconnector/mapping'), { params: { limit: 500 } }),
					axios.get(generateUrl('/apps/openregister/api/objects/openconnector/synchronization'), { params: { limit: 500 } }),
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
		 * Flatten an OR list response — enveloped or bare — into picker
		 * options.
		 *
		 * @param {object|Array} data The response body.
		 * @return {Array<{id: string, label: string}>} The options.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		toOptions(data) {
			const list = Array.isArray(data?.results) ? data.results : (Array.isArray(data) ? data : [])
			return list.map((row) => ({
				id: String(row.id || row.uuid),
				label: row.name || row.title || row.id,
			}))
		},

		/**
		 * Write one top-level field into the draft. Never writes through to
		 * the server — that is Save's job.
		 *
		 * @param {string} key   The draft field.
		 * @param {*}      value The new value.
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-the-flow-detail-page-is-a-draft-editor-not-a-live-one-req-010
		 */
		updateDraft(key, value) {
			if (!this.draft) return
			this.draft[key] = value
		},

		/**
		 * The order a new step takes: ten past the highest in use, leaving room
		 * to insert between existing steps without renumbering — and without
		 * invalidating the branch targets that reference `order` BY VALUE.
		 *
		 * @return {number} The next order.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		nextOrder() {
			const orders = this.stepOrders
			return orders.length === 0 ? 10 : Math.max(...orders) + 10
		},

		/**
		 * Append a step, defaulting to a mapping step that stops on error.
		 *
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		addStep() {
			const steps = [...this.draft.steps, ...keyedSteps([{ order: this.nextOrder(), type: 'mapping', onError: 'stop' }])]
			this.draft.steps = steps
		},

		/**
		 * Merge a step row's change into the draft, replacing the array rather
		 * than mutating it so the diff and the render both see it.
		 *
		 * @param {number} index The step's position.
		 * @param {object} value The changed fields.
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		updateStep(index, value) {
			const steps = [...this.draft.steps]
			steps[index] = { ...steps[index], ...value }
			this.draft.steps = steps
		},

		/**
		 * Remove a step. Any branch still targeting its order becomes a
		 * validation error rather than a silent runtime failure (REQ-011).
		 *
		 * @param {number} index The step's position.
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
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		moveStep(index, direction) {
			const neighbourIndex = index + direction
			if (neighbourIndex < 0 || neighbourIndex >= this.draft.steps.length) return
			const steps = [...this.draft.steps]
			const currentOrder = steps[index].order
			const neighbourOrder = steps[neighbourIndex].order
			steps[index] = { ...steps[index], order: neighbourOrder }
			steps[neighbourIndex] = { ...steps[neighbourIndex], order: currentOrder }
			steps.sort((a, b) => a.order - b.order)
			this.draft.steps = steps
		},

		/**
		 * REQ-007's manual trigger. Reports the run's own status rather than
		 * "triggered": a run that returns `failed` has already failed, and
		 * telling the operator it started would be a lie they act on.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-flow-can-be-run-from-its-detail-page-and-its-last-run-is-visible-req-012
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-flow-runs-via-cron-endpoint-rule-event-or-manual-trigger-req-007
		 */
		async runFlow() {
			if (!this.objectIdString || this.running) return
			this.running = true
			try {
				const response = await axios.post(generateUrl(`/apps/openconnector/api/flows/${this.objectIdString}/run`))
				const status = response.data?.status || 'completed'
				this.lastRunStatus = status
				if (status === 'failed' || status === 'stopped' || status === 'dead_letter') {
					showError(t('openconnector', 'Flow run ended with status: {status}', { status }))
				} else {
					showSuccess(t('openconnector', 'Flow run triggered'))
				}
			} catch (err) {
				showError(err?.response?.data?.error || err?.message || t('openconnector', 'Failed to run flow'))
			} finally {
				this.running = false
			}
		},

		/**
		 * Persist the draft. Spreads `original` first so fields this page does
		 * not edit survive the write — a PUT built from the draft alone would
		 * drop everything the editor does not show.
		 *
		 * Re-normalises from the SAVED object afterwards, so the page is clean
		 * against what the server actually stored rather than against what was
		 * sent.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-the-flow-detail-page-is-a-draft-editor-not-a-live-one-req-010
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
				const saved = await this.objectStore.saveObject(this.schemaSlug, payload)
				if (!saved) {
					this.saveError = this.objectStore.errors?.[this.schemaSlug] || t('openconnector', 'Save failed')
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
		 * Discard: rebuild the draft from what was loaded, and clear the save
		 * error with it — the error described a draft that no longer exists.
		 *
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-the-flow-detail-page-is-a-draft-editor-not-a-live-one-req-010
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

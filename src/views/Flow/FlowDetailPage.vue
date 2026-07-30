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
				<NcTextArea resize="vertical" :label="t('openconnector', 'Description')" v-model="draft.description" />
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
		objectIdString() {
			return this.id != null ? String(this.id) : ''
		},
		registerSlug() {
			return this.register || REGISTER_SLUG
		},
		schemaSlug() {
			return this.schema || SCHEMA_SLUG
		},
		title() {
			if (this.draft?.name) return this.draft.name
			return this.original?.name || t('openconnector', 'Flow')
		},
		description() {
			return this.original?.description || ''
		},
		hasError() {
			return Boolean(this.loadError) && !this.draft
		},
		errorMessage() {
			return this.loadError || t('openconnector', 'Failed to load flow')
		},
		stepOrders() {
			return (this.draft?.steps || []).map((step) => step.order)
		},
		canSave() {
			return Boolean(this.draft?.name && this.draft.name.trim().length > 0) && this.validationErrors.length === 0
		},
		dirty() {
			if (!this.draft || !this.original) return false
			const originalNormalized = this.normalizeForDiff(this.original)
			return JSON.stringify(serializeSteps(this.draft.steps)) !== JSON.stringify(serializeSteps(originalNormalized.steps))
				|| this.draft.name !== originalNormalized.name
				|| this.draft.description !== originalNormalized.description
				|| this.draft.isEnabled !== originalNormalized.isEnabled
		},
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
			handler() {
				this.loadObject()
			},
		},
	},

	mounted() {
		this.fetchPickerOptions()
	},

	methods: {
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

		applyLiveObject(fresh) {
			if (this.dirty || this.saving) return
			this.original = fresh
			this.draft = this.normalizeForDiff(fresh)
		},

		normalizeForDiff(obj) {
			const base = emptyDraft()
			return {
				name: obj?.name ?? base.name,
				description: obj?.description ?? base.description,
				isEnabled: obj?.isEnabled !== false,
				steps: keyedSteps(obj?.steps),
			}
		},

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

		toOptions(data) {
			const list = Array.isArray(data?.results) ? data.results : (Array.isArray(data) ? data : [])
			return list.map((row) => ({
				id: String(row.id || row.uuid),
				label: row.name || row.title || row.id,
			}))
		},

		updateDraft(key, value) {
			if (!this.draft) return
			this.draft[key] = value
		},

		nextOrder() {
			const orders = this.stepOrders
			return orders.length === 0 ? 10 : Math.max(...orders) + 10
		},

		addStep() {
			const steps = [...this.draft.steps, ...keyedSteps([{ order: this.nextOrder(), type: 'mapping', onError: 'stop' }])]
			this.draft.steps = steps
		},

		updateStep(index, value) {
			const steps = [...this.draft.steps]
			steps[index] = { ...steps[index], ...value }
			this.draft.steps = steps
		},

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

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  FlowStepRow — one row in the Flow detail page's step-list editor
  (visual-flow-orchestration REQ-009). A typed row, not a canvas node:

    - type            NcSelect (call|mapping|synchronization|event|approval|branch)
    - configRef       NcSelect, options scoped to the picked type's entity
                       list (Sources for call, Mappings for mapping,
                       Synchronizations for synchronization) — n/a for
                       event/approval (their parameters live in `config`)
                       and branch (no service call at all)
    - condition       reuses RuleConditionGroup (the Rule editor's own
                       JsonLogic builder) — no new condition editor built
    - onError         NcSelect (stop|continue|dead_letter)
    - config          type-specific fields (call: endpoint/method; event:
                       type/source/subject; approval: approverGroup/
                       onReject/onTimeout/ttlSeconds)
    - branches        (branch steps only) a nested list of
                       {condition, nextStepOrder} + a defaultNextStepOrder
                       picker, both scoped to `stepOrders` (every other
                       step's `order` in the flow)

  Move up/down/remove are simple array/order operations — no drag-and-drop,
  no SortableJS dependency (REQ-009's explicit v1 constraint).
-->
<template>
	<div class="flow-step-row" data-testid="flow-step-row">
		<div class="flow-step-row__header">
			<span class="flow-step-row__order">#{{ step.order }}</span>

			<div class="flow-step-row__field">
				<NcSelect
					:input-id="'flow-step-type-' + uid"
					:input-label="t('openconnector', 'Step type')"
					:model-value="selectedType"
					:options="typeOptions"
					:clearable="false"
					@input="onTypePick" />
			</div>

			<div v-if="showConfigRefPicker" class="flow-step-row__field">
				<NcSelect
					:input-id="'flow-step-config-ref-' + uid"
					:input-label="configRefLabel"
					:model-value="selectedConfigRef"
					:options="configRefOptions"
					:loading="configRefLoading"
					:clearable="false"
					:placeholder="t('openconnector', 'Pick a {type}', { type: configRefLabel })"
					@input="onConfigRefPick" />
			</div>

			<div class="flow-step-row__field">
				<NcSelect
					:input-id="'flow-step-on-error-' + uid"
					:input-label="t('openconnector', 'On error')"
					:model-value="selectedOnError"
					:options="onErrorOptions"
					:clearable="false"
					@input="onOnErrorPick" />
			</div>

			<div class="flow-step-row__actions">
				<NcButton
					:disabled="isFirst"
					:aria-label="t('openconnector', 'Move step up')"
					type="tertiary"
					@click="$emit('move-up')">
					<template #icon>
						<ArrowUp :size="18" />
					</template>
				</NcButton>
				<NcButton
					:disabled="isLast"
					:aria-label="t('openconnector', 'Move step down')"
					type="tertiary"
					@click="$emit('move-down')">
					<template #icon>
						<ArrowDown :size="18" />
					</template>
				</NcButton>
				<NcButton :aria-label="t('openconnector', 'Remove step')" type="tertiary" @click="$emit('remove')">
					<template #icon>
						<Delete :size="18" />
					</template>
				</NcButton>
			</div>
		</div>

		<!-- call: endpoint/method -->
		<div v-if="step.type === 'call'" class="flow-step-row__config">
			<NcTextField :label="t('openconnector', 'Endpoint path')" :model-value="step.config.endpoint || ''" @update:model-value="(value) => updateConfig('endpoint', value)" />
			<NcTextField :label="t('openconnector', 'HTTP method')" :model-value="step.config.method || 'GET'" @update:model-value="(value) => updateConfig('method', value)" />
		</div>

		<!-- event: type/source/subject -->
		<div v-else-if="step.type === 'event'" class="flow-step-row__config">
			<NcTextField :label="t('openconnector', 'CloudEvent type') + '*'" :model-value="step.config.type || ''" @update:model-value="(value) => updateConfig('type', value)" />
			<NcTextField :label="t('openconnector', 'CloudEvent source') + '*'" :model-value="step.config.source || ''" @update:model-value="(value) => updateConfig('source', value)" />
			<NcTextField :label="t('openconnector', 'CloudEvent subject')" :model-value="step.config.subject || ''" @update:model-value="(value) => updateConfig('subject', value)" />
		</div>

		<!-- approval: approverGroup/onReject/onTimeout/ttlSeconds -->
		<div v-else-if="step.type === 'approval'" class="flow-step-row__config">
			<NcTextField :label="t('openconnector', 'Approver group') + '*'" :model-value="step.config.approverGroup || ''" @update:model-value="(value) => updateConfig('approverGroup', value)" />
			<div class="flow-step-row__field">
				<NcSelect
					:input-id="'flow-step-on-reject-' + uid"
					:input-label="t('openconnector', 'On reject')"
					:model-value="resolveEnumOption(ON_REJECT_OPTIONS, step.config.onReject || 'error')"
					:options="ON_REJECT_OPTIONS"
					:clearable="false"
					@input="(option) => updateConfig('onReject', option?.id || 'error')" />
			</div>
			<div class="flow-step-row__field">
				<NcSelect
					:input-id="'flow-step-on-timeout-' + uid"
					:input-label="t('openconnector', 'On timeout')"
					:model-value="resolveEnumOption(ON_REJECT_OPTIONS, step.config.onTimeout || 'error')"
					:options="ON_REJECT_OPTIONS"
					:clearable="false"
					@input="(option) => updateConfig('onTimeout', option?.id || 'error')" />
			</div>
			<NcTextField
				:label="t('openconnector', 'TTL (seconds)')"
				type="number"
				:model-value="String(step.config.ttlSeconds || 86400)"
				@update:model-value="(value) => updateConfig('ttlSeconds', parseInt(value, 10) || 86400)" />
		</div>

		<!-- branch: branches[] + defaultNextStepOrder -->
		<div v-else-if="step.type === 'branch'" class="flow-step-row__branches">
			<p class="flow-step-row__hint">
				{{ t('openconnector', 'Evaluated in order; the first matching condition selects the next step.') }}
			</p>
			<div v-for="(branch, branchIndex) in step.branches" :key="branchIndex" class="flow-step-row__branch">
				<RuleConditionGroup :node="branchConditionGroup(branch)" :removable="false" @update="(value) => updateBranchCondition(branchIndex, value)" />
				<div class="flow-step-row__field">
					<NcSelect
						:input-id="'flow-step-branch-target-' + uid + '-' + branchIndex"
						:input-label="t('openconnector', 'Then go to step')"
						:model-value="resolveOrderOption(branch.nextStepOrder)"
						:options="orderOptions"
						:clearable="false"
						@input="(option) => updateBranchTarget(branchIndex, option?.id)" />
				</div>
				<NcButton type="tertiary" :aria-label="t('openconnector', 'Remove branch')" @click="removeBranch(branchIndex)">
					<template #icon>
						<Delete :size="18" />
					</template>
				</NcButton>
			</div>
			<NcButton type="secondary" @click="addBranch">
				<template #icon>
					<Plus :size="18" />
				</template>
				{{ t('openconnector', 'Add branch') }}
			</NcButton>
			<div class="flow-step-row__field">
				<NcSelect
					:input-id="'flow-step-default-target-' + uid"
					:input-label="t('openconnector', 'Otherwise go to step')"
					:model-value="resolveOrderOption(step.defaultNextStepOrder)"
					:options="orderOptions"
					:clearable="true"
					@input="(option) => $emit('update', { ...step, defaultNextStepOrder: option?.id ?? null })" />
			</div>
		</div>

		<!-- Condition editor: every step type except branch (branch has its own per-branch conditions above). -->
		<div v-if="step.type !== 'branch'" class="flow-step-row__condition">
			<span class="flow-step-row__label">{{ t('openconnector', 'Run-if condition (optional — always runs when empty)') }}</span>
			<RuleConditionGroup :node="conditionGroup" :removable="false" @update="onConditionUpdate" />
		</div>
	</div>
</template>

<script>
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import RuleConditionGroup from '../Rule/RuleConditionGroup.vue'

const EMPTY_ROOT_GROUP = { and: [] }

const ON_REJECT_OPTIONS = [
	{ id: 'error', label: 'Error' },
	{ id: 'skip', label: 'Skip' },
	{ id: 'dead_letter', label: 'Dead letter' },
]

let rowSeq = 0

export default {
	name: 'FlowStepRow',

	components: {
		NcButton,
		NcSelect,
		NcTextField,
		ArrowUp,
		ArrowDown,
		Delete,
		Plus,
		RuleConditionGroup,
	},

	props: {
		step: { type: Object, required: true },
		stepOrders: { type: Array, default: () => [] },
		sourceOptions: { type: Array, default: () => [] },
		mappingOptions: { type: Array, default: () => [] },
		synchronizationOptions: { type: Array, default: () => [] },
		configRefLoading: { type: Boolean, default: false },
		isFirst: { type: Boolean, default: false },
		isLast: { type: Boolean, default: false },
	},

	data() {
		const seq = ++rowSeq
		return {
			uid: seq,
			ON_REJECT_OPTIONS,
			typeOptions: [
				{ id: 'call', label: t('openconnector', 'Call') },
				{ id: 'mapping', label: t('openconnector', 'Mapping') },
				{ id: 'synchronization', label: t('openconnector', 'Synchronization') },
				{ id: 'event', label: t('openconnector', 'Event') },
				{ id: 'approval', label: t('openconnector', 'Approval') },
				{ id: 'branch', label: t('openconnector', 'Branch') },
			],
			onErrorOptions: [
				{ id: 'stop', label: t('openconnector', 'Stop') },
				{ id: 'continue', label: t('openconnector', 'Continue') },
				{ id: 'dead_letter', label: t('openconnector', 'Dead letter') },
			],
		}
	},

	computed: {
		selectedType() {
			return this.typeOptions.find((opt) => opt.id === this.step.type) || this.typeOptions[1]
		},
		selectedOnError() {
			return this.onErrorOptions.find((opt) => opt.id === this.step.onError) || this.onErrorOptions[0]
		},
		showConfigRefPicker() {
			return this.step.type === 'call' || this.step.type === 'mapping' || this.step.type === 'synchronization'
		},
		configRefLabel() {
			if (this.step.type === 'call') return t('openconnector', 'Source')
			if (this.step.type === 'synchronization') return t('openconnector', 'Synchronization')
			return t('openconnector', 'Mapping')
		},
		configRefOptions() {
			if (this.step.type === 'call') return this.sourceOptions
			if (this.step.type === 'synchronization') return this.synchronizationOptions
			return this.mappingOptions
		},
		selectedConfigRef() {
			return this.configRefOptions.find((opt) => opt.id === this.step.configRef) || null
		},
		orderOptions() {
			return this.stepOrders
				.filter((order) => order !== this.step.order)
				.map((order) => ({ id: order, label: '#' + order }))
		},
		conditionGroup() {
			return this.normaliseConditions(this.step.condition)
		},
	},

	methods: {
		onTypePick(option) {
			if (!option?.id) return
			const next = { ...this.step, type: option.id, configRef: '', config: {} }
			if (option.id === 'branch') {
				next.branches = next.branches || []
			}
			this.$emit('update', next)
		},
		onConfigRefPick(option) {
			this.$emit('update', { ...this.step, configRef: option?.id || '' })
		},
		onOnErrorPick(option) {
			if (!option?.id) return
			this.$emit('update', { ...this.step, onError: option.id })
		},
		updateConfig(key, value) {
			this.$emit('update', { ...this.step, config: { ...(this.step.config || {}), [key]: value } })
		},
		resolveEnumOption(options, id) {
			return options.find((opt) => opt.id === id) || options[0]
		},
		resolveOrderOption(order) {
			if (order === null || order === undefined) return null
			return this.orderOptions.find((opt) => opt.id === order) || { id: order, label: '#' + order + ' (missing)' }
		},
		addBranch() {
			const branches = [...(this.step.branches || []), { condition: { ...EMPTY_ROOT_GROUP }, nextStepOrder: null }]
			this.$emit('update', { ...this.step, branches })
		},
		removeBranch(index) {
			const branches = (this.step.branches || []).filter((_, i) => i !== index)
			this.$emit('update', { ...this.step, branches })
		},
		updateBranchTarget(index, order) {
			const branches = (this.step.branches || []).map((b, i) => (i === index ? { ...b, nextStepOrder: order ?? null } : b))
			this.$emit('update', { ...this.step, branches })
		},
		updateBranchCondition(index, condition) {
			const branches = (this.step.branches || []).map((b, i) => (i === index ? { ...b, condition } : b))
			this.$emit('update', { ...this.step, branches })
		},
		branchConditionGroup(branch) {
			return this.normaliseConditions(branch.condition)
		},
		onConditionUpdate(value) {
			// An empty group means "no condition" (always run) — store null
			// rather than an empty {and:[]} so REQ-003's "absent/empty"
			// wording round-trips literally.
			const isEmpty = value && Array.isArray(value.and) && value.and.length === 0
			this.$emit('update', { ...this.step, condition: isEmpty ? null : value })
		},
		/**
		 * Coerce a persisted condition (null/undefined/object) into a
		 * JsonLogic group node for RuleConditionGroup — mirrors
		 * SynchronizationDetailPage's own normaliseConditions helper, minus
		 * the array-wrapping (flow step `condition` is stored as a bare
		 * object, not array<object>).
		 *
		 * @param {object|null} raw Persisted condition value.
		 * @return {object} JsonLogic group node.
		 */
		normaliseConditions(raw) {
			if (raw === null || raw === undefined) return { ...EMPTY_ROOT_GROUP }
			if (typeof raw === 'object' && !Array.isArray(raw)) {
				const keys = Object.keys(raw)
				if (keys.length === 1 && (keys[0] === 'and' || keys[0] === 'or') && Array.isArray(raw[keys[0]])) {
					return raw
				}
				if (keys.length === 0) return { ...EMPTY_ROOT_GROUP }
				return { and: [raw] }
			}
			return { ...EMPTY_ROOT_GROUP }
		},
	},
}
</script>

<style scoped>
.flow-step-row {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	margin-bottom: 12px;
}

.flow-step-row__header {
	display: flex;
	align-items: flex-end;
	gap: 12px;
	flex-wrap: wrap;
}

.flow-step-row__order {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
	padding-bottom: 8px;
}

.flow-step-row__field {
	min-width: 180px;
}

.flow-step-row__actions {
	display: flex;
	gap: 4px;
	margin-left: auto;
}

.flow-step-row__config,
.flow-step-row__branches,
.flow-step-row__condition {
	margin-top: 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.flow-step-row__branch {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	border-top: 1px solid var(--color-border);
	padding-top: 8px;
}

.flow-step-row__label {
	font-weight: bold;
	font-size: 0.9em;
}

.flow-step-row__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>

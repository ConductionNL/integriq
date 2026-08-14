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
					:inputId="'flow-step-type-' + uid"
					:inputLabel="t('openconnector', 'Step type')"
					:modelValue="selectedType"
					:options="typeOptions"
					:clearable="false"
					@update:modelValue="onTypePick" />
			</div>

			<div v-if="showConfigRefPicker" class="flow-step-row__field">
				<NcSelect
					:inputId="'flow-step-config-ref-' + uid"
					:inputLabel="configRefLabel"
					:modelValue="selectedConfigRef"
					:options="configRefOptions"
					:loading="configRefLoading"
					:clearable="false"
					:placeholder="
						t('openconnector', 'Pick a {type}', { type: configRefLabel })
					"
					@update:modelValue="onConfigRefPick" />
			</div>

			<div class="flow-step-row__field">
				<NcSelect
					:inputId="'flow-step-on-error-' + uid"
					:inputLabel="t('openconnector', 'On error')"
					:modelValue="selectedOnError"
					:options="onErrorOptions"
					:clearable="false"
					@update:modelValue="onOnErrorPick" />
			</div>

			<div class="flow-step-row__actions">
				<NcButton
					:disabled="isFirst"
					:aria-label="t('openconnector', 'Move step up')"
					variant="tertiary"
					@click="$emit('move-up')">
					<template #icon>
						<ArrowUp :size="18" />
					</template>
				</NcButton>
				<NcButton
					:disabled="isLast"
					:aria-label="t('openconnector', 'Move step down')"
					variant="tertiary"
					@click="$emit('move-down')">
					<template #icon>
						<ArrowDown :size="18" />
					</template>
				</NcButton>
				<NcButton
					:aria-label="t('openconnector', 'Remove step')"
					variant="tertiary"
					@click="$emit('remove')">
					<template #icon>
						<Delete :size="18" />
					</template>
				</NcButton>
			</div>
		</div>

		<!-- call: endpoint/method -->
		<div v-if="step.type === 'call'" class="flow-step-row__config">
			<NcTextField
				:label="t('openconnector', 'Endpoint path')"
				:modelValue="step.config.endpoint || ''"
				@update:modelValue="(value) => updateConfig('endpoint', value)" />
			<NcTextField
				:label="t('openconnector', 'HTTP method')"
				:modelValue="step.config.method || 'GET'"
				@update:modelValue="(value) => updateConfig('method', value)" />
		</div>

		<!-- event: type/source/subject -->
		<div v-else-if="step.type === 'event'" class="flow-step-row__config">
			<NcTextField
				:label="t('openconnector', 'CloudEvent type') + '*'"
				:modelValue="step.config.type || ''"
				@update:modelValue="(value) => updateConfig('type', value)" />
			<NcTextField
				:label="t('openconnector', 'CloudEvent source') + '*'"
				:modelValue="step.config.source || ''"
				@update:modelValue="(value) => updateConfig('source', value)" />
			<NcTextField
				:label="t('openconnector', 'CloudEvent subject')"
				:modelValue="step.config.subject || ''"
				@update:modelValue="(value) => updateConfig('subject', value)" />
		</div>

		<!-- approval: approverGroup/onReject/onTimeout/ttlSeconds -->
		<div v-else-if="step.type === 'approval'" class="flow-step-row__config">
			<NcTextField
				:label="t('openconnector', 'Approver group') + '*'"
				:modelValue="step.config.approverGroup || ''"
				@update:modelValue="
					(value) => updateConfig('approverGroup', value)
				" />
			<div class="flow-step-row__field">
				<NcSelect
					:inputId="'flow-step-on-reject-' + uid"
					:inputLabel="t('openconnector', 'On reject')"
					:modelValue="
						resolveEnumOption(
							ON_REJECT_OPTIONS,
							step.config.onReject || 'error',
						)
					"
					:options="ON_REJECT_OPTIONS"
					:clearable="false"
					@update:modelValue="
						(option) => updateConfig('onReject', option?.id || 'error')
					" />
			</div>
			<div class="flow-step-row__field">
				<NcSelect
					:inputId="'flow-step-on-timeout-' + uid"
					:inputLabel="t('openconnector', 'On timeout')"
					:modelValue="
						resolveEnumOption(
							ON_REJECT_OPTIONS,
							step.config.onTimeout || 'error',
						)
					"
					:options="ON_REJECT_OPTIONS"
					:clearable="false"
					@update:modelValue="
						(option) => updateConfig('onTimeout', option?.id || 'error')
					" />
			</div>
			<NcTextField
				:label="t('openconnector', 'TTL (seconds)')"
				type="number"
				:modelValue="String(step.config.ttlSeconds || 86400)"
				@update:modelValue="
					(value) =>
						updateConfig('ttlSeconds', parseInt(value, 10) || 86400)
				" />
		</div>

		<!-- branch: branches[] + defaultNextStepOrder -->
		<div v-else-if="step.type === 'branch'" class="flow-step-row__branches">
			<p class="flow-step-row__hint">
				{{
					t(
						'openconnector',
						'Evaluated in order; the first matching condition selects the next step.',
					)
				}}
			</p>
			<div
				v-for="(branch, branchIndex) in step.branches"
				:key="branchIndex"
				class="flow-step-row__branch">
				<RuleConditionGroup
					:node="branchConditionGroup(branch)"
					:removable="false"
					@update="(value) => updateBranchCondition(branchIndex, value)" />
				<div class="flow-step-row__field">
					<NcSelect
						:inputId="
							'flow-step-branch-target-' + uid + '-' + branchIndex
						"
						:inputLabel="t('openconnector', 'Then go to step')"
						:modelValue="resolveOrderOption(branch.nextStepOrder)"
						:options="orderOptions"
						:clearable="false"
						@update:modelValue="
							(option) => updateBranchTarget(branchIndex, option?.id)
						" />
				</div>
				<NcButton
					variant="tertiary"
					:aria-label="t('openconnector', 'Remove branch')"
					@click="removeBranch(branchIndex)">
					<template #icon>
						<Delete :size="18" />
					</template>
				</NcButton>
			</div>
			<NcButton variant="secondary" @click="addBranch">
				<template #icon>
					<Plus :size="18" />
				</template>
				{{ t('openconnector', 'Add branch') }}
			</NcButton>
			<div class="flow-step-row__field">
				<NcSelect
					:inputId="'flow-step-default-target-' + uid"
					:inputLabel="t('openconnector', 'Otherwise go to step')"
					:modelValue="resolveOrderOption(step.defaultNextStepOrder)"
					:options="orderOptions"
					:clearable="true"
					@update:modelValue="
						(option) =>
							$emit('update', {
								...step,
								defaultNextStepOrder: option?.id ?? null,
							})
					" />
			</div>
		</div>

		<!-- Condition editor: every step type except branch (branch has its own per-branch conditions above). -->
		<div v-if="step.type !== 'branch'" class="flow-step-row__condition">
			<span class="flow-step-row__label">{{
				t(
					'openconnector',
					'Run-if condition (optional — always runs when empty)',
				)
			}}</span>
			<RuleConditionGroup
				:node="conditionGroup"
				:removable="false"
				@update="onConditionUpdate" />
		</div>
	</div>
</template>

<script>
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
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
				{
					id: 'synchronization',
					label: t('openconnector', 'Synchronization'),
				},
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
		/**
		 * The `NcSelect` model for the step's `type`.
		 *
		 * @return {object} The selected type option.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		selectedType() {
			return (
				this.typeOptions.find((opt) => opt.id === this.step.type)
				|| this.typeOptions[1]
			)
		},

		/**
		 * The `NcSelect` model for the step's `onError` policy.
		 *
		 * @return {object} The selected onError option.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-per-step-onerror-policy-governs-failure-handling-req-006
		 */
		selectedOnError() {
			return (
				this.onErrorOptions.find((opt) => opt.id === this.step.onError)
				|| this.onErrorOptions[0]
			)
		},

		/**
		 * Whether this step type references a configured entity at all —
		 * `branch`, `approval` and `condition` steps have no `configRef`.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		showConfigRefPicker() {
			return (
				this.step.type === 'call'
				|| this.step.type === 'mapping'
				|| this.step.type === 'synchronization'
			)
		},

		/**
		 * The picker's `inputLabel`, named for the entity the step actually
		 * references — REQ-009 requires an explicit label per WCAG 1.3.1/4.1.2,
		 * and a generic "Config" would not tell a screen reader which entity
		 * kind is being chosen.
		 *
		 * @return {string}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		configRefLabel() {
			if (this.step.type === 'call') return t('openconnector', 'Source')
			if (this.step.type === 'synchronization')
				return t('openconnector', 'Synchronization')
			return t('openconnector', 'Mapping')
		},

		/**
		 * The config-ref options, scoped to the step's own entity type —
		 * REQ-009's scenario requires a `mapping` step's picker to offer
		 * Mappings only, not Sources or Synchronizations.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		configRefOptions() {
			if (this.step.type === 'call') return this.sourceOptions
			if (this.step.type === 'synchronization')
				return this.synchronizationOptions
			return this.mappingOptions
		},

		/**
		 * The `NcSelect` model for `configRef`; null when the stored id is not
		 * in the scoped option set, so a stale reference shows as unset rather
		 * than silently displaying another entity.
		 *
		 * @return {object|null}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		selectedConfigRef() {
			return (
				this.configRefOptions.find((opt) => opt.id === this.step.configRef)
				|| null
			)
		},

		/**
		 * Branch-target options: every other step's `order`, excluding this
		 * step's own so a branch cannot be pointed at itself.
		 *
		 * @return {Array<{id: number, label: string}>}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-branch-step-selects-the-next-step-via-jsonlogic-req-004
		 */
		orderOptions() {
			return this.stepOrders
				.filter((order) => order !== this.step.order)
				.map((order) => ({ id: order, label: '#' + order }))
		},

		/**
		 * The step's `condition` as a JsonLogic group node for the editor.
		 *
		 * @return {object}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-step-condition-skips-a-step-when-it-evaluates-false-req-003
		 */
		conditionGroup() {
			return this.normaliseConditions(this.step.condition)
		},
	},

	methods: {
		/**
		 * Changes the step's type, clearing `configRef`/`config` because they
		 * are type-specific — carrying a Mapping id onto a `call` step would
		 * leave a reference the runner cannot resolve.
		 *
		 * @param {object} option The chosen type option.
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		onTypePick(option) {
			if (!option?.id) return
			const next = { ...this.step, type: option.id, configRef: '', config: {} }
			if (option.id === 'branch') {
				next.branches = next.branches || []
			}
			this.$emit('update', next)
		},

		/**
		 * Sets the referenced entity, or clears it when the picker is emptied.
		 *
		 * @param {object|null} option The chosen entity option.
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		onConfigRefPick(option) {
			this.$emit('update', { ...this.step, configRef: option?.id || '' })
		},

		/**
		 * Sets the step's failure policy. A cleared select is ignored rather
		 * than written as empty — every step must carry an `onError`, since it
		 * governs what the run does when the step throws.
		 *
		 * @param {object} option The chosen onError option.
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-per-step-onerror-policy-governs-failure-handling-req-006
		 */
		onOnErrorPick(option) {
			if (!option?.id) return
			this.$emit('update', { ...this.step, onError: option.id })
		},

		/**
		 * Merges one key into the step's type-specific `config` block.
		 *
		 * @param {string} key   The config key.
		 * @param {*}      value The new value.
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		updateConfig(key, value) {
			this.$emit('update', {
				...this.step,
				config: { ...(this.step.config || {}), [key]: value },
			})
		},

		/**
		 * Resolves a stored enum id to its option, falling back to the first so
		 * an `NcSelect` never renders with a null model.
		 *
		 * @param {Array}  options The option set.
		 * @param {string} id      The stored id.
		 * @return {object}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009
		 */
		resolveEnumOption(options, id) {
			return options.find((opt) => opt.id === id) || options[0]
		},

		/**
		 * Resolves a branch target to its option. A target that no longer
		 * matches any step is shown as "(missing)" rather than blank — the
		 * dangling reference is exactly what the page's save-time validation
		 * blocks, so the row must make it visible rather than hide it.
		 *
		 * @param {number|null} order The stored target order.
		 * @return {object|null}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-branch-step-selects-the-next-step-via-jsonlogic-req-004
		 */
		resolveOrderOption(order) {
			if (order === null || order === undefined) return null
			return (
				this.orderOptions.find((opt) => opt.id === order) || {
					id: order,
					label: '#' + order + ' (missing)',
				}
			)
		},

		/**
		 * Appends a branch with an empty condition and no target yet.
		 *
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-branch-step-selects-the-next-step-via-jsonlogic-req-004
		 */
		addBranch() {
			const branches = [
				...(this.step.branches || []),
				{ condition: { ...EMPTY_ROOT_GROUP }, nextStepOrder: null },
			]
			this.$emit('update', { ...this.step, branches })
		},

		/**
		 * Removes one branch from the step.
		 *
		 * @param {number} index The branch's array index.
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-branch-step-selects-the-next-step-via-jsonlogic-req-004
		 */
		removeBranch(index) {
			const branches = (this.step.branches || []).filter((_, i) => i !== index)
			this.$emit('update', { ...this.step, branches })
		},

		/**
		 * Points one branch at a step `order`. An undefined pick is stored as
		 * explicit null, so "no target" round-trips as a value rather than as
		 * an absent key.
		 *
		 * @param {number}      index The branch's array index.
		 * @param {number|null} order The target step's order.
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-branch-step-selects-the-next-step-via-jsonlogic-req-004
		 */
		updateBranchTarget(index, order) {
			const branches = (this.step.branches || []).map((b, i) =>
				i === index ? { ...b, nextStepOrder: order ?? null } : b,
			)
			this.$emit('update', { ...this.step, branches })
		},

		/**
		 * Replaces one branch's JsonLogic condition.
		 *
		 * @param {number} index     The branch's array index.
		 * @param {object} condition The new JsonLogic node.
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-branch-step-selects-the-next-step-via-jsonlogic-req-004
		 */
		updateBranchCondition(index, condition) {
			const branches = (this.step.branches || []).map((b, i) =>
				i === index ? { ...b, condition } : b,
			)
			this.$emit('update', { ...this.step, branches })
		},

		/**
		 * One branch's condition as a group node for the condition editor.
		 *
		 * @param {object} branch The branch entry.
		 * @return {object}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-branch-step-selects-the-next-step-via-jsonlogic-req-004
		 */
		branchConditionGroup(branch) {
			return this.normaliseConditions(branch.condition)
		},

		/**
		 * Stores the step's own condition.
		 *
		 * @param {object} value The edited JsonLogic group.
		 * @return {void}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-step-condition-skips-a-step-when-it-evaluates-false-req-003
		 */
		onConditionUpdate(value) {
			// An empty group means "no condition" (always run) — store null
			// rather than an empty {and:[]} so REQ-003's "absent/empty"
			// wording round-trips literally.
			const isEmpty =
				value && Array.isArray(value.and) && value.and.length === 0
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
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-step-condition-skips-a-step-when-it-evaluates-false-req-003
		 */
		normaliseConditions(raw) {
			if (raw === null || raw === undefined) return { ...EMPTY_ROOT_GROUP }
			if (typeof raw === 'object' && !Array.isArray(raw)) {
				const keys = Object.keys(raw)
				if (
					keys.length === 1
					&& (keys[0] === 'and' || keys[0] === 'or')
					&& Array.isArray(raw[keys[0]])
				) {
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

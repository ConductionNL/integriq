<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  RuleConditionGroup — recursive boolean group in the visual condition
  tree. Renders as `{ "and": [...children] }` or `{ "or": [...children] }`
  JsonLogic. Each child is either another RuleConditionGroup (sub-tree)
  or a RuleConditionLeaf (predicate).

  We classify a child as a "group" when its top-level operator key is
  exactly `and` or `or` and the value is an array; everything else is
  treated as a leaf (the leaf component itself is forgiving about
  malformed shapes).

  MVP scope (closes #833): boolean grouping + add/remove children;
  drag-reorder is deferred (tracked as a v2 follow-up). Users wanting
  exotic constructs (negation around a group, comparisons across two
  refs) can still drop down to the raw JSON editor on the parent page.
-->
<template>
	<div class="rule-condition-group" data-testid="rule-condition-group">
		<div class="rule-condition-group__header">
			<NcSelect
				class="rule-condition-group__op"
				:input-id="'rule-condition-group-op-' + uid"
				:input-label="t('openconnector', 'Group operator')"
				:value="selectedOperator"
				:options="operatorOptions"
				:clearable="false"
				@input="onOperatorPick" />
			<span class="rule-condition-group__hint">
				{{ operatorHint }}
			</span>
			<div class="rule-condition-group__spacer" />
			<NcButton
				v-if="removable"
				:aria-label="t('openconnector', 'Remove group')"
				type="tertiary-no-background"
				@click="$emit('remove')">
				<template #icon>
					<Close :size="18" />
				</template>
			</NcButton>
		</div>
		<div class="rule-condition-group__children">
			<template v-for="(child, index) in children">
				<RuleConditionGroup
					v-if="isGroup(child)"
					:key="'group-' + index"
					:node="child"
					:removable="true"
					@update="(value) => updateChild(index, value)"
					@remove="removeChild(index)" />
				<RuleConditionLeaf
					v-else
					:key="'leaf-' + index"
					:node="child"
					@update="(value) => updateChild(index, value)"
					@remove="removeChild(index)" />
			</template>
			<NcEmptyContent
				v-if="children.length === 0"
				:name="t('openconnector', 'No conditions yet')"
				:description="t('openconnector', 'Add a condition or sub-group to start matching incoming data.')">
				<template #icon>
					<FilterVariant :size="32" />
				</template>
			</NcEmptyContent>
		</div>
		<div class="rule-condition-group__actions">
			<NcButton type="secondary" @click="addLeaf">
				<template #icon>
					<Plus :size="18" />
				</template>
				{{ t('openconnector', 'Add condition') }}
			</NcButton>
			<NcButton type="tertiary" @click="addGroup">
				<template #icon>
					<FormatListGroup :size="18" />
				</template>
				{{ t('openconnector', 'Add group') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcSelect } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'
import FormatListGroup from 'vue-material-design-icons/FormatListGroup.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import RuleConditionLeaf from './RuleConditionLeaf.vue'

let groupUidCounter = 0

const GROUP_OPERATORS = ['and', 'or']

export default {
	name: 'RuleConditionGroup',

	components: {
		NcButton,
		NcEmptyContent,
		NcSelect,
		Close,
		FilterVariant,
		FormatListGroup,
		Plus,
		RuleConditionLeaf,
	},

	props: {
		/**
		 * JsonLogic group node — `{ and: [...] }` or `{ or: [...] }`.
		 * Anything else gets coerced to an empty `and` group so the
		 * UI never shows an inconsistent root.
		 *
		 * @type {object}
		 */
		node: { type: Object, default: () => ({ and: [] }) },
		/**
		 * Whether to render the per-group remove button. The root
		 * group on the parent page sets this to false so the user
		 * cannot delete the whole condition tree by accident.
		 */
		removable: { type: Boolean, default: false },
	},

	data() {
		return {
			uid: ++groupUidCounter,
		}
	},

	computed: {
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		currentOperator() {
			const keys = Object.keys(this.node || {})
			const found = keys.find((key) => GROUP_OPERATORS.includes(key))
			return found || 'and'
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		children() {
			const value = this.node?.[this.currentOperator]
			return Array.isArray(value) ? value : []
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		operatorOptions() {
			return [
				{ id: 'and', label: this.t('openconnector', 'ALL of (AND)') },
				{ id: 'or', label: this.t('openconnector', 'ANY of (OR)') },
			]
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		selectedOperator() {
			return this.operatorOptions.find((option) => option.id === this.currentOperator)
				?? this.operatorOptions[0]
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		operatorHint() {
			return this.currentOperator === 'and'
				? this.t('openconnector', 'Every child below must match.')
				: this.t('openconnector', 'At least one child below must match.')
		},
	},

	methods: {
		/**
		 * Decide whether a child renders as a nested RuleConditionGroup rather
		 * than a leaf predicate: exactly one top-level key, that key being
		 * `and`/`or`, and its value an array.
		 *
		 * @param {*} child One entry of this group's operand array. Untyped on
		 *   purpose — the tree is user-editable, so a child may be any JsonLogic
		 *   value (or malformed) and still has to be classified without throwing.
		 * @return {boolean} True when the child is a boolean sub-group.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		isGroup(child) {
			if (!child || typeof child !== 'object' || Array.isArray(child)) return false
			const keys = Object.keys(child)
			return keys.length === 1 && GROUP_OPERATORS.includes(keys[0]) && Array.isArray(child[keys[0]])
		},
		/**
		 * Re-key the group under the newly picked boolean operator, carrying the
		 * existing children over unchanged, and emit the rewritten node.
		 *
		 * @param {{id: string, label: string}} option The operator option picked
		 *   in the NcSelect; `id` is `and` or `or`. Null/undefined when the
		 *   select clears, in which case the current operator is kept.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onOperatorPick(option) {
			if (!option) return
			this.$emit('update', { [option.id]: this.children.slice() })
		},
		/**
		 * Replace one child with the node its component just emitted and emit
		 * the whole group upwards (the tree is edited immutably, top-down).
		 *
		 * @param {number} index Position of the child inside the operand array,
		 *   as bound by the `v-for` in the template.
		 * @param {object} value The replacement JsonLogic node — a sub-group
		 *   from RuleConditionGroup or a predicate from RuleConditionLeaf.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		updateChild(index, value) {
			const next = this.children.slice()
			next[index] = value
			this.$emit('update', { [this.currentOperator]: next })
		},
		/**
		 * Drop one child from the group and emit the shortened group upwards.
		 *
		 * @param {number} index Position of the child to remove from the operand
		 *   array, as bound by the `v-for` in the template.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		removeChild(index) {
			const next = this.children.slice()
			next.splice(index, 1)
			this.$emit('update', { [this.currentOperator]: next })
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		addLeaf() {
			const next = this.children.slice()
			next.push({ '==': [{ var: '' }, ''] })
			this.$emit('update', { [this.currentOperator]: next })
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		addGroup() {
			const next = this.children.slice()
			next.push({ and: [] })
			this.$emit('update', { [this.currentOperator]: next })
		},
	},
}
</script>

<style scoped>
.rule-condition-group {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	background: var(--color-main-background);
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.rule-condition-group__header {
	display: flex;
	align-items: center;
	gap: 12px;
}

.rule-condition-group__op {
	min-width: 220px;
}

.rule-condition-group__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.rule-condition-group__spacer {
	flex: 1;
}

.rule-condition-group__children {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-left: 16px;
	border-left: 2px solid var(--color-border);
}

.rule-condition-group__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}
</style>

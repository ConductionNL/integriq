<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  RuleConditionLeaf — one predicate in the visual condition tree.

  A leaf is a (var, operator, value) triple. JsonLogic represents this
  as `{ "<op>": [ { "var": "<path>" }, <literal> ] }` for binary ops,
  or `{ "<op>": [ { "var": "<path>" } ] }` for unary ones (e.g. `!`,
  `!!`). This component owns the inputs for those three slots and
  emits the rebuilt JsonLogic node up to RuleConditionGroup.

  Scope is MVP: a hand-picked subset of operators (==, !=, >, >=, <,
  <=, in, !in, exists). String/number/boolean values share one text
  input — JsonLogic is type-flexible at evaluation time, so coercion
  is deferred to the rule engine. The advanced operators (`some`,
  `all`, regex `match`, arithmetic) are out of scope for v1; users
  who need them can still hand-edit the JSON via the raw editor
  fallback in RuleDetailPage.

  Closes #833 (leaf-half of the visual builder).
-->
<template>
	<div class="rule-condition-leaf" data-testid="rule-condition-leaf">
		<NcTextField
			class="rule-condition-leaf__var"
			:label="t('openconnector', 'Field')"
			:value="varPath"
			:placeholder="t('openconnector', 'e.g. body.status')"
			@update:value="onVarInput" />
		<NcSelect
			class="rule-condition-leaf__op"
			:input-id="'rule-condition-op-' + uid"
			:value="selectedOperator"
			:options="operatorOptions"
			:clearable="false"
			:placeholder="t('openconnector', 'Operator')"
			@input="onOperatorPick" />
		<NcTextField
			v-if="needsValue"
			class="rule-condition-leaf__value"
			:label="t('openconnector', 'Value')"
			:value="valueString"
			:placeholder="t('openconnector', 'Comparison value')"
			@update:value="onValueInput" />
		<span v-else class="rule-condition-leaf__value rule-condition-leaf__value--placeholder">
			{{ t('openconnector', '(no value needed)') }}
		</span>
		<NcButton
			class="rule-condition-leaf__remove"
			:aria-label="t('openconnector', 'Remove condition')"
			type="tertiary-no-background"
			@click="$emit('remove')">
			<template #icon>
				<Close :size="18" />
			</template>
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'

/**
 * Operators the visual builder exposes. Each entry maps a JsonLogic
 * operator string to a human label plus a flag for whether the
 * second operand (the value) is required. Unary operators (`!`, `!!`)
 * are emitted with a single-element args array.
 *
 * @type {Array<{ id: string, label: string, unary: boolean }>}
 */
const OPERATORS = [
	{ id: '==', label: 'equals', unary: false },
	{ id: '!=', label: 'does not equal', unary: false },
	{ id: '>', label: 'greater than', unary: false },
	{ id: '>=', label: 'greater than or equal', unary: false },
	{ id: '<', label: 'less than', unary: false },
	{ id: '<=', label: 'less than or equal', unary: false },
	{ id: 'in', label: 'in (string contains / array member)', unary: false },
	{ id: '!!', label: 'exists / truthy', unary: true },
	{ id: '!', label: 'missing / falsy', unary: true },
]

let leafUidCounter = 0

export default {
	name: 'RuleConditionLeaf',

	components: {
		NcButton,
		NcSelect,
		NcTextField,
		Close,
	},

	props: {
		/**
		 * The JsonLogic node this leaf renders. Shape is one of:
		 *   `{ "<op>": [ { "var": "<path>" }, <literal> ] }` (binary)
		 *   `{ "<op>": [ { "var": "<path>" } ] }`            (unary)
		 *
		 * Anything else is treated as an empty leaf so the UI never
		 * blanks the user out of a malformed-but-recoverable node.
		 *
		 * @type {object}
		 */
		node: { type: Object, default: () => ({ '==': [{ var: '' }, ''] }) },
	},

	data() {
		return {
			uid: ++leafUidCounter,
		}
	},

	computed: {
		operatorOptions() {
			return OPERATORS.map((op) => ({ id: op.id, label: this.t('openconnector', op.label) }))
		},
		currentOperator() {
			const keys = Object.keys(this.node || {})
			const op = keys.find((key) => OPERATORS.some((entry) => entry.id === key))
			return op || '=='
		},
		selectedOperator() {
			return this.operatorOptions.find((option) => option.id === this.currentOperator)
				?? this.operatorOptions[0]
		},
		needsValue() {
			const op = OPERATORS.find((entry) => entry.id === this.currentOperator)
			return op ? !op.unary : true
		},
		args() {
			const value = this.node?.[this.currentOperator]
			return Array.isArray(value) ? value : []
		},
		varPath() {
			const first = this.args[0]
			if (first && typeof first === 'object' && Object.prototype.hasOwnProperty.call(first, 'var')) {
				return String(first.var ?? '')
			}
			if (typeof first === 'string') return first
			return ''
		},
		rawValue() {
			return this.args.length > 1 ? this.args[1] : ''
		},
		valueString() {
			const raw = this.rawValue
			if (raw === null || raw === undefined) return ''
			if (typeof raw === 'object') {
				try { return JSON.stringify(raw) } catch (_e) { return String(raw) }
			}
			return String(raw)
		},
	},

	methods: {
		onVarInput(value) {
			this.emitUpdate({ varPath: value })
		},
		onOperatorPick(option) {
			if (!option) return
			this.emitUpdate({ operator: option.id })
		},
		onValueInput(value) {
			this.emitUpdate({ rawValue: this.coerce(value) })
		},
		/**
		 * Best-effort literal coercion. Numbers and booleans get
		 * recognised so JsonLogic's `>`/`<` actually compare numerics
		 * instead of lexicographic strings. Anything else stays as a
		 * string — users wanting nested var refs or complex literals
		 * are expected to drop down to the raw JSON editor.
		 *
		 * @param {string} raw User-entered text.
		 * @return {*} Coerced value.
		 */
		coerce(raw) {
			if (raw === '') return ''
			if (raw === 'true') return true
			if (raw === 'false') return false
			if (raw === 'null') return null
			if (/^-?\d+$/.test(raw)) return Number(raw)
			if (/^-?\d+\.\d+$/.test(raw)) return Number(raw)
			return raw
		},
		/**
		 * Rebuild the JsonLogic node and emit. Done in one place so
		 * the operator change can both flip the operator key AND
		 * trim the value arg for unary operators.
		 *
		 * @param {{ varPath?: string, operator?: string, rawValue?: * }} patch
		 *   Partial change — fields omitted come from current state.
		 * @return {void}
		 */
		emitUpdate(patch) {
			const operator = patch.operator ?? this.currentOperator
			const varPath = patch.varPath ?? this.varPath
			const opEntry = OPERATORS.find((entry) => entry.id === operator)
			const isUnary = !!opEntry?.unary
			const value = isUnary ? undefined : (patch.rawValue !== undefined ? patch.rawValue : this.rawValue)
			const args = isUnary ? [{ var: varPath }] : [{ var: varPath }, value]
			this.$emit('update', { [operator]: args })
		},
	},
}
</script>

<style scoped>
.rule-condition-leaf {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 220px minmax(0, 1fr) auto;
	gap: 8px;
	align-items: end;
	padding: 8px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.rule-condition-leaf__value--placeholder {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	padding: 8px 0;
}

@media (max-width: 720px) {
	.rule-condition-leaf {
		grid-template-columns: 1fr;
	}
}
</style>

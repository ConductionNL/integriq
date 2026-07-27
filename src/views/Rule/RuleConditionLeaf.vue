<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  RuleConditionLeaf — one predicate in the visual condition tree.

  A leaf is one of:
    - binary: `{ "<op>": [ { "var": "<path>" }, <literal> ] }`
    - unary:  `{ "<op>": [ { "var": "<path>" } ] }`
    - ternary (e.g. `if`, `substr` with length): `{ "<op>": [a, b, c] }`
    - higher-order array op (e.g. `map`, `filter`, `reduce`, `all`,
      `none`, `some`): `{ "<op>": [<collection>, <predicate>] }`
    - `var` reference: `{ "var": "<path>" }` — the leaf renders this as
      a plain text input; the leaf's own variable slot still exists for
      consistency but is hidden when the picked op is `var` itself.

  The operator picker is grouped (comparison / arithmetic / string /
  array / control-flow / negation) so users can scan the list. Closes
  the leaf half of #877.
-->
<template>
	<div class="rule-condition-leaf" data-testid="rule-condition-leaf">
		<NcTextField
			v-if="schema.kind !== 'var-only'"
			class="rule-condition-leaf__var"
			:label="t('openconnector', 'Field')"
			:model-value="varPath"
			:placeholder="t('openconnector', 'e.g. body.status')"
			@update:model-value="onVarInput" />
		<NcTextField
			v-else
			class="rule-condition-leaf__var"
			:label="t('openconnector', 'Variable path')"
			:model-value="varOnlyPath"
			:placeholder="t('openconnector', 'e.g. user.email')"
			@update:model-value="onVarOnlyInput" />
		<NcSelect
			class="rule-condition-leaf__op"
			:input-id="'rule-condition-op-' + uid"
			:input-label="t('openconnector', 'Operator')"
			:model-value="selectedOperator"
			:options="operatorOptions"
			:clearable="false"
			:placeholder="t('openconnector', 'Operator')"
			label="label"
			:reduce="(option) => option"
			@input="onOperatorPick">
			<template #option="{ label, group }">
				<div class="rule-condition-leaf__op-option">
					<span class="rule-condition-leaf__op-option-label">{{ label }}</span>
					<span v-if="group" class="rule-condition-leaf__op-option-group">{{ group }}</span>
				</div>
			</template>
		</NcSelect>
		<template v-if="schema.kind === 'binary' || schema.kind === 'ternary'">
			<NcTextField
				class="rule-condition-leaf__value"
				:label="schema.labels?.[0] || t('openconnector', 'Value')"
				:model-value="slotString(1)"
				:placeholder="schema.placeholders?.[0] || t('openconnector', 'Comparison value')"
				@update:model-value="(value) => onSlotInput(1, value)" />
			<NcTextField
				v-if="schema.kind === 'ternary'"
				class="rule-condition-leaf__value"
				:label="schema.labels?.[1] || t('openconnector', 'Length')"
				:model-value="slotString(2)"
				:placeholder="schema.placeholders?.[1] || ''"
				@update:model-value="(value) => onSlotInput(2, value)" />
		</template>
		<template v-else-if="schema.kind === 'if'">
			<label class="rule-condition-leaf__json-label">
				{{ t('openconnector', 'Then (JsonLogic)') }}
				<textarea
					class="rule-condition-leaf__textarea"
					:value="slotJson(1)"
					spellcheck="false"
					rows="2"
					placeholder="{ &quot;var&quot;: &quot;a&quot; }"
					@input="(event) => onJsonSlotInput(1, event.target.value)" />
			</label>
			<label class="rule-condition-leaf__json-label">
				{{ t('openconnector', 'Else (JsonLogic)') }}
				<textarea
					class="rule-condition-leaf__textarea"
					:value="slotJson(2)"
					spellcheck="false"
					rows="2"
					placeholder="{ &quot;var&quot;: &quot;b&quot; }"
					@input="(event) => onJsonSlotInput(2, event.target.value)" />
			</label>
		</template>
		<template v-else-if="schema.kind === 'array-op'">
			<label class="rule-condition-leaf__json-label">
				{{ schema.labels?.[0] || t('openconnector', 'Collection (JsonLogic)') }}
				<textarea
					class="rule-condition-leaf__textarea"
					:value="slotJson(0, { fallback: '' })"
					spellcheck="false"
					rows="2"
					:placeholder="schema.placeholders?.[0] || '{ &quot;var&quot;: &quot;items&quot; }'"
					@input="(event) => onJsonSlotInput(0, event.target.value)" />
			</label>
			<label class="rule-condition-leaf__json-label">
				{{ schema.labels?.[1] || t('openconnector', 'Predicate (JsonLogic)') }}
				<textarea
					class="rule-condition-leaf__textarea"
					:value="slotJson(1)"
					spellcheck="false"
					rows="2"
					:placeholder="schema.placeholders?.[1] || '{ &quot;==&quot;: [{&quot;var&quot;: &quot;&quot;}, true] }'"
					@input="(event) => onJsonSlotInput(1, event.target.value)" />
			</label>
		</template>
		<template v-else-if="schema.kind === 'merge'">
			<label class="rule-condition-leaf__json-label">
				{{ t('openconnector', 'Arrays to merge (JSON array of JsonLogic)') }}
				<textarea
					class="rule-condition-leaf__textarea"
					:value="mergeJson"
					spellcheck="false"
					rows="3"
					placeholder="[ { &quot;var&quot;: &quot;a&quot; }, [ 1, 2 ] ]"
					@input="(event) => onMergeInput(event.target.value)" />
			</label>
		</template>
		<template v-else-if="schema.kind === 'unary' || schema.kind === 'var-only'">
			<span class="rule-condition-leaf__value rule-condition-leaf__value--placeholder">
				{{ t('openconnector', '(no value needed)') }}
			</span>
		</template>
		<span v-if="parseError" class="rule-condition-leaf__error">
			{{ parseError }}
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
 * Operator schema. `kind` drives which inputs render:
 *
 *   - 'binary'    — 2 args: field + value
 *   - 'unary'     — 1 arg: field only
 *   - 'ternary'   — 3 args: field + value + length (currently `substr`)
 *   - 'if'        — 3 args: condition leaf + then JSON + else JSON
 *   - 'array-op'  — 2 args: collection JSON + predicate JSON
 *   - 'merge'     — variadic: a JSON array of JsonLogic nodes
 *   - 'var-only'  — single dotted-path string emitted as `{ var: "..." }`
 *
 * `group` is purely cosmetic for the picker.
 *
 * @type {Array<{ id: string, label: string, group: string, kind: string,
 *                labels?: string[], placeholders?: string[] }>}
 */
const OPERATORS = [
	// Comparison
	{ id: '==', label: 'equals', group: 'comparison', kind: 'binary' },
	{ id: '!=', label: 'does not equal', group: 'comparison', kind: 'binary' },
	{ id: '>', label: 'greater than', group: 'comparison', kind: 'binary' },
	{ id: '>=', label: 'greater than or equal', group: 'comparison', kind: 'binary' },
	{ id: '<', label: 'less than', group: 'comparison', kind: 'binary' },
	{ id: '<=', label: 'less than or equal', group: 'comparison', kind: 'binary' },
	{ id: 'in', label: 'in (string contains / array member)', group: 'comparison', kind: 'binary' },
	// Negation / existence
	{ id: '!!', label: 'exists / truthy', group: 'negation', kind: 'unary' },
	{ id: '!', label: 'missing / falsy', group: 'negation', kind: 'unary' },
	{ id: 'missing', label: 'missing (list of required paths)', group: 'negation', kind: 'binary', labels: ['Comma-separated paths'], placeholders: ['a,b,c'] },
	// Arithmetic
	{ id: '+', label: 'add', group: 'arithmetic', kind: 'binary' },
	{ id: '-', label: 'subtract', group: 'arithmetic', kind: 'binary' },
	{ id: '*', label: 'multiply', group: 'arithmetic', kind: 'binary' },
	{ id: '/', label: 'divide', group: 'arithmetic', kind: 'binary' },
	{ id: '%', label: 'modulo', group: 'arithmetic', kind: 'binary' },
	// String
	{ id: 'cat', label: 'concatenate strings', group: 'string', kind: 'binary', labels: ['Right operand'] },
	{ id: 'substr', label: 'substring', group: 'string', kind: 'ternary', labels: ['Start', 'Length'], placeholders: ['0', '5'] },
	// Array / higher-order
	{ id: 'map', label: 'map (collection, predicate)', group: 'array', kind: 'array-op' },
	{ id: 'filter', label: 'filter (collection, predicate)', group: 'array', kind: 'array-op' },
	{ id: 'reduce', label: 'reduce (collection, predicate)', group: 'array', kind: 'array-op' },
	{ id: 'all', label: 'all (collection, predicate)', group: 'array', kind: 'array-op' },
	{ id: 'none', label: 'none (collection, predicate)', group: 'array', kind: 'array-op' },
	{ id: 'some', label: 'some (collection, predicate)', group: 'array', kind: 'array-op' },
	{ id: 'merge', label: 'merge arrays', group: 'array', kind: 'merge' },
	// Control flow / refs
	{ id: 'if', label: 'if (condition, then, else)', group: 'control', kind: 'if' },
	{ id: 'var', label: 'var (read value at path)', group: 'control', kind: 'var-only' },
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
		 * The JsonLogic node this leaf renders. Shape is one of the
		 * forms enumerated in the file header. Anything unrecognised
		 * is coerced to an empty `==` leaf so the UI never blanks out.
		 *
		 * @type {object}
		 */
		node: { type: Object, default: () => ({ '==': [{ var: '' }, ''] }) },
	},

	data() {
		return {
			uid: ++leafUidCounter,
			parseError: '',
		}
	},

	computed: {
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		operatorOptions() {
			return OPERATORS.map((op) => ({
				id: op.id,
				label: this.t('openconnector', op.label),
				group: op.group ? this.t('openconnector', op.group) : '',
			}))
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		currentOperator() {
			const keys = Object.keys(this.node || {})
			const op = keys.find((key) => OPERATORS.some((entry) => entry.id === key))
			return op || '=='
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		schema() {
			return OPERATORS.find((entry) => entry.id === this.currentOperator) || OPERATORS[0]
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		selectedOperator() {
			return this.operatorOptions.find((option) => option.id === this.currentOperator)
				?? this.operatorOptions[0]
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		args() {
			const value = this.node?.[this.currentOperator]
			if (Array.isArray(value)) return value
			// `var` accepts a bare string too.
			if (this.currentOperator === 'var') {
				return [typeof value === 'string' ? value : '']
			}
			return []
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		varPath() {
			const first = this.args[0]
			if (first && typeof first === 'object' && Object.prototype.hasOwnProperty.call(first, 'var')) {
				return String(first.var ?? '')
			}
			if (typeof first === 'string') return first
			return ''
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		varOnlyPath() {
			// For top-level `var` ops, args[0] is the dotted-path string.
			const first = this.args[0]
			if (typeof first === 'string') return first
			if (first && typeof first === 'object' && Object.prototype.hasOwnProperty.call(first, 'var')) {
				return String(first.var ?? '')
			}
			return ''
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		mergeJson() {
			try { return JSON.stringify(this.args, null, 2) } catch (_e) { return '[]' }
		},
	},

	methods: {
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		onVarInput(value) {
			this.emitUpdate({ varPath: value })
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		onVarOnlyInput(value) {
			// Emit `{ "var": [<path>] }` — jsonlogic-php accepts both
			// `{ var: "a" }` and `{ var: ["a"] }`; we use the array form
			// here for shape parity with other ops in the tree.
			this.$emit('update', { var: [value] })
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		onOperatorPick(option) {
			if (!option) return
			this.emitUpdate({ operator: option.id })
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		onSlotInput(slot, value) {
			this.emitUpdate({ slot, slotValue: this.coerce(value) })
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		onJsonSlotInput(slot, value) {
			const trimmed = value.trim()
			if (trimmed.length === 0) {
				this.emitUpdate({ slot, slotValue: '' })
				this.parseError = ''
				return
			}
			try {
				const parsed = JSON.parse(trimmed)
				this.parseError = ''
				this.emitUpdate({ slot, slotValue: parsed })
			} catch (parseErr) {
				this.parseError = this.t('openconnector', 'Invalid JSON: {message}', { message: parseErr.message })
			}
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		onMergeInput(value) {
			const trimmed = value.trim()
			if (trimmed.length === 0) {
				this.parseError = ''
				this.$emit('update', { merge: [] })
				return
			}
			try {
				const parsed = JSON.parse(trimmed)
				if (!Array.isArray(parsed)) {
					this.parseError = this.t('openconnector', 'merge expects a JSON array')
					return
				}
				this.parseError = ''
				this.$emit('update', { merge: parsed })
			} catch (parseErr) {
				this.parseError = this.t('openconnector', 'Invalid JSON: {message}', { message: parseErr.message })
			}
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		slotString(index) {
			const raw = this.args[index]
			if (raw === null || raw === undefined) return ''
			if (typeof raw === 'object') {
				try { return JSON.stringify(raw) } catch (_e) { return String(raw) }
			}
			return String(raw)
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		slotJson(index, { fallback = '' } = {}) {
			const raw = this.args[index]
			if (raw === undefined) return fallback
			if (typeof raw === 'string') return raw
			try { return JSON.stringify(raw, null, 2) } catch (_e) { return String(raw) }
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
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
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
		 * resize the args array.
		 *
		 * @param {{ varPath?: string, operator?: string, slot?: number, slotValue?: * }} patch
		 *   Partial change — fields omitted come from current state.
		 * @return {void}
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		emitUpdate(patch) {
			const operator = patch.operator ?? this.currentOperator
			const nextSchema = OPERATORS.find((entry) => entry.id === operator) || OPERATORS[0]

			// Operator switch: rebuild args from scratch using current
			// var/value where possible, falling back to defaults.
			if (patch.operator !== undefined) {
				const args = this.argsForKind(nextSchema, { carryVar: this.varPath, carryValue: this.args[1] })
				this.$emit('update', { [operator]: args })
				return
			}

			// var-only schema funnels through onVarOnlyInput; here we
			// rebuild non-var-only schemas.
			const args = (this.node?.[this.currentOperator] && Array.isArray(this.node[this.currentOperator]))
				? this.node[this.currentOperator].slice()
				: this.argsForKind(nextSchema, { carryVar: this.varPath, carryValue: this.args[1] })

			if (patch.varPath !== undefined) {
				args[0] = { var: patch.varPath }
			}
			if (patch.slot !== undefined) {
				args[patch.slot] = patch.slotValue
			}

			// For unary, trim trailing slots.
			if (nextSchema.kind === 'unary') {
				this.$emit('update', { [operator]: [args[0] ?? { var: this.varPath }] })
				return
			}

			this.$emit('update', { [operator]: args })
		},
		/**
		 * Build a fresh args array for a given schema kind. Used when
		 * switching ops so the new shape is well-formed.
		 *
		 * @param {object} schema Operator schema.
		 * @param {{ carryVar: string, carryValue: * }} carry Best-effort
		 *   reuse of the current var/value across an op change.
		 * @return {Array} Initial args array.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		argsForKind(schema, { carryVar = '', carryValue = '' } = {}) {
			switch (schema.kind) {
			case 'unary':
				return [{ var: carryVar }]
			case 'binary':
				return [{ var: carryVar }, carryValue ?? '']
			case 'ternary':
				return [{ var: carryVar }, carryValue ?? '', '']
			case 'if':
				return [{ var: carryVar }, '', '']
			case 'array-op':
				return [{ var: carryVar }, { '==': [{ var: '' }, true] }]
			case 'merge':
				return [{ var: carryVar }]
			case 'var-only':
				return [carryVar]
			default:
				return [{ var: carryVar }, carryValue ?? '']
			}
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

.rule-condition-leaf__json-label {
	display: flex;
	flex-direction: column;
	gap: 4px;
	grid-column: 1 / -2;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.rule-condition-leaf__textarea {
	width: 100%;
	padding: 6px 8px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 12px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.rule-condition-leaf__error {
	grid-column: 1 / -2;
	font-size: 12px;
	color: var(--color-error);
}

.rule-condition-leaf__op-option {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
}

.rule-condition-leaf__op-option-group {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
}

@media (max-width: 720px) {
	.rule-condition-leaf {
		grid-template-columns: 1fr;
	}
}
</style>

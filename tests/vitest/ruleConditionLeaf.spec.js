/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for RuleConditionLeaf (src/views/Rule/RuleConditionLeaf.vue), one
 * JsonLogic leaf of the rule condition builder: how it reads a node, how it
 * reshapes the arguments when the operator changes, how typed input is
 * coerced, and how JSON slots and merge input are parsed. The component logic
 * runs over a plain context; emitted updates are recorded.
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('vue-material-design-icons/Close.vue', () => ({ default: {} }))

const leaf = (await import('@/views/Rule/RuleConditionLeaf.vue')).default

/**
 * A component context for `node`, with every computed as a getter, every
 * method bound and emitted updates recorded.
 *
 * @param {object|null} node The JsonLogic node.
 * @return {object} The context.
 */
function vm(node) {
	const ctx = {
		node,
		updates: [],
		t: (app, text, vars) => text.replace(/\{(\w+)\}/g, (match, key) => vars?.[key] ?? match),
	}
	ctx.$emit = (event, payload) => ctx.updates.push([event, payload])
	Object.assign(ctx, leaf.data.call(ctx))
	for (const [name, getter] of Object.entries(leaf.computed)) {
		Object.defineProperty(ctx, name, { get: () => getter.call(ctx) })
	}
	for (const [name, method] of Object.entries(leaf.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

describe('reading a node', () => {
	it('finds the operator among the node keys and reads its arguments and path', () => {
		const form = vm({ '>=': [{ var: 'age' }, 18] })
		expect(form.currentOperator).toBe('>=')
		expect(form.schema.kind).toBe('binary')
		expect(form.selectedOperator).toMatchObject({ id: '>=', label: 'greater than or equal', group: 'comparison' })
		expect(form.args).toEqual([{ var: 'age' }, 18])
		expect(form.varPath).toBe('age')
	})

	it('falls back to equals for an empty or unknown node', () => {
		expect(vm(null).currentOperator).toBe('==')
		expect(vm({ unknown: [1] }).currentOperator).toBe('==')
		expect(vm({ unknown: [1] }).args).toEqual([])
	})

	it('reads a bare var node as a one-path argument list', () => {
		const form = vm({ var: 'status' })
		expect(form.args).toEqual(['status'])
		expect(form.varOnlyPath).toBe('status')
		expect(vm({ var: 5 }).args).toEqual([''])
	})

	it('reads the path from a var object or a plain string, else empty', () => {
		expect(vm({ '==': ['plain', 1] }).varPath).toBe('plain')
		expect(vm({ '==': [{ var: null }, 1] }).varPath).toBe('')
		expect(vm({ '==': [3, 1] }).varPath).toBe('')
		expect(vm({ var: [{ var: 'nested' }] }).varOnlyPath).toBe('nested')
		expect(vm({ var: [7] }).varOnlyPath).toBe('')
	})

	it('shows merge arguments as indented JSON', () => {
		expect(vm({ merge: [[1], { var: 'a' }] }).mergeJson).toBe('[\n  [\n    1\n  ],\n  {\n    "var": "a"\n  }\n]')
	})

	it('lists every operator with its group', () => {
		const ids = vm(null).operatorOptions.map((option) => option.id)
		expect(ids).toContain('substr')
		expect(ids).toContain('merge')
		expect(ids).toHaveLength(26)
	})
})

describe('changing the operator', () => {
	it.each([
		['!!', { '!!': [{ var: 'age' }] }],
		['!=', { '!=': [{ var: 'age' }, 18] }],
		['substr', { substr: [{ var: 'age' }, 18, ''] }],
		['if', { if: [{ var: 'age' }, '', ''] }],
		['filter', { filter: [{ var: 'age' }, { '==': [{ var: '' }, true] }] }],
		['merge', { merge: [{ var: 'age' }] }],
		['var', { var: ['age'] }],
	])('to %s carries the path and value into its argument shape', (operator, expected) => {
		const form = vm({ '==': [{ var: 'age' }, 18] })
		form.onOperatorPick({ id: operator })
		expect(form.updates).toEqual([['update', expected]])
	})

	it('ignores a cleared operator selection', () => {
		const form = vm({ '==': [{ var: 'a' }, 1] })
		form.onOperatorPick(null)
		expect(form.updates).toEqual([])
	})
})

describe('editing slots', () => {
	it.each([
		['', ''],
		['true', true],
		['false', false],
		['null', null],
		['42', 42],
		['-7', -7],
		['3.14', 3.14],
		['1e3', '1e3'],
		['open', 'open'],
	])('coerces %j to %j', (raw, value) => {
		expect(vm(null).coerce(raw)).toBe(value)
	})

	it('writes a typed slot value into a copy of the arguments', () => {
		const node = { '==': [{ var: 'age' }, 1] }
		const form = vm(node)
		form.onSlotInput(1, '30')
		expect(form.updates).toEqual([['update', { '==': [{ var: 'age' }, 30] }]])
		expect(node['==']).toEqual([{ var: 'age' }, 1])
	})

	it('writes a new path, and a unary operator keeps only its path', () => {
		const binary = vm({ '<': [{ var: 'a' }, 2] })
		binary.onVarInput('b')
		expect(binary.updates).toEqual([['update', { '<': [{ var: 'b' }, 2] }]])

		const unary = vm({ '!': [{ var: 'a' }, 'stray'] })
		unary.onVarInput('c')
		expect(unary.updates).toEqual([['update', { '!': [{ var: 'c' }] }]])
	})

	it('builds the arguments when the node holds none yet', () => {
		const form = vm({ '==': 'not a list' })
		form.onSlotInput(1, 'x')
		expect(form.updates).toEqual([['update', { '==': [{ var: '' }, 'x'] }]])
	})

	it('sets a var-only node to its new path', () => {
		const form = vm({ var: 'a' })
		form.onVarOnlyInput('b.c')
		expect(form.updates).toEqual([['update', { var: ['b.c'] }]])
	})
})

describe('JSON slots', () => {
	it('parses valid JSON into the slot and clears the error', () => {
		const form = vm({ filter: [{ var: 'items' }, true] })
		form.parseError = 'old'
		form.onJsonSlotInput(1, ' {"==": [{"var": "x"}, 1]} ')
		expect(form.parseError).toBe('')
		expect(form.updates).toEqual([['update', { filter: [{ var: 'items' }, { '==': [{ var: 'x' }, 1] }] }]])
	})

	it('empties the slot for blank input', () => {
		const form = vm({ if: [{ var: 'a' }, 1, 2] })
		form.onJsonSlotInput(2, '   ')
		expect(form.updates).toEqual([['update', { if: [{ var: 'a' }, 1, ''] }]])
	})

	it('reports invalid JSON and emits nothing', () => {
		const form = vm({ if: [{ var: 'a' }, 1, 2] })
		form.onJsonSlotInput(1, '{oops')
		expect(form.parseError).toMatch(/^Invalid JSON: /)
		expect(form.updates).toEqual([])
	})

	it('shows a slot as text or JSON', () => {
		const form = vm({ if: [{ var: 'a' }, 'yes', null, 5] })
		expect(form.slotString(0)).toBe('{"var":"a"}')
		expect(form.slotString(1)).toBe('yes')
		expect(form.slotString(2)).toBe('')
		expect(form.slotString(3)).toBe('5')
		expect(form.slotJson(0)).toBe('{\n  "var": "a"\n}')
		expect(form.slotJson(1)).toBe('yes')
		expect(form.slotJson(9, { fallback: 'none' })).toBe('none')
	})
})

describe('merge input', () => {
	it('emits a parsed array', () => {
		const form = vm({ merge: [] })
		form.onMergeInput('[[1], [2]]')
		expect(form.updates).toEqual([['update', { merge: [[1], [2]] }]])
		expect(form.parseError).toBe('')
	})

	it('emits an empty merge for blank input', () => {
		const form = vm({ merge: [1] })
		form.onMergeInput('  ')
		expect(form.updates).toEqual([['update', { merge: [] }]])
	})

	it('refuses JSON that is not an array, and invalid JSON', () => {
		const form = vm({ merge: [] })
		form.onMergeInput('{"a": 1}')
		expect(form.parseError).toBe('merge expects a JSON array')
		form.onMergeInput('[1,')
		expect(form.parseError).toMatch(/^Invalid JSON: /)
		expect(form.updates).toEqual([])
	})
})

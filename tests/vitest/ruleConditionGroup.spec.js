/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for RuleConditionGroup (src/views/Rule/RuleConditionGroup.vue), the
 * recursive AND/OR group of the rule condition builder: how it reads a group
 * node, which children it treats as sub-groups, and the node it emits when the
 * operator changes or a child is added, replaced or removed. The component
 * logic runs over a plain context; emitted events are recorded.
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcEmptyContent: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('vue-material-design-icons/Close.vue', () => ({ default: {} }))
vi.mock('vue-material-design-icons/FilterVariant.vue', () => ({ default: {} }))
vi.mock('vue-material-design-icons/FormatListGroup.vue', () => ({ default: {} }))
vi.mock('vue-material-design-icons/Plus.vue', () => ({ default: {} }))

const group = (await import('@/views/Rule/RuleConditionGroup.vue')).default

/**
 * A component context for `node`, with every computed as a getter, every
 * method bound and emitted events recorded.
 *
 * @param {object|null} node The JsonLogic group node.
 * @return {object} The context.
 */
function vm(node) {
	const ctx = {
		node,
		events: [],
		t: (app, text) => text,
	}
	ctx.$emit = (event, payload) => ctx.events.push([event, payload])
	Object.assign(ctx, group.data.call(ctx))
	for (const [name, getter] of Object.entries(group.computed)) {
		Object.defineProperty(ctx, name, { get: () => getter.call(ctx) })
	}
	for (const [name, method] of Object.entries(group.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

const leafA = { '==': [{ var: 'a' }, 1] }
const leafB = { '>': [{ var: 'b' }, 2] }

describe('reading a group node', () => {
	it('reads an AND group and its children', () => {
		const form = vm({ and: [leafA, leafB] })
		expect(form.currentOperator).toBe('and')
		expect(form.children).toEqual([leafA, leafB])
		expect(form.selectedOperator).toEqual({ id: 'and', label: 'ALL of (AND)' })
		expect(form.operatorHint).toBe('Every child below must match.')
	})

	it('reads an OR group and says one child is enough', () => {
		const form = vm({ or: [leafA] })
		expect(form.currentOperator).toBe('or')
		expect(form.selectedOperator).toEqual({ id: 'or', label: 'ANY of (OR)' })
		expect(form.operatorHint).toBe('At least one child below must match.')
	})

	it('falls back to an empty AND group for a missing or foreign node', () => {
		expect(vm(null).currentOperator).toBe('and')
		expect(vm(null).children).toEqual([])
		expect(vm({ '!': [leafA] }).currentOperator).toBe('and')
		expect(vm({ '!': [leafA] }).children).toEqual([])
	})

	it('has no children when the operand is not an array', () => {
		expect(vm({ or: 'nope' }).children).toEqual([])
	})

	it('offers exactly the two boolean operators', () => {
		expect(vm(null).operatorOptions.map((option) => option.id)).toEqual(['and', 'or'])
	})
})

describe('classifying children', () => {
	const form = vm({ and: [] })

	it('treats a single and/or key with an array value as a sub-group', () => {
		expect(form.isGroup({ and: [] })).toBe(true)
		expect(form.isGroup({ or: [leafA] })).toBe(true)
	})

	it('treats everything else as a leaf', () => {
		expect(form.isGroup(null)).toBe(false)
		expect(form.isGroup('and')).toBe(false)
		expect(form.isGroup([leafA])).toBe(false)
		expect(form.isGroup(leafA)).toBe(false)
		expect(form.isGroup({ and: 'x' })).toBe(false)
		expect(form.isGroup({ and: [], or: [] })).toBe(false)
	})
})

describe('editing the group', () => {
	it('re-keys the children under a newly picked operator', () => {
		const form = vm({ and: [leafA, leafB] })
		form.onOperatorPick({ id: 'or', label: 'ANY of (OR)' })
		expect(form.events).toEqual([['update', { or: [leafA, leafB] }]])
	})

	it('ignores a cleared operator pick', () => {
		const form = vm({ and: [leafA] })
		form.onOperatorPick(null)
		expect(form.events).toEqual([])
	})

	it('replaces one child and leaves the node it was given untouched', () => {
		const node = { or: [leafA, leafB] }
		const form = vm(node)
		form.updateChild(1, { and: [] })
		expect(form.events).toEqual([['update', { or: [leafA, { and: [] }] }]])
		expect(node.or).toEqual([leafA, leafB])
	})

	it('removes one child', () => {
		const form = vm({ and: [leafA, leafB] })
		form.removeChild(0)
		expect(form.events).toEqual([['update', { and: [leafB] }]])
	})

	it('adds an empty equals leaf at the end', () => {
		const form = vm({ or: [leafA] })
		form.addLeaf()
		expect(form.events).toEqual([['update', { or: [leafA, { '==': [{ var: '' }, ''] }] }]])
	})

	it('adds an empty AND sub-group at the end', () => {
		const form = vm({ and: [] })
		form.addGroup()
		expect(form.events).toEqual([['update', { and: [{ and: [] }] }]])
	})
})

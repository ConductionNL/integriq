/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The row helpers and keyboard reorder of one rule table of the mapping rules
 * editor (src/components/mapping/RulesTable.vue), run on a plain context
 * built from the component's computed and methods.
 *
 * @spec openspec/specs/mapping-editor-ui/spec.md
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({ NcButton: {} }))
vi.mock('vue-draggable-plus', () => ({ VueDraggable: {} }))

const table = (await import('@/components/mapping/RulesTable.vue')).default

/**
 * A plain context carrying the table's props and methods.
 *
 * @param {Array} rows The rows prop.
 * @return {{ctx: object, emitted: Array}} The context and its emitted events.
 */
function context(rows) {
	const emitted = []
	const ctx = { rows }
	for (const [name, fn] of Object.entries(table.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	ctx.$emit = (...args) => emitted.push(args)
	return { ctx, emitted }
}

describe('RulesTable', () => {
	it('labels a record row by its key and a plain row by itself', () => {
		const { ctx } = context([])
		expect(ctx.labelOf({ key: 'title', value: '{{ name }}' })).toBe('title')
		expect(ctx.labelOf('secret')).toBe('secret')
	})

	it('keys a plain row with its index and a record row with its key', () => {
		const { ctx } = context([])
		expect(ctx.keyOf('secret', 3)).toBe('secret-3')
		expect(ctx.keyOf({ key: 'title', value: 'x' }, 3)).toBe('title')
	})

	it('moves a row down, emitting the new order and then reorder', () => {
		const rows = ['a', 'b', 'c']
		const { ctx, emitted } = context(rows)
		ctx.move(0, 1)
		expect(emitted).toEqual([['update:rows', ['b', 'a', 'c']], ['reorder']])
		expect(rows).toEqual(['a', 'b', 'c'])
	})

	it('moves a row up', () => {
		const { ctx, emitted } = context(['a', 'b', 'c'])
		ctx.move(2, -1)
		expect(emitted).toEqual([['update:rows', ['a', 'c', 'b']], ['reorder']])
	})

	it('does not move the first row up', () => {
		const { ctx, emitted } = context(['a', 'b'])
		ctx.move(0, -1)
		expect(emitted).toEqual([])
	})

	it('does not move the last row down', () => {
		const { ctx, emitted } = context(['a', 'b'])
		ctx.move(1, 1)
		expect(emitted).toEqual([])
	})

	it('reads the list from the rows prop and writes it back as update:rows', () => {
		const rows = [{ key: 'a', value: '1' }]
		const { ctx, emitted } = context(rows)
		expect(table.computed.list.get.call(ctx)).toBe(rows)
		table.computed.list.set.call(ctx, ['x'])
		expect(emitted).toEqual([['update:rows', ['x']]])
	})
})

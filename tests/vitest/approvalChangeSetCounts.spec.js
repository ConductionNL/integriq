/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The change set view of an approval request
 * (src/components/Approvals/ApprovalChangeSet.vue): its counts, the objects
 * of the selected tab, and how a field value is shown. Runs on a plain
 * context built from the component's data(), computed and methods.
 *
 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
 */
import { describe, expect, it } from 'vitest'
import changeSetView from '@/components/Approvals/ApprovalChangeSet.vue'

/**
 * A plain context for the given change set.
 *
 * @param {object} changeSet The changeSet prop.
 * @return {object} The context.
 */
function context(changeSet) {
	const ctx = { changeSet }
	Object.assign(ctx, changeSetView.data.call(ctx))
	for (const [name, fn] of Object.entries(changeSetView.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	for (const [name, getter] of Object.entries(changeSetView.computed)) {
		Object.defineProperty(ctx, name, { get: () => getter.call(ctx) })
	}
	return ctx
}

describe('ApprovalChangeSet', () => {
	it('lists the tabs created, changed, removed in that order', () => {
		expect(context({}).tabs).toEqual([
			{ key: 'created', label: 'Created' },
			{ key: 'changed', label: 'Changed' },
			{ key: 'removed', label: 'Removed' },
		])
	})

	it('opens on the created tab with a per-instance uid', () => {
		const a = context({})
		const b = context({})
		expect(a.active).toBe('created')
		expect(b.uid).toBe(a.uid + 1)
	})

	it('takes the stored counts, which hold past the cut', () => {
		const ctx = context({
			counts: { created: 120, changed: 0, removed: 3, unchanged: 40 },
			created: [{ id: 1 }],
			changed: [{ id: 2 }],
			removed: [],
			unchanged: 9,
		})
		expect(ctx.counts).toEqual({
			created: 120,
			changed: 0,
			removed: 3,
			unchanged: 40,
		})
	})

	it('falls back to the list lengths and the bare unchanged count', () => {
		const ctx = context({
			created: [{ id: 1 }, { id: 2 }],
			changed: [{ id: 3 }],
			unchanged: 5,
		})
		expect(ctx.counts).toEqual({
			created: 2,
			changed: 1,
			removed: 0,
			unchanged: 5,
		})
	})

	it('counts zero for an empty change set', () => {
		expect(context({}).counts).toEqual({
			created: 0,
			changed: 0,
			removed: 0,
			unchanged: 0,
		})
	})

	it('shows the objects of the active tab', () => {
		const changeSet = { created: [{ id: 1 }], removed: [{ id: 9 }] }
		const ctx = context(changeSet)
		expect(ctx.items).toBe(changeSet.created)
		ctx.active = 'removed'
		expect(ctx.items).toBe(changeSet.removed)
		ctx.active = 'changed'
		expect(ctx.items).toEqual([])
	})

	it.each([
		[null, '(empty)'],
		[undefined, '(empty)'],
		['text', 'text'],
		['', ''],
		[0, '0'],
		[false, 'false'],
		[['a', 1], '["a",1]'],
		[{ a: { b: null } }, '{"a":{"b":null}}'],
	])('shows %j as %j', (value, text) => {
		expect(context({}).show(value)).toBe(text)
	})
})

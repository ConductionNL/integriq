/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Object rights on the action authorization screen: the "every signed-in
 * user" column on a section of object rights, the warning on reading sources
 * and consumers, and the notice naming schemas whose rights were not applied
 * (src/views/admin/actionMatrix.js and ActionAuthMatrix.vue).
 *
 * @spec openspec/changes/archive/2026-11-25-platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-per-configuration-schema-are-set-on-the-screen-req-arc-003
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { isObjectRight, savePayload, sectionColumns, warnsOnGrant } from '@/views/admin/actionMatrix.js'

const calls = vi.hoisted(() => ({ put: [], errors: [], successes: [] }))

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: () => Promise.resolve({
			data: {
				actions: ['object.mapping.update', 'object.source.read', 'job.run'],
				groups: ['admin', 'integratie-beheer'],
				matrix: { 'object.mapping.update': ['admin', 'authenticated'], 'object.source.read': ['admin'] },
				labels: { 'object.mapping.update': 'Edit mappings', 'object.source.read': 'Read sources' },
				areas: { 'object.mapping.update': 'Configuration objects', 'object.source.read': 'Configuration objects', 'job.run': 'Jobs' },
			},
		}),
		put: (url, body) => {
			calls.put.push(body)
			return Promise.resolve({ data: { matrix: body.matrix, notApplied: ['mapping'] } })
		},
	},
}))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => calls.errors.push(text),
	showSuccess: (text) => calls.successes.push(text),
}))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {} }))

describe('object rights helpers', () => {
	it('recognises object rights by schema and verb', () => {
		expect(isObjectRight('object.event_subscription.delete')).toBe(true)
		expect(isObjectRight('object.mapping.approve')).toBe(false)
		expect(isObjectRight('job.run')).toBe(false)
		expect(isObjectRight(undefined)).toBe(false)
	})

	it('adds every signed-in user only to a section of object rights', () => {
		const groups = ['integratie-beheer', 'admin', 'authenticated']
		expect(sectionColumns({ actions: [{ name: 'object.mapping.update' }] }, groups)).toEqual(['admin', 'authenticated', 'integratie-beheer'])
		expect(sectionColumns({ actions: [{ name: 'job.run' }] }, groups)).toEqual(['admin', 'integratie-beheer'])
		expect(sectionColumns(null, null)).toEqual(['admin'])
	})

	it('warns on reading sources and consumers only', () => {
		expect(warnsOnGrant('object.source.read')).toBe(true)
		expect(warnsOnGrant('object.consumer.read')).toBe(true)
		expect(warnsOnGrant('object.source.update')).toBe(false)
		expect(warnsOnGrant('object.mapping.read')).toBe(false)
	})

	it('keeps every signed-in user in the saved row', () => {
		expect(savePayload(['object.mapping.update'], { 'object.mapping.update': ['authenticated', 'admin'] }))
			.toEqual({ 'object.mapping.update': ['admin', 'authenticated'] })
	})
})

describe('the screen', () => {
	beforeEach(() => {
		calls.put.length = 0
		calls.errors.length = 0
		calls.successes.length = 0
	})

	/**
	 * The component's options bound to a plain context.
	 *
	 * @return {Promise<{component: object, ctx: object}>} The component and its context.
	 */
	async function mounted() {
		const component = (await import('@/views/admin/ActionAuthMatrix.vue')).default
		const ctx = { ...component.data(), t: (app, text, vars) => text.replace(/\{(\w+)\}/g, (m, key) => (vars && key in vars ? vars[key] : m)) }
		for (const [name, fn] of Object.entries(component.methods)) {
			ctx[name] = typeof fn === 'function' ? fn.bind(ctx) : fn
		}
		await ctx.load()
		return { component, ctx }
	}

	it('shows every signed-in user as a column of the object rights and ticks it where stored', async () => {
		const { component, ctx } = await mounted()
		const sections = component.computed.sections.call(ctx)
		const objects = sections.find((section) => section.area === 'Configuration objects')

		expect(ctx.columnsOf(objects)).toEqual(['admin', 'authenticated', 'integratie-beheer'])
		expect(ctx.columnTitle('authenticated')).toBe('Every signed-in user')
		expect(ctx.columnTitle('integratie-beheer')).toBe('integratie-beheer')
		expect(ctx.isChecked('object.mapping.update', 'authenticated')).toBe(true)
		expect(ctx.isChecked('object.source.read', 'authenticated')).toBe(false)
	})

	it('narrows an object right and names a schema that was not applied', async () => {
		const { ctx } = await mounted()

		ctx.toggle('object.mapping.update', 'authenticated', false)
		ctx.toggle('object.mapping.update', 'integratie-beheer', true)
		await ctx.save()

		expect(calls.put[0].matrix['object.mapping.update']).toEqual(['admin', 'integratie-beheer'])
		expect(calls.successes).toEqual(['Action matrix saved.'])
		expect(calls.errors).toEqual(['The object rights of these schemas were not applied: mapping'])
	})
})

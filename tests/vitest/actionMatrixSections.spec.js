/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The action authorization screen groups actions by area, each with its
 * label and its name (src/views/admin/actionMatrix.js actionSections and
 * ActionAuthMatrix.vue).
 *
 * @spec openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-the-screen-groups-actions-by-area-with-labels-req-arc-002
 */
import { describe, expect, it, vi } from 'vitest'
import { actionSections } from '@/views/admin/actionMatrix.js'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: () => Promise.resolve({
			data: {
				actions: ['stuf-zkn.push', 'sms.send', 'job.run'],
				groups: ['admin', 'integratie-beheer'],
				matrix: { 'stuf-zkn.push': ['integratie-beheer'] },
				labels: { 'stuf-zkn.push': 'Push through the StUF-ZKN bridge', 'sms.send': 'Send a text message' },
				areas: { 'stuf-zkn.push': 'Adapters', 'sms.send': 'Notifications', 'job.run': 'Jobs' },
			},
		}),
	},
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: () => {}, showSuccess: () => {} }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {} }))

describe('actionSections', () => {
	it('makes one section per area, ordered by area and then by action', () => {
		const sections = actionSections(
			['sms.status', 'stuf-zkn.push', 'sms.send', 'kiss.push'],
			{ 'sms.status': 'Notifications', 'sms.send': 'Notifications', 'stuf-zkn.push': 'Adapters', 'kiss.push': 'Adapters' },
			{ 'sms.send': 'Send a text message', 'sms.status': 'Read a text message status', 'stuf-zkn.push': 'Push through the StUF-ZKN bridge', 'kiss.push': 'Push to KISS' },
		)

		expect(sections).toEqual([
			{ area: 'Adapters', actions: [{ name: 'kiss.push', label: 'Push to KISS' }, { name: 'stuf-zkn.push', label: 'Push through the StUF-ZKN bridge' }] },
			{ area: 'Notifications', actions: [{ name: 'sms.send', label: 'Send a text message' }, { name: 'sms.status', label: 'Read a text message status' }] },
		])
	})

	it('puts an action without an area last and labels an action without a label by its name', () => {
		const sections = actionSections(['old.action', 'job.run'], { 'job.run': 'Jobs' }, null)

		expect(sections).toEqual([
			{ area: 'Jobs', actions: [{ name: 'job.run', label: 'job.run' }] },
			{ area: '', actions: [{ name: 'old.action', label: 'old.action' }] },
		])
	})

	it('reads missing areas and labels as none', () => {
		expect(actionSections(['a.b'], undefined, 'no')).toEqual([{ area: '', actions: [{ name: 'a.b', label: 'a.b' }] }])
	})
})

describe('the screen', () => {
	it('loads the labels and areas and builds its sections from them', async () => {
		const component = (await import('@/views/admin/ActionAuthMatrix.vue')).default
		const ctx = { ...component.data(), t: (app, text) => text }
		for (const [name, fn] of Object.entries(component.methods)) {
			ctx[name] = fn.bind(ctx)
		}

		await ctx.load()
		const sections = component.computed.sections.call(ctx)

		expect(sections.map((section) => section.area)).toEqual(['Adapters', 'Jobs', 'Notifications'])
		expect(sections[0].actions).toEqual([{ name: 'stuf-zkn.push', label: 'Push through the StUF-ZKN bridge' }])
		expect(sections[1].actions).toEqual([{ name: 'job.run', label: 'job.run' }])
		expect(ctx.isChecked('stuf-zkn.push', 'integratie-beheer')).toBe(true)
	})
})

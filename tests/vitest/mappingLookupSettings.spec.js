// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lookup allowlist section of the admin settings (mapping-formats-and-lookups
 * task 5): it lists each schema with who added it and when, adds and removes
 * one, and shows the backend's refusal.
 *
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MappingLookupSettings, {
	removeUrl,
} from '@/views/admin/MappingLookupSettings.vue'

const { get, post, del } = vi.hoisted(() => ({
	get: vi.fn(),
	post: vi.fn(),
	del: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: { get, post, delete: del } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn() }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props = []) =>
		defineComponent({
			name,
			props,
			emits: ['click', 'update:modelValue'],
			render() {
				return h(
					'div',
					{ class: name, onClick: () => this.$emit('click') },
					this.$slots.default?.(),
				)
			},
		})
	return {
		NcButton: stub('NcButton', ['variant', 'disabled']),
		NcTextField: stub('NcTextField', ['modelValue', 'label']),
	}
})

const LISTED = {
	schemas: [
		{
			schema: 'codelijsten/gemeenten',
			addedBy: 'beheer',
			addedAt: '2026-11-14T09:00:00+00:00',
		},
	],
}

describe('the lookup allowlist section', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		del.mockReset()
		get.mockResolvedValue({ data: LISTED })
	})

	it('lists each schema with who added it and when', async () => {
		const wrapper = mount(MappingLookupSettings)
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/apps/integriq/api/admin/mapping-lookups')
		const rows = wrapper.findAll('[data-testid="admin-mapping-lookups-row"]')
		expect(rows).toHaveLength(1)
		expect(rows[0].text()).toContain('codelijsten/gemeenten')
		expect(rows[0].text()).toContain('beheer')
		expect(rows[0].text()).toContain('2026-11-14')
	})

	it('says an empty list lets a mapping look up in nothing', async () => {
		get.mockResolvedValue({ data: { schemas: [] } })
		const wrapper = mount(MappingLookupSettings)
		await flushPromises()

		expect(
			wrapper.find('[data-testid="admin-mapping-lookups-empty"]').exists(),
		).toBe(true)
	})

	it('adds a schema and reloads the list', async () => {
		post.mockResolvedValueOnce({
			status: 201,
			data: { schema: 'codelijsten/landen' },
		})
		const wrapper = mount(MappingLookupSettings)
		await flushPromises()
		wrapper.vm.newSchema = ' codelijsten/landen '

		await wrapper
			.find('[data-testid="admin-mapping-lookups-add"]')
			.trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(
			'/apps/integriq/api/admin/mapping-lookups',
			{ schema: 'codelijsten/landen' },
		)
		expect(get).toHaveBeenCalledTimes(2)
		expect(wrapper.vm.newSchema).toBe('')
	})

	it('shows the reason when the backend refuses, and keeps the list', async () => {
		post.mockRejectedValueOnce({
			response: {
				status: 403,
				data: {
					error: 'Only an instance administrator may change which schemas a mapping may look up in.',
				},
			},
		})
		const wrapper = mount(MappingLookupSettings)
		await flushPromises()
		wrapper.vm.newSchema = 'codelijsten/*'

		await wrapper
			.find('[data-testid="admin-mapping-lookups-add"]')
			.trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toContain(
			'Only an instance administrator',
		)
		expect(get).toHaveBeenCalledTimes(1)
	})

	it('removes a schema by register and schema', async () => {
		del.mockResolvedValueOnce({ data: { schema: 'codelijsten/gemeenten' } })
		const wrapper = mount(MappingLookupSettings)
		await flushPromises()

		await wrapper.vm.remove('codelijsten/gemeenten')
		await flushPromises()

		expect(del).toHaveBeenCalledWith(
			'/apps/integriq/api/admin/mapping-lookups/codelijsten/gemeenten',
		)
		expect(get).toHaveBeenCalledTimes(2)
		expect(removeUrl('a b/c')).toBe(
			'/apps/integriq/api/admin/mapping-lookups/a%20b/c',
		)
	})
})

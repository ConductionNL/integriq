// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The environment allowlist on integriq's admin settings page
 * (allowlisted-expression-sources task 3, REQ-EVS-003).
 *
 * /api/admin/expression-sources had no caller in src/. These tests mount the
 * real section and assert what it sends and shows: names with who added them
 * and when, never a value; an add the backend refuses shows the backend's
 * reason and leaves the list as it was.
 *
 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md#requirement-the-allowlist-is-administered-and-every-change-is-recorded-req-evs-003
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ExpressionSourceSettings from '@/views/admin/ExpressionSourceSettings.vue'

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
	keys: [
		{ key: 'SMTP_HOST', addedBy: 'admin', addedAt: '2026-09-29T09:00:00+00:00' },
	],
	sources: [{ prefix: 'env', writable: false, allowlisted: true }],
}

describe('the environment allowlist section', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		del.mockReset()
		get.mockResolvedValue({ data: LISTED })
	})

	it('lists each name with who added it and when, and no value', async () => {
		const wrapper = mount(ExpressionSourceSettings)
		await flushPromises()

		expect(get).toHaveBeenCalledWith(
			'/apps/integriq/api/admin/expression-sources',
		)
		const row = wrapper
			.find('[data-testid="admin-expression-sources-row"]')
			.text()
		expect(row).toContain('SMTP_HOST')
		expect(row).toContain('admin')
		expect(row).toContain('2026-09-29')
		expect(
			wrapper.findAll('[data-testid="admin-expression-sources-row"]'),
		).toHaveLength(1)
	})

	it('says an empty list lets an expression read nothing', async () => {
		get.mockResolvedValue({ data: { keys: [], sources: [] } })
		const wrapper = mount(ExpressionSourceSettings)
		await flushPromises()

		expect(
			wrapper.find('[data-testid="admin-expression-sources-empty"]').exists(),
		).toBe(true)
	})

	it('adds a name by its exact spelling and reloads the list', async () => {
		post.mockResolvedValueOnce({ status: 201, data: { key: 'SMTP_PORT' } })
		const wrapper = mount(ExpressionSourceSettings)
		await flushPromises()
		wrapper.vm.newKey = ' SMTP_PORT '

		await wrapper
			.find('[data-testid="admin-expression-sources-add"]')
			.trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(
			'/apps/integriq/api/admin/expression-sources/env',
			{
				key: 'SMTP_PORT',
			},
		)
		expect(get).toHaveBeenCalledTimes(2)
		expect(wrapper.vm.newKey).toBe('')
	})

	it('shows the reason when the backend refuses a name, and keeps the list', async () => {
		post.mockRejectedValueOnce({
			response: {
				status: 422,
				data: { error: '"DATABASE_PASSWORD" cannot be listed.' },
			},
		})
		const wrapper = mount(ExpressionSourceSettings)
		await flushPromises()
		wrapper.vm.newKey = 'DATABASE_PASSWORD'

		await wrapper
			.find('[data-testid="admin-expression-sources-add"]')
			.trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toContain('DATABASE_PASSWORD')
		expect(get).toHaveBeenCalledTimes(1)
		expect(
			wrapper.findAll('[data-testid="admin-expression-sources-row"]'),
		).toHaveLength(1)
	})

	it('removes a name', async () => {
		del.mockResolvedValueOnce({ data: { key: 'SMTP_HOST' } })
		const wrapper = mount(ExpressionSourceSettings)
		await flushPromises()

		await wrapper
			.find('[data-testid="admin-expression-sources-row"] .NcButton')
			.trigger('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith(
			'/apps/integriq/api/admin/expression-sources/env/SMTP_HOST',
		)
	})
})

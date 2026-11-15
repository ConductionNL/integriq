// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of ConnectionAlertSettings: its markup while loading and
 * loaded, how it reads the stored group, what it saves (trimmed) and the
 * error text of each failure.
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ConnectionAlertSettings from '@/views/admin/ConnectionAlertSettings.vue'

const { get, put, showSuccess } = vi.hoisted(() => ({
	get: vi.fn(),
	put: vi.fn(),
	showSuccess: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: { get, put } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, tag = 'div') =>
		defineComponent({
			name,
			props: ['modelValue', 'label', 'variant', 'disabled'],
			emits: ['update:modelValue'],
			render() {
				return h(
					tag,
					{
						class: name,
						'data-label': this.label,
						'data-value': this.modelValue,
						disabled: this.disabled || undefined,
					},
					this.$slots.default?.(),
				)
			},
		})
	return { NcButton: stub('NcButton', 'button'), NcTextField: stub('NcTextField') }
})

const URL = '/index.php/apps/integriq/api/admin/connection-alert-group'

/**
 * The rendered markup without scoped-style ids and HTML comments.
 *
 * @param {object} wrapper the mounted section
 * @return {string} the markup
 */
function markup(wrapper) {
	return wrapper
		.html()
		.replace(/ data-v-[0-9a-f]+=""/g, '')
		.replace(/\n?\s*<!--[\s\S]*?-->/g, '')
}

beforeEach(() => {
	get.mockReset()
	put.mockReset()
	showSuccess.mockReset()
})

describe('ConnectionAlertSettings', () => {
	it('starts loading', () => {
		get.mockReturnValue(new Promise(() => {}))
		const wrapper = mount(ConnectionAlertSettings)
		expect(wrapper.vm.$data).toEqual({
			loading: true,
			busy: false,
			error: '',
			group: '',
			saved: '',
		})
		expect(markup(wrapper)).toMatchSnapshot()
	})

	it.each([[{ group: 'ops' }], [{}], [null]])('reads %j', async (data) => {
		get.mockResolvedValue({ data })
		const wrapper = mount(ConnectionAlertSettings)
		await flushPromises()
		expect(get).toHaveBeenCalledWith(URL)
		expect([
			wrapper.vm.group,
			wrapper.vm.saved,
			wrapper.vm.loading,
			wrapper.vm.error,
		]).toMatchSnapshot()
		expect(markup(wrapper)).toMatchSnapshot()
	})

	it.each([
		[{ response: { data: { error: 'srv' } } }, 'srv'],
		[{}, 'The setting could not be read.'],
	])('a failed read says why (%#)', async (err, text) => {
		get.mockRejectedValue(err)
		const wrapper = mount(ConnectionAlertSettings)
		await flushPromises()
		expect([wrapper.vm.error, wrapper.vm.loading, wrapper.vm.group]).toEqual([
			text,
			false,
			'',
		])
		expect(markup(wrapper)).toMatchSnapshot()
	})

	it.each([
		[{ group: 'ops' }, 'ops'],
		[{}, ''],
		[null, ''],
	])('saves trimmed and takes the answer %j', async (data, saved) => {
		get.mockResolvedValue({ data: { group: 'old' } })
		const wrapper = mount(ConnectionAlertSettings)
		await flushPromises()
		wrapper.vm.group = '  ops  '
		wrapper.vm.error = 'old'
		put.mockResolvedValue({ data })
		const saving = wrapper.vm.save()
		expect(wrapper.vm.busy).toBe(true)
		await saving
		expect(put).toHaveBeenCalledWith(URL, { group: 'ops' })
		expect([
			wrapper.vm.saved,
			wrapper.vm.group,
			wrapper.vm.busy,
			wrapper.vm.error,
		]).toEqual([saved, saved, false, ''])
		expect(showSuccess).toHaveBeenCalledWith('Saved.')
	})

	it.each([
		[{ response: { data: { error: 'srv' } } }, 'srv'],
		[{}, 'The setting could not be saved.'],
	])('a failed save says why (%#)', async (err, text) => {
		get.mockResolvedValue({ data: { group: 'old' } })
		const wrapper = mount(ConnectionAlertSettings)
		await flushPromises()
		wrapper.vm.group = 'new'
		put.mockRejectedValue(err)
		await wrapper.vm.save()
		expect([
			wrapper.vm.error,
			wrapper.vm.busy,
			wrapper.vm.saved,
			wrapper.vm.group,
		]).toEqual([text, false, 'old', 'new'])
		expect(showSuccess).not.toHaveBeenCalled()
	})

	it('saves on enter and on the button', async () => {
		get.mockResolvedValue({ data: { group: 'g' } })
		put.mockResolvedValue({ data: { group: 'g' } })
		const wrapper = mount(ConnectionAlertSettings)
		await flushPromises()
		await wrapper.find('button').trigger('click')
		await wrapper.findComponent({ name: 'NcTextField' }).trigger('keyup.enter')
		await flushPromises()
		expect(put).toHaveBeenCalledTimes(2)
	})
})

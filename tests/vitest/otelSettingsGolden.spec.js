// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of OtelSettings: the form it starts with, how it merges
 * the stored settings, what it saves and the error text of each failure.
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-is-configured-by-an-administrator-req-otel-005
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import OtelSettings from '@/views/admin/OtelSettings.vue'

const { get, put, showSuccess } = vi.hoisted(() => ({
	get: vi.fn(),
	put: vi.fn(),
	showSuccess: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: { get, put } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props) =>
		defineComponent({
			name,
			props,
			emits: ['click', 'update:modelValue'],
			render() {
				return h('div', { class: name }, this.$slots.default?.())
			},
		})
	return {
		NcButton: stub('NcButton', ['variant', 'disabled']),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch', ['modelValue', 'type']),
		NcTextField: stub('NcTextField', ['modelValue', 'label', 'placeholder']),
	}
})

const URL = '/index.php/apps/integriq/api/admin/otel'

const DEFAULT_FORM = {
	enabled: false,
	endpoint: '',
	allowLocal: false,
	serviceName: '',
	samplingRatio: 0.1,
	credentialName: '',
	headerName: '',
}

beforeEach(() => {
	get.mockReset()
	put.mockReset()
	showSuccess.mockReset()
})

describe('loading', () => {
	it('starts loading with the default form', () => {
		get.mockReturnValue(new Promise(() => {}))
		const wrapper = mount(OtelSettings)
		expect(wrapper.vm.$data).toEqual({
			loading: true,
			busy: false,
			error: '',
			form: DEFAULT_FORM,
		})
		expect(wrapper.vm.samplingPercent).toBe('10')
	})

	it('merges the stored settings over the defaults', async () => {
		get.mockResolvedValue({
			data: { enabled: true, endpoint: 'https://c', samplingRatio: 0.25 },
		})
		const wrapper = mount(OtelSettings)
		await flushPromises()
		expect(get).toHaveBeenCalledWith(URL)
		expect(wrapper.vm.form).toEqual({
			...DEFAULT_FORM,
			enabled: true,
			endpoint: 'https://c',
			samplingRatio: 0.25,
		})
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.samplingPercent).toBe('25')
	})

	it.each([
		[{ response: { data: { error: 'srv' } } }, 'srv'],
		[{}, 'The setting could not be read.'],
	])('a failed read keeps the defaults (%#)', async (err, text) => {
		get.mockRejectedValue(err)
		const wrapper = mount(OtelSettings)
		await flushPromises()
		expect(wrapper.vm.error).toBe(text)
		expect(wrapper.vm.form).toEqual(DEFAULT_FORM)
		expect(wrapper.vm.loading).toBe(false)
	})
})

describe('the sampling percentage', () => {
	it.each([
		['50', 0.5],
		['abc', 0],
		['', 0],
	])('%s is stored as ratio %s', async (typed, ratio) => {
		get.mockResolvedValue({ data: {} })
		const wrapper = mount(OtelSettings)
		await flushPromises()
		wrapper.vm.samplingPercent = typed
		expect(wrapper.vm.form.samplingRatio).toBe(ratio)
	})
})

describe('saving', () => {
	it('puts a copy of the form and merges the answer', async () => {
		get.mockResolvedValue({ data: { serviceName: 'svc' } })
		const wrapper = mount(OtelSettings)
		await flushPromises()
		wrapper.vm.error = 'old'
		put.mockResolvedValue({ data: { endpoint: 'https://saved' } })
		await wrapper.vm.save()
		expect(put).toHaveBeenCalledWith(URL, {
			...DEFAULT_FORM,
			serviceName: 'svc',
		})
		expect(put.mock.calls[0][1]).not.toBe(wrapper.vm.form)
		expect(wrapper.vm.form).toEqual({
			...DEFAULT_FORM,
			serviceName: 'svc',
			endpoint: 'https://saved',
		})
		expect(showSuccess).toHaveBeenCalledWith('Saved.')
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.vm.error).toBe('')
	})

	it.each([
		[{ response: { data: { error: 'srv' } } }, 'srv'],
		[{}, 'The setting could not be saved.'],
	])('a failed save says why (%#)', async (err, text) => {
		get.mockResolvedValue({ data: {} })
		const wrapper = mount(OtelSettings)
		await flushPromises()
		put.mockRejectedValue(err)
		await wrapper.vm.save()
		expect(wrapper.vm.error).toBe(text)
		expect(showSuccess).not.toHaveBeenCalled()
		expect(wrapper.vm.busy).toBe(false)
	})
})

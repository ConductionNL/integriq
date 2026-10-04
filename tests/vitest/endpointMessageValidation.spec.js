// @vitest-environment jsdom

import axios from '@nextcloud/axios'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The endpoint form writes the `validation` block the endpoint runtime reads
 * (mapping-message-schema-validation, REQ-MSV-002): a mode, and a message
 * schema for the request and for the proxied answer.
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import EndpointFormFields from '@/modals/v2/EndpointFormFields.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) =>
		defineComponent({
			name,
			inheritAttrs: false,
			render() {
				return h('div', { class: name })
			},
		})
	return {
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
		NcSelect: stub('NcSelect'),
		NcTextField: stub('NcTextField'),
	}
})

vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		CnFieldHelper: defineComponent({
			name: 'CnFieldHelper',
			render() {
				return h('div')
			},
		}),
	}
})

/**
 * Mount the form with the given data; every OpenRegister list answers with the message schemas.
 *
 * @param {object} formData The endpoint being edited.
 * @return {Promise<object>} The wrapper and the updateField spy.
 */
async function mountWith(formData) {
	axios.get.mockImplementation((url) =>
		Promise.resolve({
			data: {
				results: url.includes('message_schema')
					? [
							{
								'@self': { id: 'ms-1' },
								name: 'Person',
								version: '1.0.0',
							},
							{ '@self': { id: 'ms-2' }, name: 'Adres' },
						]
					: [],
			},
		}),
	)
	const updateField = vi.fn()
	const wrapper = mount(EndpointFormFields, {
		props: { fields: [], formData, updateField },
		global: { mocks: { t: (app, text) => text } },
	})
	await flushPromises()
	return { wrapper, updateField }
}

describe('EndpointFormFields message validation', () => {
	it('lists the message schemas by name and version', async () => {
		const { wrapper } = await mountWith({})
		expect(wrapper.vm.messageSchemaOptions).toEqual([
			{ id: 'ms-1', label: 'Person (1.0.0)' },
			{ id: 'ms-2', label: 'Adres' },
		])
		expect(wrapper.vm.validationModeOption.id).toBe('record')
	})

	it('writes the picked request schema and keeps the mode', async () => {
		const { wrapper, updateField } = await mountWith({
			validation: { mode: 'refuse' },
		})
		wrapper.vm.setValidationSchema('request', {
			id: 'ms-1',
			label: 'Person (1.0.0)',
		})
		expect(updateField).toHaveBeenCalledWith('validation', {
			mode: 'refuse',
			request: { messageSchema: 'ms-1' },
		})
	})

	it('keeps the operation when the answer schema changes, and clears it on null', async () => {
		const { wrapper, updateField } = await mountWith({
			validation: {
				response: { messageSchema: 'ms-1', operationId: 'getPersoon' },
			},
		})
		expect(wrapper.vm.messageSchemaOption('response')).toEqual({
			id: 'ms-1',
			label: 'Person (1.0.0)',
		})

		wrapper.vm.setValidationSchema('response', { id: 'ms-2', label: 'Adres' })
		expect(updateField).toHaveBeenLastCalledWith('validation', {
			response: { messageSchema: 'ms-2', operationId: 'getPersoon' },
		})

		wrapper.vm.setValidationSchema('response', null)
		expect(updateField).toHaveBeenLastCalledWith('validation', {})
	})

	it('sets the mode', async () => {
		const { wrapper, updateField } = await mountWith({})
		wrapper.vm.setValidation('mode', 'refuse')
		expect(updateField).toHaveBeenCalledWith('validation', { mode: 'refuse' })
	})
})

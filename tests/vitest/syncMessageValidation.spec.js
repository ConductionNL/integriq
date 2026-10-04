// @vitest-environment jsdom

import axios from '@nextcloud/axios'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The synchronization's source and target config editor writes the
 * `validation` block the engine reads (mapping-message-schema-validation,
 * REQ-MSV-003): `{mode, messageSchema}` under `sourceConfig` or
 * `targetConfig`, with mode `record` until someone picks `refuse`.
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-synchronization-validates-source-objects-and-target-bodies-req-msv-003
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SyncConfigWidget from '@/views/Synchronization/SyncConfigWidget.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/dialogs', () => ({
	FilePickerType: {},
	getFilePickerBuilder: vi.fn(),
}))

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
		NcButton: stub('NcButton'),
		NcLoadingIcon: stub('NcLoadingIcon'),
		NcSelect: stub('NcSelect'),
		NcTextField: stub('NcTextField'),
	}
})

// The widget's computed labels call the global `t`, as Nextcloud provides it.
globalThis.t = (app, text) => text

/**
 * Mount one side of the editor; OpenRegister answers with two message schemas.
 *
 * @param {string} kind source or target.
 * @param {object} config The side's config blob.
 * @param {string} type The side's type.
 * @return {Promise<object>} The wrapper.
 */
async function mountWith(kind, config, type = 'api') {
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
	const wrapper = mount(SyncConfigWidget, {
		props: { kind, type, config },
		global: { mocks: { t: (app, text) => text } },
	})
	await flushPromises()
	return wrapper
}

describe('SyncConfigWidget message validation', () => {
	it('lists the message schemas by name and version, mode record by default', async () => {
		const wrapper = await mountWith('source', {})
		expect(wrapper.vm.messageSchemaOptions).toEqual([
			{ id: 'ms-1', label: 'Person (1.0.0)' },
			{ id: 'ms-2', label: 'Adres' },
		])
		expect(wrapper.vm.validationModeOption.id).toBe('record')
		expect(wrapper.find('.sync-config__validation').exists()).toBe(true)
	})

	it('writes the picked schema into the config blob and keeps the other keys', async () => {
		const wrapper = await mountWith('source', {
			endpoint: '/personen',
			validation: { mode: 'refuse' },
		})
		wrapper.vm.onValidationSchemaPick({ id: 'ms-1', label: 'Person (1.0.0)' })
		expect(wrapper.emitted('update:config').at(-1)[0]).toEqual({
			endpoint: '/personen',
			validation: { mode: 'refuse', messageSchema: 'ms-1' },
		})
	})

	it('keeps the operation when the target schema changes, and drops the block on clear', async () => {
		const wrapper = await mountWith('target', {
			validation: { messageSchema: 'ms-1', operationId: 'postPersoon' },
		})
		expect(wrapper.vm.validationSchemaOption).toEqual({
			id: 'ms-1',
			label: 'Person (1.0.0)',
		})

		wrapper.vm.onValidationSchemaPick({ id: 'ms-2', label: 'Adres' })
		expect(wrapper.emitted('update:config').at(-1)[0]).toEqual({
			validation: { messageSchema: 'ms-2', operationId: 'postPersoon' },
		})

		wrapper.vm.onValidationSchemaPick(null)
		expect(wrapper.emitted('update:config').at(-1)[0]).toEqual({})
	})

	it('sets the mode', async () => {
		const wrapper = await mountWith('target', {
			validation: { messageSchema: 'ms-1' },
		})
		wrapper.vm.onValidationModePick({ id: 'refuse', label: 'Refuse' })
		expect(wrapper.emitted('update:config').at(-1)[0]).toEqual({
			validation: { messageSchema: 'ms-1', mode: 'refuse' },
		})
	})

	it('draws no validation section and reads no message schemas outside api mode', async () => {
		axios.get.mockClear()
		const wrapper = await mountWith('source', {}, 'file')
		expect(wrapper.find('.sync-config__validation').exists()).toBe(false)
		expect(
			axios.get.mock.calls.some(([url]) => url.includes('message_schema')),
		).toBe(false)
	})
})

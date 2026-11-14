// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * SourceFormFields beyond its golden render: the brokered-credential switch
 * and picker, the digital post binding picker (fetch, soft-fail, a binding
 * this instance does not carry, a type switched after opening), and which
 * schema fields it shows.
 *
 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-one-provider-seam-with-log-berichtenbox-and-postex-bindings-req-dpa-001
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-a-caller-picks-the-sms-source-and-the-form-picks-the-provider-req-smsp-003
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SourceFormFields from '@/modals/v2/SourceFormFields.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const field = (name) =>
		defineComponent({
			name,
			props: ['modelValue', 'label', 'inputLabel', 'options', 'id', 'inputId'],
			emits: ['update:modelValue'],
			render() {
				return h('div', { class: name })
			},
		})
	return {
		NcTextField: field('NcTextField'),
		NcSelect: field('NcSelect'),
		NcCheckboxRadioSwitch: field('NcCheckboxRadioSwitch'),
		NcButton: field('NcButton'),
	}
})

vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		CnFieldHelper: defineComponent({ name: 'CnFieldHelper', render: () => h('span') }),
	}
})

const FIELDS = [
	{ key: 'name', widget: 'text' },
	{ key: 'type', widget: 'select' },
	{ key: 'configuration', widget: 'json' },
	{ key: 'auth', widget: 'select' },
	{ key: 'apikey', widget: 'text' },
]

/**
 * Mount the fields over a form value, recording every write.
 *
 * @param {object} formData the source being edited
 * @param {Array} fields the field descriptors
 * @return {{wrapper: object, writes: Array}} the wrapper and the writes
 */
function mountFields(formData = {}, fields = FIELDS) {
	const writes = []
	const wrapper = mount(SourceFormFields, {
		props: { fields, formData, updateField: (key, value) => writes.push([key, value]) },
	})
	return { wrapper, writes }
}

describe('SourceFormFields behaviour', () => {
	beforeEach(() => {
		get.mockReset()
		vi.spyOn(console, 'warn').mockImplementation(() => {})
	})
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('maps widgets to input types and passes an unknown source type through', () => {
		const { wrapper } = mountFields({ type: 'graphql' })

		expect(['email', 'url', 'date', 'datetime', 'text', undefined].map((widget) => wrapper.vm.textFieldType({ widget })))
			.toEqual(['email', 'url', 'date', 'datetime-local', 'text', 'text'])
		expect(wrapper.vm.selectedTypeOption).toEqual({ id: 'graphql', label: 'graphql' })
		expect(mountFields({ type: 'soap' }).wrapper.vm.selectedTypeOption).toEqual({ id: 'soap', label: 'SOAP' })
		expect(mountFields({}).wrapper.vm.selectedTypeOption).toBeNull()
	})

	it('writes the picked type, or null when cleared', () => {
		const { wrapper, writes } = mountFields({})

		wrapper.vm.onTypePick({ id: 'dso' })
		wrapper.vm.onTypePick(null)

		expect(writes).toEqual([['type', 'dso'], ['type', null]])
	})

	it('hides the raw configuration on a case-system source and gives an empty object for a non-object configuration', async () => {
		get.mockResolvedValue({ data: { results: [] } })
		const { wrapper } = mountFields({ type: 'case-system', configuration: ['not', 'an', 'object'] })
		await flushPromises()

		expect(wrapper.vm.isCaseSystem).toBe(true)
		expect(wrapper.vm.visibleFields.map((field) => field.key)).toEqual(['name', 'type', 'auth', 'apikey'])
		expect(wrapper.vm.caseSystemConfiguration).toEqual({})
		expect(mountFields({ type: 'api' }).wrapper.vm.visibleFields).toHaveLength(5)
		expect(mountFields({}, 'not an array').wrapper.vm.visibleFields).toEqual([])
	})

	it('turning brokered on loads the credentials once; turning it off clears the reference', async () => {
		get.mockResolvedValue({ data: { results: [{ id: 'cred-1', name: 'Mollie test', provider: 'mollie' }] } })
		const configuration = { authentication: { credentialRef: { credentialId: 'cred-1' } }, base: 'https://api.example' }
		const { wrapper, writes } = mountFields({ type: 'api', configuration: { base: 'https://api.example' } })

		wrapper.vm.onToggleBrokered(true)
		await flushPromises()
		expect(get).toHaveBeenCalledTimes(1)
		expect(get.mock.calls[0][0]).toContain('/apps/openregister/api/credentials')
		expect(wrapper.vm.credentialOptions.map((option) => option.id)).toEqual(['cred-1'])
		expect(wrapper.vm.credentialsLoading).toBe(false)

		wrapper.vm.onToggleBrokered(true)
		expect(get).toHaveBeenCalledTimes(1)

		await wrapper.setProps({ formData: { type: 'api', configuration } })
		wrapper.vm.onToggleBrokered(false)
		expect(wrapper.vm.brokeredEnabled).toBe(false)
		const [key, cleared] = writes[writes.length - 1]
		expect(key).toBe('configuration')
		expect(cleared.authentication?.credentialRef).toBeUndefined()
		expect(cleared.base).toBe('https://api.example')
	})

	it('hides the embedded-secret fields while brokered and resolves the picked credential', async () => {
		get.mockResolvedValue({ data: { results: [{ id: 'cred-1', name: 'Mollie test', provider: 'mollie' }] } })
		const { wrapper } = mountFields({ type: 'api', configuration: { authentication: { credentialRef: { credentialId: 'cred-1' } } } })
		await flushPromises()

		expect(wrapper.vm.brokeredEnabled).toBe(true)
		expect(wrapper.vm.visibleFields.map((field) => field.key)).not.toContain('apikey')
		expect(wrapper.vm.selectedCredential.id).toBe('cred-1')
		expect(wrapper.vm.selectedCredential.label).not.toBe('')
	})

	it('a credential the broker does not list is shown as its id', async () => {
		get.mockResolvedValue({ data: { results: [] } })
		const { wrapper } = mountFields({ type: 'api', configuration: { authentication: { credentialRef: { credentialId: 'gone' } } } })
		await flushPromises()

		expect(wrapper.vm.selectedCredential).toEqual({ id: 'gone', label: 'gone', name: 'gone', provider: '' })
		expect(mountFields({ type: 'api' }).wrapper.vm.selectedCredential).toBeNull()
	})

	it('picking a credential writes the reference; clearing the picker removes it', () => {
		const { wrapper, writes } = mountFields({ type: 'api', configuration: { base: 'x' } })

		wrapper.vm.onCredentialPick({ id: 42 })
		wrapper.vm.onCredentialPick(null)

		expect(writes[0][1].authentication.credentialRef).toEqual({ credentialId: '42' })
		expect(writes[1][1].authentication?.credentialRef).toBeUndefined()
		expect(writes[1][1].base).toBe('x')
	})

	it('a failing credential fetch soft-fails to the broker-unavailable note', async () => {
		get.mockRejectedValue(new Error('404'))
		const { wrapper } = mountFields({ type: 'api' })

		wrapper.vm.onToggleBrokered(true)
		await flushPromises()

		expect(wrapper.vm.brokerUnavailable).toBe(true)
		expect(wrapper.vm.credentialOptions).toEqual([])
		expect(wrapper.vm.credentialsLoading).toBe(false)

		wrapper.vm.onToggleBrokered(true)
		expect(get).toHaveBeenCalledTimes(1)
	})

	it('a digital post source loads the bindings and keeps a stored binding this instance lacks', async () => {
		get.mockResolvedValue({ data: { providers: [{ providerId: 'berichtenbox' }, { providerId: 'postex' }] } })
		const { wrapper } = mountFields({ type: 'digitalPost', configuration: { provider: 'retired-binding' } })
		await flushPromises()

		expect(get.mock.calls[0][0]).toContain('/apps/integriq/api/digital-post/providers')
		expect(wrapper.vm.digitalPostProviderOptions).toEqual([
			{ id: 'berichtenbox', label: 'berichtenbox' },
			{ id: 'postex', label: 'postex' },
		])
		expect(wrapper.vm.selectedDigitalPostProvider).toEqual({ id: 'retired-binding', label: 'retired-binding' })
		expect(wrapper.vm.digitalPostLoading).toBe(false)

		await wrapper.setProps({ formData: { type: 'digitalPost', configuration: { provider: 'postex' } } })
		expect(wrapper.vm.selectedDigitalPostProvider).toEqual({ id: 'postex', label: 'postex' })
		expect(get).toHaveBeenCalledTimes(1)
		await wrapper.setProps({ formData: { type: 'digitalPost', configuration: {} } })
		expect(wrapper.vm.selectedDigitalPostProvider).toBeNull()
	})

	it('picking a binding writes it onto the configuration, keeping the rest', () => {
		const { wrapper, writes } = mountFields({ type: 'api', configuration: { timeout: 30 } })

		wrapper.vm.onDigitalPostProviderPick({ id: 'postex' })
		wrapper.vm.onDigitalPostProviderPick(null)

		expect(writes).toEqual([
			['configuration', { timeout: 30, provider: 'postex' }],
			['configuration', { timeout: 30, provider: '' }],
		])
	})

	it('switching to digital post after opening loads the bindings; a failed or odd answer is an empty list', async () => {
		get.mockRejectedValueOnce(new Error('no endpoint'))
		const { wrapper } = mountFields({ type: 'api' })
		expect(get).not.toHaveBeenCalled()

		await wrapper.setProps({ formData: { type: 'digitalPost', configuration: {} } })
		await flushPromises()
		expect(get).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.digitalPostProviders).toEqual([])
		expect(wrapper.vm.digitalPostLoading).toBe(false)

		get.mockResolvedValueOnce({ data: { providers: 'not a list' } })
		await wrapper.vm.fetchDigitalPostProviders()
		expect(wrapper.vm.digitalPostProviders).toEqual([])
	})

	it('an SMS source lists the five providers by name and shows the settings the picked one reads', async () => {
		get.mockResolvedValue({
			data: {
				providers: [
					{ providerId: 'log', name: 'Log', configSchema: { properties: { provider: { type: 'string' } } } },
					{ providerId: 'notifynl', name: 'NotifyNL', configSchema: {} },
					{
						providerId: 'cmcom',
						name: 'CM.com',
						configSchema: {
							required: ['senderId', 'authentication'],
							properties: {
								provider: { type: 'string' },
								baseUrl: { type: 'string', default: 'https://gw.cmtelecom.com/v1.0' },
								senderId: { type: 'string', description: 'The sender name.' },
								authentication: { type: 'object' },
							},
						},
					},
					{ providerId: 'messagebird', name: 'MessageBird', configSchema: {} },
					{ providerId: 'twilio', name: 'Twilio', configSchema: {} },
					{ name: 'no id' },
				],
			},
		})
		const { wrapper } = mountFields({ type: 'sms', configuration: { provider: 'cmcom' } })
		await flushPromises()

		expect(get.mock.calls[0][0]).toContain('/apps/integriq/api/sms/providers')
		expect(wrapper.vm.smsProviderOptions.map((option) => option.label)).toEqual(['Log', 'NotifyNL', 'CM.com', 'MessageBird', 'Twilio'])
		expect(wrapper.vm.selectedSmsProvider).toEqual({ id: 'cmcom', label: 'CM.com' })
		expect(wrapper.vm.smsProviderFields).toEqual([
			{ key: 'baseUrl', label: 'Base URL', description: '', required: false, placeholder: 'https://gw.cmtelecom.com/v1.0' },
			{ key: 'senderId', label: 'Sender', description: 'The sender name.', required: true, placeholder: '' },
		])
		expect(wrapper.vm.typeOptions.map((option) => option.id)).toContain('sms')

		await wrapper.setProps({ formData: { type: 'sms', configuration: { provider: 'retired' } } })
		expect(wrapper.vm.selectedSmsProvider).toEqual({ id: 'retired', label: 'retired' })
		expect(wrapper.vm.smsProviderFields).toEqual([])
		expect(get).toHaveBeenCalledTimes(1)
	})

	it('picking an SMS provider and typing its settings writes them onto the configuration', () => {
		const { wrapper, writes } = mountFields({ type: 'sms', configuration: { senderId: 'Gemeente' } })
		get.mockResolvedValue({ data: { providers: [] } })

		wrapper.vm.onSmsProviderPick({ id: 'twilio' })
		wrapper.vm.onSmsConfigInput('accountSid', 'AC123')
		wrapper.vm.onSmsConfigInput('senderId', '')
		wrapper.vm.onSmsProviderPick(null)

		expect(writes).toEqual([
			['configuration', { senderId: 'Gemeente', provider: 'twilio' }],
			['configuration', { senderId: 'Gemeente', accountSid: 'AC123' }],
			['configuration', {}],
			['configuration', { senderId: 'Gemeente', provider: '' }],
		])
	})

	it('a failed or odd SMS provider answer is an empty list, and switching to SMS loads it', async () => {
		get.mockRejectedValueOnce(new Error('no endpoint'))
		const { wrapper } = mountFields({ type: 'api' })
		expect(get).not.toHaveBeenCalled()

		await wrapper.setProps({ formData: { type: 'sms', configuration: {} } })
		await flushPromises()
		expect(get).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.smsProviders).toEqual([])
		expect(wrapper.vm.smsLoading).toBe(false)

		get.mockResolvedValueOnce({ data: { providers: { not: 'a list' } } })
		await wrapper.vm.fetchSmsProviders()
		expect(wrapper.vm.smsProviders).toEqual([])
	})
})

// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * SubscriptionActionFields and BrokerConnectionFields: what each writes back
 * through updateField for each kind of input, and which lists each loads.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { get } }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) =>
		defineComponent({
			name,
			props: ['modelValue', 'label', 'options', 'loading', 'type', 'inputLabel'],
			emits: ['update:modelValue'],
			render() {
				return h('div', { class: name, 'data-label': this.label || this.inputLabel }, this.$slots.default?.())
			},
		})
	return {
		NcSelect: stub('NcSelect'),
		NcTextField: stub('NcTextField'),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
		NcNoteCard: stub('NcNoteCard'),
	}
})

const SubscriptionActionFields = (await import('@/modals/EventSubscription/SubscriptionActionFields.vue')).default
const BrokerConnectionFields = (await import('@/modals/EventSubscription/BrokerConnectionFields.vue')).default

let warn

beforeEach(() => {
	get.mockReset()
	get.mockResolvedValue({ data: { results: [] } })
	warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
	return () => warn.mockRestore()
})

/**
 * Mount the action fields with a spy for updateField.
 *
 * @param {object} formData The form data.
 * @return {{wrapper: object, updateField: Function}} The wrapper and the spy.
 */
function actionFields(formData = {}) {
	const updateField = vi.fn()
	const wrapper = mount(SubscriptionActionFields, {
		props: { formData, updateField },
		global: { stubs: { BrokerConnectionFields: true } },
	})
	return { wrapper, updateField }
}

describe('SubscriptionActionFields', () => {
	it('reads the event types one per line and writes back the non-empty trimmed lines', () => {
		const { wrapper, updateField } = actionFields({ types: ['a.created', 'b.updated'] })
		expect(wrapper.vm.typesText).toBe('a.created\nb.updated')
		expect(actionFields({ types: 'x' }).wrapper.vm.typesText).toBe('')

		wrapper.vm.onTypesInput(' a.created \n\n  \nc.deleted')
		expect(updateField).toHaveBeenCalledWith('types', ['a.created', 'c.deleted'])
	})

	it('defaults to the webhook kind and loads nothing', () => {
		const { wrapper } = actionFields()
		expect(wrapper.vm.actionKind).toBe('webhook')
		expect(wrapper.vm.selectedKindOption).toEqual({ id: 'webhook', label: 'Webhook' })
		expect(actionFields({ action: { kind: 'unknown' } }).wrapper.vm.selectedKindOption.id).toBe('webhook')
		expect(get).not.toHaveBeenCalled()
	})

	it('drops the other fields when switching to webhook and keeps them otherwise', () => {
		const { wrapper, updateField } = actionFields({ action: { kind: 'job', jobId: 'j1' } })
		wrapper.vm.onKindPick({ id: 'webhook' })
		expect(updateField).toHaveBeenLastCalledWith('action', { kind: 'webhook' })
		wrapper.vm.onKindPick({ id: 'synchronization' })
		expect(updateField).toHaveBeenLastCalledWith('action', { kind: 'synchronization', jobId: 'j1' })
		wrapper.vm.onKindPick(null)
		expect(updateField).toHaveBeenLastCalledWith('action', { kind: 'webhook' })

		const fresh = actionFields({ action: 'broken' })
		fresh.wrapper.vm.onKindPick({ id: 'job' })
		expect(fresh.updateField).toHaveBeenCalledWith('action', { kind: 'job' })
	})

	it('loads the synchronizations for a synchronization action and shows an unknown one by id', async () => {
		get.mockResolvedValueOnce({ data: { results: [{ id: 1, name: 'Zaken' }, { uuid: 'u2', title: 'Docs' }] } })
		const { wrapper, updateField } = actionFields({ action: { kind: 'synchronization', synchronizationId: 'gone' } })
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/integriq/synchronization', { params: { _limit: 500 } })
		expect(wrapper.vm.synchronizationOptions).toEqual([{ id: '1', label: 'Zaken' }, { id: 'u2', label: 'Docs' }])
		expect(wrapper.vm.selectedSynchronization).toEqual({ id: 'gone', label: 'gone' })
		expect(wrapper.vm.synchronizationsLoading).toBe(false)

		wrapper.vm.onSynchronizationPick({ id: 1 })
		expect(updateField).toHaveBeenLastCalledWith('action', { kind: 'synchronization', synchronizationId: '1' })
		wrapper.vm.onSynchronizationPick(null)
		expect(updateField).toHaveBeenLastCalledWith('action', { kind: 'synchronization', synchronizationId: null })
	})

	it('loads the jobs for a job action from a bare list, and picks one', async () => {
		get.mockResolvedValueOnce({ data: [{ id: 'j1', name: 'Nightly' }] })
		const { wrapper, updateField } = actionFields({ action: { kind: 'job', jobId: 'j1' } })
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/integriq/job', { params: { _limit: 500 } })
		expect(wrapper.vm.selectedJob).toEqual({ id: 'j1', label: 'Nightly' })
		wrapper.vm.onJobPick({ id: 'j2' })
		expect(updateField).toHaveBeenLastCalledWith('action', { kind: 'job', jobId: 'j2' })
		wrapper.vm.onJobPick(undefined)
		expect(updateField).toHaveBeenLastCalledWith('action', { kind: 'job', jobId: null })
		expect(actionFields({ action: { kind: 'webhook' } }).wrapper.vm.selectedJob).toBeNull()
	})

	it('loads a list once when the kind changes to it', async () => {
		const { wrapper } = actionFields({ action: { kind: 'webhook' } })
		get.mockResolvedValueOnce({ data: { results: [{ id: 's1' }] } })
		await wrapper.setProps({ formData: { action: { kind: 'synchronization' } } })
		await flushPromises()
		await wrapper.setProps({ formData: { action: { kind: 'job' } } })
		await flushPromises()
		await wrapper.setProps({ formData: { action: { kind: 'synchronization' } } })
		await flushPromises()

		expect(get.mock.calls.map(([url]) => url.split('/').pop())).toEqual(['synchronization', 'job'])
		expect(wrapper.vm.synchronizationOptions).toEqual([{ id: 's1', label: 's1' }])
	})

	it('falls back to an empty list when a load fails', async () => {
		get.mockRejectedValue(new Error('down'))
		for (const [kind, key] of [['synchronization', 'synchronizationOptions'], ['job', 'jobOptions'], ['flow', 'flowOptions'], ['broker', 'brokerSelectOptions']]) {
			const { wrapper } = actionFields({ action: { kind } })
			await flushPromises()
			expect(wrapper.vm[key], kind).toEqual([])
		}
		expect(warn).toHaveBeenCalledTimes(4)
	})

	it('turns the retry policy on and off', () => {
		const { wrapper, updateField } = actionFields()
		expect(wrapper.vm.retryPolicyEnabled).toBe(false)
		wrapper.vm.onToggleRetryPolicy(true)
		expect(updateField).toHaveBeenLastCalledWith('retryPolicy', {})
		wrapper.vm.onToggleRetryPolicy(false)
		expect(updateField).toHaveBeenLastCalledWith('retryPolicy', null)
		expect(actionFields({ retryPolicy: {} }).wrapper.vm.retryPolicyEnabled).toBe(true)
	})

	it('writes retry policy numbers, drops cleared ones, and marks non-numbers undefined', () => {
		const { wrapper, updateField } = actionFields({ retryPolicy: { maxAttempts: 3, backoff: 10 } })
		expect(wrapper.vm.retryPolicyValue('maxAttempts')).toBe('3')
		expect(wrapper.vm.retryPolicyValue('missing')).toBe('')

		wrapper.vm.onRetryPolicyField('maxAttempts', '5')
		expect(updateField).toHaveBeenLastCalledWith('retryPolicy', { maxAttempts: 5, backoff: 10 })
		wrapper.vm.onRetryPolicyField('backoff', '')
		expect(updateField).toHaveBeenLastCalledWith('retryPolicy', { maxAttempts: 3 })
		wrapper.vm.onRetryPolicyField('backoff', 'soon')
		expect(updateField).toHaveBeenLastCalledWith('retryPolicy', { maxAttempts: 3, backoff: undefined })

		const none = actionFields({})
		none.wrapper.vm.onRetryPolicyField('maxAttempts', 2)
		expect(none.updateField).toHaveBeenCalledWith('retryPolicy', { maxAttempts: 2 })
	})

	it('labels the content modes of the picked broker and defaults to structured', async () => {
		get.mockResolvedValueOnce({ data: { results: [{ id: 'rabbitmq', label: 'RabbitMQ', contentModes: ['structured', 'binary'] }, { id: 'log', label: 'Log' }] } })
		const { wrapper } = actionFields({ action: { kind: 'broker', brokerId: 'rabbitmq', contentMode: 'binary' } })
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/integriq/api/events/brokers')
		expect(wrapper.vm.brokerSelectOptions.map((o) => o.label)).toEqual(['RabbitMQ', 'No broker configured'])
		expect(wrapper.vm.showsBrokerRoutingKey).toBe(true)
		expect(wrapper.vm.contentModeOptions).toEqual([
			{ id: 'structured', label: 'Structured: the whole event in the body' },
			{ id: 'binary', label: 'Binary: the data in the body, the attributes in headers' },
		])
		expect(wrapper.vm.selectedContentMode.id).toBe('binary')

		const unknown = actionFields({ action: { kind: 'webhook', brokerId: 'gone', contentMode: 'binary' } })
		expect(unknown.wrapper.vm.selectedBroker).toEqual({ id: 'gone', label: 'gone', needsTopic: true, contentModes: ['structured'], refuses: false })
		expect(unknown.wrapper.vm.selectedContentMode.id).toBe('structured')
		expect(unknown.wrapper.vm.showsBrokerRoutingKey).toBe(false)
		expect(actionFields({}).wrapper.vm.selectedBroker).toBeNull()
	})

	it('writes a broker field change as the built broker action', async () => {
		get.mockResolvedValueOnce({ data: { results: [{ id: 'kafka', label: 'Kafka' }] } })
		const { wrapper, updateField } = actionFields({ action: { kind: 'broker', brokerId: 'kafka', routingKey: 'r' } })
		await flushPromises()

		wrapper.vm.onBrokerField({ topic: ' orders ' })
		expect(updateField).toHaveBeenCalledWith('action', { kind: 'broker', brokerId: 'kafka', topic: 'orders', contentMode: 'structured' })
	})
})

describe('BrokerConnectionFields', () => {
	/**
	 * Mount the connection fields with a spy for updateField.
	 *
	 * @param {object}      formData The form data.
	 * @param {string|null} brokerId The broker.
	 * @return {{wrapper: object, updateField: Function}} The wrapper and the spy.
	 */
	function connectionFields(formData = {}, brokerId = 'rabbitmq') {
		const updateField = vi.fn()
		const wrapper = mount(BrokerConnectionFields, { props: { formData, updateField, brokerId } })
		return { wrapper, updateField }
	}

	const label = (wrapper) => wrapper.findAllComponents({ name: 'NcTextField' }).map((f) => f.props('label'))

	it('shows the virtual host for RabbitMQ only', () => {
		expect(label(connectionFields({}, 'rabbitmq').wrapper)).toEqual(['Broker address', 'Virtual host', 'Username'])
		expect(label(connectionFields({}, 'kafka').wrapper)).toEqual(['Broker address', 'Username'])
	})

	it('lists the credentials, and says when the credential broker is unavailable', async () => {
		get.mockResolvedValueOnce({ data: [{ id: 'c1', name: 'Rabbit', provider: 'basic' }] })
		const { wrapper } = connectionFields({ protocolSettings: { broker: { credentialRef: { credentialId: 'c1' } } } })
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/openregister/api/credentials')
		expect(wrapper.vm.credentialOptions.map((o) => o.id)).toEqual(['c1'])
		expect(wrapper.vm.selectedCredential.id).toBe('c1')
		expect(wrapper.text()).not.toContain('is not available')

		get.mockRejectedValueOnce(new Error('404'))
		const down = connectionFields({ protocolSettings: { broker: { credentialRef: { credentialId: 'gone' } } } })
		await flushPromises()
		expect(down.wrapper.vm.credentialsUnavailable).toBe(true)
		expect(down.wrapper.vm.selectedCredential).toEqual({ id: 'gone', label: 'gone' })
		expect(down.wrapper.text()).toContain('The OpenRegister credential broker is not available, so no credentials can be listed.')
		expect(connectionFields({}).wrapper.vm.selectedCredential).toBeNull()
	})

	it('writes a connection field into the broker settings, keeping the others', () => {
		const { wrapper, updateField } = connectionFields({ protocolSettings: { other: 1, broker: { baseUrl: 'http://b', vhost: '/v' } } }, 'kafka')
		wrapper.findAllComponents({ name: 'NcTextField' })[1].vm.$emit('update:modelValue', ' admin ')
		expect(updateField).toHaveBeenCalledWith('protocolSettings', { other: 1, broker: { baseUrl: 'http://b', username: 'admin' } })
	})

	it('sets and clears the credential reference', () => {
		const { wrapper, updateField } = connectionFields({ protocolSettings: { broker: { baseUrl: 'http://b', credentialRef: { credentialId: 'old' } } } })
		wrapper.vm.onCredentialPick({ id: 7 })
		expect(updateField).toHaveBeenLastCalledWith('protocolSettings', { broker: { baseUrl: 'http://b', credentialRef: { credentialId: '7' } } })
		wrapper.vm.onCredentialPick(null)
		expect(updateField).toHaveBeenLastCalledWith('protocolSettings', { broker: { baseUrl: 'http://b' } })
	})

	it('warns about a stored password only for a saved subscription that has one', async () => {
		get.mockImplementation((url) => Promise.resolve(url.endsWith('/subscriptions')
			? { data: { results: [{ protocolSettings: { broker: { password: 'x' } } }] } }
			: { data: [] }))
		const { wrapper } = connectionFields({ uuid: 's1' })
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/integriq/api/events/subscriptions', { params: { uuid: 's1' } })
		expect(wrapper.vm.storedSecret).toBe(true)
		expect(wrapper.findComponent({ name: 'NcNoteCard' }).exists()).toBe(true)

		get.mockClear()
		connectionFields({})
		await flushPromises()
		expect(get).toHaveBeenCalledTimes(1)

		get.mockImplementation((url) => (url.endsWith('/subscriptions') ? Promise.reject(new Error('x')) : Promise.resolve({ data: [] })))
		const failed = connectionFields({ '@self': { id: 's2' } })
		await flushPromises()
		expect(failed.wrapper.vm.storedSecret).toBe(false)
	})
})

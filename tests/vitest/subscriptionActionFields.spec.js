// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The delivery-action block of the event subscription modal
 * (SubscriptionActionFields), driven on the real component: the event types
 * textarea, switching the action kind, the synchronization and job pickers
 * and what they load, the broker content modes, and the retry policy fields.
 * Every change goes out through the dialog's updateField, which is asserted
 * exactly.
 *
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-the-subscription-form-offers-broker-as-a-delivery-action-req-ebsc-002
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SubscriptionActionFields from '@/modals/EventSubscription/SubscriptionActionFields.vue'

const get = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { get } }))

// The @nextcloud/vue barrel registers its own l10n on import, which the node
// l10n stub cannot serve; the pickers are stood in by named components.
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props) =>
		defineComponent({ name, props, render: () => h('div', { class: name }) })
	return {
		NcSelect: stub('NcSelect', ['inputId', 'inputLabel', 'ariaLabelCombobox', 'modelValue', 'options', 'loading', 'clearable', 'placeholder']),
		NcTextField: stub('NcTextField', ['label', 'modelValue', 'type']),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch', ['modelValue', 'type']),
		NcNoteCard: stub('NcNoteCard', ['type']),
	}
})

/**
 * Mount the fields with a spy updateField and the given form data.
 *
 * @param {object} formData the dialog's form data
 * @return {{wrapper: object, updateField: Function}} the wrapper and the spy
 */
function mountFields(formData) {
	const updateField = vi.fn()
	const wrapper = mount(SubscriptionActionFields, { props: { formData, updateField } })
	return { wrapper, updateField }
}

let warn

beforeEach(() => {
	get.mockReset()
	get.mockResolvedValue({ data: { results: [] } })
	warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => {
	warn.mockRestore()
})

describe('SubscriptionActionFields event types', () => {
	it('shows the stored types one per line', () => {
		const { wrapper } = mountFields({ types: ['nl.overheid.zaak.created', 'nl.overheid.zaak.updated'] })
		expect(wrapper.vm.typesText).toBe('nl.overheid.zaak.created\nnl.overheid.zaak.updated')
		expect(mountFields({ types: 'not-a-list' }).wrapper.vm.typesText).toBe('')
	})

	it('writes the typed lines trimmed, without blank lines', () => {
		const { wrapper, updateField } = mountFields({})
		wrapper.vm.onTypesInput('  a.created \n\n   \nb.updated')
		expect(updateField).toHaveBeenCalledWith('types', ['a.created', 'b.updated'])
	})
})

describe('SubscriptionActionFields action kind', () => {
	it('is a webhook by default and loads nothing', () => {
		const { wrapper } = mountFields({})
		expect(wrapper.vm.actionKind).toBe('webhook')
		expect(wrapper.vm.selectedKindOption).toEqual({ id: 'webhook', label: 'Webhook' })
		expect(get).not.toHaveBeenCalled()
	})

	it('falls back to the webhook option for an unknown kind', () => {
		const { wrapper } = mountFields({ action: { kind: 'carrier-pigeon' } })
		expect(wrapper.vm.selectedKindOption).toEqual({ id: 'webhook', label: 'Webhook' })
	})

	it('picking webhook replaces the whole action', () => {
		const { wrapper, updateField } = mountFields({ action: { kind: 'job', jobId: 'j1' } })
		wrapper.vm.onKindPick({ id: 'webhook' })
		wrapper.vm.onKindPick(null)
		expect(updateField.mock.calls).toEqual([
			['action', { kind: 'webhook' }],
			['action', { kind: 'webhook' }],
		])
	})

	it('picking another kind keeps the rest of the action', () => {
		const { wrapper, updateField } = mountFields({ action: { kind: 'job', jobId: 'j1' } })
		wrapper.vm.onKindPick({ id: 'synchronization' })
		expect(updateField).toHaveBeenCalledWith('action', { kind: 'synchronization', jobId: 'j1' })
		const fresh = mountFields({ action: 'broken' })
		fresh.wrapper.vm.onKindPick({ id: 'job' })
		expect(fresh.updateField).toHaveBeenCalledWith('action', { kind: 'job' })
	})

	it('loads a kind\'s choices once it becomes the chosen kind', async () => {
		const { wrapper } = mountFields({})
		await wrapper.setProps({ formData: { action: { kind: 'job' } } })
		await flushPromises()
		expect(get).toHaveBeenCalledTimes(1)
		expect(get.mock.calls[0][0]).toBe('/index.php/apps/openregister/api/objects/integriq/job')

		await wrapper.setProps({ formData: { action: { kind: 'synchronization' } } })
		await flushPromises()
		expect(get.mock.calls[1][0]).toBe('/index.php/apps/openregister/api/objects/integriq/synchronization')

		await wrapper.setProps({ formData: { action: { kind: 'broker' } } })
		await flushPromises()
		// The broker block's own connection fields load their credentials too.
		const urls = get.mock.calls.map((call) => call[0])
		expect(urls.filter((url) => url === '/index.php/apps/integriq/api/events/brokers')).toHaveLength(1)
		expect(urls.filter((url) => url.includes('/objects/integriq/'))).toHaveLength(2)
	})
})

describe('SubscriptionActionFields synchronization and job pickers', () => {
	it('loads the synchronizations by name, title or id', async () => {
		get.mockResolvedValue({ data: { results: [{ id: 1, name: 'Zaken' }, { uuid: 'u-2', title: 'Klanten' }, { id: 'x3' }] } })
		const { wrapper } = mountFields({ action: { kind: 'synchronization', synchronizationId: 'u-2' } })
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/integriq/synchronization', { params: { _limit: 500 } })
		expect(wrapper.vm.synchronizationOptions).toEqual([
			{ id: '1', label: 'Zaken' },
			{ id: 'u-2', label: 'Klanten' },
			{ id: 'x3', label: 'x3' },
		])
		expect(wrapper.vm.selectedSynchronization).toEqual({ id: 'u-2', label: 'Klanten' })
		expect(wrapper.vm.synchronizationsLoading).toBe(false)
	})

	it('loads the jobs from a bare list and keeps an unknown stored id visible', async () => {
		get.mockResolvedValue({ data: [{ id: 'j1', name: 'Nightly' }] })
		const { wrapper } = mountFields({ action: { kind: 'job', jobId: 'gone' } })
		await flushPromises()
		expect(wrapper.vm.jobOptions).toEqual([{ id: 'j1', label: 'Nightly' }])
		expect(wrapper.vm.selectedJob).toEqual({ id: 'gone', label: 'gone' })
		expect(wrapper.vm.jobsLoading).toBe(false)
	})

	it('reads an answer that is no list as no choices', async () => {
		get.mockResolvedValue({ data: { total: 0 } })
		const { wrapper } = mountFields({ action: { kind: 'job' } })
		await flushPromises()
		expect(wrapper.vm.jobOptions).toEqual([])
		expect(wrapper.vm.selectedJob).toBeNull()
	})

	it('a failed load leaves no choices and stops loading', async () => {
		get.mockRejectedValue(new Error('500'))
		const sync = mountFields({ action: { kind: 'synchronization' } })
		const job = mountFields({ action: { kind: 'job' } })
		const flow = mountFields({ action: { kind: 'flow' } })
		const broker = mountFields({ action: { kind: 'broker' } })
		await flushPromises()
		expect(sync.wrapper.vm.synchronizationOptions).toEqual([])
		expect(sync.wrapper.vm.synchronizationsLoading).toBe(false)
		expect(job.wrapper.vm.jobOptions).toEqual([])
		expect(flow.wrapper.vm.flowOptions).toEqual([])
		expect(flow.wrapper.vm.flowsLoading).toBe(false)
		expect(broker.wrapper.vm.brokerSelectOptions).toEqual([])
		expect(broker.wrapper.vm.brokersLoading).toBe(false)
		const own = warn.mock.calls.filter((call) => String(call[0]).startsWith('[SubscriptionActionFields]'))
		expect(own.map((call) => call[0])).toEqual([
			'[SubscriptionActionFields] synchronization fetch failed',
			'[SubscriptionActionFields] job fetch failed',
			'[SubscriptionActionFields] flow fetch failed',
			'[SubscriptionActionFields] broker fetch failed',
		])
	})

	it('a pick writes the kind and the id as a string, or null when cleared', () => {
		const { wrapper, updateField } = mountFields({})
		wrapper.vm.onSynchronizationPick({ id: 7 })
		wrapper.vm.onSynchronizationPick(null)
		wrapper.vm.onJobPick({ id: 'j1' })
		wrapper.vm.onJobPick({})
		wrapper.vm.onFlowPick(null)
		expect(updateField.mock.calls).toEqual([
			['action', { kind: 'synchronization', synchronizationId: '7' }],
			['action', { kind: 'synchronization', synchronizationId: null }],
			['action', { kind: 'job', jobId: 'j1' }],
			['action', { kind: 'job', jobId: null }],
			['action', { kind: 'flow', flowId: null }],
		])
	})
})

describe('SubscriptionActionFields broker', () => {
	it('names the log broker as no broker configured, and offers its content modes', async () => {
		get.mockResolvedValue({ data: { results: [
			{ id: 'rabbitmq', label: 'RabbitMQ', contentModes: ['structured', 'binary'] },
			{ id: 'log', label: 'Log' },
		] } })
		const { wrapper } = mountFields({ action: { kind: 'broker', brokerId: 'rabbitmq', contentMode: 'binary' } })
		await flushPromises()
		expect(wrapper.vm.brokerSelectOptions.map((o) => [o.id, o.label])).toEqual([
			['rabbitmq', 'RabbitMQ'],
			['log', 'No broker configured'],
		])
		expect(wrapper.vm.showsBrokerRoutingKey).toBe(true)
		expect(wrapper.vm.contentModeOptions).toEqual([
			{ id: 'structured', label: 'Structured: the whole event in the body' },
			{ id: 'binary', label: 'Binary: the data in the body, the attributes in headers' },
		])
		expect(wrapper.vm.selectedContentMode.id).toBe('binary')
	})

	it('an unknown stored broker still shows, with structured mode only', () => {
		const { wrapper } = mountFields({ action: { kind: 'webhook', brokerId: 'kafka-old' } })
		expect(wrapper.vm.selectedBroker).toEqual({
			id: 'kafka-old',
			label: 'kafka-old',
			needsTopic: true,
			contentModes: ['structured'],
			refuses: false,
		})
		expect(wrapper.vm.showsBrokerRoutingKey).toBe(false)
		expect(wrapper.vm.selectedContentMode).toEqual({ id: 'structured', label: 'Structured: the whole event in the body' })
	})

	it('a mode the broker does not offer falls back to its first mode', () => {
		const { wrapper } = mountFields({ action: { contentMode: 'binary' } })
		expect(wrapper.vm.selectedBroker).toBeNull()
		expect(wrapper.vm.selectedContentMode.id).toBe('structured')
	})
})

describe('SubscriptionActionFields retry policy', () => {
	it('is on only when the policy is an object', () => {
		expect(mountFields({ retryPolicy: {} }).wrapper.vm.retryPolicyEnabled).toBe(true)
		expect(mountFields({ retryPolicy: null }).wrapper.vm.retryPolicyEnabled).toBe(false)
		expect(mountFields({ retryPolicy: 3 }).wrapper.vm.retryPolicyEnabled).toBe(false)
		expect(mountFields({}).wrapper.vm.retryPolicyEnabled).toBe(false)
	})

	it('switching it writes an empty policy, or none', () => {
		const { wrapper, updateField } = mountFields({})
		wrapper.vm.onToggleRetryPolicy(true)
		wrapper.vm.onToggleRetryPolicy(false)
		expect(updateField.mock.calls).toEqual([['retryPolicy', {}], ['retryPolicy', null]])
	})

	it('shows each stored value as text, a missing one as empty', () => {
		const { wrapper } = mountFields({ retryPolicy: { maxAttempts: 5, backoffSeconds: 0 } })
		expect(wrapper.vm.retryPolicyValue('maxAttempts')).toBe('5')
		expect(wrapper.vm.retryPolicyValue('backoffSeconds')).toBe('0')
		expect(wrapper.vm.retryPolicyValue('ceiling')).toBe('')
	})

	it('a typed number is stored as a number, a cleared one is removed', () => {
		const { wrapper, updateField } = mountFields({ retryPolicy: { maxAttempts: 5, backoffSeconds: 10 } })
		wrapper.vm.onRetryPolicyField('maxAttempts', '8')
		wrapper.vm.onRetryPolicyField('backoffSeconds', '')
		wrapper.vm.onRetryPolicyField('ceiling', 'abc')
		expect(updateField.mock.calls).toEqual([
			['retryPolicy', { maxAttempts: 8, backoffSeconds: 10 }],
			['retryPolicy', { maxAttempts: 5 }],
			['retryPolicy', { maxAttempts: 5, backoffSeconds: 10, ceiling: undefined }],
		])
	})

	it('a field typed without a policy starts one', () => {
		const { wrapper, updateField } = mountFields({})
		wrapper.vm.onRetryPolicyField('maxAttempts', 3)
		wrapper.vm.onRetryPolicyField('maxAttempts', null)
		expect(updateField.mock.calls).toEqual([['retryPolicy', { maxAttempts: 3 }], ['retryPolicy', {}]])
	})
})

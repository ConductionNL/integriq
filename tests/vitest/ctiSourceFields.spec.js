// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A telephony (cti) source reads everything from its configuration:
 * CtiSourceResolver the binding, the two bindings their secrets, the webhook
 * binding its field and kind mapping, ConfiguredCallerSearch the
 * Klantinteracties source. These tests mount the real SourceFormFields and the
 * real CtiSourceFields and check that every key is written under the name and
 * in the shape the backend reads.
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CtiSourceFields, {
	kindMappingFrom,
	vendorWordsFrom,
} from '@/modals/v2/CtiSourceFields.vue'
import SourceFormFields from '@/modals/v2/SourceFormFields.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const field = (name) =>
		defineComponent({
			name,
			props: [
				'modelValue',
				'label',
				'inputLabel',
				'options',
				'id',
				'inputId',
				'type',
			],
			emits: ['update:modelValue'],
			render() {
				return h('div', {
					class: name,
					'data-label': this.label || this.inputLabel,
					'data-id': this.id || this.inputId,
				})
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
		CnFieldHelper: defineComponent({
			name: 'CnFieldHelper',
			render: () => h('span'),
		}),
	}
})

const SOURCES = {
	data: {
		results: [
			{ id: 'kiss-uuid', name: 'Klantinteracties', type: 'kiss' },
			{ id: 'zaken-uuid', name: 'ZGW Zaken API', type: 'api' },
		],
	},
}

const PROVIDERS = {
	data: {
		providers: [
			{ id: 'log', configSchema: {} },
			{ id: 'webhook', configSchema: {} },
		],
	},
}

/**
 * Answer each endpoint the form reads.
 *
 * @param {object} providers the /api/cti/providers answer
 * @return {void}
 */
function answer(providers = PROVIDERS) {
	get.mockImplementation((url) =>
		Promise.resolve(url.includes('/api/cti/providers') ? providers : SOURCES),
	)
}

/**
 * Mount the telephony fields over a configuration.
 *
 * @param {object} configuration the source configuration
 * @param {string} sourceId the saved source id
 * @return {object} the wrapper
 */
function mountFields(configuration = {}, sourceId = '') {
	return mount(CtiSourceFields, { props: { configuration, sourceId } })
}

/**
 * The last configuration the component emitted.
 *
 * @param {object} wrapper the wrapper
 * @return {object} the configuration
 */
function lastEmitted(wrapper) {
	const events = wrapper.emitted('update:configuration')
	return events[events.length - 1][0]
}

/**
 * One stubbed control by its id.
 *
 * @param {object} wrapper the wrapper
 * @param {string} name NcSelect or NcTextField
 * @param {string} id the control id
 * @return {object|undefined} the control
 */
function control(wrapper, name, id) {
	return wrapper
		.findAllComponents({ name })
		.find((c) => c.attributes('data-id') === id)
}

describe('CtiSourceFields', () => {
	beforeEach(() => {
		get.mockReset()
		answer()
	})

	it('offers the bindings the providers endpoint lists, labelling the known ones', async () => {
		answer({
			data: {
				providers: [
					{ id: 'log' },
					{ id: 'webhook' },
					{ id: 'sip-trunk' },
					{ id: '' },
				],
			},
		})
		const wrapper = mountFields()
		await flushPromises()

		expect(
			get.mock.calls.some(([url]) =>
				url.endsWith('/apps/integriq/api/cti/providers'),
			),
		).toBe(true)
		const provider = control(wrapper, 'NcSelect', 'cn-cti-provider')
		expect(provider.props('options')).toEqual([
			{ id: 'log', label: 'Log (test events in our own shape)' },
			{ id: 'webhook', label: 'Webhook (the phone system posts events)' },
			{ id: 'sip-trunk', label: 'sip-trunk' },
		])
		expect(wrapper.find('[data-testid="cti-no-bindings"]').exists()).toBe(false)

		provider.vm.$emit('update:modelValue', { id: 'webhook' })
		expect(lastEmitted(wrapper).provider).toBe('webhook')
	})

	it('says so when the instance has no binding, or the list cannot be read', async () => {
		get.mockImplementation((url) =>
			url.includes('/api/cti/providers')
				? Promise.reject(new Error('down'))
				: Promise.resolve(SOURCES),
		)
		const wrapper = mountFields()
		await flushPromises()
		expect(
			control(wrapper, 'NcSelect', 'cn-cti-provider').props('options'),
		).toEqual([])
		expect(wrapper.find('[data-testid="cti-no-bindings"]').exists()).toBe(true)
	})

	it('shows an unknown stored binding as itself rather than as empty', () => {
		const provider = control(
			mountFields({ provider: 'asterisk' }),
			'NcSelect',
			'cn-cti-provider',
		)
		expect(provider.props('modelValue')).toEqual({
			id: 'asterisk',
			label: 'asterisk',
		})
	})

	it('offers only KISS sources to look callers up in', async () => {
		const wrapper = mountFields()
		await flushPromises()

		const call = get.mock.calls.find(([url]) =>
			url.includes('/apps/openregister/api/objects/integriq/source'),
		)
		expect(call[1].params).toMatchObject({ type: 'kiss' })
		const lookup = control(wrapper, 'NcSelect', 'cn-cti-caller-lookup-source')
		expect(lookup.props('options')).toEqual([
			{ id: 'kiss-uuid', label: 'Klantinteracties' },
		])

		lookup.vm.$emit('update:modelValue', { id: 'kiss-uuid' })
		expect(lastEmitted(wrapper).callerLookupSource).toBe('kiss-uuid')

		lookup.vm.$emit('update:modelValue', null)
		expect(lastEmitted(wrapper).callerLookupSource).toBe('')
	})

	it('leaves the lookup list empty when the fetch fails', async () => {
		get.mockImplementation((url) =>
			url.includes('/api/cti/providers')
				? Promise.resolve(PROVIDERS)
				: Promise.reject(new Error('down')),
		)
		const wrapper = mountFields()
		await flushPromises()
		const lookup = control(wrapper, 'NcSelect', 'cn-cti-caller-lookup-source')
		expect(lookup.props('options')).toEqual([])
	})

	it('asks the log binding for a shared secret only', async () => {
		const wrapper = mountFields({ provider: 'log' })
		await flushPromises()
		const ids = wrapper
			.findAllComponents({ name: 'NcTextField' })
			.map((c) => c.attributes('data-id'))
		expect(ids).toEqual(['cn-cti-shared-secret'])

		control(wrapper, 'NcTextField', 'cn-cti-shared-secret').vm.$emit(
			'update:modelValue',
			's3cret',
		)
		expect(lastEmitted(wrapper)).toEqual({
			provider: 'log',
			sharedSecret: 's3cret',
		})
	})

	it('writes every webhook key under the name the backend reads, keeping the rest', async () => {
		const wrapper = mountFields({ provider: 'webhook', unrelated: 'kept' })
		await flushPromises()
		const text = (id) => control(wrapper, 'NcTextField', id)

		text('cn-cti-signature-secret').vm.$emit('update:modelValue', 'hmac-key')
		text('cn-cti-events-path').vm.$emit('update:modelValue', ' data.events ')
		text('cn-cti-field-callId').vm.$emit('update:modelValue', 'call.uuid')
		text('cn-cti-field-callerNumber').vm.$emit('update:modelValue', 'call.from')
		text('cn-cti-kind-ringing').vm.$emit('update:modelValue', 'alerting')
		text('cn-cti-kind-ended').vm.$emit('update:modelValue', 'hangup')

		expect(lastEmitted(wrapper)).toEqual({
			provider: 'webhook',
			unrelated: 'kept',
			signatureSecret: 'hmac-key',
			eventsPath: 'data.events',
			fieldMapping: { callId: 'call.uuid', callerNumber: 'call.from' },
			kindMapping: { alerting: 'ringing', hangup: 'ended' },
		})
	})

	it('removes a field mapping entry when its path is cleared', async () => {
		const wrapper = mountFields({
			provider: 'webhook',
			fieldMapping: { callId: 'call.uuid', at: 'ts' },
		})
		await flushPromises()
		control(wrapper, 'NcTextField', 'cn-cti-field-at').vm.$emit(
			'update:modelValue',
			'  ',
		)
		expect(lastEmitted(wrapper).fieldMapping).toEqual({ callId: 'call.uuid' })
	})

	it('reads a stored kind mapping back into one field per kind', () => {
		const wrapper = mountFields({
			provider: 'webhook',
			kindMapping: { alerting: 'ringing', connected: 'answered' },
		})
		expect(
			control(wrapper, 'NcTextField', 'cn-cti-kind-ringing').props(
				'modelValue',
			),
		).toBe('alerting')
		expect(
			control(wrapper, 'NcTextField', 'cn-cti-kind-answered').props(
				'modelValue',
			),
		).toBe('connected')
		expect(
			control(wrapper, 'NcTextField', 'cn-cti-kind-ended').props('modelValue'),
		).toBe('')
	})

	it('warns while the webhook binding has neither secret, and stops once one is set', async () => {
		const wrapper = mountFields({ provider: 'webhook' })
		expect(wrapper.find('[data-testid="cti-no-secret"]').exists()).toBe(true)

		control(wrapper, 'NcTextField', 'cn-cti-shared-secret').vm.$emit(
			'update:modelValue',
			'key',
		)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="cti-no-secret"]').exists()).toBe(false)
	})

	it('shows the events address of a saved source only', () => {
		expect(
			mountFields({}, '').find('[data-testid="cti-events-url"]').exists(),
		).toBe(false)
		const url = mountFields({}, 'src-1')
			.find('[data-testid="cti-events-url"]')
			.text()
		expect(url).toMatch(/\/apps\/integriq\/api\/cti\/src-1\/events$/)
	})
})

describe('kind mapping helpers', () => {
	it('stores vendor word to our kind, leaving out words equal to our kind and empty ones', () => {
		expect(
			kindMappingFrom({
				ringing: ' alerting ',
				answered: 'answered',
				ended: '',
				transferred: 'xfer',
			}),
		).toEqual({ alerting: 'ringing', xfer: 'transferred' })
	})

	it('ignores stored entries that name none of our kinds', () => {
		expect(
			vendorWordsFrom({ alerting: 'ringing', parked: 'hold', bad: 3 }),
		).toEqual({ ringing: 'alerting' })
		expect(vendorWordsFrom(['x'])).toEqual({})
		expect(vendorWordsFrom(null)).toEqual({})
	})
})

describe('SourceFormFields and the cti type', () => {
	beforeEach(() => {
		get.mockReset()
		get.mockResolvedValue({ data: { results: [], providers: [] } })
	})

	/**
	 * Mount the source editor over one form.
	 *
	 * @param {object} formData the form data
	 * @return {object} the wrapper and its updateField spy
	 */
	function mountEditor(formData) {
		const updateField = vi.fn()
		const wrapper = mount(SourceFormFields, {
			props: {
				fields: [
					{ key: 'type', label: 'Type' },
					{ key: 'configuration', label: 'Configuration', widget: 'json' },
				],
				formData,
				updateField,
			},
		})
		return { wrapper, updateField }
	}

	it('offers cti and kiss as source types', () => {
		const { wrapper } = mountEditor({ type: 'api' })
		const ids = wrapper
			.findAllComponents({ name: 'NcSelect' })[0]
			.props('options')
			.map((o) => o.id)
		expect(ids).toContain('cti')
		expect(ids).toContain('kiss')
	})

	it('shows the telephony fields instead of the raw configuration only on a cti source', async () => {
		const api = mountEditor({ type: 'api', configuration: {} }).wrapper
		expect(api.findComponent(CtiSourceFields).exists()).toBe(false)
		expect(api.find('#cn-source-form-configuration').exists()).toBe(true)

		const { wrapper, updateField } = mountEditor({
			type: 'cti',
			id: 'src-9',
			configuration: { provider: 'log' },
		})
		await flushPromises()
		const fields = wrapper.findComponent(CtiSourceFields)
		expect(fields.exists()).toBe(true)
		expect(fields.props('configuration')).toEqual({ provider: 'log' })
		expect(fields.props('sourceId')).toBe('src-9')
		expect(wrapper.find('#cn-source-form-configuration').exists()).toBe(false)

		fields.vm.$emit('update:configuration', { provider: 'webhook' })
		expect(updateField).toHaveBeenCalledWith('configuration', {
			provider: 'webhook',
		})
	})
})

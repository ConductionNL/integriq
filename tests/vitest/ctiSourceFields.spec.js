// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A CTI source reads everything from its configuration: the binding it names
 * (CtiSourceResolver), the secrets and the field and kind mappings
 * (WebhookCtiProvider, CallEventNormaliser) and the KISS source callers are
 * looked up in (ConfiguredCallerSearch). Until now the source editor could
 * not pick the type at all, so an administrator wrote that as raw JSON. These
 * tests mount the real SourceFormFields and the real CtiSourceFields and check
 * that every key is written under the name the backend reads, and that the
 * ids the form offers are the ids the PHP side switches on.
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CtiSourceFields, {
	CTI_CALL_FIELDS,
	CTI_CALL_KINDS,
	CTI_PROVIDERS,
} from '@/modals/v2/CtiSourceFields.vue'
import SourceFormFields from '@/modals/v2/SourceFormFields.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const field = (name) =>
		defineComponent({
			name,
			props: ['modelValue', 'label', 'inputLabel', 'options', 'id', 'inputId', 'type'],
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
		NcButton: defineComponent({
			name: 'NcButton',
			render: () => h('button'),
		}),
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

const root = join(__dirname, '..', '..')

/**
 * Read a PHP file of the app.
 *
 * @param {string} path the path from the app root
 * @return {string} the file
 */
function php(path) {
	return readFileSync(join(root, path), 'utf8')
}

const SOURCES = {
	data: {
		results: [
			{ id: 'kiss-rest', name: 'KISS', type: 'kiss', configuration: { provider: 'rest' } },
			{ id: 'kiss-log', name: 'KISS sandbox', type: 'kiss', configuration: { provider: 'log' } },
			{ id: 'zaken', name: 'Zaken API', type: 'api' },
		],
	},
}

/**
 * Mount the CTI fields over a configuration.
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
 * The text field with a label.
 *
 * @param {object} wrapper the wrapper
 * @param {string} label the label
 * @return {object|undefined} the field
 */
function text(wrapper, label) {
	return wrapper
		.findAllComponents({ name: 'NcTextField' })
		.find((field) => field.attributes('data-label') === label)
}

/**
 * The select with an input id.
 *
 * @param {object} wrapper the wrapper
 * @param {string} id the input id
 * @return {object|undefined} the select
 */
function select(wrapper, id) {
	return wrapper
		.findAllComponents({ name: 'NcSelect' })
		.find((field) => field.attributes('data-id') === id)
}

describe('CtiSourceFields', () => {
	beforeEach(() => {
		get.mockReset()
		get.mockResolvedValue(SOURCES)
	})

	it('offers exactly the bindings CtiSourceResolver resolves', () => {
		const ids = [...php('lib/Service/Kiss/LogCtiProvider.php').matchAll(/function getProviderId\(\): string \{\s*return '([a-z]+)';/g)]
			.concat([...php('lib/Service/Kiss/WebhookCtiProvider.php').matchAll(/function getProviderId\(\): string \{\s*return '([a-z]+)';/g)])
			.map((match) => match[1])
		expect(ids).toHaveLength(2)
		expect(CTI_PROVIDERS.map((provider) => provider.id).sort()).toEqual(ids.sort())
	})

	it('maps exactly the fields the normaliser reads, and the kinds CallEvent carries', () => {
		const fields = [...php('lib/Service/Kiss/CallEventNormaliser.php').matchAll(/name: '([A-Za-z]+)'/g)].map((m) => m[1])
		expect(CTI_CALL_FIELDS.map((field) => field.key).sort()).toEqual([...new Set(fields)].sort())

		const kinds = [...php('lib/Event/CallEvent.php').matchAll(/const KIND_[A-Z]+ = '([a-z]+)'/g)].map((m) => m[1])
		expect(CTI_CALL_KINDS.map((kind) => kind.id).sort()).toEqual(kinds.sort())
	})

	it('offers only KISS sources on the REST provider for the caller lookup', async () => {
		const wrapper = mountFields()
		await flushPromises()
		expect(get.mock.calls[0][0]).toContain('/apps/openregister/api/objects/integriq/source')
		expect(select(wrapper, 'cn-cti-caller-lookup-source').props('options')).toEqual([
			{ id: 'kiss-rest', label: 'KISS' },
		])
	})

	it('writes the provider, secrets, path and lookup source under the names the backend reads, keeping the rest', async () => {
		const wrapper = mountFields({ unrelated: 'kept' })
		await flushPromises()

		select(wrapper, 'cn-cti-provider').vm.$emit('update:modelValue', { id: 'webhook' })
		await wrapper.vm.$nextTick()
		text(wrapper, 'Signature secret').vm.$emit('update:modelValue', 'sig')
		text(wrapper, 'Shared secret').vm.$emit('update:modelValue', 'key')
		text(wrapper, 'Events path').vm.$emit('update:modelValue', 'data.events')
		select(wrapper, 'cn-cti-caller-lookup-source').vm.$emit('update:modelValue', { id: 'kiss-rest' })

		expect(lastEmitted(wrapper)).toEqual({
			unrelated: 'kept',
			provider: 'webhook',
			signatureSecret: 'sig',
			sharedSecret: 'key',
			eventsPath: 'data.events',
			callerLookupSource: 'kiss-rest',
		})
	})

	it('writes the field mapping as our key to the vendor path, dropping an emptied field', async () => {
		const wrapper = mountFields({ provider: 'webhook', fieldMapping: { at: 'timestamp' } })
		await flushPromises()

		text(wrapper, 'Call id').vm.$emit('update:modelValue', ' call.uuid ')
		text(wrapper, 'Caller number').vm.$emit('update:modelValue', 'from')
		text(wrapper, 'Time').vm.$emit('update:modelValue', '')

		expect(lastEmitted(wrapper).fieldMapping).toEqual({ callId: 'call.uuid', callerNumber: 'from' })
	})

	it('writes the kind mapping vendor word to ours, as the normaliser reads it', async () => {
		const wrapper = mountFields({ provider: 'webhook', kindMapping: { hangup: 'ended' } })
		await flushPromises()

		expect(text(wrapper, 'Ended').props('modelValue')).toBe('hangup')
		text(wrapper, 'Ringing').vm.$emit('update:modelValue', 'alerting, ring')
		text(wrapper, 'Ended').vm.$emit('update:modelValue', 'released')

		expect(lastEmitted(wrapper).kindMapping).toEqual({
			alerting: 'ringing',
			ring: 'ringing',
			released: 'ended',
		})
	})

	it('shows only the shared secret for the sandbox, and warns while no secret is set', async () => {
		const wrapper = mountFields({ provider: 'log' })
		await flushPromises()
		expect(text(wrapper, 'Shared secret')).toBeDefined()
		expect(text(wrapper, 'Signature secret')).toBeUndefined()
		expect(text(wrapper, 'Call id')).toBeUndefined()
		expect(wrapper.text()).toContain('every call to this source is refused')

		const configured = mountFields({ provider: 'log', sharedSecret: 'key' })
		await flushPromises()
		expect(configured.text()).not.toContain('every call to this source is refused')
	})

	it('shows a stored provider this instance has no binding for as itself, with a warning', async () => {
		const wrapper = mountFields({ provider: 'asterisk' })
		await flushPromises()
		expect(select(wrapper, 'cn-cti-provider').props('modelValue')).toEqual({ id: 'asterisk', label: 'asterisk' })
		expect(wrapper.text()).toContain('not installed on this instance')
	})

	it('shows the events URL once the source is saved', async () => {
		const unsaved = mountFields({ provider: 'webhook' })
		await flushPromises()
		expect(unsaved.find('code').exists()).toBe(false)

		const saved = mountFields({ provider: 'webhook' }, 'abc-123')
		await flushPromises()
		expect(saved.find('code').text()).toContain('/apps/integriq/api/cti/abc-123/events')
	})

	it('still renders when the source fetch fails, with an empty lookup list', async () => {
		get.mockRejectedValue(new Error('down'))
		const wrapper = mountFields()
		await flushPromises()
		expect(select(wrapper, 'cn-cti-caller-lookup-source').props('options')).toEqual([])
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
				fields: [{ key: 'type', label: 'Type' }, { key: 'configuration', label: 'Configuration' }],
				formData,
				updateField,
			},
		})
		return { wrapper, updateField }
	}

	it('offers cti as a source type', () => {
		const { wrapper } = mountEditor({ type: 'api' })
		const type = wrapper.findAllComponents({ name: 'NcSelect' })[0]
		expect(type.props('options').map((o) => o.id)).toContain('cti')
	})

	it('shows the CTI fields only on a cti source, and hands them the saved id', async () => {
		expect(mountEditor({ type: 'api' }).wrapper.findComponent(CtiSourceFields).exists()).toBe(false)

		const { wrapper, updateField } = mountEditor({
			'@self': { id: 'cti-uuid' },
			type: 'cti',
			configuration: { provider: 'log' },
		})
		await flushPromises()
		const fields = wrapper.findComponent(CtiSourceFields)
		expect(fields.exists()).toBe(true)
		expect(fields.props('configuration')).toEqual({ provider: 'log' })
		expect(fields.props('sourceId')).toBe('cti-uuid')

		fields.vm.$emit('update:configuration', { provider: 'webhook' })
		expect(updateField).toHaveBeenCalledWith('configuration', { provider: 'webhook' })
	})
})

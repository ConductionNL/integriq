// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The intake routing rule editor, mounted: what the form shows for a rule,
 * how each input writes the draft, the held-message picker, the test result
 * table, and the refusals placed on their field. The HTTP client and the
 * Nextcloud inputs are stubbed; the draft helpers are the real ones.
 *
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-draft-rule-is-tested-against-a-sample-message-without-side-effects-req-ire-002
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axios = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axios }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, tag = 'div') => defineComponent({
		name,
		props: ['modelValue', 'label', 'inputLabel', 'options', 'disabled', 'type', 'error', 'helperText', 'loading', 'name', 'noClose'],
		emits: ['update:modelValue', 'open', 'click', 'closing', 'blur'],
		render() {
			return h(tag, { class: name, onClick: () => this.$emit('click') }, [
				this.$slots.icon?.(),
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	})
	return {
		NcButton: stub('NcButton', 'button'),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
		NcDialog: stub('NcDialog'),
		NcLoadingIcon: stub('NcLoadingIcon'),
		NcNoteCard: stub('NcNoteCard'),
		NcSelect: stub('NcSelect'),
		NcTextArea: stub('NcTextArea'),
		NcTextField: stub('NcTextField'),
	}
})

const Editor = (await import('@/modals/v2/IntakeRuleEditorModal.vue')).default

const CHANNELS = [
	{ channelId: 'teams', label: 'Microsoft Teams', supportsLocation: false, fields: [] },
	{ channelId: 'meldingen', supportsLocation: true, fields: ['category'] },
]
const REGISTERS = [{ title: 'Zaken', schemas: [1] }]
const SCHEMAS = [{ id: 1, slug: 'melding', title: 'Melding', properties: { omschrijving: {}, locatie: {} } }]
const RULE = {
	id: 'r1',
	name: 'Teams naar melding',
	channelId: 'teams',
	targetSchema: 'melding',
	condition: { field: 'text', operator: 'contains', value: 'kapot' },
	fieldMapping: { omschrijving: 'text' },
	order: 3,
	isEnabled: true,
}

/**
 * Answer the option lists the editor loads when it opens.
 *
 * @param {object} [overrides] Replacement answers by URL suffix.
 * @return {void}
 */
function answerLists(overrides = {}) {
	axios.get.mockImplementation(async (url, config) => {
		for (const [suffix, answer] of Object.entries(overrides)) {
			if (url.endsWith(suffix)) {
				return typeof answer === 'function' ? answer(url, config) : answer
			}
		}
		if (url.endsWith('/intake/channels')) return { data: { channels: CHANNELS } }
		if (url.endsWith('/registers')) return { data: { results: REGISTERS } }
		if (url.endsWith('/schemas')) return { data: { results: SCHEMAS } }
		return { data: { results: [] } }
	})
}

/**
 * Mount an open editor and let its lists load.
 *
 * @param {object|null} item The rule, or null to create.
 * @return {Promise<{wrapper: object, close: Function, refresh: Function}>}
 */
async function open(item = null) {
	const close = vi.fn()
	const refresh = vi.fn()
	const wrapper = mount(Editor, { props: { show: true, item, close, refresh } })
	await flushPromises()
	return { wrapper, close, refresh }
}

/**
 * The stubbed input with a label or input label.
 *
 * @param {object} wrapper The editor.
 * @param {string} kind    The component name.
 * @param {string} label   The (input) label.
 * @return {object}
 */
function input(wrapper, kind, label) {
	const found = wrapper.findAllComponents({ name: kind }).filter((c) => (c.props('inputLabel') || c.props('label')) === label)
	return found[0]
}

beforeEach(() => {
	vi.clearAllMocks()
	answerLists()
})

describe('IntakeRuleEditorModal mounted', () => {
	it('renders nothing while closed', () => {
		const wrapper = mount(Editor, { props: { show: false } })
		expect(wrapper.findComponent({ name: 'NcDialog' }).exists()).toBe(false)
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('shows an existing rule with its channel, case type, condition and mapping', async () => {
		const { wrapper } = await open(RULE)
		expect(wrapper.findComponent({ name: 'NcDialog' }).props('name')).toBe('Edit routing rule')
		expect(input(wrapper, 'NcTextField', 'Name *').props('modelValue')).toBe('Teams naar melding')
		expect(input(wrapper, 'NcTextField', 'Order').props('modelValue')).toBe('3')
		expect(input(wrapper, 'NcSelect', 'Channel *').props('modelValue')).toEqual({ id: 'teams', label: 'Microsoft Teams (teams)' })
		expect(input(wrapper, 'NcSelect', 'Channel *').props('options').map((o) => o.label)).toEqual(['Microsoft Teams (teams)', 'meldingen'])
		expect(input(wrapper, 'NcSelect', 'Opens (case type) *').props('modelValue')).toMatchObject({ id: 'melding', label: 'Zaken – Melding' })
		expect(input(wrapper, 'NcSelect', 'Operator').props('modelValue')).toEqual({ id: 'contains', label: 'contains' })
		expect(input(wrapper, 'NcTextField', 'Value').props('modelValue')).toBe('kapot')
		expect(wrapper.findAll('[data-testid="intake-rule-mapping-row"]')).toHaveLength(1)
		expect(input(wrapper, 'NcSelect', 'Case field').props('options')).toEqual(['omschrijving', 'locatie'])
		expect(input(wrapper, 'NcSelect', 'Location field')).toBeUndefined()
	})

	it('warns when the lists could not be loaded and offers no channels', async () => {
		answerLists({ '/intake/channels': () => Promise.reject(new Error('down')) })
		const { wrapper } = await open()
		expect(wrapper.findComponent({ name: 'NcDialog' }).props('name')).toBe('New routing rule')
		expect(wrapper.text()).toContain('The channels or case types could not be loaded.')
		expect(input(wrapper, 'NcSelect', 'Channel *').props('options')).toEqual([])
		expect(input(wrapper, 'NcSelect', 'Opens (case type) *').props('options')).toHaveLength(1)
	})

	it('writes each input into the draft and saves what was typed', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const { wrapper, close, refresh } = await open()
		input(wrapper, 'NcTextField', 'Name *').vm.$emit('update:modelValue', 'Meldingen')
		input(wrapper, 'NcTextField', 'Order').vm.$emit('update:modelValue', '7')
		input(wrapper, 'NcTextArea', 'Description').vm.$emit('update:modelValue', 'Alles van buiten')
		input(wrapper, 'NcSelect', 'Channel *').vm.$emit('update:modelValue', { id: 'meldingen' })
		input(wrapper, 'NcSelect', 'Opens (case type) *').vm.$emit('update:modelValue', { id: 'melding' })
		await flushPromises()
		input(wrapper, 'NcSelect', 'Field').vm.$emit('update:modelValue', 'fields.category')
		await flushPromises()
		input(wrapper, 'NcSelect', 'Operator').vm.$emit('update:modelValue', { id: 'exists' })
		await flushPromises()
		expect(input(wrapper, 'NcTextField', 'Value')).toBeUndefined()
		input(wrapper, 'NcSelect', 'Location field').vm.$emit('update:modelValue', 'locatie')
		wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })[0].vm.$emit('update:modelValue', false)
		await flushPromises()

		await wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.text() === 'Add mapping').trigger('click')
		await flushPromises()
		input(wrapper, 'NcSelect', 'Case field').vm.$emit('update:modelValue', 'omschrijving')
		input(wrapper, 'NcSelect', 'From the message').vm.$emit('update:modelValue', 'text')
		await flushPromises()

		await wrapper.vm.onSave()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/intake/routing-rules', {
			name: 'Meldingen',
			description: 'Alles van buiten',
			channelId: 'meldingen',
			condition: expect.objectContaining({ field: 'fields.category', operator: 'exists' }),
			targetSchema: 'melding',
			fieldMapping: { omschrijving: 'text' },
			locationField: 'locatie',
			order: 7,
			isEnabled: false,
		})
		expect(refresh).toHaveBeenCalledTimes(1)
		expect(close).toHaveBeenCalledTimes(1)
	})

	it('removes a mapping row and a cleared select writes an empty value', async () => {
		const { wrapper } = await open(RULE)
		input(wrapper, 'NcSelect', 'Case field').vm.$emit('update:modelValue', null)
		await flushPromises()
		expect(wrapper.vm.draft.mappings).toEqual([{ target: '', source: 'text' }])
		await wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.props('disabled') === false && b.text() === '').trigger('click')
		await flushPromises()
		expect(wrapper.findAll('[data-testid="intake-rule-mapping-row"]')).toHaveLength(0)
	})

	it('shows a name error after the name was left empty', async () => {
		const { wrapper } = await open()
		expect(input(wrapper, 'NcTextField', 'Name *').props('helperText')).toBe('')
		input(wrapper, 'NcTextField', 'Name *').vm.$emit('blur')
		await flushPromises()
		expect(input(wrapper, 'NcTextField', 'Name *').props('helperText')).toBe('Name is required')
		input(wrapper, 'NcTextField', 'Name *').vm.$emit('update:modelValue', '---')
		await flushPromises()
		expect(input(wrapper, 'NcTextField', 'Name *').props('error')).toBe(true)
	})

	it('places refusals on the channel, the case type and the location field', async () => {
		const { wrapper } = await open({ ...RULE, locationField: 'plek' })
		const refuse = (error) => axios.put.mockRejectedValueOnce({ response: { data: { error } } })

		refuse('No intake channel adapter answers to "teams".')
		expect(await wrapper.vm.onSave()).toBe(false)
		await flushPromises()
		expect(wrapper.find('[data-testid="intake-rule-channel"] .intake-rule-editor__error').text()).toBe('No intake channel adapter answers to "teams".')

		refuse('A routing rule must name the case type it opens.')
		await wrapper.vm.onSave()
		await flushPromises()
		expect(wrapper.find('[data-testid="intake-rule-case-type"] .intake-rule-editor__error').text()).toBe('A routing rule must name the case type it opens.')
		input(wrapper, 'NcSelect', 'Opens (case type) *').vm.$emit('update:modelValue', null)
		await flushPromises()
		expect(wrapper.find('[data-testid="intake-rule-case-type"] .intake-rule-editor__error').exists()).toBe(false)

		input(wrapper, 'NcSelect', 'Opens (case type) *').vm.$emit('update:modelValue', { id: 'melding' })
		refuse('Case type "melding" has no field "plek".')
		await wrapper.vm.onSave()
		await flushPromises()
		expect(input(wrapper, 'NcSelect', 'Location field')).toBeDefined()
		expect(wrapper.text()).toContain('Case type "melding" has no field "plek".')
		expect(wrapper.vm.fieldErrors.locationField).toBe('Case type "melding" has no field "plek".')
	})

	it('marks the refused mapping row', async () => {
		const { wrapper } = await open(RULE)
		axios.put.mockRejectedValueOnce({ response: { data: { error: 'Case type "melding" has no field "omschrijving".' } } })
		await wrapper.vm.onSave()
		await flushPromises()
		expect(wrapper.find('.intake-rule-editor__mapping--refused').exists()).toBe(true)
		input(wrapper, 'NcSelect', 'From the message').vm.$emit('update:modelValue', 'externalId')
		await flushPromises()
		expect(wrapper.find('.intake-rule-editor__mapping--refused').exists()).toBe(false)
	})

	it('saves nothing without a refresh function and still closes', async () => {
		axios.put.mockResolvedValue({ data: {} })
		const close = vi.fn()
		const wrapper = mount(Editor, { props: { show: true, item: RULE, close } })
		await flushPromises()
		expect(await wrapper.vm.onSave()).toBe(true)
		expect(close).toHaveBeenCalledTimes(1)
	})

	it('cancels through the dialog and the cancel button, but not while saving', async () => {
		const { wrapper, close } = await open(RULE)
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('closing')
		expect(close).toHaveBeenCalledTimes(1)
		await wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.text() === 'Cancel').trigger('click')
		expect(close).toHaveBeenCalledTimes(2)
		await wrapper.setData({ saving: true })
		wrapper.vm.onCancel()
		expect(close).toHaveBeenCalledTimes(2)
	})
})

describe('IntakeRuleEditorModal test section', () => {
	const HELD = [
		{ id: 'm1', channelId: 'teams', text: 'De lantaarnpaal is kapot', receivedAt: '2026-11-02T10:00:00Z', correspondent: { name: 'Ann' } },
		{ id: 'm2', channelId: 'meldingen', externalId: 'EXT-9', receivedAt: '2026-11-01T10:00:00Z' },
		{ '@self': { id: 'm3' }, channelId: 'teams', receivedAt: '2026-10-01T10:00:00Z' },
	]

	it('loads held messages once, on the channel of the draft, labelled by text or external id', async () => {
		answerLists({ '/intake_message': (url, { params }) => ({ data: { results: params.status === 'held' ? HELD : [] } }) })
		const { wrapper } = await open(RULE)
		const picker = input(wrapper, 'NcSelect', 'Held message')
		picker.vm.$emit('open')
		picker.vm.$emit('open')
		await flushPromises()
		picker.vm.$emit('open')
		await flushPromises()
		const calls = axios.get.mock.calls.filter(([url]) => url.endsWith('/intake_message'))
		expect(calls).toHaveLength(2)
		expect(input(wrapper, 'NcSelect', 'Held message').props('options').map((o) => [o.id, o.label])).toEqual([['m1', 'De lantaarnpaal is kapot'], ['m3', 'm3']])

		input(wrapper, 'NcSelect', 'Channel *').vm.$emit('update:modelValue', null)
		await flushPromises()
		expect(input(wrapper, 'NcSelect', 'Held message').props('options').map((o) => o.label)).toEqual(['De lantaarnpaal is kapot', 'EXT-9', 'm3'])
	})

	it('keeps no held messages when they could not be read, and may try again', async () => {
		answerLists({ '/intake_message': () => Promise.reject(new Error('down')) })
		const { wrapper } = await open(RULE)
		await wrapper.vm.loadHeldMessages()
		expect(wrapper.vm.heldMessages).toEqual([])
		expect(wrapper.vm.heldLoaded).toBe(false)
		expect(wrapper.vm.heldLoading).toBe(false)
	})

	it('tests a picked held message instead of typed text and hides the text box', async () => {
		axios.post.mockResolvedValue({ data: { matches: true, payload: { omschrijving: 'x', extra: { a: 1 }, leeg: null } } })
		const { wrapper } = await open(RULE)
		expect(input(wrapper, 'NcTextArea', 'Sample message text')).toBeDefined()
		input(wrapper, 'NcSelect', 'Held message').vm.$emit('update:modelValue', { id: 'm1', label: 'x', message: HELD[0] })
		await flushPromises()
		expect(input(wrapper, 'NcTextArea', 'Sample message text')).toBeUndefined()
		await wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.text() === 'Test').trigger('click')
		await flushPromises()
		const [, body] = axios.post.mock.calls[0]
		expect(body.sample).toEqual({ text: 'De lantaarnpaal is kapot', receivedAt: '2026-11-02T10:00:00Z', correspondent: { name: 'Ann' } })
		const result = wrapper.find('[data-testid="intake-rule-test-result"]')
		expect(result.text()).toContain('The rule matches this message.')
		expect(result.text()).toContain('No saved rule matches this message today, so it would be held.')
		expect(result.findAll('tbody tr').map((tr) => [tr.find('th').text(), tr.find('td').text()])).toEqual([['omschrijving', 'x'], ['extra', '{"a":1}'], ['leeg', '']])
	})

	it('shows no payload table for a result without a payload, and a miss as a warning', async () => {
		axios.post.mockResolvedValue({ data: { matches: false, payload: 'none', winningRule: { name: 'Ander', order: 1 } } })
		const { wrapper } = await open(RULE)
		input(wrapper, 'NcTextArea', 'Sample message text').vm.$emit('update:modelValue', 'hallo')
		await flushPromises()
		await wrapper.vm.runTest()
		await flushPromises()
		expect(axios.post.mock.calls[0][1].sample).toEqual({ text: 'hallo' })
		const result = wrapper.find('[data-testid="intake-rule-test-result"]')
		expect(result.text()).toContain('The rule does not match this message.')
		expect(result.text()).toContain('Today the saved rule "Ander" (order 1) wins for this message.')
		expect(result.find('table').exists()).toBe(false)
	})

	it('cannot test without a channel and a case type', async () => {
		const { wrapper } = await open()
		const button = wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.text() === 'Test')
		expect(button.props('disabled')).toBe(true)
	})
})

// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rule detail page as an editor uses it: it loads the rule (or says it
 * is missing), every basic field writes the draft, the conditions switch
 * between the visual builder and raw JSON (with the parse error shown), the
 * action editor writes type and configuration, save stores the draft and is
 * refused for a flow rule without a flow, undo restores the stored rule, and
 * a live update replaces the draft only while nothing is being edited.
 */
import { translate } from '@nextcloud/l10n'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({
	fetchObject: vi.fn(),
	saveObject: vi.fn(),
	registerObjectType: vi.fn(),
	errors: {},
}))

vi.mock('../../src/store/objectStore.js', () => ({ useObjectStore: () => store }))
vi.mock('../../src/mixins/liveObjectSubscription.js', () => ({
	default: { methods: { syncLiveSubscription: vi.fn() } },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDetailPage: {
		name: 'CnDetailPage',
		props: ['title', 'description', 'icon', 'loading', 'error', 'errorMessage', 'onRetry', 'empty'],
		template: '<section><slot name="actions" /><slot /></section>',
	},
	CnDetailCard: { name: 'CnDetailCard', props: ['title', 'icon'], template: '<div class="card"><slot name="actions" /><slot /></div>' },
}))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const field = (name) => defineComponent({
		name,
		props: ['modelValue', 'label', 'type', 'placeholder', 'options', 'ariaLabelCombobox', 'clearable', 'inputId'],
		emits: ['update:modelValue'],
		render() {
			return h('div', { class: name })
		},
	})
	return {
		NcButton: { name: 'NcButton', props: ['disabled', 'variant'], template: '<button :disabled="disabled"><slot /></button>' },
		NcLoadingIcon: { template: '<i />' },
		NcSelect: field('NcSelect'),
		NcTextField: field('NcTextField'),
	}
})
for (const icon of ['CodeJson', 'ContentSave', 'Undo']) {
	vi.doMock(`vue-material-design-icons/${icon}.vue`, () => ({ default: { template: '<i />' } }))
}
vi.mock('../../src/components/AutomationDeprecationNotice.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../../src/views/Rule/RuleConditionGroup.vue', () => ({
	default: { name: 'RuleConditionGroup', props: ['node', 'removable'], emits: ['update'], template: '<div class="group" />' },
}))
vi.mock('../../src/views/Rule/RuleActionConfig.vue', () => ({
	default: { name: 'RuleActionConfig', props: ['configuration', 'type'], emits: ['update', 'update:type'], template: '<div class="action" />' },
}))

const RuleDetailPage = (await import('../../src/views/Rule/RuleDetailPage.vue')).default

const RULE = { id: 'r1', name: 'Block deletes', action: 'delete', timing: 'after', order: 2, description: 'd', conditions: { and: [{ '==': [1, 1] }] }, type: 'error', configuration: { code: 403 } }

async function mountPage(props = { id: 'r1' }) {
	const wrapper = mount(RuleDetailPage, { props, global: { mocks: { t: translate } } })
	await flushPromises()
	return wrapper
}

function select(wrapper, label) {
	return wrapper.findAllComponents({ name: 'NcSelect' }).find((c) => c.props('ariaLabelCombobox') === label)
}

function textField(wrapper, label) {
	return wrapper.findAllComponents({ name: 'NcTextField' }).find((c) => c.props('label') === label)
}

function button(wrapper, text) {
	return wrapper.findAll('button').find((b) => b.text() === text)
}

beforeEach(() => {
	store.fetchObject.mockReset()
	store.saveObject.mockReset()
	store.registerObjectType.mockReset()
	store.errors = {}
})

describe('RuleDetailPage loading', () => {
	it('registers the rule type and loads the rule into the page', async () => {
		store.fetchObject.mockResolvedValue(RULE)
		const wrapper = await mountPage({ id: 'r1', register: 'reg', schema: 'sch' })

		expect(store.registerObjectType).toHaveBeenCalledWith('rule', 'sch', 'reg')
		expect(store.fetchObject).toHaveBeenCalledWith('rule', 'r1')
		const page = wrapper.findComponent({ name: 'CnDetailPage' })
		expect(page.props()).toMatchObject({ title: 'Rule: Block deletes', loading: false, error: false, empty: false })
		expect(textField(wrapper, 'Name *').props('modelValue')).toBe('Block deletes')
		expect(select(wrapper, 'Action').props('modelValue').id).toBe('delete')
		expect(select(wrapper, 'Timing').props('modelValue').id).toBe('after')
		expect(textField(wrapper, 'Order').props('modelValue')).toBe('2')
		expect(wrapper.find('#rule-description').element.value).toBe('d')
		expect(wrapper.findComponent({ name: 'RuleActionConfig' }).props()).toEqual({ configuration: { code: 403 }, type: 'error' })
		expect(wrapper.vm.dirty).toBe(false)
	})

	it('names a missing rule, a store error and a thrown error, and retries', async () => {
		store.fetchObject.mockResolvedValueOnce(null)
		const missing = await mountPage()
		expect(missing.findComponent({ name: 'CnDetailPage' }).props()).toMatchObject({ error: true, errorMessage: 'Rule not found', title: 'Rule' })

		store.errors = { rule: 'Forbidden' }
		store.fetchObject.mockResolvedValueOnce(null)
		expect((await mountPage()).vm.errorMessage).toBe('Forbidden')

		store.fetchObject.mockRejectedValueOnce({})
		const thrown = await mountPage()
		expect(thrown.vm.errorMessage).toBe('An error occurred')

		store.fetchObject.mockResolvedValueOnce(RULE)
		thrown.findComponent({ name: 'CnDetailPage' }).props('onRetry')()
		await flushPromises()
		expect(thrown.vm.error).toBeNull()
		expect(thrown.vm.draft.name).toBe('Block deletes')
	})

	it('loads nothing without an id, and defaults the timing to the first option', async () => {
		const wrapper = await mountPage({ id: '' })

		expect(store.fetchObject).not.toHaveBeenCalled()
		expect(wrapper.findComponent({ name: 'CnDetailPage' }).props('empty')).toBe(true)
		expect(wrapper.vm.selectedTiming).toEqual(wrapper.vm.timingOptions[0])
		expect(wrapper.vm.selectedAction).toBeNull()
		wrapper.vm.updateField('name', 'x')
		wrapper.vm.onConditionsUpdate({})
		wrapper.vm.onConfigurationUpdate({})
		wrapper.vm.onActionTypeUpdate('flow')
		expect(wrapper.vm.draft).toBeNull()
	})
})

describe('RuleDetailPage editing', () => {
	beforeEach(() => {
		store.fetchObject.mockResolvedValue(RULE)
	})

	it('writes every basic field into the draft', async () => {
		const wrapper = await mountPage()

		textField(wrapper, 'Name *').vm.$emit('update:modelValue', 'Renamed')
		select(wrapper, 'Action').vm.$emit('update:modelValue', { id: 'post' })
		select(wrapper, 'Timing').vm.$emit('update:modelValue', null)
		textField(wrapper, 'Order').vm.$emit('update:modelValue', '7')
		await wrapper.find('#rule-description').setValue('new')
		expect(wrapper.vm.draft).toMatchObject({ name: 'Renamed', action: 'post', timing: 'before', order: 7, description: 'new' })

		textField(wrapper, 'Order').vm.$emit('update:modelValue', '')
		select(wrapper, 'Action').vm.$emit('update:modelValue', null)
		expect(wrapper.vm.draft).toMatchObject({ order: null, action: '' })
		expect(wrapper.vm.dirty).toBe(true)
	})

	it('takes conditions from the builder and the action from its editor', async () => {
		const wrapper = await mountPage()

		wrapper.findComponent({ name: 'RuleConditionGroup' }).vm.$emit('update', { or: [] })
		wrapper.findComponent({ name: 'RuleActionConfig' }).vm.$emit('update', { code: 500 })
		wrapper.findComponent({ name: 'RuleActionConfig' }).vm.$emit('update:type', 'mapping')

		expect(wrapper.vm.draft).toMatchObject({ conditions: { or: [] }, configuration: { code: 500 }, type: 'mapping' })
	})

	it('edits the conditions as raw JSON, shows a parse error and clears to an empty group', async () => {
		const wrapper = await mountPage()

		await wrapper.findAll('button').find((b) => b.text() === 'Raw JSON').trigger('click')
		const textarea = wrapper.find('#rule-raw-conditions')
		expect(JSON.parse(textarea.element.value)).toEqual(wrapper.vm.rootConditionGroup)
		expect(wrapper.findComponent({ name: 'RuleConditionGroup' }).exists()).toBe(false)

		await textarea.setValue('{"or":[]}')
		expect(wrapper.vm.draft.conditions).toEqual({ or: [] })
		await textarea.setValue('{nope')
		expect(wrapper.find('.rule-detail-page__helper--error').text()).toContain('Invalid JSON')
		expect(wrapper.vm.draft.conditions).toEqual({ or: [] })
		await textarea.setValue('   ')
		expect(wrapper.vm.draft.conditions).toEqual({ and: [] })
		expect(wrapper.vm.rawConditionsError).toBe('')

		await wrapper.findAll('button').find((b) => b.text() === 'Visual builder').trigger('click')
		expect(wrapper.findComponent({ name: 'RuleConditionGroup' }).exists()).toBe(true)
	})

	it('saves the draft and takes the stored rule as the new baseline', async () => {
		store.saveObject.mockResolvedValue({ ...RULE, name: 'Saved' })
		const wrapper = await mountPage()
		expect(button(wrapper, 'Save').attributes('disabled')).toBeDefined()

		textField(wrapper, 'Name *').vm.$emit('update:modelValue', 'Saved')
		await flushPromises()
		await button(wrapper, 'Save').trigger('click')
		await flushPromises()

		expect(store.saveObject).toHaveBeenCalledWith('rule', expect.objectContaining({ name: 'Saved' }))
		expect(wrapper.vm.dirty).toBe(false)
		expect(wrapper.vm.saving).toBe(false)
	})

	it('names a refused or failed save and keeps the edits', async () => {
		const wrapper = await mountPage()
		wrapper.vm.updateField('name', 'Edited')

		store.saveObject.mockResolvedValueOnce(null)
		await wrapper.vm.onSave()
		expect(wrapper.vm.errorMessage).toBe('Saving failed')
		store.errors = { rule: { message: 'Validation failed' } }
		store.saveObject.mockResolvedValueOnce(null)
		await wrapper.vm.onSave()
		expect(wrapper.vm.errorMessage).toBe('Validation failed')
		store.saveObject.mockRejectedValueOnce(new Error('offline'))
		await wrapper.vm.onSave()
		expect(wrapper.vm.errorMessage).toBe('offline')
		expect(wrapper.vm.draft.name).toBe('Edited')
	})

	it('refuses to save a flow rule without a flow', async () => {
		const wrapper = await mountPage()
		wrapper.vm.onActionTypeUpdate('flow')
		wrapper.vm.onConfigurationUpdate({ flow: ' ' })

		await wrapper.vm.onSave()

		expect(wrapper.vm.missingFlow).toBe(true)
		expect(store.saveObject).not.toHaveBeenCalled()
	})

	it('undoes the edits back to the stored rule, also in the raw JSON view', async () => {
		const wrapper = await mountPage()
		await wrapper.findAll('button').find((b) => b.text() === 'Raw JSON').trigger('click')
		await wrapper.find('#rule-raw-conditions').setValue('{"or":[{"var":"x"}]}')
		expect(wrapper.vm.dirty).toBe(true)

		await button(wrapper, 'Discard').trigger('click')

		expect(wrapper.vm.draft).toEqual(RULE)
		expect(JSON.parse(wrapper.vm.rawConditionsDraft)).toEqual(wrapper.vm.rootConditionGroup)
		wrapper.vm.resetEdits()
		expect(wrapper.vm.draft).toEqual(RULE)
	})

	it('takes a live update only while nothing is being edited', async () => {
		const wrapper = await mountPage()

		wrapper.vm.applyLiveObject({ ...RULE, name: 'From elsewhere' })
		expect(wrapper.vm.draft.name).toBe('From elsewhere')

		wrapper.vm.updateField('name', 'Mine')
		wrapper.vm.applyLiveObject({ ...RULE, name: 'Later' })
		expect(wrapper.vm.draft.name).toBe('Mine')
	})
})

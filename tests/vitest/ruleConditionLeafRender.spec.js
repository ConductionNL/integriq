// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * RuleConditionLeaf as an editor sees it: which inputs each kind of operator
 * draws, what each input shows, and what typing into it emits. The leaf's
 * logic is covered over a plain context in ruleConditionLeaf.spec.js; this
 * mounts the template.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const NcTextField = defineComponent({
		name: 'NcTextField',
		props: ['modelValue', 'label', 'placeholder'],
		emits: ['update:modelValue'],
		render() {
			return h('div', { class: 'NcTextField', 'data-label': this.label, 'data-value': this.modelValue, 'data-placeholder': this.placeholder })
		},
	})
	const NcSelect = defineComponent({
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel'],
		emits: ['update:modelValue'],
		render() {
			return h('div', { class: 'NcSelect', 'data-selected': this.modelValue?.id }, (this.options ?? []).map((option) => this.$slots.option?.(option)))
		},
	})
	const NcButton = defineComponent({
		name: 'NcButton',
		props: ['ariaLabel'],
		emits: ['click'],
		render() {
			return h('button', { class: 'NcButton', 'aria-label': this.ariaLabel, onClick: () => this.$emit('click') }, [this.$slots.icon?.()])
		},
	})
	return { NcButton, NcSelect, NcTextField }
})
vi.mock('vue-material-design-icons/Close.vue', () => ({ default: { name: 'Close', render: () => null } }))

const Leaf = (await import('@/views/Rule/RuleConditionLeaf.vue')).default

/**
 * Mount the leaf over a node.
 *
 * @param {object} node The JsonLogic node.
 * @return {object} The wrapper.
 */
function mountLeaf(node) {
	return mount(Leaf, { props: { node }, global: { mocks: { t: (app, text) => text } } })
}

/**
 * The text fields, as label and value.
 *
 * @param {object} wrapper The leaf.
 * @return {Array<{label: string, value: string}>}
 */
function fields(wrapper) {
	return wrapper.findAllComponents({ name: 'NcTextField' }).map((field) => ({ label: field.props('label'), value: field.props('modelValue') }))
}

/**
 * The last `update` the leaf emitted.
 *
 * @param {object} wrapper The leaf.
 * @return {object|undefined}
 */
function lastUpdate(wrapper) {
	return wrapper.emitted('update')?.at(-1)?.[0]
}

describe('a binary operator', () => {
	it('draws the field and one value, and emits what is typed into each', async () => {
		const wrapper = mountLeaf({ '>=': [{ var: 'age' }, 18] })

		expect(fields(wrapper)).toEqual([{ label: 'Field', value: 'age' }, { label: 'Value', value: '18' }])

		await wrapper.findAllComponents({ name: 'NcTextField' })[1].vm.$emit('update:modelValue', '21')
		expect(lastUpdate(wrapper)).toEqual({ '>=': [{ var: 'age' }, 21] })

		await wrapper.findAllComponents({ name: 'NcTextField' })[0].vm.$emit('update:modelValue', 'leeftijd')
		expect(lastUpdate(wrapper)).toEqual({ '>=': [{ var: 'leeftijd' }, 18] })
	})

	it('names the value with the operator\'s own label and placeholder', () => {
		const field = mountLeaf({ missing: [{ var: '' }, 'a,b'] }).findAllComponents({ name: 'NcTextField' })[1]

		expect(field.props('label')).toBe('Comma-separated paths')
		expect(field.props('placeholder')).toBe('a,b,c')
	})
})

describe('a ternary operator', () => {
	it('draws a second value and emits into its slot', async () => {
		const wrapper = mountLeaf({ substr: [{ var: 'name' }, 0, 5] })

		expect(fields(wrapper)).toEqual([{ label: 'Field', value: 'name' }, { label: 'Start', value: '0' }, { label: 'Length', value: '5' }])

		await wrapper.findAllComponents({ name: 'NcTextField' })[2].vm.$emit('update:modelValue', '3')
		expect(lastUpdate(wrapper)).toEqual({ substr: [{ var: 'name' }, 0, 3] })
	})
})

describe('if, array and merge operators', () => {
	it('draws then and else as JSON and emits a parsed slot', async () => {
		const wrapper = mountLeaf({ if: [{ var: 'x' }, { var: 'a' }, { var: 'b' }] })
		const areas = wrapper.findAll('textarea')

		expect(areas).toHaveLength(2)
		expect(JSON.parse(areas[0].element.value)).toEqual({ var: 'a' })
		expect(JSON.parse(areas[1].element.value)).toEqual({ var: 'b' })

		await areas[1].setValue('{"var":"c"}')
		expect(lastUpdate(wrapper)).toEqual({ if: [{ var: 'x' }, { var: 'a' }, { var: 'c' }] })

		await areas[0].setValue('{"var":"d"}')
		expect(lastUpdate(wrapper)).toEqual({ if: [{ var: 'x' }, { var: 'd' }, { var: 'b' }] })
	})

	it('draws a collection and a predicate for an array operator', async () => {
		const wrapper = mountLeaf({ some: [{ var: 'items' }, { '==': [{ var: '' }, true] }] })
		const areas = wrapper.findAll('textarea')

		expect(areas).toHaveLength(2)
		expect(wrapper.text()).toContain('Collection (JsonLogic)')
		expect(wrapper.text()).toContain('Predicate (JsonLogic)')

		await areas[0].setValue('{"var":"lines"}')
		expect(lastUpdate(wrapper)).toEqual({ some: [{ var: 'lines' }, { '==': [{ var: '' }, true] }] })

		await areas[1].setValue('{"!!":[{"var":""}]}')
		expect(lastUpdate(wrapper)).toEqual({ some: [{ var: 'items' }, { '!!': [{ var: '' }] }] })
	})

	it('draws the arrays to merge as one JSON list and emits it parsed', async () => {
		const wrapper = mountLeaf({ merge: [{ var: 'a' }, [1, 2]] })
		const area = wrapper.find('textarea')

		expect(JSON.parse(area.element.value)).toEqual([{ var: 'a' }, [1, 2]])

		await area.setValue('[[3]]')
		expect(lastUpdate(wrapper)).toEqual({ merge: [[3]] })
	})

	it('shows the parse error under invalid JSON and emits nothing', async () => {
		const wrapper = mountLeaf({ if: [{ var: 'x' }, 1, 2] })

		await wrapper.findAll('textarea')[0].setValue('{ not json')

		expect(wrapper.find('.rule-condition-leaf__error').exists()).toBe(true)
		expect(wrapper.emitted('update')).toBeUndefined()
	})
})

describe('unary and var operators', () => {
	it('says no value is needed for a unary operator', () => {
		const wrapper = mountLeaf({ '!!': [{ var: 'email' }] })

		expect(fields(wrapper)).toEqual([{ label: 'Field', value: 'email' }])
		expect(wrapper.text()).toContain('(no value needed)')
	})

	it('draws a variable path for var and emits the new path', async () => {
		const wrapper = mountLeaf({ var: 'user.email' })

		expect(fields(wrapper)).toEqual([{ label: 'Variable path', value: 'user.email' }])
		expect(wrapper.text()).toContain('(no value needed)')

		await wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', 'user.name')
		expect(lastUpdate(wrapper)).toEqual({ var: ['user.name'] })
	})
})

describe('the operator picker and the remove button', () => {
	it('selects the current operator and lists every option with its group', () => {
		const select = mountLeaf({ cat: [{ var: 'a' }, 'b'] }).findComponent({ name: 'NcSelect' })

		expect(select.props('modelValue')).toMatchObject({ id: 'cat', group: 'string' })
		expect(select.findAll('.rule-condition-leaf__op-option')).toHaveLength(select.props('options').length)
		expect(select.find('.rule-condition-leaf__op-option-group').text()).toBe('comparison')
	})

	it('reshapes the node when another operator is picked', async () => {
		const wrapper = mountLeaf({ '==': [{ var: 'status' }, 'open'] })

		await wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { id: '!!' })

		expect(lastUpdate(wrapper)).toEqual({ '!!': [{ var: 'status' }] })
	})

	it('asks to be removed', async () => {
		const wrapper = mountLeaf({ '==': [{ var: 'status' }, 'open'] })

		await wrapper.find('button[aria-label="Remove condition"]').trigger('click')

		expect(wrapper.emitted('remove')).toHaveLength(1)
	})
})

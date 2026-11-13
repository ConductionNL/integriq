// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * RuleConditionGroup as an editor sees it: its operator and hint, a leaf or a
 * nested group per child, an empty state, and the buttons that add, remove
 * and rewrite children. Every change is emitted as the group's whole node.
 * The group's logic is covered over a plain context in
 * ruleConditionGroup.spec.js; this mounts the template.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent: define, h: render } = await import('vue')
	return {
		NcSelect: define({
			name: 'NcSelect',
			props: ['modelValue', 'options', 'inputLabel'],
			emits: ['update:modelValue'],
			render() {
				return render('div', { class: 'NcSelect' })
			},
		}),
		NcButton: define({
			name: 'NcButton',
			props: ['ariaLabel', 'variant'],
			emits: ['click'],
			render() {
				return render('button', { 'aria-label': this.ariaLabel, onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()])
			},
		}),
		NcTextField: define({ name: 'NcTextField', render: () => null }),
		NcEmptyContent: define({
			name: 'NcEmptyContent',
			props: ['name', 'description'],
			render() {
				return render('div', { class: 'NcEmptyContent' }, [this.name, this.$slots.icon?.()])
			},
		}),
	}
})
for (const icon of ['Close', 'FilterVariant', 'FormatListGroup', 'Plus']) {
	vi.doMock(`vue-material-design-icons/${icon}.vue`, () => ({ default: { name: icon, render: () => null } }))
}

const Group = (await import('@/views/Rule/RuleConditionGroup.vue')).default

const LeafStub = defineComponent({
	name: 'RuleConditionLeaf',
	props: ['node'],
	emits: ['update', 'remove'],
	render() {
		return h('div', { class: 'leaf-stub' }, JSON.stringify(this.node))
	},
})

/**
 * Mount a group over a node.
 *
 * @param {object} node The group node.
 * @param {object} [props] More props.
 * @return {object} The wrapper.
 */
function mountGroup(node, props = {}) {
	return mount(Group, { props: { node, ...props }, global: { mocks: { t: (app, text) => text }, stubs: { RuleConditionLeaf: LeafStub } } })
}

/**
 * The last `update` the outer group emitted.
 *
 * @param {object} wrapper The group.
 * @return {object|undefined}
 */
function lastUpdate(wrapper) {
	return wrapper.emitted('update')?.at(-1)?.[0]
}

/**
 * The button with this text.
 *
 * @param {object} wrapper The group.
 * @param {string} text The button text.
 * @return {object}
 */
function button(wrapper, text) {
	return wrapper.findAll('.rule-condition-group__actions button').find((candidate) => candidate.text() === text)
}

describe('the header', () => {
	it('selects the operator and says what it means', () => {
		const and = mountGroup({ and: [] })
		const or = mountGroup({ or: [] })

		expect(and.findComponent({ name: 'NcSelect' }).props('modelValue')).toEqual({ id: 'and', label: 'ALL of (AND)' })
		expect(and.find('.rule-condition-group__hint').text()).toBe('Every child below must match.')
		expect(or.find('.rule-condition-group__hint').text()).toBe('At least one child below must match.')
	})

	it('rewrites the node under the picked operator, keeping the children', async () => {
		const wrapper = mountGroup({ and: [{ '==': [{ var: 'a' }, 1] }] })

		await wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { id: 'or' })

		expect(lastUpdate(wrapper)).toEqual({ or: [{ '==': [{ var: 'a' }, 1] }] })
	})

	it('offers Remove group only when the group is removable', async () => {
		expect(mountGroup({ and: [] }).find('button[aria-label="Remove group"]').exists()).toBe(false)

		const removable = mountGroup({ and: [] }, { removable: true })
		await removable.find('button[aria-label="Remove group"]').trigger('click')

		expect(removable.emitted('remove')).toHaveLength(1)
	})
})

describe('the children', () => {
	it('shows the empty state without children', () => {
		const wrapper = mountGroup({ and: [] })

		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).props('name')).toBe('No conditions yet')
	})

	it('draws a leaf per condition and a nested group per sub-group', () => {
		const wrapper = mountGroup({ and: [{ '==': [{ var: 'a' }, 1] }, { or: [{ '!!': [{ var: 'b' }] }] }] })

		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).exists()).toBe(false)
		expect(wrapper.findAll('.leaf-stub').map((leaf) => JSON.parse(leaf.text()))).toEqual([{ '==': [{ var: 'a' }, 1] }, { '!!': [{ var: 'b' }] }])
		const nested = wrapper.findAllComponents({ name: 'RuleConditionGroup' })
		expect(nested).toHaveLength(1)
		expect(nested[0].props('removable')).toBe(true)
		expect(nested[0].props('node')).toEqual({ or: [{ '!!': [{ var: 'b' }] }] })
	})

	it('writes a child\'s update and removal back into the node', async () => {
		const wrapper = mountGroup({ and: [{ '==': [{ var: 'a' }, 1] }, { '==': [{ var: 'b' }, 2] }] })
		const leaves = wrapper.findAllComponents({ name: 'RuleConditionLeaf' })

		await leaves[1].vm.$emit('update', { '!=': [{ var: 'b' }, 3] })
		expect(lastUpdate(wrapper)).toEqual({ and: [{ '==': [{ var: 'a' }, 1] }, { '!=': [{ var: 'b' }, 3] }] })

		await leaves[0].vm.$emit('remove')
		expect(lastUpdate(wrapper)).toEqual({ and: [{ '==': [{ var: 'b' }, 2] }] })
	})

	it('writes a nested group\'s update and removal back into the node', async () => {
		const wrapper = mountGroup({ or: [{ and: [] }] })
		const nested = wrapper.findAllComponents({ name: 'RuleConditionGroup' })[0]

		await nested.vm.$emit('update', { or: [{ '!!': [{ var: 'x' }] }] })
		expect(lastUpdate(wrapper)).toEqual({ or: [{ or: [{ '!!': [{ var: 'x' }] }] }] })

		await nested.vm.$emit('remove')
		expect(lastUpdate(wrapper)).toEqual({ or: [] })
	})
})

describe('adding children', () => {
	it('adds an empty condition and an empty group at the end', async () => {
		const wrapper = mountGroup({ and: [{ '==': [{ var: 'a' }, 1] }] })

		await button(wrapper, 'Add condition').trigger('click')
		expect(lastUpdate(wrapper)).toEqual({ and: [{ '==': [{ var: 'a' }, 1] }, { '==': [{ var: '' }, ''] }] })

		await button(wrapper, 'Add group').trigger('click')
		expect(lastUpdate(wrapper)).toEqual({ and: [{ '==': [{ var: 'a' }, 1] }, { and: [] }] })
	})
})

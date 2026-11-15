// @vitest-environment jsdom

import { translate } from '@nextcloud/l10n'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of the three rule tables of MappingRulesEditor (mapping,
 * cast, unset): their markup with and without rows, and what each row
 * button, arrow key and drag end emits.
 *
 * @spec openspec/specs/mapping-editor-ui/spec.md
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import MappingRulesEditor from '@/views/wrappers/MappingRulesEditor.vue'

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, tag = 'div') =>
		defineComponent({
			name,
			props: ['variant', 'disabled', 'modelValue', 'type', 'ariaLabel'],
			render() {
				return h(
					tag,
					{
						class: name,
						disabled: this.disabled || undefined,
						'aria-label': this.ariaLabel,
					},
					[this.$slots.icon?.(), this.$slots.default?.()],
				)
			},
		})
	return {
		NcButton: stub('NcButton', 'button'),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	}
})
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) =>
		defineComponent({
			name,
			render() {
				return h('div', { class: name }, [
					this.$slots.title?.(),
					this.$slots.default?.(),
				])
			},
		})
	return { CnTab: stub('CnTab'), CnTabs: stub('CnTabs') }
})
vi.mock('vue-draggable-plus', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		VueDraggable: defineComponent({
			name: 'VueDraggable',
			props: [
				'modelValue',
				'tag',
				'handle',
				'disabled',
				'animation',
				'ghostClass',
				'dragClass',
			],
			emits: ['update:modelValue', 'end'],
			render() {
				return h(
					this.tag || 'div',
					{ 'data-draggable': this.handle },
					this.$slots.default?.(),
				)
			},
		}),
	}
})
vi.mock('@/dialogs/EditMappingRuleDialog.vue', () => ({
	default: { name: 'EditMappingRuleDialog', render: () => null },
}))

const PROPS = {
	mappingRules: { a: '{{ x }}', b: '{{ y }}', c: { not: 'a string' } },
	castRules: { n: 'int', m: 'bool' },
	unsetRules: ['p', 'q', 'p'],
	passThrough: true,
}

/**
 * The rendered markup without the scoped-style ids, which follow the file,
 * and without the empty placeholders Vue leaves for a false v-if.
 *
 * @param {object} wrapper the mounted editor or an element of it
 * @return {string} the markup
 */
function markup(wrapper) {
	return wrapper
		.html()
		.replace(/ data-v-[0-9a-f]+=""/g, '')
		.replace(/\n\s*<!--v-if-->/g, '')
}

/**
 * The rule events the editor emitted, without native DOM events.
 *
 * @param {object} wrapper the mounted editor
 * @return {object} event name to payloads
 */
function ruleEvents(wrapper) {
	return Object.fromEntries(
		Object.entries(wrapper.emitted()).filter(([name]) =>
			name.startsWith('update-'),
		),
	)
}

/**
 * Mount the editor.
 *
 * @param {object} props the editor props
 * @return {object} the wrapper
 */
function mountEditor(props) {
	return mount(MappingRulesEditor, { props, global: { mocks: { t: translate } } })
}

describe('MappingRulesEditor rule tables', () => {
	it.each([
		['with rules', PROPS],
		['empty', { passThrough: false }],
		['saving', { ...PROPS, saving: true }],
	])('renders %s as recorded', (_label, props) => {
		expect(markup(mountEditor(props))).toMatchSnapshot()
	})

	it('row buttons edit and delete as recorded', async () => {
		const wrapper = mountEditor(PROPS)
		const steps = []
		const rows = wrapper.findAll('tr.cn-rules-editor__row')
		for (const [i, row] of rows.entries()) {
			for (const button of row.findAll('button.NcButton')) {
				await button.trigger('click')
				steps.push([
					i,
					button.attributes('aria-label'),
					wrapper.vm.editing,
					ruleEvents(wrapper),
				])
				wrapper.vm.editing = null
			}
		}
		expect(JSON.parse(JSON.stringify(steps))).toMatchSnapshot()
	})

	it('arrow keys move rows as recorded', async () => {
		const steps = []
		for (const [i] of mountEditor(PROPS)
			.findAll('tr.cn-rules-editor__row')
			.entries()) {
			for (const key of ['up', 'down']) {
				const wrapper = mountEditor(PROPS)
				await wrapper
					.findAll('tr.cn-rules-editor__row')
					[i].trigger('keydown', {
						key: key === 'up' ? 'ArrowUp' : 'ArrowDown',
					})
				steps.push([i, key, ruleEvents(wrapper)])
			}
		}
		expect(JSON.parse(JSON.stringify(steps))).toMatchSnapshot()
	})

	it('a drag end commits the dragged order as recorded', async () => {
		const wrapper = mountEditor(PROPS)
		const steps = []
		for (const drag of wrapper.findAllComponents({ name: 'VueDraggable' })) {
			const reversed = [...drag.props('modelValue')].reverse()
			drag.vm.$emit('update:modelValue', reversed)
			await wrapper.vm.$nextTick()
			drag.vm.$emit('end')
			steps.push(JSON.parse(JSON.stringify(ruleEvents(wrapper))))
		}
		expect(steps).toMatchSnapshot()
	})
})

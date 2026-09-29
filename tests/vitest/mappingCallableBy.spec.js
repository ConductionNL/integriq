// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The mapping detail page shows and saves callableBy, the apps allowed to run
 * a mapping by event (mapping-woo-index-field-mapping, task 2).
 *
 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-mapping-names-the-apps-allowed-to-run-it-by-event-req-woom-002
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import MappingCallableByField from '@/components/mapping/MappingCallableByField.vue'
import { normaliseCallableBy } from '@/components/mapping/callableBy.js'

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcSelect: defineComponent({
			name: 'NcSelect',
			props: [
				'modelValue',
				'options',
				'inputLabel',
				'multiple',
				'taggable',
				'disabled',
			],
			emits: ['update:modelValue'],
			render() {
				return h('div', { class: 'NcSelect' })
			},
		}),
	}
})

describe('callableBy', () => {
	it('keeps trimmed, lower-case app ids once and drops blanks', () => {
		expect(
			normaliseCallableBy([
				' OpenCatalogi ',
				'opencatalogi',
				'',
				{ label: 'dossiq' },
			]),
		).toEqual(['opencatalogi', 'dossiq'])
		expect(normaliseCallableBy(undefined)).toEqual([])
	})

	it('says no app can run a mapping with an empty list', () => {
		const wrapper = mount(MappingCallableByField, { props: { value: [] } })
		expect(wrapper.text()).toContain('No other app can run this mapping.')
	})

	it('shows the stored apps and emits the edited list normalised', async () => {
		const wrapper = mount(MappingCallableByField, {
			props: { value: ['opencatalogi'] },
		})
		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.props('modelValue')).toEqual(['opencatalogi'])
		expect(select.props('inputLabel')).toBe('Apps that may run this mapping')

		select.vm.$emit('update:modelValue', ['opencatalogi', ' Dossiq '])
		expect(wrapper.emitted('update')[0][0]).toEqual(['opencatalogi', 'dossiq'])
	})
})

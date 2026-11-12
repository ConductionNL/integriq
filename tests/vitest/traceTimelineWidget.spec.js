// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * TraceTimelineWidget: one row per step with its order, type, name, status
 * and duration; a row opens and closes its input and output, each step on
 * its own; and an empty trace says so.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import TraceTimelineWidget from '@/views/ExecutionTrace/TraceTimelineWidget.vue'

const steps = [
	{ order: 1, type: 'source', name: 'Read case', status: 'success', durationMs: 12, input: { id: 7 }, output: { title: 'Permit' } },
	{ order: 2, type: 'action', name: 'Notify', status: 'failed', durationMs: 340, input: null, output: undefined },
]

describe('TraceTimelineWidget', () => {
	it('renders one row per step with its details and status classes', () => {
		const wrapper = mount(TraceTimelineWidget, { props: { steps } })
		const rows = wrapper.findAll('.traceTimeline__step')

		expect(rows).toHaveLength(2)
		expect(rows[0].classes()).toContain('traceTimeline__step--success')
		expect(rows[1].find('.traceTimeline__badge').classes()).toContain('traceTimeline__badge--failed')
		expect(rows[0].find('.traceTimeline__row').findAll('span').map((span) => span.text())).toEqual(['1', 'source', 'Read case', 'success', '12 ms'])
		expect(wrapper.find('.traceTimeline__empty').exists()).toBe(false)
		expect(wrapper.find('[data-testid="step-detail"]').exists()).toBe(false)
	})

	it('opens a step to show its input and output as indented JSON, and closes it again', async () => {
		const wrapper = mount(TraceTimelineWidget, { props: { steps } })
		const button = wrapper.findAll('.traceTimeline__row')[0]

		await button.trigger('click')
		expect(button.attributes('aria-expanded')).toBe('true')
		const pres = wrapper.find('[data-testid="step-detail"]').findAll('pre')
		expect(pres.map((p) => p.text())).toEqual([
			JSON.stringify({ id: 7 }, null, 2),
			JSON.stringify({ title: 'Permit' }, null, 2),
		])

		await button.trigger('click')
		expect(button.attributes('aria-expanded')).toBe('false')
		expect(wrapper.find('[data-testid="step-detail"]').exists()).toBe(false)
	})

	it('keeps each step open on its own', async () => {
		const wrapper = mount(TraceTimelineWidget, { props: { steps } })
		const [first, second] = wrapper.findAll('.traceTimeline__row')

		await first.trigger('click')
		await second.trigger('click')
		expect(wrapper.findAll('[data-testid="step-detail"]')).toHaveLength(2)

		await first.trigger('click')
		expect(first.attributes('aria-expanded')).toBe('false')
		expect(second.attributes('aria-expanded')).toBe('true')
	})

	it('shows a missing input or output as an empty object', async () => {
		const wrapper = mount(TraceTimelineWidget, { props: { steps } })

		await wrapper.findAll('.traceTimeline__row')[1].trigger('click')

		expect(wrapper.find('[data-testid="step-detail"]').findAll('pre').map((p) => p.text())).toEqual(['{}', '{}'])
	})

	it('shows a value JSON cannot encode as text', () => {
		const wrapper = mount(TraceTimelineWidget)
		const cyclic = { name: 'loop' }
		cyclic.self = cyclic

		expect(wrapper.vm.formatJson(cyclic)).toBe('[object Object]')
		expect(wrapper.vm.formatJson(10n)).toBe('10')
	})

	it('says so when the trace has no steps', () => {
		const wrapper = mount(TraceTimelineWidget)

		expect(wrapper.findAll('.traceTimeline__step')).toHaveLength(0)
		expect(wrapper.find('.traceTimeline__empty').text()).toBe('No steps recorded for this trace.')
	})
})

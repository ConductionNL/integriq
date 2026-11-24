// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The schedule section of the Jobs form, mounted: the type switch writes the
 * whole schedule back, a calendar shows its fields and previews the next runs
 * from POST /api/jobs/schedule-preview, and a refused or incomplete calendar
 * shows why instead of run times. The HTTP client and the Nextcloud inputs
 * are stubbed; the schedule helpers are the real ones.
 *
 * @spec openspec/changes/archive/2026-11-24-automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-the-job-form-previews-the-next-five-run-times-req-jcal-003
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const { post } = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { post } }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const input = (name) => defineComponent({
		name,
		props: ['modelValue', 'label', 'inputLabel', 'options', 'value', 'type', 'helperText'],
		emits: ['update:modelValue'],
		render() {
			return h('div', { class: name, 'data-label': this.label || this.inputLabel || '' }, this.$slots.default?.())
		},
	})
	return {
		NcCheckboxRadioSwitch: input('NcCheckboxRadioSwitch'),
		NcSelect: input('NcSelect'),
		NcTextField: input('NcTextField'),
	}
})

const JobScheduleFields = (await import('@/modals/v2/JobScheduleFields.vue')).default

const CALENDAR = { type: 'calendar', weekdays: [1, 3], times: ['07:00'], timeZone: 'Europe/Amsterdam', skipDates: [] }

/**
 * Mount the section with a recording updateField.
 *
 * @param {object|null} schedule The schedule prop.
 * @return {{wrapper: object, updateField: Function}}
 */
function mountWith(schedule) {
	const updateField = vi.fn()
	const wrapper = mount(JobScheduleFields, { props: { schedule, updateField } })
	return { wrapper, updateField }
}

/**
 * The stubbed text field with a label.
 *
 * @param {object} wrapper The mounted section.
 * @param {string} label   The label.
 * @return {object}
 */
function field(wrapper, label) {
	return wrapper.findAllComponents({ name: 'NcTextField' }).find((c) => c.props('label') === label)
}

beforeEach(() => {
	vi.useFakeTimers()
	post.mockReset()
})

afterEach(() => {
	vi.useRealTimers()
})

describe('JobScheduleFields: interval', () => {
	it('shows only the type switch for an interval job and previews nothing', async () => {
		const { wrapper } = mountWith(null)
		const radios = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		expect(radios).toHaveLength(2)
		expect(radios.map((r) => r.props('modelValue'))).toEqual(['interval', 'interval'])
		expect(wrapper.findAllComponents({ name: 'NcTextField' })).toHaveLength(0)
		await vi.advanceTimersByTimeAsync(400)
		expect(post).not.toHaveBeenCalled()
	})

	it('switches to a calendar with weekday mornings in a time zone', () => {
		const { wrapper, updateField } = mountWith({ type: 'interval' })
		wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })[1].vm.$emit('update:modelValue', 'calendar')
		expect(updateField).toHaveBeenCalledTimes(1)
		const [key, schedule] = updateField.mock.calls[0]
		expect(key).toBe('schedule')
		expect(schedule).toMatchObject({ type: 'calendar', weekdays: [1, 2, 3, 4, 5], times: ['07:00'], skipDates: [] })
		expect(typeof schedule.timeZone).toBe('string')
		expect(schedule.timeZone).not.toBe('')
	})

	it('switches back to an interval with nothing else kept', () => {
		const { wrapper, updateField } = mountWith(CALENDAR)
		wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })[0].vm.$emit('update:modelValue', 'interval')
		expect(updateField).toHaveBeenCalledWith('schedule', { type: 'interval' })
	})
})

describe('JobScheduleFields: calendar fields', () => {
	it('ticks the scheduled weekdays and writes a toggled one back sorted', () => {
		const { wrapper, updateField } = mountWith(CALENDAR)
		const days = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).slice(2)
		expect(days).toHaveLength(7)
		expect(days.map((d) => d.props('modelValue'))).toEqual([true, false, true, false, false, false, false])
		days[1].vm.$emit('update:modelValue', true)
		expect(updateField).toHaveBeenLastCalledWith('schedule', { ...CALENDAR, weekdays: [1, 2, 3] })
		days[0].vm.$emit('update:modelValue', false)
		expect(updateField).toHaveBeenLastCalledWith('schedule', { ...CALENDAR, weekdays: [3] })
	})

	it('offers four named day rules and 31 numbered days, and shows the chosen one', () => {
		const { wrapper } = mountWith({ ...CALENDAR, dayOfMonth: 'lastWorkingDay' })
		const select = wrapper.findComponent({ name: 'NcSelect' })
		const options = select.props('options')
		expect(options).toHaveLength(35)
		expect(options.slice(0, 4).map((o) => o.id)).toEqual(['first', 'last', 'firstWorkingDay', 'lastWorkingDay'])
		expect(options[4].id).toBe(1)
		expect(options[34].id).toBe(31)
		expect(select.props('modelValue')).toEqual({ id: 'lastWorkingDay', label: 'Last working day of the month' })
	})

	it('writes a picked day and removes it when cleared', () => {
		const { wrapper, updateField } = mountWith({ ...CALENDAR, dayOfMonth: 15 })
		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.props('modelValue').id).toBe(15)
		select.vm.$emit('update:modelValue', { id: 'first' })
		expect(updateField).toHaveBeenLastCalledWith('schedule', { ...CALENDAR, dayOfMonth: 'first' })
		select.vm.$emit('update:modelValue', null)
		expect(updateField).toHaveBeenLastCalledWith('schedule', CALENDAR)
	})

	it('shows no day when none is chosen', () => {
		const { wrapper } = mountWith(CALENDAR)
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue')).toBeNull()
	})

	it('joins times and skip dates for display and splits typed text back into unique items', () => {
		const { wrapper, updateField } = mountWith({ ...CALENDAR, times: ['07:00', '13:30'], skipDates: ['2026-12-25'] })
		expect(field(wrapper, 'Times of day').props('modelValue')).toBe('07:00, 13:30')
		expect(field(wrapper, 'Dates to skip').props('modelValue')).toBe('2026-12-25')
		field(wrapper, 'Times of day').vm.$emit('update:modelValue', '08:00, 08:00 17:00')
		expect(updateField).toHaveBeenLastCalledWith('schedule', expect.objectContaining({ times: ['08:00', '17:00'] }))
		field(wrapper, 'Dates to skip').vm.$emit('update:modelValue', '2026-12-25,2026-12-26')
		expect(updateField).toHaveBeenLastCalledWith('schedule', expect.objectContaining({ skipDates: ['2026-12-25', '2026-12-26'] }))
	})

	it('trims a typed time zone and shows an empty one as blank', () => {
		const { wrapper, updateField } = mountWith({ ...CALENDAR, timeZone: undefined })
		expect(field(wrapper, 'Time zone').props('modelValue')).toBe('')
		field(wrapper, 'Time zone').vm.$emit('update:modelValue', '  UTC ')
		expect(updateField).toHaveBeenLastCalledWith('schedule', expect.objectContaining({ timeZone: 'UTC' }))
	})
})

describe('JobScheduleFields: preview', () => {
	it('posts the schedule once after a pause and lists the runs', async () => {
		let answer
		post.mockReturnValue(new Promise((resolve) => { answer = resolve }))
		const { wrapper } = mountWith(CALENDAR)
		expect(post).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(300)
		expect(wrapper.text()).toContain('Computing run times')
		answer({ data: { runs: ['2026-11-16T06:00:00Z', '2026-11-18T06:00:00Z'] } })
		await flushPromises()
		expect(post).toHaveBeenCalledTimes(1)
		expect(post).toHaveBeenCalledWith('/index.php/apps/integriq/api/jobs/schedule-preview', { schedule: CALENDAR })
		expect(wrapper.findAll('.cn-job-schedule__preview li')).toHaveLength(2)
		expect(wrapper.find('.cn-job-schedule__error').exists()).toBe(false)
	})

	it('says a calendar has no run time when the answer lists none', async () => {
		post.mockResolvedValue({ data: {} })
		const { wrapper } = mountWith(CALENDAR)
		await vi.advanceTimersByTimeAsync(300)
		await flushPromises()
		expect(wrapper.text()).toContain('This calendar has no run time.')
	})

	it('shows the server refusal, or a fallback, instead of run times', async () => {
		post.mockRejectedValueOnce({ response: { data: { error: 'Unknown time zone' } } })
		const { wrapper } = mountWith(CALENDAR)
		await vi.advanceTimersByTimeAsync(300)
		await flushPromises()
		expect(wrapper.find('.cn-job-schedule__error').text()).toBe('Unknown time zone')

		post.mockRejectedValueOnce(new Error('offline'))
		await wrapper.setProps({ schedule: { ...CALENDAR, times: ['09:00'] } })
		await vi.advanceTimersByTimeAsync(300)
		await flushPromises()
		expect(wrapper.find('.cn-job-schedule__error').text()).toBe('The run times could not be computed.')
	})

	it('debounces quick edits into one request', async () => {
		post.mockResolvedValue({ data: { runs: [] } })
		const { wrapper } = mountWith(CALENDAR)
		await vi.advanceTimersByTimeAsync(100)
		await wrapper.setProps({ schedule: { ...CALENDAR, times: ['08:00'] } })
		await vi.advanceTimersByTimeAsync(300)
		await flushPromises()
		expect(post).toHaveBeenCalledTimes(1)
		expect(post.mock.calls[0][1]).toEqual({ schedule: { ...CALENDAR, times: ['08:00'] } })
	})

	it('clears the preview and asks nothing for a calendar without weekdays', async () => {
		post.mockResolvedValue({ data: { runs: ['2026-11-16T06:00:00Z'] } })
		const { wrapper } = mountWith(CALENDAR)
		await vi.advanceTimersByTimeAsync(300)
		await flushPromises()
		expect(wrapper.findAll('.cn-job-schedule__preview li')).toHaveLength(1)
		await wrapper.setProps({ schedule: { ...CALENDAR, weekdays: [] } })
		await vi.advanceTimersByTimeAsync(300)
		await flushPromises()
		expect(post).toHaveBeenCalledTimes(1)
		expect(wrapper.findAll('.cn-job-schedule__preview li')).toHaveLength(0)
	})

	it('cancels a pending preview when unmounted', async () => {
		const { wrapper } = mountWith(CALENDAR)
		wrapper.unmount()
		await vi.advanceTimersByTimeAsync(400)
		expect(post).not.toHaveBeenCalled()
	})
})

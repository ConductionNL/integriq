// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * RetentionSettings: values shown in days and hours with their defaults,
 * saved in milliseconds, and "Apply to existing logs" queueing the rebase
 * and showing its progress.
 *
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RetentionSettings from '@/views/admin/RetentionSettings.vue'
import { toMilliseconds, toUnit } from '@/views/admin/retentionUnits.js'

const { get, put, post, showSuccess } = vi.hoisted(() => ({
	get: vi.fn(),
	put: vi.fn(),
	post: vi.fn(),
	showSuccess: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: { get, put, post } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props) =>
		defineComponent({
			name,
			props,
			emits: ['click', 'update:modelValue'],
			render() {
				return h('div', { class: name }, this.$slots.default?.())
			},
		})
	return {
		NcButton: stub('NcButton', ['variant', 'disabled']),
		NcTextField: stub('NcTextField', ['modelValue', 'label']),
	}
})

const URL = '/index.php/apps/integriq/api/settings'
const DAY = 86400000

const STORED = {
	retention: {
		callLogRetention: 30 * DAY,
		jobLogRetention: 30 * DAY,
		syncLogRetention: 30 * DAY,
		syncContractLogRetention: 30 * DAY,
		eventMessageRetention: 7 * DAY,
		executionTraceRetention: 30 * DAY,
		successLogRetention: 3600000,
	},
	defaults: {
		callLogRetention: 30 * DAY,
		jobLogRetention: 30 * DAY,
		syncLogRetention: 30 * DAY,
		syncContractLogRetention: 30 * DAY,
		eventMessageRetention: 7 * DAY,
		executionTraceRetention: 30 * DAY,
		successLogRetention: 3600000,
	},
	rebase: {},
}

beforeEach(() => {
	get.mockReset()
	put.mockReset()
	post.mockReset()
	showSuccess.mockReset()
})

describe('units', () => {
	it('shows milliseconds in days or hours', () => {
		expect(toUnit(90 * DAY, 'days')).toBe(90)
		expect(toUnit(3600000, 'hours')).toBe(1)
		expect(toUnit(12 * 3600000, 'days')).toBe(0.5)
	})

	it('stores a typed value as whole milliseconds, and refuses what is not one', () => {
		expect(toMilliseconds('90', 'days')).toBe(90 * DAY)
		expect(toMilliseconds(2, 'hours')).toBe(7200000)
		expect(toMilliseconds('0', 'days')).toBe(0)
		expect(toMilliseconds('-1', 'days')).toBeNull()
		expect(toMilliseconds('soon', 'days')).toBeNull()
		expect(toMilliseconds('', 'days')).toBeNull()
	})
})

describe('the section', () => {
	it('shows each retention in its unit with its default', async () => {
		get.mockResolvedValue({ data: STORED })
		const wrapper = mount(RetentionSettings)
		await flushPromises()

		expect(get).toHaveBeenCalledWith(URL)
		expect(wrapper.vm.values.callLogRetention).toBe(30)
		expect(wrapper.vm.values.successLogRetention).toBe(1)
		expect(wrapper.vm.defaultOf({ key: 'eventMessageRetention', unit: 'days' })).toBe('Default: 7')
		expect(wrapper.findAll('.retention-settings__row')).toHaveLength(7)
	})

	it('saves 90 days of call logs in milliseconds and shows what came back', async () => {
		get.mockResolvedValue({ data: STORED })
		const saved = { ...STORED, retention: { ...STORED.retention, callLogRetention: 90 * DAY } }
		put.mockResolvedValue({ data: saved })
		const wrapper = mount(RetentionSettings)
		await flushPromises()

		wrapper.vm.values.callLogRetention = '90'
		await wrapper.vm.save()

		expect(put).toHaveBeenCalledWith(URL, { retention: { ...STORED.retention, callLogRetention: 90 * DAY } })
		expect(wrapper.vm.values.callLogRetention).toBe(90)
		expect(showSuccess).toHaveBeenCalled()
	})

	it('sends a value it cannot read as typed, and shows the refusal naming the field', async () => {
		get.mockResolvedValue({ data: STORED })
		put.mockRejectedValue({ response: { data: { error: 'jobLogRetention: must be a whole number of milliseconds, 0 or more.' } } })
		const wrapper = mount(RetentionSettings)
		await flushPromises()

		wrapper.vm.values.jobLogRetention = '-3'
		await wrapper.vm.save()

		expect(put.mock.calls[0][1].retention.jobLogRetention).toBe('-3')
		expect(wrapper.vm.error).toBe('jobLogRetention: must be a whole number of milliseconds, 0 or more.')
	})

	it('queues the rebase and shows it queued, with the button off while it runs', async () => {
		get.mockResolvedValue({ data: STORED })
		post.mockResolvedValue({ data: { queued: true, kinds: ['callLog', 'jobLog', 'syncLog', 'syncContractLog'] } })
		const wrapper = mount(RetentionSettings)
		await flushPromises()

		await wrapper.vm.rebase()

		expect(post).toHaveBeenCalledWith(URL + '/rebase', {})
		expect(wrapper.vm.rebaseRunning).toBe(true)
		expect(wrapper.vm.rebaseStatus).toBe('Queued: the background job starts with the next cron run.')
	})

	it('shows the progress the read reports', async () => {
		get.mockResolvedValue({ data: { ...STORED, rebase: { status: 'done', processed: 10000, updated: 9990 } } })
		const wrapper = mount(RetentionSettings)
		await flushPromises()

		expect(wrapper.vm.rebaseRunning).toBe(false)
		expect(wrapper.vm.rebaseStatus).toBe('Done: 10000 logs read, 9990 given a new expiry.')
		expect(wrapper.find('[data-testid="admin-retention-rebase-status"]').exists()).toBe(true)
	})

	it('shows why a rebase could not be queued', async () => {
		get.mockResolvedValue({ data: STORED })
		post.mockRejectedValue(new Error('offline'))
		const wrapper = mount(RetentionSettings)
		await flushPromises()

		await wrapper.vm.rebase()

		expect(wrapper.vm.error).toBe('The rebase could not be queued.')
		expect(wrapper.vm.busy).toBe(false)
	})
})

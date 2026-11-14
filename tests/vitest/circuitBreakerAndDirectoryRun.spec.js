// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CircuitBreakerBadge and DirectoryRunSummary: what each shows for the state
 * it is handed, and what the badge's reset sends.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const { http, toasts } = vi.hoisted(() => ({ http: { post: vi.fn() }, toasts: [] }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => toasts.push(['error', text]),
	showSuccess: (text) => toasts.push(['success', text]),
}))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: ['disabled'],
			emits: ['click'],
			render() {
				return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		}),
	}
})

const CircuitBreakerBadge = (await import('@/components/CircuitBreakerBadge.vue')).default
const DirectoryRunSummary = (await import('@/components/DirectoryRunSummary.vue')).default

/**
 * Mount the badge inside a section context.
 *
 * @param {object} context The provided cnSectionContext.
 * @return {object} The wrapper.
 */
function badge(context) {
	return mount(CircuitBreakerBadge, { global: { provide: { cnSectionContext: context } } })
}

describe('CircuitBreakerBadge', () => {
	beforeEach(() => {
		http.post.mockReset()
		toasts.length = 0
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-12T12:00:00Z'))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	const NOW = Math.floor(new Date('2026-11-12T12:00:00Z').getTime() / 1000)

	it('reads closed with no failures and no reset when the source says nothing', () => {
		const wrapper = badge({ value: { objectId: null, object: null } })
		expect(wrapper.find('[data-testid="circuit-breaker-state"]').text()).toBe('Circuit closed')
		expect(wrapper.find('[data-testid="circuit-breaker-state"]').classes()).toContain('circuitBreaker__badge--closed')
		expect(wrapper.text()).toContain('Failures: 0')
		expect(wrapper.find('[data-testid="circuit-breaker-reset"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="circuit-breaker-cooldown"]').exists()).toBe(false)
	})

	it('shows an open breaker with its failures and a cooldown that counts down', async () => {
		const wrapper = badge({ value: { objectId: 's1', object: { circuitBreakerState: 'open', circuitBreakerFailureCount: '5', circuitBreakerOpenedAt: NOW - 10, circuitBreakerCooldownSeconds: 60 } } })

		expect(wrapper.find('[data-testid="circuit-breaker-state"]').text()).toBe('Circuit open')
		expect(wrapper.text()).toContain('Failures: 5')
		expect(wrapper.find('[data-testid="circuit-breaker-cooldown"]').text()).toBe('Cooldown: 50s')

		await vi.advanceTimersByTimeAsync(5000)
		expect(wrapper.find('[data-testid="circuit-breaker-cooldown"]').text()).toBe('Cooldown: 45s')
	})

	it('defaults the cooldown to 30 seconds and hides it once it has passed', () => {
		const running = badge({ object: { circuitBreakerState: 'open', circuitBreakerOpenedAt: NOW - 10 } })
		expect(running.vm.cooldownRemaining).toBe(20)

		const passed = badge({ value: { object: { circuitBreakerState: 'open', circuitBreakerOpenedAt: NOW - 100 } } })
		expect(passed.vm.cooldownRemaining).toBe(0)
		expect(passed.find('[data-testid="circuit-breaker-cooldown"]').exists()).toBe(false)
	})

	it('takes the id from the context, else the object uuid, else its id', () => {
		expect(badge({ value: { objectId: 'ctx', object: { uuid: 'u', id: 'i' } } }).vm.objectId).toBe('ctx')
		expect(badge({ value: { object: { uuid: 'u', id: 'i' } } }).vm.objectId).toBe('u')
		expect(badge({ object: { id: 'i' } }).vm.objectId).toBe('i')
	})

	it('resets the breaker and flips to closed with no failures', async () => {
		http.post.mockResolvedValueOnce({ data: {} })
		const wrapper = badge({ value: { objectId: 's1', object: { circuitBreakerState: 'open', circuitBreakerFailureCount: 3 } } })

		await wrapper.find('[data-testid="circuit-breaker-reset"]').trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/sources/s1/circuit-breaker/reset')
		expect(wrapper.find('[data-testid="circuit-breaker-state"]').text()).toBe('Circuit closed')
		expect(wrapper.text()).toContain('Failures: 0')
		expect(toasts).toEqual([['success', 'Circuit breaker reset']])
		expect(wrapper.vm.busy).toBe(false)
	})

	it('keeps the state the server answers after a reset', async () => {
		http.post.mockResolvedValueOnce({ data: { circuitBreakerState: 'half-open' } })
		const wrapper = badge({ value: { objectId: 's1', object: { circuitBreakerState: 'open', circuitBreakerFailureCount: 3 } } })

		await wrapper.vm.reset()

		expect(wrapper.vm.state).toBe('half-open')
		expect(wrapper.vm.failureCount).toBe(3)
	})

	it('reports a failed reset and stays open', async () => {
		http.post.mockRejectedValueOnce({ response: { data: { error: 'Locked' } } })
		const wrapper = badge({ value: { objectId: 's1', object: { circuitBreakerState: 'open' } } })

		await wrapper.vm.reset()

		expect(toasts).toEqual([['error', 'Failed to reset circuit breaker: Locked']])
		expect(wrapper.vm.isOpen).toBe(true)

		http.post.mockRejectedValueOnce({})
		await wrapper.vm.reset()
		expect(toasts.at(-1)).toEqual(['error', 'Failed to reset circuit breaker'])
	})

	it('disables the reset and sends nothing without an id', async () => {
		const wrapper = badge({ value: { object: { circuitBreakerState: 'open' } } })
		expect(wrapper.find('[data-testid="circuit-breaker-reset"]').attributes('disabled')).toBeDefined()
		await wrapper.vm.reset()
		expect(http.post).not.toHaveBeenCalled()
	})

	it('stops ticking when unmounted', () => {
		const clear = vi.spyOn(globalThis, 'clearInterval')
		const wrapper = badge({ value: { object: {} } })
		const timer = wrapper.vm.tickTimer
		wrapper.unmount()
		expect(clear).toHaveBeenCalledWith(timer)
		clear.mockRestore()
	})
})

describe('DirectoryRunSummary', () => {
	const summary = (record) => mount(DirectoryRunSummary, { props: { record } })
	const counts = (wrapper) => wrapper.findAll('[data-testid="directory-run-counts"] dd').map((dd) => dd.text())

	it('shows zero counts and no lists for an empty record', () => {
		const wrapper = summary({})
		expect(counts(wrapper)).toEqual(['0', '0', '0', '0'])
		for (const id of ['preview', 'guard', 'refusal', 'additions', 'removals', 'failures', 'open-work']) {
			expect(wrapper.find(`[data-testid="directory-run-${id}"]`).exists()).toBe(false)
		}
	})

	it('counts a real run from its totals', () => {
		const wrapper = summary({ usersRead: 12, groupsRead: 3, membershipsAdded: 4, membershipsRemoved: 1, additions: [{ userId: 'a', group: 'g' }] })
		expect(counts(wrapper)).toEqual(['12', '3', '4', '1'])
		expect(wrapper.find('[data-testid="directory-run-additions"]').text()).toBe('a joins g')
	})

	it('counts a preview from its lists and says nothing changed', () => {
		const wrapper = summary({
			dryRun: true,
			membershipsAdded: 99,
			additions: [{ userId: 'anna', group: 'staf' }, { userId: 'bram', group: 'staf' }],
			removals: [{ userId: 'cor', group: 'oud' }],
		})
		expect(wrapper.find('[data-testid="directory-run-preview"]').text()).toBe('Preview only. Nothing was changed.')
		expect(wrapper.find('[data-testid="directory-run-added"]').text()).toBe('2')
		expect(wrapper.find('[data-testid="directory-run-removed"]').text()).toBe('1')
		expect(wrapper.findAll('[data-testid="directory-run-additions"] li').map((li) => li.text())).toEqual(['anna joins staf', 'bram joins staf'])
		expect(wrapper.find('[data-testid="directory-run-removals"]').text()).toBe('cor leaves oud')
	})

	it('shows the guard and refusal messages', () => {
		const wrapper = summary({ guard: { message: 'Too many removals' }, refusal: { message: 'Directory unreachable' } })
		expect(wrapper.find('[data-testid="directory-run-guard"]').text()).toBe('Too many removals')
		expect(wrapper.find('[data-testid="directory-run-refusal"]').text()).toBe('Directory unreachable')
	})

	it('lists failures by user or index, and the open work each user still holds', () => {
		const wrapper = summary({
			failures: [{ userId: 'dirk', reason: 'no email' }, { index: 4, reason: 'malformed' }],
			openWork: { erik: { dossiq: 2, pipelinq: 1 } },
		})
		expect(wrapper.findAll('[data-testid="directory-run-failures"] li').map((li) => li.text())).toEqual(['dirk: no email', '4: malformed'])
		expect(wrapper.find('[data-testid="directory-run-open-work"]').text()).toBe('erik still holds: dossiq 2, pipelinq 1')
	})
})

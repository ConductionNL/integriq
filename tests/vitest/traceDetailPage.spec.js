// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * TraceDetailPage against an axios fake: the trace load and its rendered
 * fields, the not-found state, the dry-run replay (force:false) that always
 * comes first, the separate confirmation before a forced replay
 * (force:true), cancelling that confirmation, and the failure toasts.
 *
 * @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TraceDetailPage from '@/views/ExecutionTrace/TraceDetailPage.vue'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
const toast = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: ['variant', 'disabled'],
			emits: ['click'],
			render() {
				return h('button', {
					class: 'NcButton',
					disabled: this.disabled || undefined,
					'data-testid': this.$attrs['data-testid'],
					onClick: () => this.$emit('click'),
				}, this.$slots.default?.())
			},
		}),
		NcEmptyContent: defineComponent({
			name: 'NcEmptyContent',
			props: ['name', 'description'],
			render() {
				return h('div', { class: 'NcEmptyContent' }, [this.name, ' ', this.description])
			},
		}),
		NcLoadingIcon: defineComponent({
			name: 'NcLoadingIcon',
			props: ['size'],
			render() {
				return h('div', { class: 'NcLoadingIcon' })
			},
		}),
	}
})

const TRACE_URL = '/index.php/apps/integriq/api/execution-traces/t-7'
const REPLAY_URL = `${TRACE_URL}/replay`

const TRACE = {
	id: 't-7',
	status: 'failed',
	entryPoint: 'webhook',
	entryPointId: 'wh-1',
	startedAt: '2026-03-01T10:00:00Z',
	finishedAt: '2026-03-01T10:00:02Z',
	durationMs: 2000,
	replayOf: 't-1',
	error: { message: 'mapping failed' },
	steps: [{ id: 's1', name: 'map' }],
}

/**
 * Mount the page on route id t-7 after the given GET answer.
 *
 * @return {{wrapper: object, push: Function}} The wrapper and router push spy.
 */
async function mountPage() {
	const push = vi.fn()
	const wrapper = mount(TraceDetailPage, {
		global: {
			mocks: { $route: { params: { id: 't-7' } }, $router: { push } },
			stubs: { TraceTimelineWidget: { name: 'TraceTimelineWidget', props: ['steps'], template: '<div class="timeline" />' }, AlertCircleOutline: true },
		},
	})
	await flushPromises()
	return { wrapper, push }
}

/**
 * Find a button by its text.
 *
 * @param {object} wrapper The page wrapper.
 * @param {string} text The button label.
 * @return {object} The button wrapper.
 */
function button(wrapper, text) {
	return wrapper.findAll('button').find((b) => b.text() === text)
}

describe('TraceDetailPage', () => {
	beforeEach(() => {
		http.get.mockReset()
		http.post.mockReset()
		toast.showError.mockReset()
		toast.showSuccess.mockReset()
	})

	it('loads the trace of the route id and renders its fields, error and steps', async () => {
		http.get.mockResolvedValue({ data: TRACE })
		const { wrapper } = await mountPage()

		expect(http.get).toHaveBeenCalledWith(TRACE_URL)
		expect(wrapper.find('.traceDetail__badge').text()).toBe('failed')
		expect(wrapper.find('.traceDetail__badge').classes()).toContain('traceDetail__badge--failed')
		const dd = wrapper.findAll('dd').map((d) => d.text())
		expect(dd).toEqual(['webhook (wh-1)', '2026-03-01T10:00:00Z', '2026-03-01T10:00:02Z', '2000 ms', 't-1'])
		expect(wrapper.find('[data-testid="trace-error"]').text()).toContain('mapping failed')
		expect(wrapper.findAllComponents({ name: 'TraceTimelineWidget' })[0].props('steps')).toEqual(TRACE.steps)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('fills the dashes and zero for a sparse trace and omits replay-of and error', async () => {
		http.get.mockResolvedValue({ data: { id: 't-7', status: 'ok', entryPoint: 'cron' } })
		const { wrapper } = await mountPage()

		expect(wrapper.findAll('dd').map((d) => d.text())).toEqual(['cron (—)', '—', '—', '0 ms'])
		expect(wrapper.find('[data-testid="trace-error"]').exists()).toBe(false)
		expect(wrapper.findAllComponents({ name: 'TraceTimelineWidget' })[0].props('steps')).toEqual([])
	})

	it('shows the not-found state when the load fails', async () => {
		http.get.mockRejectedValue({ response: { status: 404 } })
		const { wrapper } = await mountPage()

		expect(wrapper.vm.trace).toBeNull()
		expect(wrapper.find('.NcEmptyContent').text()).toContain('Trace not found')
		expect(toast.showError).not.toHaveBeenCalled()
	})

	it('goes back to the traces list', async () => {
		http.get.mockResolvedValue({ data: TRACE })
		const { wrapper, push } = await mountPage()

		await button(wrapper, 'Back to traces').trigger('click')
		expect(push).toHaveBeenCalledWith('/traces')
	})

	it('runs a dry-run replay first and shows its preview timeline', async () => {
		http.get.mockResolvedValue({ data: TRACE })
		http.post.mockResolvedValue({ data: { id: 'p-1', steps: [{ id: 'ps' }] } })
		const { wrapper } = await mountPage()

		await button(wrapper, 'Replay (dry-run preview)').trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledTimes(1)
		expect(http.post).toHaveBeenCalledWith(REPLAY_URL, { force: false })
		expect(toast.showSuccess).toHaveBeenCalledWith('Dry-run preview complete')
		expect(wrapper.find('[data-testid="replay-preview-notice"]').exists()).toBe(true)
		expect(wrapper.findAllComponents({ name: 'TraceTimelineWidget' })[1].props('steps')).toEqual([{ id: 'ps' }])
		expect(wrapper.vm.busy).toBe(false)
	})

	it('needs a separate confirmation before sending a forced replay', async () => {
		http.get.mockResolvedValue({ data: TRACE })
		http.post.mockResolvedValueOnce({ data: { id: 'p-1', steps: [] } })
		const { wrapper } = await mountPage()
		await button(wrapper, 'Replay (dry-run preview)').trigger('click')
		await flushPromises()

		await button(wrapper, 'Force replay (real write)').trigger('click')
		expect(http.post).toHaveBeenCalledTimes(1)
		expect(wrapper.find('[data-testid="force-confirm-notice"]').exists()).toBe(true)

		http.post.mockResolvedValueOnce({ data: { id: 'r-1', steps: [{ id: 'real' }] } })
		await wrapper.find('[data-testid="confirm-force-replay"]').trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledTimes(2)
		expect(http.post).toHaveBeenLastCalledWith(REPLAY_URL, { force: true })
		expect(toast.showSuccess).toHaveBeenLastCalledWith('Forced replay complete')
		expect(wrapper.vm.preview).toEqual({ id: 'r-1', steps: [{ id: 'real' }] })
		expect(wrapper.vm.confirmingForce).toBe(false)
		expect(wrapper.find('[data-testid="force-confirm-notice"]').exists()).toBe(false)
	})

	it('cancels the confirmation without sending anything', async () => {
		http.get.mockResolvedValue({ data: TRACE })
		http.post.mockResolvedValue({ data: { steps: [] } })
		const { wrapper } = await mountPage()
		await button(wrapper, 'Replay (dry-run preview)').trigger('click')
		await flushPromises()
		await button(wrapper, 'Force replay (real write)').trigger('click')

		await button(wrapper, 'Cancel').trigger('click')
		expect(wrapper.vm.confirmingForce).toBe(false)
		expect(http.post).toHaveBeenCalledTimes(1)
		expect(button(wrapper, 'Re-run preview')).toBeDefined()
	})

	it('re-running the preview drops a pending force confirmation', async () => {
		http.get.mockResolvedValue({ data: TRACE })
		http.post.mockResolvedValue({ data: { steps: [] } })
		const { wrapper } = await mountPage()
		wrapper.vm.confirmingForce = true

		await wrapper.vm.runDryRun()
		expect(wrapper.vm.confirmingForce).toBe(false)
		expect(http.post).toHaveBeenCalledWith(REPLAY_URL, { force: false })
	})

	it('disables the replay button while a replay is in flight', async () => {
		http.get.mockResolvedValue({ data: TRACE })
		let resolve
		http.post.mockReturnValue(new Promise((r) => { resolve = r }))
		const { wrapper } = await mountPage()

		const pending = wrapper.vm.runDryRun()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.busy).toBe(true)
		expect(button(wrapper, 'Replay (dry-run preview)').attributes('disabled')).toBeDefined()
		resolve({ data: { steps: [] } })
		await pending
		expect(wrapper.vm.busy).toBe(false)
	})

	for (const [label, err, expected] of [
		['server error text', { response: { data: { error: 'locked' } } }, ': locked'],
		['network message', { message: 'Network Error' }, ': Network Error'],
		['bare failure', {}, ''],
	]) {
		it(`toasts a failed dry-run with the ${label}`, async () => {
			http.get.mockResolvedValue({ data: TRACE })
			http.post.mockRejectedValue(err)
			const { wrapper } = await mountPage()

			await wrapper.vm.runDryRun()
			expect(toast.showError).toHaveBeenCalledWith(`Dry-run preview failed${expected}`)
			expect(toast.showSuccess).not.toHaveBeenCalled()
			expect(wrapper.vm.preview).toBeNull()
			expect(wrapper.vm.busy).toBe(false)
		})

		it(`toasts a failed forced replay with the ${label} and keeps the confirmation`, async () => {
			http.get.mockResolvedValue({ data: TRACE })
			http.post.mockRejectedValue(err)
			const { wrapper } = await mountPage()
			wrapper.vm.confirmingForce = true

			await wrapper.vm.runForced()
			expect(http.post).toHaveBeenCalledWith(REPLAY_URL, { force: true })
			expect(toast.showError).toHaveBeenCalledWith(`Forced replay failed${expected}`)
			expect(wrapper.vm.confirmingForce).toBe(true)
			expect(wrapper.vm.busy).toBe(false)
		})
	}
})

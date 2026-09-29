// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A source shows its pulls per day and restarts a failed pull with one click
 * (observability-connection-run-summary tasks 2 and 3, REQ-CRUN-002 and
 * REQ-CRUN-003). These mount the real widget with the section context
 * CnDetailPage provides, call the real row-action handler, and read the real
 * manifest, and assert the requests sent and what is shown.
 *
 * @spec openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
 * @spec openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-a-failed-pull-restarts-with-one-click-req-crun-003
 */
import { evaluateVisibleWhenLocal } from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SourceRunSummaryWidget from '@/components/SourceRunSummaryWidget.vue'
import { rerunFailedRunHandler } from '@/handlers/actionHandlers.js'
import { modalBus } from '@/handlers/modalBus.js'
import { setRouter } from '@/handlers/routerRef.js'

const { get, post, showSuccess, showError } = vi.hoisted(() => ({
	get: vi.fn(),
	post: vi.fn(),
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess, showError }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: ['variant', 'disabled'],
			emits: ['click'],
			render() {
				return h(
					'button',
					{ disabled: this.disabled, onClick: () => this.$emit('click') },
					this.$slots.default?.(),
				)
			},
		}),
	}
})

const manifest = JSON.parse(
	readFileSync(join(__dirname, '../../src/manifest.json'), 'utf8'),
)

/**
 * Seven days, two runs a day, two of them failed.
 *
 * @return {object} the route's answer
 */
function aWeek() {
	const days = []
	for (let i = 0; i < 7; i++) {
		days.push({
			date: `2026-09-${String(28 - i).padStart(2, '0')}`,
			runs: 2,
			succeeded: i === 1 || i === 4 ? 1 : 2,
			failed: i === 1 || i === 4 ? 1 : 0,
			found: 15,
			created: 3,
			updated: 3,
			invalid: 1,
		})
	}
	return {
		sourceId: 'src-kvk',
		days,
		runs: [
			{ id: 'run-2', synchronizationId: 'sync-1', status: 'failed', triggeredBy: 'cron', startedAt: '2026-09-27T02:00:00+02:00', found: 5, created: 1, updated: 0, invalid: 1, message: 'Source answered 500' },
			{ id: 'run-1', synchronizationId: 'sync-1', status: 'success', triggeredBy: 'cron', startedAt: '2026-09-27T14:00:00+02:00', found: 10, created: 2, updated: 3, invalid: 0 },
		],
	}
}

/**
 * Mount the widget over the KVK source.
 *
 * @return {object} the wrapper
 */
function mountWidget() {
	return mount(SourceRunSummaryWidget, {
		global: {
			provide: { cnSectionContext: { value: { objectId: 'src-kvk', object: { name: 'KVK' } } } },
		},
	})
}

describe('the pulls per day on the source page', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		showSuccess.mockReset()
		showError.mockReset()
		get.mockResolvedValue({ data: aWeek() })
	})

	it('shows seven rows whose runs add up to fourteen and whose failures add up to two', async () => {
		const wrapper = mountWidget()
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/integriq/api/sources/src-kvk/run-summary')
		const rows = wrapper.findAll('[data-testid="run-summary-days"] tbody tr')
		expect(rows).toHaveLength(7)
		const runs = rows.map((row) => Number(row.find('[data-col="runs"]').text()))
		const failed = rows.map((row) => Number(row.find('[data-col="failed"]').text()))
		expect(runs.reduce((a, b) => a + b, 0)).toBe(14)
		expect(failed.reduce((a, b) => a + b, 0)).toBe(2)
	})

	it('lists the source runs and offers Run again only on the failed one', async () => {
		const wrapper = mountWidget()
		await flushPromises()

		const rows = wrapper.findAll('[data-testid="run-summary-runs"] tbody tr')
		expect(rows).toHaveLength(2)
		expect(rows[0].find('[data-testid="run-again"]').exists()).toBe(true)
		expect(rows[1].find('[data-testid="run-again"]').exists()).toBe(false)
	})

	it('restarts the failed run with one POST, no dialog, and reads the summary again', async () => {
		const opened = vi.fn()
		modalBus.on('*', opened)
		post.mockResolvedValue({ data: { runId: 'run-3' } })
		const wrapper = mountWidget()
		await flushPromises()

		await wrapper.find('[data-testid="run-again"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledTimes(1)
		expect(post).toHaveBeenCalledWith(
			'/index.php/apps/integriq/api/synchronizations/sync-1/run',
			{ triggeredBy: 'rerun' },
		)
		expect(opened).not.toHaveBeenCalled()
		expect(showSuccess).toHaveBeenCalledTimes(1)
		expect(get).toHaveBeenCalledTimes(2)
		modalBus.off('*', opened)
	})

	it('says so when the summary cannot be read', async () => {
		get.mockRejectedValue({ response: { data: { error: 'Action requires admin rights' } } })
		const wrapper = mountWidget()
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toContain('Action requires admin rights')
	})
})

describe('Run again as a row action', () => {
	beforeEach(() => {
		post.mockReset()
		showSuccess.mockReset()
		showError.mockReset()
	})

	it('posts the run once with triggeredBy rerun and the notice opens the new run', async () => {
		const push = vi.fn(() => Promise.resolve())
		setRouter({ push })
		post.mockResolvedValue({ data: { runId: 'run-3' } })

		await rerunFailedRunHandler({ actionId: 'run-again', item: { id: 'run-2', synchronizationId: 'sync-1', status: 'failed' } })

		expect(post).toHaveBeenCalledTimes(1)
		expect(post.mock.calls[0][1]).toEqual({ triggeredBy: 'rerun' })
		expect(showSuccess).toHaveBeenCalledTimes(1)
		const options = showSuccess.mock.calls[0][1]
		options.onClick()
		expect(push).toHaveBeenCalledWith({ name: 'SynchronizationRuns', query: { run: 'run-3' } })
	})

	it('reports the refusal and starts nothing else', async () => {
		post.mockRejectedValue({ response: { data: { error: 'Action requires admin rights' } } })

		await rerunFailedRunHandler({ item: { synchronizationId: 'sync-1', status: 'failed' } })

		expect(showError).toHaveBeenCalledWith(expect.stringContaining('Action requires admin rights'))
		expect(showSuccess).not.toHaveBeenCalled()
	})

	it('is offered on a failed run and not on a successful one', () => {
		const page = manifest.pages.find((p) => p.id === 'SynchronizationRuns')
		const action = page.config.actions.find((a) => a.id === 'run-again')
		expect(action.handler).toBe('rerunFailedRunHandler')
		expect(evaluateVisibleWhenLocal(action.visibleWhen, { status: 'failed' })).toBe(true)
		expect(evaluateVisibleWhenLocal(action.visibleWhen, { status: 'success' })).toBe(false)
	})

	it('the source page mounts the pulls per day widget', () => {
		const page = manifest.pages.find((p) => p.id === 'SourceDetail')
		const widget = page.config.bodyWidgets.find((w) => w.component === 'SourceRunSummaryWidget')
		expect(widget).toBeTruthy()
	})
})

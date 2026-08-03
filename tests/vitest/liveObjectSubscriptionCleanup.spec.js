/**
 * @vitest-environment jsdom
 *
 * SPDX-FileCopyrightText: 2026 Conduction / OpenConnector Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression guard for the live-subscription teardown.
 *
 * This mixin backs four detail pages. Before the Vue 3 migration its
 * teardown hung off `beforeDestroy`, a hook Vue 3 does not recognise and
 * silently never calls — so every visit to a detail page leaked a live
 * object subscription (and its bridge watcher) with no console error to
 * show for it. The hook was renamed to `beforeUnmount`, but nothing
 * asserted that the rename actually reconnected the teardown.
 *
 * These tests exercise the real cleanup path rather than spying on the
 * method name: they seed an active handle plus an unwatch function, unmount,
 * and assert the store was told to unsubscribe and the watcher was stopped.
 * A rename back to `beforeDestroy` (or any other hook Vue ignores) fails
 * here instead of leaking silently in production.
 *
 * @spec openspec/specs/realtime-updates/spec.md
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'

const unsubscribe = vi.fn()

vi.mock('@/store/objectStore.js', () => ({
	useObjectStore: () => ({ unsubscribe, subscribe: vi.fn() }),
}))

const liveObjectSubscription = (await import('@/mixins/liveObjectSubscription.js')).default

/**
 * Mount a throwaway host component that carries the mixin.
 *
 * @return {object} the mounted wrapper
 */
function mountHost() {
	return mount(defineComponent({
		mixins: [liveObjectSubscription],
		render: () => h('div'),
	}))
}

describe('liveObjectSubscription teardown', () => {
	beforeEach(() => {
		unsubscribe.mockClear()
	})

	it('unsubscribes the active handle when the component unmounts', () => {
		const wrapper = mountHost()
		wrapper.vm.liveHandle = { id: 'handle-1' }
		wrapper.vm.liveKey = 'rule:uuid-1'

		wrapper.unmount()

		expect(unsubscribe).toHaveBeenCalledTimes(1)
		expect(unsubscribe).toHaveBeenCalledWith({ id: 'handle-1' })
	})

	it('stops the bridge watcher when the component unmounts', () => {
		const stopWatcher = vi.fn()
		const wrapper = mountHost()
		wrapper.vm.liveUnwatch = stopWatcher

		wrapper.unmount()

		expect(stopWatcher).toHaveBeenCalledTimes(1)
	})

	it('invalidates an in-flight subscribe by bumping the epoch', () => {
		const wrapper = mountHost()
		wrapper.vm.livePendingKey = 'rule:uuid-2'
		const epochBefore = wrapper.vm.liveEpoch

		wrapper.unmount()

		expect(wrapper.vm.liveEpoch).toBe(epochBefore + 1)
		expect(wrapper.vm.livePendingKey).toBe('')
	})

	it('does not call unsubscribe when no handle was ever taken', () => {
		const wrapper = mountHost()

		wrapper.unmount()

		expect(unsubscribe).not.toHaveBeenCalled()
	})
})

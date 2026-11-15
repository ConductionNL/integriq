/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the live-subscription mixin
 * (src/mixins/liveObjectSubscription.js).
 *
 * The mixin's methods run against a plain context built from its own data()
 * and methods, with a scripted object store. Each scenario records every
 * store call, watcher registration, bridge call, warning and the mixin state
 * after each step; the snapshot pins that trace.
 *
 * @spec openspec/specs/realtime-updates/spec.md
 */
import { afterEach, describe, expect, it, vi } from 'vitest'

const store = { current: null }

vi.mock('@/store/objectStore.js', () => ({
	useObjectStore: () => store.current,
}))

const mixin = (await import('@/mixins/liveObjectSubscription.js')).default

/**
 * Build a scripted store whose subscribe() resolves or rejects on demand.
 *
 * @param {Array} log The trace to append to.
 * @return {object} The store, with `settle(index, outcome)` to finish a subscribe.
 */
function scriptedStore(log) {
	const pending = []
	let handles = 0
	return {
		pending,
		subscribe: (type, uuid) => {
			log.push(['subscribe', type, uuid])
			return new Promise((resolve, reject) => {
				pending.push({ resolve, reject })
			})
		},
		unsubscribe: (handle) => log.push(['unsubscribe', handle]),
		getObject: (type, uuid) => ({ type, uuid }),
		settle: async (index, outcome) => {
			if (outcome === 'reject') {
				pending[index].reject(new Error('down'))
			} else if (outcome === 'reject-bare') {
				pending[index].reject('bare')
			} else {
				handles += 1
				pending[index].resolve({ handle: handles })
			}
			await Promise.resolve()
			await Promise.resolve()
			await Promise.resolve()
		},
	}
}

/**
 * Build a component-like context from the mixin.
 *
 * @param {Array} log The trace to append to.
 * @param {boolean} bridge Whether the host defines applyLiveObject().
 * @return {object} The context.
 */
function context(log, bridge) {
	const ctx = { ...mixin.data(), watchers: [] }
	for (const [name, fn] of Object.entries(mixin.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	ctx.$watch = (source, callback) => {
		log.push(['watch', source()])
		ctx.watchers.push(callback)
		return () => log.push(['unwatch'])
	}
	if (bridge) {
		ctx.applyLiveObject = (fresh) => log.push(['apply', fresh])
	}
	return ctx
}

/**
 * The mixin state of a context.
 *
 * @param {object} ctx The context.
 * @return {object} The state.
 */
function state(ctx) {
	return {
		liveHandle: ctx.liveHandle,
		liveKey: ctx.liveKey,
		livePendingKey: ctx.livePendingKey,
		liveEpoch: ctx.liveEpoch,
		watching: ctx.liveUnwatch !== null,
	}
}

const SCENARIOS = {
	'no subscribe on the store': async (ctx, log) => {
		store.current = { unsubscribe: (h) => log.push(['unsubscribe', h]) }
		await ctx.syncLiveSubscription('rule', 'u1')
	},
	'missing type': async (ctx) => {
		await ctx.syncLiveSubscription('', 'u1')
	},
	'missing uuid': async (ctx) => {
		await ctx.syncLiveSubscription('rule', undefined)
	},
	'resolve without bridge': async (ctx, log, s, step) => {
		const done = ctx.syncLiveSubscription('rule', 'u1')
		step('pending')
		await s.settle(0, 'resolve')
		await done
	},
	'resolve with bridge, then events': async (ctx, log, s, step) => {
		const done = ctx.syncLiveSubscription('rule', 'u1')
		await s.settle(0, 'resolve')
		await done
		step('subscribed')
		const emit = (fresh) => ctx.watchers.forEach((watcher) => watcher(fresh))
		emit(null)
		emit({ id: 'fresh' })
		ctx.liveKey = 'other::x'
		emit({ id: 'stale' })
	},
	'same key while active': async (ctx, log, s, step) => {
		const done = ctx.syncLiveSubscription('rule', 'u1')
		await s.settle(0, 'resolve')
		await done
		step('subscribed')
		await ctx.syncLiveSubscription('rule', 'u1')
	},
	'same key while pending': async (ctx, log, s, step) => {
		const first = ctx.syncLiveSubscription('rule', 'u1')
		const second = ctx.syncLiveSubscription('rule', 'u1')
		step('both called')
		await s.settle(0, 'resolve')
		await first
		await second
	},
	'released while pending': async (ctx, log, s, step) => {
		const done = ctx.syncLiveSubscription('rule', 'u1')
		ctx.releaseLiveSubscription()
		step('released')
		await s.settle(0, 'resolve')
		await done
	},
	'switch object while pending': async (ctx, log, s, step) => {
		const first = ctx.syncLiveSubscription('rule', 'u1')
		const second = ctx.syncLiveSubscription('rule', 'u2')
		step('switched')
		await s.settle(0, 'resolve')
		await first
		step('first settled')
		await s.settle(1, 'resolve')
		await second
	},
	'switch object while active': async (ctx, log, s, step) => {
		const first = ctx.syncLiveSubscription('mapping', 'u1')
		await s.settle(0, 'resolve')
		await first
		step('first active')
		const second = ctx.syncLiveSubscription('mapping', 'u2')
		await s.settle(1, 'resolve')
		await second
	},
	'subscribe rejects': async (ctx, log, s) => {
		const done = ctx.syncLiveSubscription('rule', 'u1')
		await s.settle(0, 'reject')
		await done
	},
	'subscribe rejects with a bare value': async (ctx, log, s) => {
		const done = ctx.syncLiveSubscription('rule', 'u1')
		await s.settle(0, 'reject-bare')
		await done
	},
	'rejects after the object switched': async (ctx, log, s, step) => {
		const first = ctx.syncLiveSubscription('rule', 'u1')
		const second = ctx.syncLiveSubscription('rule', 'u2')
		await s.settle(0, 'reject')
		await first
		step('first rejected')
		await s.settle(1, 'resolve')
		await second
	},
	'release without store unsubscribe': async (ctx, log) => {
		ctx.liveHandle = { handle: 'seeded' }
		store.current = { subscribe: () => log.push(['never']) }
		ctx.releaseLiveSubscription()
	},
}

describe('liveObjectSubscription golden', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	for (const bridge of [false, true]) {
		it.each(Object.keys(SCENARIOS))(`%s (bridge ${bridge})`, async (name) => {
			const log = []
			vi.spyOn(console, 'warn').mockImplementation((...args) =>
				log.push(['warn', ...args]),
			)
			const s = scriptedStore(log)
			store.current = s
			const ctx = context(log, bridge)
			const step = (label) => log.push(['state', label, state(ctx)])
			await SCENARIOS[name](ctx, log, s, step)
			step('end')
			expect(log).toMatchSnapshot()
		})
	}
})

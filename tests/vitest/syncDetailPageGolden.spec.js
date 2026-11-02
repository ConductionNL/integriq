// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of SynchronizationDetailPage's state handling: its
 * initial data, loading, normalising a stored record, the raw conditions
 * editor, resetting the cursor and saving. The page's data, computed
 * getters and methods are resolved through its mixins and run on a plain
 * context with a scripted store and axios; every call and state step is
 * recorded.
 *
 * @spec openspec/specs/sync-editor-ui/spec.md
 */
import { translate } from '@nextcloud/l10n'
import { describe, expect, it, vi } from 'vitest'

const { trace, post } = vi.hoisted(() => ({ trace: [], post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post } }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => trace.push(['error', text]),
	showSuccess: (text) => trace.push(['success', text]),
}))
vi.mock('@nextcloud/vue', () => anyExport())
vi.mock('@conduction/nextcloud-vue', () => anyExport())

/**
 * A module mock whose every export, property and call result is itself.
 *
 * @return {object} the module
 */
function anyExport() {
	const any = new Proxy(function () {}, {
		get: (_target, name) => (name === 'then' ? undefined : any),
		has: () => true,
		apply: () => any,
	})
	return new Proxy(
		{},
		{
			get: (_target, name) => (name === 'then' ? undefined : any),
			has: () => true,
		},
	)
}

globalThis.t = translate

const page = (await import('@/views/Synchronization/SynchronizationDetailPage.vue'))
	.default

const FIELDS = [
	'loading',
	'saving',
	'saveError',
	'loadError',
	'draft',
	'original',
	'rawConditions',
	'rawConditionsDraft',
	'rawConditionsError',
	'tablesEnabled',
	'formsEnabled',
	'resettingCursor',
]

/**
 * A plain context with the page's data, computed getters and methods.
 *
 * @param {object} store the scripted object store
 * @param {string} id the route id
 * @return {object} the context
 */
function context(store, id = 'sync-1') {
	const ctx = { id, register: '', schema: '', objectStore: store }
	const layers = [...(page.mixins || []), page]
	for (const layer of layers) {
		Object.assign(ctx, layer.data ? layer.data.call(ctx) : {})
	}
	for (const layer of layers) {
		for (const [name, def] of Object.entries(layer.computed || {})) {
			const getter = typeof def === 'function' ? def : def.get
			Object.defineProperty(ctx, name, {
				configurable: true,
				get: () => getter.call(ctx),
			})
		}
		for (const [name, fn] of Object.entries(layer.methods || {})) {
			ctx[name] = fn.bind(ctx)
		}
	}
	ctx.syncLiveSubscription = (...args) => trace.push(['subscribe', ...args])
	return ctx
}

/**
 * The page state as plain JSON.
 *
 * @param {object} ctx the context
 * @return {object} the state
 */
function state(ctx) {
	return JSON.parse(
		JSON.stringify(Object.fromEntries(FIELDS.map((f) => [f, ctx[f] ?? null]))),
	)
}

/**
 * A store that answers fetch and save as scripted.
 *
 * @param {object} script fetch and save answers (value or Error)
 * @return {object} the store
 */
function store(script) {
	const answer = (value) =>
		value instanceof Error ? Promise.reject(value) : Promise.resolve(value)
	return {
		errors: script.errors,
		fetchObject: (...args) => {
			trace.push(['fetch', ...args])
			return answer(script.fetch)
		},
		saveObject: (...args) => {
			trace.push(['save', JSON.parse(JSON.stringify(args))])
			return answer(script.save)
		},
	}
}

const STORED = {
	id: 'sync-1',
	name: 'Nightly',
	description: 'd',
	sourceType: 'api',
	sourceConfig: { a: 1 },
	targetConfig: [1],
	actions: 'not-a-list',
	followUps: ['f1'],
	conditions: '{"==":[1,1]}',
	syncMode: null,
	extra: 'kept',
	cursorWatermark: 'w1',
}

describe('SynchronizationDetailPage', () => {
	it('starts with the recorded data', () => {
		expect(state(context(store({})))).toMatchSnapshot()
	})

	it.each([
		['a stored record', { fetch: STORED }, 'sync-1'],
		['a new record', {}, ''],
		[
			'nothing found with a store error',
			{ fetch: null, errors: { synchronization: 'gone' } },
			'sync-1',
		],
		['nothing found', { fetch: null }, 'sync-1'],
		['a failed fetch', { fetch: new Error('boom') }, 'sync-1'],
		['a failed fetch without message', { fetch: new Error('') }, 'sync-1'],
	])('loads %s', async (_label, script, id) => {
		trace.length = 0
		const ctx = context(store(script), id)
		ctx.draft = { stale: true }
		ctx.original = { stale: true }
		ctx.loadError = 'old'
		const loading = ctx.loadObject()
		const during = ctx.loading
		await loading
		expect({ during, trace: [...trace], after: state(ctx) }).toMatchSnapshot()
	})

	it.each([
		['null', null],
		['undefined', undefined],
		['a full record', STORED],
		['conditions as a list', { conditions: [{ '==': [1, 1] }] }],
		['conditions as an object', { conditions: { or: [] } }],
		['objects given as lists', { sourceConfig: [1], targetConfig: 'x' }],
		['nulls everywhere', { name: null, sourceConfig: null, actions: null }],
	])('normalises %s', (_label, obj) => {
		const ctx = context(store({}))
		const out = ctx.normalizeForDiff(obj)
		if (obj && obj.sourceConfig && typeof obj.sourceConfig === 'object') {
			expect(out.sourceConfig).not.toBe(obj.sourceConfig)
		}
		expect(out).toMatchSnapshot()
	})

	it('edits raw conditions as recorded', () => {
		const ctx = context(store({}))
		ctx.draft = ctx.normalizeForDiff({})
		const steps = []
		for (const raw of ['{"and":', '{"or": []}', '   ', 'nope', '']) {
			ctx.onRawConditionsInput(raw)
			steps.push(
				JSON.parse(
					JSON.stringify([
						raw,
						ctx.rawConditionsDraft,
						ctx.rawConditionsError,
						ctx.draft.conditions,
					]),
				),
			)
		}
		const empty = context(store({}))
		empty.onRawConditionsInput('{"or": []}')
		steps.push([empty.draft, empty.rawConditionsError])
		expect(steps).toMatchSnapshot()
	})

	it.each([
		[
			'a cleared watermark',
			() => Promise.resolve({ data: { cursorWatermark: '' } }),
			true,
		],
		[
			'an answer without a watermark',
			() => Promise.resolve({ data: {} }),
			false,
		],
		[
			'a server error',
			() =>
				Promise.reject({
					response: { data: { error: 'srv' } },
					message: 'm',
				}),
			true,
		],
		['a transport error', () => Promise.reject(new Error('net')), true],
		['a bare failure', () => Promise.reject({}), true],
	])('resets the cursor with %s', async (_label, answer, withOriginal) => {
		trace.length = 0
		post.mockReset()
		post.mockImplementation((...args) => {
			trace.push(['post', ...args])
			return answer()
		})
		const ctx = context(store({}))
		ctx.original = withOriginal ? { cursorWatermark: 'w1' } : null
		const running = ctx.resetCursor()
		const during = ctx.resettingCursor
		await running
		expect({ during, trace: [...trace], after: state(ctx) }).toMatchSnapshot()
	})

	it('does not reset the cursor twice or without an id', async () => {
		post.mockReset()
		const busy = context(store({}))
		busy.resettingCursor = true
		await busy.resetCursor()
		await context(store({}), '').resetCursor()
		expect(post).not.toHaveBeenCalled()
	})

	it.each([
		['a saved record', { save: { ...STORED, name: 'Saved' } }],
		[
			'nothing saved with a store error',
			{ save: null, errors: { synchronization: 'invalid' } },
		],
		['nothing saved', { save: null }],
		['a failed save', { save: new Error('boom') }],
		['a failed save without message', { save: new Error('') }],
	])('saves with %s', async (_label, script) => {
		trace.length = 0
		const ctx = context(store(script))
		ctx.original = { id: 'sync-1', extra: 'kept', name: 'Old' }
		ctx.draft = ctx.normalizeForDiff({
			...STORED,
			name: 'Edited',
			conditions: { and: [{ '==': [1, 1] }] },
		})
		ctx.saveError = 'old'
		const saving = ctx.save()
		const during = ctx.saving
		await saving
		expect({ during, trace: [...trace], after: state(ctx) }).toMatchSnapshot()
	})

	it('does not save without a draft or twice', async () => {
		trace.length = 0
		const ctx = context(store({ save: STORED }))
		await ctx.save()
		ctx.draft = {}
		ctx.saving = true
		await ctx.save()
		expect(trace).toEqual([])
	})
})

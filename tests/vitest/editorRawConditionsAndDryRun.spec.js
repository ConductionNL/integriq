/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rule editor's raw JSON Logic mode and dirty check, and the
 * synchronization editor's dry run: what each keystroke commits, when Format
 * refuses, how the raw text is seeded, and what a dry run shows on success and
 * on each kind of failure.
 *
 * Exercised through the real component options from the `.vue` files, with
 * HTTP and the component libraries mocked.
 *
 * @spec openspec/specs/rule-editor-ui/spec.md
 * @spec openspec/specs/sync-editor-ui/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const post = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn().mockResolvedValue({ data: {} }), post, put: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) => defineComponent({ name, render: () => h('div') })
	return new Proxy({}, {
		get: (_t, key) => (typeof key === 'string' && /^Nc/.test(key) ? stub(key) : undefined),
		has: (_t, key) => typeof key === 'string' && /^Nc/.test(key),
	})
})
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) => defineComponent({ name, render: () => h('div') })
	return { CnTab: stub('CnTab'), CnTabs: stub('CnTabs'), useObjectStore: () => ({}) }
})

const RuleEditorModal = (await import('@/modals/v2/RuleEditorModal.vue')).default
const SynchronizationEditorModal = (await import('@/modals/v2/SynchronizationEditorModal.vue')).default

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
function t(app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match))
}

describe('the rule editor\'s raw JSON Logic mode', () => {
	/**
	 * A rule editor context recording what is committed to the draft.
	 *
	 * @return {object} The context.
	 */
	function rule() {
		const ctx = {
			t,
			draft: { conditions: { and: [] } },
			rawConditionsDraft: '',
			rawConditionsError: '',
			committed: [],
			onConditionsUpdate(node) {
				this.committed.push(node)
			},
		}
		ctx.onRawConditionsInput = RuleEditorModal.methods.onRawConditionsInput.bind(ctx)
		ctx.formatRawConditions = RuleEditorModal.methods.formatRawConditions.bind(ctx)
		return ctx
	}

	it('commits the text only when it parses, and keeps it verbatim either way', () => {
		const ctx = rule()
		ctx.onRawConditionsInput('{"==": [{"var": "status"}, "open"]}')
		expect(ctx.committed).toEqual([{ '==': [{ var: 'status' }, 'open'] }])
		expect(ctx.rawConditionsError).toBe('')

		ctx.onRawConditionsInput('{"==": [')
		expect(ctx.rawConditionsDraft).toBe('{"==": [')
		expect(ctx.committed).toHaveLength(1)
		expect(ctx.rawConditionsError).toMatch(/^Invalid JSON: /)
	})

	it('treats empty text as "always match": an empty AND group', () => {
		const ctx = rule()
		ctx.rawConditionsError = 'old'
		ctx.onRawConditionsInput('   ')
		expect(ctx.rawConditionsError).toBe('')
		expect(ctx.committed).toHaveLength(1)
		expect(JSON.stringify(ctx.committed[0])).not.toContain('var')
	})

	it('re-indents valid text and reports invalid text instead of discarding it', () => {
		const ctx = rule()
		ctx.rawConditionsDraft = '{"and":[{"var":"a"}]}'
		ctx.formatRawConditions()
		expect(ctx.rawConditionsDraft).toBe('{\n  "and": [\n    {\n      "var": "a"\n    }\n  ]\n}')
		expect(ctx.rawConditionsError).toBe('')

		ctx.rawConditionsDraft = '{"and":'
		ctx.formatRawConditions()
		expect(ctx.rawConditionsDraft).toBe('{"and":')
		expect(ctx.rawConditionsError).toMatch(/^Invalid JSON: /)
	})

	it('seeds the raw text from the visual tree when raw mode is switched on, and only then', () => {
		const ctx = { rawConditionsDraft: 'keep', rawConditionsError: 'old', rootConditionGroup: { and: [{ var: 'x' }] } }
		RuleEditorModal.watch.rawConditions.call(ctx, false)
		expect(ctx.rawConditionsDraft).toBe('keep')
		expect(ctx.rawConditionsError).toBe('old')

		RuleEditorModal.watch.rawConditions.call(ctx, true)
		expect(ctx.rawConditionsDraft).toBe(JSON.stringify({ and: [{ var: 'x' }] }, null, 2))
		expect(ctx.rawConditionsError).toBe('')

		const cyclic = {}
		cyclic.self = cyclic
		const broken = { rawConditionsDraft: 'x', rawConditionsError: '', rootConditionGroup: cyclic }
		RuleEditorModal.watch.rawConditions.call(broken, true)
		expect(broken.rawConditionsDraft).toBe('')
	})

	it('is dirty only when the draft differs from what was loaded', () => {
		const draft = { name: 'Open zaken', conditions: { and: [] } }
		const dirty = RuleEditorModal.computed.dirty
		expect(dirty.call({ draft, originalSignature: JSON.stringify(draft) })).toBe(false)
		expect(dirty.call({ draft: { ...draft, name: 'Gesloten zaken' }, originalSignature: JSON.stringify(draft) })).toBe(true)
		expect(RuleEditorModal.computed.conditionsPlaceholder.call({})).toContain('var')
	})
})

describe('the synchronization editor\'s dry run', () => {
	beforeEach(() => {
		post.mockReset()
	})

	/**
	 * A synchronization editor context for a saved record.
	 *
	 * @param {object} extra Fields over the defaults.
	 * @return {object} The context.
	 */
	function sync(extra = {}) {
		return { t, isCreate: false, itemIdString: 'sync-1', testing: false, testError: 'old', testResult: 'old', ...extra }
	}

	it('runs the dry run of the saved record and shows its result', async () => {
		post.mockResolvedValueOnce({ data: { created: 2, updated: 0 } })
		const ctx = sync()
		await SynchronizationEditorModal.methods.onTest.call(ctx)

		expect(post).toHaveBeenCalledWith('/index.php/apps/integriq/api/synchronizations/sync-1/test')
		expect(ctx.testResult).toEqual({ created: 2, updated: 0 })
		expect(ctx.testError).toBe('')
		expect(ctx.testing).toBe(false)
		expect(SynchronizationEditorModal.computed.formattedTestResult.call(ctx)).toBe('{\n  "created": 2,\n  "updated": 0\n}')
	})

	it('does nothing for a record that is not saved yet', async () => {
		await SynchronizationEditorModal.methods.onTest.call(sync({ isCreate: true }))
		await SynchronizationEditorModal.methods.onTest.call(sync({ itemIdString: '' }))
		expect(post).not.toHaveBeenCalled()
	})

	it('says why a dry run failed: status and the server\'s message, or just what is known', async () => {
		const ctx = sync()
		post.mockRejectedValueOnce({ response: { status: 502, data: { message: 'Source unreachable' } } })
		await SynchronizationEditorModal.methods.onTest.call(ctx)
		expect(ctx.testError).toBe('Synchronization test failed (502): Source unreachable')
		expect(ctx.testResult).toBe(null)

		post.mockRejectedValueOnce({ response: { status: 404, data: { error: 'Not found' } } })
		await SynchronizationEditorModal.methods.onTest.call(ctx)
		expect(ctx.testError).toBe('Synchronization test failed (404): Not found')

		post.mockRejectedValueOnce(new Error('Network Error'))
		await SynchronizationEditorModal.methods.onTest.call(ctx)
		expect(ctx.testError).toBe('Synchronization test failed: Network Error')

		post.mockRejectedValueOnce({})
		await SynchronizationEditorModal.methods.onTest.call(ctx)
		expect(ctx.testError).toBe('Synchronization test failed')
		expect(ctx.testing).toBe(false)
	})

	it('shows an empty answer as null and an unprintable one as text', () => {
		const formatted = SynchronizationEditorModal.computed.formattedTestResult
		expect(formatted.call({ testResult: null })).toBe('null')
		const cyclic = {}
		cyclic.self = cyclic
		expect(formatted.call({ testResult: cyclic })).toBe('[object Object]')
	})
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for FormsFieldMapping (src/views/Synchronization/FormsFieldMapping.vue),
 * the panel that lists a Nextcloud Forms form's questions for a forms-bridge
 * synchronisation: when it fetches, what it asks the server, how it reads the
 * answer, and what it shows when the fetch fails. The component logic runs over
 * a plain context; HTTP is mocked.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const http = { get: vi.fn() }

vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => http.get(...args) } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (match, key) => String(params[key])),
}))
vi.mock('@nextcloud/vue', () => ({ NcLoadingIcon: {} }))

const panel = (await import('@/views/Synchronization/FormsFieldMapping.vue')).default

/**
 * A component context for the given props, with computeds as getters and
 * methods bound.
 *
 * @param {object} props The sourceId and formId.
 * @return {object} The context.
 */
function vm(props) {
	const ctx = { ...props }
	Object.assign(ctx, panel.data.call(ctx))
	for (const [name, getter] of Object.entries(panel.computed)) {
		Object.defineProperty(ctx, name, { get: () => getter.call(ctx) })
	}
	for (const [name, method] of Object.entries(panel.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	globalThis.t = (app, text) => text
	http.get.mockReset()
	vi.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => {
	delete globalThis.t
	vi.restoreAllMocks()
})

describe('fetching questions', () => {
	it('asks nothing and clears the list without a source or a form', async () => {
		const form = vm({ sourceId: '', formId: 4 })
		form.questions = [{ id: 1 }]
		await form.fetchQuestions()
		expect(form.questions).toEqual([])
		expect(http.get).not.toHaveBeenCalled()

		const noForm = vm({ sourceId: 3, formId: '' })
		await noForm.fetchQuestions()
		expect(http.get).not.toHaveBeenCalled()
	})

	it('asks the forms bridge for the questions of the form on the source', async () => {
		http.get.mockResolvedValue({ data: { results: [{ id: '7', text: 'Name', name: 'name', type: 'short' }] } })
		const form = vm({ sourceId: 3, formId: 12 })
		form.questionsError = 'old'
		await form.fetchQuestions()
		expect(http.get).toHaveBeenCalledWith(
			'/apps/integriq/api/synchronizations/forms-bridge/forms/12/questions',
			{ params: { sourceId: 3 } },
		)
		expect(form.questions).toEqual([{ id: 7, text: 'Name', name: 'name', type: 'short' }])
		expect(form.questionsError).toBe('')
		expect(form.loading).toBe(false)
	})

	it('reads a bare list as well as the results envelope', async () => {
		http.get.mockResolvedValue({ data: [{ id: 1, text: 'A' }] })
		const form = vm({ sourceId: 3, formId: 12 })
		await form.fetchQuestions()
		expect(form.questions).toEqual([{ id: 1, text: 'A', name: '', type: '' }])
	})

	it('shows the server error and empties the list when the fetch fails', async () => {
		http.get.mockRejectedValue({ response: { data: { error: 'Source unreachable' } } })
		const form = vm({ sourceId: 3, formId: 12 })
		form.questions = [{ id: 1 }]
		await form.fetchQuestions()
		expect(form.questions).toEqual([])
		expect(form.questionsError).toBe('Source unreachable')
		expect(form.loading).toBe(false)
	})

	it('falls back to its own sentence when the failure carries no error', async () => {
		http.get.mockRejectedValue(new Error('Network Error'))
		const form = vm({ sourceId: 3, formId: 12 })
		await form.fetchQuestions()
		expect(form.questionsError).toBe('Could not load questions for this form.')
	})
})

describe('refetching', () => {
	it('fetches when the form or the source changes', () => {
		const form = vm({ sourceId: 3, formId: 12 })
		form.fetchQuestions = vi.fn()
		expect(panel.watch.formId.immediate).toBe(true)
		panel.watch.formId.handler.call(form)
		panel.watch.sourceId.call(form)
		expect(form.fetchQuestions).toHaveBeenCalledTimes(2)
	})
})

describe('marking questions', () => {
	it('marks multi-value question types as array-valued', () => {
		const form = vm({})
		expect(form.isArrayValued({ type: 'multiple' })).toBe(true)
		expect(form.isArrayValued({ type: 'multiple_unique' })).toBe(true)
		expect(form.isArrayValued({ type: 'short' })).toBe(false)
	})

	it('marks a question whose text another question shares as ambiguous', () => {
		const form = vm({})
		form.questions = [{ id: 1, text: 'Name' }, { id: 2, text: 'Name' }, { id: 3, text: 'Email' }]
		expect(form.isAmbiguous({ text: 'Name' })).toBe(true)
		expect(form.isAmbiguous({ text: 'Email' })).toBe(false)
	})
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for SyncMappingPreview (src/views/Synchronization/SyncMappingPreview.vue),
 * the collapsible panel that runs a synchronisation's mapping over sample
 * input: loading the mapping, parsing the input, posting the preview, the
 * debounce on typing, and what each failure shows. The component logic runs
 * over a plain context; HTTP and timers are mocked.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const http = { get: vi.fn(), post: vi.fn() }

vi.mock('@nextcloud/axios', () => ({
	default: { get: (...args) => http.get(...args), post: (...args) => http.post(...args) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (match, key) => String(params[key])),
}))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {} }))
vi.mock('vue-material-design-icons/ChevronDown.vue', () => ({ default: {} }))
vi.mock('vue-material-design-icons/ChevronRight.vue', () => ({ default: {} }))

const preview = (await import('@/views/Synchronization/SyncMappingPreview.vue')).default

/**
 * A component context for `mappingId`, with computeds as getters and methods
 * bound.
 *
 * @param {string} mappingId The mapping slug or id.
 * @return {object} The context.
 */
function vm(mappingId = '') {
	const ctx = { mappingId }
	Object.assign(ctx, preview.data.call(ctx))
	for (const [name, getter] of Object.entries(preview.computed)) {
		Object.defineProperty(ctx, name, { get: () => getter.call(ctx) })
	}
	for (const [name, method] of Object.entries(preview.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

const mapping = { id: 'm1', mapping: { name: '{{ title }}' } }

beforeEach(() => {
	globalThis.t = (app, text, vars) => text.replace(/\{(\w+)\}/g, (match, key) => vars?.[key] ?? match)
	globalThis.window = {
		setTimeout: (...args) => setTimeout(...args),
		clearTimeout: (...args) => clearTimeout(...args),
	}
	http.get.mockReset()
	http.post.mockReset()
	vi.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => {
	delete globalThis.t
	delete globalThis.window
	vi.useRealTimers()
	vi.restoreAllMocks()
})

describe('state', () => {
	it('gives each panel its own input id', () => {
		const first = vm()
		const second = vm()
		expect(first.inputId).toMatch(/^sync-mapping-preview-\d+-input$/)
		expect(first.inputId).not.toBe(second.inputId)
	})

	it('shows the result as indented JSON, empty when there is none', () => {
		const form = vm()
		expect(form.resultJson).toBe('')
		form.result = { a: 1 }
		expect(form.resultJson).toBe('{\n  "a": 1\n}')
	})

	it('falls back to String() for a result JSON cannot serialise', () => {
		const form = vm()
		form.result = 10n
		expect(form.resultJson).toBe('10')
	})
})

describe('loading the mapping', () => {
	it('reads the mapping object and runs the preview', async () => {
		http.get.mockResolvedValue({ data: { object: mapping } })
		http.post.mockResolvedValue({ data: { resultObject: { name: 'x' } } })
		const form = vm('m1')
		await form.loadAndRun()
		expect(http.get).toHaveBeenCalledWith('/apps/openregister/api/objects/integriq/mapping/m1')
		expect(form.mapping).toEqual(mapping)
		expect(http.post).toHaveBeenCalledWith('/apps/integriq/api/mappings/test', {
			inputObject: { example: 'value' },
			mapping,
		})
		expect(form.result).toEqual({ name: 'x' })
		expect(form.running).toBe(false)
	})

	it('takes a bare object answer as the mapping', async () => {
		http.get.mockResolvedValue({ data: mapping })
		http.post.mockResolvedValue({ data: {} })
		const form = vm('m1')
		await form.loadAndRun()
		expect(form.mapping).toEqual(mapping)
	})

	it('says the mapping was not found on an empty answer and posts nothing', async () => {
		http.get.mockResolvedValue({ data: null })
		const form = vm('m1')
		await form.loadAndRun()
		expect(form.loadError).toBe('Mapping not found.')
		expect(http.post).not.toHaveBeenCalled()
	})

	it('shows the server message, the error message or its own sentence on a failed load', async () => {
		const form = vm('m1')
		http.get.mockRejectedValue({ response: { data: { message: 'Forbidden' } } })
		await form.loadAndRun()
		expect(form.loadError).toBe('Forbidden')
		http.get.mockRejectedValue(new Error('Network Error'))
		await form.loadAndRun()
		expect(form.loadError).toBe('Network Error')
		http.get.mockRejectedValue({})
		await form.loadAndRun()
		expect(form.loadError).toBe('Failed to load mapping.')
	})
})

describe('running the preview', () => {
	it('loads the mapping first when it has none yet', async () => {
		const form = vm('m1')
		form.loadAndRun = vi.fn()
		await form.runPreview()
		expect(form.loadAndRun).toHaveBeenCalledOnce()
		expect(http.post).not.toHaveBeenCalled()
	})

	it('does nothing without a mapping or an id', async () => {
		const form = vm('')
		await form.runPreview()
		expect(http.get).not.toHaveBeenCalled()
		expect(http.post).not.toHaveBeenCalled()
	})

	it('posts an empty object for empty input', async () => {
		http.post.mockResolvedValue({ data: { ok: true } })
		const form = vm('m1')
		form.mapping = mapping
		form.inputJson = '   '
		await form.runPreview()
		expect(http.post).toHaveBeenCalledWith('/apps/integriq/api/mappings/test', { inputObject: {}, mapping })
		expect(form.result).toEqual({ ok: true })
	})

	it('refuses invalid input JSON, clears the result and posts nothing', async () => {
		const form = vm('m1')
		form.mapping = mapping
		form.result = { stale: true }
		form.inputJson = '{"a": '
		await form.runPreview()
		expect(form.inputError).toMatch(/^Input is not valid JSON: /)
		expect(form.result).toBeNull()
		expect(http.post).not.toHaveBeenCalled()
	})

	it('shows the failure and clears the result when the preview fails', async () => {
		const form = vm('m1')
		form.mapping = mapping
		form.result = { stale: true }
		http.post.mockRejectedValue({ response: { data: { message: 'Twig error' } } })
		await form.runPreview()
		expect(form.runError).toBe('Twig error')
		expect(form.result).toBeNull()
		expect(form.running).toBe(false)
		http.post.mockRejectedValue({})
		await form.runPreview()
		expect(form.runError).toBe('Mapping preview failed.')
	})
})

describe('typing input', () => {
	it('runs the preview once, 300 ms after the last keystroke', () => {
		vi.useFakeTimers()
		const form = vm('m1')
		form.runPreview = vi.fn()
		form.onInput('{"a": 1}')
		vi.advanceTimersByTime(200)
		form.onInput('{"a": 2}')
		vi.advanceTimersByTime(299)
		expect(form.runPreview).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		expect(form.runPreview).toHaveBeenCalledOnce()
		expect(form.inputJson).toBe('{"a": 2}')
		expect(form.debounceTimer).toBeNull()
	})

	it('cancels a pending run when the panel goes away', () => {
		vi.useFakeTimers()
		const form = vm('m1')
		form.runPreview = vi.fn()
		form.onInput('{}')
		preview.beforeUnmount.call(form)
		vi.advanceTimersByTime(1000)
		expect(form.runPreview).not.toHaveBeenCalled()
	})
})

describe('reacting to the mapping and the panel', () => {
	it('clears stale state and forgets the mapping when the id is cleared', () => {
		const form = vm('m1')
		Object.assign(form, { mapping, result: { a: 1 }, runError: 'x', loadError: 'y' })
		preview.watch.mappingId.handler.call(form, '', 'm1')
		expect(form.mapping).toBeNull()
		expect(form.result).toBeNull()
		expect(form.runError).toBe('')
		expect(form.loadError).toBe('')
	})

	it('reloads a new mapping only while the panel is open', () => {
		const form = vm('m2')
		form.loadAndRun = vi.fn()
		preview.watch.mappingId.handler.call(form, 'm2', 'm1')
		expect(form.loadAndRun).not.toHaveBeenCalled()
		form.expanded = true
		preview.watch.mappingId.handler.call(form, 'm2', 'm1')
		expect(form.loadAndRun).toHaveBeenCalledOnce()
	})

	it('ignores an unchanged id', () => {
		const form = vm('m1')
		form.result = { a: 1 }
		preview.watch.mappingId.handler.call(form, 'm1', 'm1')
		expect(form.result).toEqual({ a: 1 })
	})

	it('loads on opening when no mapping is cached', () => {
		const form = vm('m1')
		form.loadAndRun = vi.fn()
		preview.watch.expanded.call(form, true)
		expect(form.loadAndRun).toHaveBeenCalledOnce()
		form.mapping = mapping
		preview.watch.expanded.call(form, true)
		preview.watch.expanded.call(form, false)
		expect(form.loadAndRun).toHaveBeenCalledOnce()
	})
})

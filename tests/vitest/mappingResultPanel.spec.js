/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The input parsing, validation-error table, save gating, option loading,
 * save-to-register and reset of the mapping test panel
 * (src/components/mapping/MappingResultPanel.vue). run() itself is pinned by
 * mappingResultRunGolden.spec.js; this file covers the rest of the panel on a
 * plain context built from its data(), computed and methods, with only the
 * HTTP client and the toast functions mocked.
 *
 * @spec openspec/specs/mapping-editor-ui/spec.md
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const toast = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
	NcSelect: {},
}))

const panel = (await import('@/components/mapping/MappingResultPanel.vue')).default

/**
 * A plain context with the panel's data, methods and live computed getters.
 *
 * @param {object} props The props to start from.
 * @return {{ctx: object, emitted: Array}} The context and its emitted events.
 */
function context(props = {}) {
	const emitted = []
	const ctx = {
		auto: true,
		debounceMs: 400,
		allowSaveToRegister: true,
		showSchemaPicker: true,
		mapping: null,
		inputObject: '',
		t: (app, text, vars) =>
			text.replace(/\{(\w+)\}/g, (m, k) =>
				vars && k in vars ? String(vars[k]) : m,
			),
		...props,
	}
	Object.assign(ctx, panel.data.call(ctx))
	for (const [name, fn] of Object.entries(panel.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	for (const [name, def] of Object.entries(panel.computed)) {
		const getter = typeof def === 'function' ? def : def.get
		Object.defineProperty(ctx, name, { get: () => getter.call(ctx) })
	}
	ctx.$emit = (...args) => emitted.push(args)
	return { ctx, emitted }
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	toast.showError.mockReset()
	toast.showSuccess.mockReset()
	vi.spyOn(console, 'warn').mockImplementation(() => {})
})

describe('parseInput', () => {
	it('passes an object through and clears the input error', () => {
		const input = { b: 1 }
		const { ctx, emitted } = context({ inputObject: input })
		expect(ctx.parseInput()).toBe(input)
		expect(emitted).toEqual([['input-error', '']])
	})

	it('treats blank text as an empty object', () => {
		const { ctx, emitted } = context({ inputObject: '  \n ' })
		expect(ctx.parseInput()).toEqual({})
		expect(emitted).toEqual([['input-error', '']])
	})

	it('treats null as an empty object', () => {
		const { ctx } = context({ inputObject: null })
		expect(ctx.parseInput()).toEqual({})
	})

	it('parses JSON text', () => {
		const { ctx, emitted } = context({ inputObject: ' {"a":[1,2]} ' })
		expect(ctx.parseInput()).toEqual({ a: [1, 2] })
		expect(emitted).toEqual([['input-error', '']])
	})

	it('returns null and emits the parse message for invalid JSON', () => {
		const { ctx, emitted } = context({ inputObject: '{nope' })
		expect(ctx.parseInput()).toBeNull()
		expect(emitted).toHaveLength(1)
		expect(emitted[0][0]).toBe('input-error')
		expect(emitted[0][1]).toMatch(/^Input is not valid JSON: .+/)
	})
})

describe('validationErrorRows', () => {
	it('is empty without errors', () => {
		const { ctx } = context()
		expect(ctx.validationErrorRows).toEqual([])
	})

	it('turns a bare string into one row without a field', () => {
		const { ctx } = context()
		ctx.validationErrors = 'whole object is wrong'
		expect(ctx.validationErrorRows).toEqual([
			{ field: '', messages: ['whole object is wrong'] },
		])
	})

	it('turns a bare array into rows without a field', () => {
		const { ctx } = context()
		ctx.validationErrors = ['first', { message: 'second' }]
		expect(ctx.validationErrorRows).toEqual([
			{ field: '', messages: ['first'] },
			{ field: '', messages: ['second'] },
		])
	})

	it('maps a field map, wrapping single messages in a list', () => {
		const { ctx } = context()
		ctx.validationErrors = {
			title: ['is required', { error: 'too short' }],
			age: 'must be an integer',
		}
		expect(ctx.validationErrorRows).toEqual([
			{ field: 'title', messages: ['is required', 'too short'] },
			{ field: 'age', messages: ['must be an integer'] },
		])
	})

	it('is empty for a non-object, non-string value', () => {
		const { ctx } = context()
		ctx.validationErrors = 42
		expect(ctx.validationErrorRows).toEqual([])
	})
})

describe('formatValidationError', () => {
	it('prefers message, then error, then the JSON of the entry', () => {
		const { ctx } = context()
		expect(ctx.formatValidationError('plain')).toBe('plain')
		expect(ctx.formatValidationError({ message: 'm', error: 'e' })).toBe('m')
		expect(ctx.formatValidationError({ error: 'e' })).toBe('e')
		expect(ctx.formatValidationError({ code: 7 })).toBe('{"code":7}')
	})
})

describe('mappingTestFailureText', () => {
	it('adds the status and the first message found', () => {
		const { ctx } = context()
		expect(
			ctx.mappingTestFailureText({
				response: { status: 422, data: { message: 'bad', error: 'worse' } },
			}),
		).toBe('Mapping test failed (422): bad')
	})

	it('falls back to the transport message without a response', () => {
		const { ctx } = context()
		expect(ctx.mappingTestFailureText(new Error('Network Error'))).toBe(
			'Mapping test failed: Network Error',
		)
	})

	it('is the bare line without status or message', () => {
		const { ctx } = context()
		expect(ctx.mappingTestFailureText(undefined)).toBe('Mapping test failed')
	})
})

describe('mappingTestPayload', () => {
	it('sends input and mapping, without validation when no schema is picked', () => {
		const mapping = { mapping: { a: 'b' } }
		const { ctx } = context({ mapping })
		expect(ctx.mappingTestPayload({ b: 1 })).toEqual({
			inputObject: { b: 1 },
			mapping,
		})
	})

	it('asks for validation against the picked schema', () => {
		const { ctx } = context({ mapping: {} })
		ctx.selectedSchema = { id: 's-1' }
		expect(ctx.mappingTestPayload({})).toEqual({
			inputObject: {},
			mapping: {},
			schema: 's-1',
			validation: true,
		})
	})
})

describe('applyMappingTestResult', () => {
	it('records the result, validity, errors and the schema validated against', () => {
		const { ctx } = context()
		ctx.selectedSchema = { id: 's-1' }
		ctx.applyMappingTestResult({
			resultObject: { a: 1 },
			isValid: false,
			validationErrors: { a: ['x'] },
		})
		expect(ctx.result).toEqual({ a: 1 })
		expect(ctx.isValid).toBe(false)
		expect(ctx.validationErrors).toEqual({ a: ['x'] })
		expect(ctx.validatedSchemaId).toBe('s-1')
	})

	it('keeps the XML or CSV text of a mapping that writes one, and none otherwise', () => {
		const { ctx } = context()
		const rendered = { format: 'xml', contentType: 'application/xml', body: '<persoon />' }
		ctx.applyMappingTestResult({ resultObject: { naam: 'Jan' }, renderedOutput: rendered })
		expect(ctx.renderedOutput).toEqual(rendered)
		ctx.applyMappingTestResult({ resultObject: { naam: 'Jan' } })
		expect(ctx.renderedOutput).toBeNull()
		ctx.applyMappingTestResult({ resultObject: {}, renderedOutput: { format: 'csv', body: null } })
		expect(ctx.renderedOutput).toBeNull()
	})

	it('uses the bare body as the result and counts it valid', () => {
		const { ctx } = context()
		ctx.applyMappingTestResult({ a: 3 })
		expect(ctx.result).toEqual({ a: 3 })
		expect(ctx.isValid).toBe(true)
		expect(ctx.validationErrors).toBeNull()
		expect(ctx.validatedSchemaId).toBeNull()
	})
})

describe('computed display state', () => {
	it('pretty-prints the result', () => {
		const { ctx } = context()
		ctx.result = { a: 1 }
		expect(ctx.hasResult).toBe(true)
		expect(ctx.formattedResult).toBe('{\n  "a": 1\n}')
	})

	it('falls back to String() for a result JSON cannot serialise', () => {
		const { ctx } = context()
		ctx.result = { big: 1n }
		expect(ctx.formattedResult).toBe('[object Object]')
	})

	it('words the empty text after the run mode', () => {
		expect(context({ auto: true }).ctx.emptyText).toMatch(/^Output appears here/)
		expect(context({ auto: false }).ctx.emptyText).toBe(
			'Run the test to see the result here.',
		)
	})

	it('gives each instance its own input ids', () => {
		const a = context().ctx
		const b = context().ctx
		expect(a.schemaInputId).toBe(`cn-mapping-result-schema-${a.uid}`)
		expect(a.registerInputId).toBe(`cn-mapping-result-register-${a.uid}`)
		expect(b.uid).toBe(a.uid + 1)
	})

	it('shows the save block only with save allowed, OpenRegister and a result', () => {
		const { ctx } = context()
		ctx.openRegisterAvailable = true
		expect(ctx.showSaveBlock).toBe(false)
		ctx.result = {}
		expect(ctx.showSaveBlock).toBe(true)
		ctx.openRegisterAvailable = false
		expect(ctx.showSaveBlock).toBe(false)
		const { ctx: noSave } = context({ allowSaveToRegister: false })
		noSave.openRegisterAvailable = true
		noSave.result = {}
		expect(noSave.showSaveBlock).toBe(false)
	})

	it('changes the run signal with the input and the schema', () => {
		const { ctx } = context({
			mapping: { mapping: { a: 'b' } },
			inputObject: '{}',
		})
		const first = ctx.runSignal
		expect(JSON.parse(first)).toEqual({
			mapping: { a: 'b' },
			cast: null,
			unset: null,
			passThrough: false,
			input: '{}',
			schema: null,
		})
		ctx.selectedSchema = { id: 's-1' }
		expect(ctx.runSignal).not.toBe(first)
	})

	it('gives an empty run signal when the input cannot be serialised', () => {
		const { ctx } = context({ inputObject: { big: 1n } })
		expect(ctx.runSignal).toBe('')
	})
})

describe('canSaveResult', () => {
	/**
	 * A panel holding a valid result validated against s-1, register r-1 picked.
	 *
	 * @return {object} The context.
	 */
	function ready() {
		const { ctx } = context()
		ctx.result = { a: 1 }
		ctx.isValid = true
		ctx.selectedSchema = { id: 's-1' }
		ctx.selectedRegister = { id: 'r-1' }
		ctx.validatedSchemaId = 's-1'
		return ctx
	}

	it('is enabled with a valid result, a register and the validated schema', () => {
		expect(ready().canSaveResult).toBe(true)
	})

	it.each([
		['saving', (c) => (c.savingResult = true)],
		['no result', (c) => (c.result = null)],
		['an invalid result', (c) => (c.isValid = false)],
		['no schema', (c) => (c.selectedSchema = null)],
		['no register', (c) => (c.selectedRegister = null)],
		[
			'a schema swapped after the run',
			(c) => (c.selectedSchema = { id: 's-2' }),
		],
	])('is disabled with %s', (_label, change) => {
		const ctx = ready()
		change(ctx)
		expect(ctx.canSaveResult).toBe(false)
	})
})

describe('reset', () => {
	it('clears the result surface, retires the run and cancels the timer', () => {
		const { ctx } = context()
		ctx.scheduleRun = { cancel: vi.fn() }
		ctx.runSequence = 4
		ctx.running = true
		ctx.runError = 'x'
		ctx.result = { a: 1 }
		ctx.renderedOutput = { format: 'xml', body: '<a />' }
		ctx.isValid = false
		ctx.validationErrors = { a: ['x'] }
		ctx.validatedSchemaId = 's-1'
		ctx.reset()
		expect(ctx.scheduleRun.cancel).toHaveBeenCalledTimes(1)
		expect(ctx.runSequence).toBe(5)
		expect(ctx.running).toBe(false)
		expect(ctx.runError).toBe('')
		expect(ctx.result).toBeNull()
		expect(ctx.renderedOutput).toBeNull()
		expect(ctx.isValid).toBe(true)
		expect(ctx.validationErrors).toBeNull()
		expect(ctx.validatedSchemaId).toBeNull()
	})
})

describe('fetchSchemas', () => {
	it('maps a results envelope to options', async () => {
		http.get.mockResolvedValue({
			data: {
				results: [
					{ id: 1, title: 'Person' },
					{ uuid: 'u-2', name: 'Case' },
					{ id: 3, slug: 'doc' },
				],
			},
		})
		const { ctx } = context()
		await ctx.fetchSchemas()
		expect(http.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/schemas',
		)
		expect(ctx.schemaOptions).toEqual([
			{ id: 1, label: 'Person' },
			{ id: 'u-2', label: 'Case' },
			{ id: 3, label: 'doc' },
		])
		expect(ctx.schemasLoading).toBe(false)
	})

	it('accepts a bare list and ignores any other shape', async () => {
		const { ctx } = context()
		http.get.mockResolvedValueOnce({ data: [{ id: 1, title: 'A' }] })
		await ctx.fetchSchemas()
		expect(ctx.schemaOptions).toEqual([{ id: 1, label: 'A' }])
		http.get.mockResolvedValueOnce({ data: { results: 'nope' } })
		await ctx.fetchSchemas()
		expect(ctx.schemaOptions).toEqual([])
	})

	it('leaves the picker empty when the request fails', async () => {
		http.get.mockRejectedValue(new Error('down'))
		const { ctx } = context()
		ctx.schemaOptions = [{ id: 'old' }]
		await ctx.fetchSchemas()
		expect(ctx.schemaOptions).toEqual([])
		expect(ctx.schemasLoading).toBe(false)
	})
})

describe('fetchRegisters', () => {
	it('records OpenRegister availability and maps the registers', async () => {
		http.get.mockResolvedValue({
			data: {
				openRegisters: true,
				availableRegisters: [
					{ id: 1, title: 'Main', description: 'Primary' },
					{ id: 2, name: 'Archive' },
					{ id: 3, slug: 'misc' },
				],
			},
		})
		const { ctx } = context()
		await ctx.fetchRegisters()
		expect(http.get).toHaveBeenCalledWith(
			'/index.php/apps/integriq/api/mappings/objects',
		)
		expect(ctx.openRegisterAvailable).toBe(true)
		expect(ctx.registerOptions).toEqual([
			{ id: 1, label: 'Main', description: 'Primary' },
			{ id: 2, label: 'Archive', description: '' },
			{ id: 3, label: 'misc', description: '' },
		])
		expect(ctx.registersLoading).toBe(false)
	})

	it('counts anything but true as OpenRegister missing', async () => {
		http.get.mockResolvedValue({ data: { openRegisters: 'yes' } })
		const { ctx } = context()
		await ctx.fetchRegisters()
		expect(ctx.openRegisterAvailable).toBe(false)
		expect(ctx.registerOptions).toEqual([])
	})

	it('hides the save block when the request fails', async () => {
		http.get.mockRejectedValue(new Error('down'))
		const { ctx } = context()
		ctx.openRegisterAvailable = true
		ctx.registerOptions = [{ id: 1 }]
		await ctx.fetchRegisters()
		expect(ctx.openRegisterAvailable).toBe(false)
		expect(ctx.registerOptions).toEqual([])
		expect(ctx.registersLoading).toBe(false)
	})
})

describe('saveResult', () => {
	/**
	 * A panel that may save: valid result validated against s-1, register r-1.
	 *
	 * @return {object} The context.
	 */
	function ready() {
		const { ctx } = context()
		ctx.result = { a: 1 }
		ctx.selectedSchema = { id: 's-1' }
		ctx.selectedRegister = { id: 'r-1' }
		ctx.validatedSchemaId = 's-1'
		return ctx
	}

	it('posts the result to the chosen register and schema and confirms', async () => {
		http.post.mockResolvedValue({ data: {} })
		const ctx = ready()
		await ctx.saveResult()
		expect(http.post).toHaveBeenCalledWith(
			'/index.php/apps/integriq/api/mappings/objects',
			{ object: { a: 1 }, register: 'r-1', schema: 's-1' },
		)
		expect(toast.showSuccess).toHaveBeenCalledWith('Result saved as object')
		expect(toast.showError).not.toHaveBeenCalled()
		expect(ctx.savingResult).toBe(false)
	})

	it('does nothing when saving is not allowed', async () => {
		const ctx = ready()
		ctx.isValid = false
		await ctx.saveResult()
		expect(http.post).not.toHaveBeenCalled()
		expect(toast.showSuccess).not.toHaveBeenCalled()
	})

	it.each([
		[
			{ response: { data: { error: 'e', message: 'm' } } },
			'Failed to save result: e',
		],
		[{ response: { data: { message: 'm' } } }, 'Failed to save result: m'],
		[new Error('Network Error'), 'Failed to save result: Network Error'],
		[{}, 'Failed to save result'],
	])('reports a failed save', async (err, text) => {
		http.post.mockRejectedValue(err)
		const ctx = ready()
		await ctx.saveResult()
		expect(toast.showError).toHaveBeenCalledWith(text)
		expect(toast.showSuccess).not.toHaveBeenCalled()
		expect(ctx.savingResult).toBe(false)
	})
})

describe('lifecycle', () => {
	it('loads the pickers and schedules a run on mount when all are enabled', () => {
		const { ctx } = context()
		ctx.fetchSchemas = vi.fn()
		ctx.fetchRegisters = vi.fn()
		ctx.scheduleRun = vi.fn()
		panel.mounted.call(ctx)
		expect(ctx.fetchSchemas).toHaveBeenCalledTimes(1)
		expect(ctx.fetchRegisters).toHaveBeenCalledTimes(1)
		expect(ctx.scheduleRun).toHaveBeenCalledTimes(1)
	})

	it('skips what is switched off', () => {
		const { ctx } = context({
			auto: false,
			allowSaveToRegister: false,
			showSchemaPicker: false,
		})
		ctx.fetchSchemas = vi.fn()
		ctx.fetchRegisters = vi.fn()
		ctx.scheduleRun = vi.fn()
		panel.mounted.call(ctx)
		expect(ctx.fetchSchemas).not.toHaveBeenCalled()
		expect(ctx.fetchRegisters).not.toHaveBeenCalled()
		expect(ctx.scheduleRun).not.toHaveBeenCalled()
	})

	it('re-runs on a signal change only in auto mode', () => {
		const { ctx } = context()
		ctx.scheduleRun = vi.fn()
		panel.watch.runSignal.handler.call(ctx)
		expect(ctx.scheduleRun).toHaveBeenCalledTimes(1)
		const { ctx: manual } = context({ auto: false })
		manual.scheduleRun = vi.fn()
		panel.watch.runSignal.handler.call(manual)
		expect(manual.scheduleRun).not.toHaveBeenCalled()
	})

	it('cancels the pending run on unmount', () => {
		const { ctx } = context()
		panel.created.call(ctx)
		const cancel = vi.spyOn(ctx.scheduleRun, 'cancel')
		panel.beforeUnmount.call(ctx)
		expect(cancel).toHaveBeenCalledTimes(1)
	})
})

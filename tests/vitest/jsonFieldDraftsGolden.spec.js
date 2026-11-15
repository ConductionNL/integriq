// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of the json textareas of the source and job form fields:
 * what each textarea shows, and what an edit stores, keeps as a draft or
 * reports as invalid. The data and methods are resolved through each
 * component's mixins and its own options and run on a plain context.
 *
 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
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

const COMPONENTS = {
	JobFormFields: (await import('@/modals/v2/JobFormFields.vue')).default,
	SourceFormFields: (await import('@/modals/v2/SourceFormFields.vue')).default,
}

/**
 * A plain context with the component's json state and its methods.
 *
 * @param {object} component the component options
 * @param {object} formData the stored values
 * @param {Array} writes where updateField calls are recorded
 * @return {object} the context
 */
function context(component, formData, writes) {
	const ctx = {
		formData,
		updateField: (key, value) => writes.push([key, value]),
	}
	for (const layer of [...(component.mixins || []), component]) {
		const data = layer.data ? layer.data.call(ctx) : {}
		ctx.jsonDrafts = data.jsonDrafts ?? ctx.jsonDrafts
		ctx.jsonErrors = data.jsonErrors ?? ctx.jsonErrors
		for (const [name, fn] of Object.entries(layer.methods || {})) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

const circular = {}
circular.self = circular

describe.each(Object.entries(COMPONENTS))('%s', (_name, component) => {
	it('starts with no drafts and no errors', () => {
		const ctx = context(component, {}, [])
		expect([ctx.jsonDrafts, ctx.jsonErrors]).toEqual([{}, {}])
	})

	it.each([
		['missing', undefined, ''],
		['null', null, ''],
		['a string', '{"raw": true}', '{"raw": true}'],
		['an object', { a: [1, 2] }, '{\n  "a": [\n    1,\n    2\n  ]\n}'],
		['a number', 0, '0'],
		['false', false, 'false'],
		['circular', circular, '[object Object]'],
	])('shows %s as text', (_label, value, text) => {
		const ctx = context(component, { conf: value }, [])
		expect(ctx.jsonStringFor({ key: 'conf' })).toBe(text)
	})

	it('records edits, drafts and errors as recorded', () => {
		const writes = []
		const ctx = context(component, { conf: { stored: 1 } }, writes)
		const field = { key: 'conf' }
		const steps = []
		for (const raw of ['{"a":', '{"a": 1}', '   ', ' [1, 2] ', 'nope', '']) {
			ctx.onJsonInput(field, raw)
			steps.push({
				raw,
				shown: ctx.jsonStringFor(field),
				drafts: { ...ctx.jsonDrafts },
				errors: { ...ctx.jsonErrors },
				writes: [...writes],
			})
		}
		expect(steps).toMatchSnapshot()
	})
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for onSave() of the column-mapping editor
 * (src/modals/Migration/ColumnMappingEditorModal.vue).
 *
 * onSave() runs on a plain context for every combination of draft, stored
 * row, check outcome and store outcome. Each run records the requests, the
 * row handed to `confirm`, toasts, close calls, errors and the saving flag;
 * the snapshot pins that trace.
 */
import { describe, expect, it, vi } from 'vitest'

const trace = []
const server = { check: 'ok' }

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: () => Promise.resolve({ data: [] }),
		post: (url, body) => {
			trace.push(['post', url, body])
			if (server.check === 'ok') return Promise.resolve({ data: {} })
			if (server.check === 'reasons') {
				return Promise.reject({
					response: { data: { errors: ['name is missing', 42] } },
				})
			}
			if (server.check === 'empty reasons') {
				return Promise.reject({ response: { data: { errors: [] } } })
			}
			return Promise.reject(new Error('Network Error'))
		},
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (text) => trace.push(['success', text]),
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcDialog: {},
	NcNoteCard: {},
	NcSelect: {},
	NcTextField: {},
}))

const editor = (await import('@/modals/Migration/ColumnMappingEditorModal.vue'))
	.default

const DRAFTS = {
	new: {
		id: null,
		name: 'Leden',
		kind: '',
		targetSchema: '',
		identifierColumn: '',
		version: 0,
		rows: [{ column: 'Naam', target: 'name' }],
	},
	stored: {
		id: 'm-1',
		name: 'Leden',
		kind: 'csv',
		targetSchema: 'person',
		identifierColumn: 'Nr',
		version: 3,
		rows: [
			{ column: 'Nr', target: 'number' },
			{ column: 'Naam', target: 'name' },
		],
	},
	blank: { ...emptyLike() },
}

/**
 * A draft with no name, which the editor refuses to save.
 *
 * @return {object} The draft.
 */
function emptyLike() {
	return {
		id: null,
		name: '  ',
		kind: '',
		targetSchema: '',
		identifierColumn: '',
		version: 0,
		rows: [{ column: '', target: '' }],
	}
}

const ITEMS = [
	null,
	{
		id: 'm-1',
		uuid: 'u-1',
		kind: 'old',
		targetSchema: 'old',
		identifierColumn: 'old',
		extra: 1,
	},
]
const SCHEMAS = [
	null,
	{ schema: { properties: { name: { type: 'string' } }, required: ['name'] } },
	{},
]
const CHECKS = ['ok', 'reasons', 'empty reasons', 'down']
const STORES = ['ok', 'throws', 'throws bare', 'no confirm']

/**
 * A plain context carrying the editor's methods and computed getters.
 *
 * @param {object} state The props and data to start from.
 * @return {object} The context.
 */
function context(state) {
	const ctx = { saving: false, saveErrors: ['stale'], ...state }
	for (const [name, fn] of Object.entries(editor.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	for (const [name, fn] of Object.entries(editor.computed)) {
		Object.defineProperty(ctx, name, { get: fn.bind(ctx) })
	}
	return ctx
}

/**
 * The `confirm` slot prop for a store outcome.
 *
 * @param {string} outcome The store outcome.
 * @return {Function|undefined} The confirm callback.
 */
function confirmFor(outcome) {
	if (outcome === 'no confirm') return undefined
	return (row) => {
		trace.push(['confirm', row])
		if (outcome === 'throws') return Promise.reject(new Error('conflict'))
		if (outcome === 'throws bare') return Promise.reject({})
		return Promise.resolve()
	}
}

describe('column mapping editor save golden', () => {
	for (const check of CHECKS) {
		it(`saves as recorded when the check answers ${check}`, async () => {
			const runs = []
			server.check = check
			for (const [draftName, draft] of Object.entries(DRAFTS)) {
				for (const item of ITEMS) {
					for (const selectedSchema of SCHEMAS) {
						for (const store of STORES) {
							trace.length = 0
							const ctx = context({
								draft: structuredClone(draft),
								item,
								selectedSchema,
								confirm: confirmFor(store),
								close: () => trace.push(['close']),
							})
							const pending = ctx.onSave()
							const savingDuring = ctx.saving
							await pending
							runs.push({
								draftName,
								item,
								selectedSchema,
								store,
								savingDuring,
								savingAfter: ctx.saving,
								saveErrors: ctx.saveErrors,
								trace: [...trace],
							})
						}
					}
				}
			}
			expect(runs).toMatchSnapshot()
		})
	}
})

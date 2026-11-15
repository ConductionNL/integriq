/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for TablesColumnMapping (src/views/Synchronization/TablesColumnMapping.vue),
 * the panel that maps a Nextcloud Tables table's columns for a tables-bridge
 * synchronisation: when it fetches, what it asks the server, how it reads the
 * answer and reports a failure, and the config it emits when a column mapping
 * is typed. The component logic runs over a plain context; HTTP is mocked.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const http = { get: vi.fn() }

vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => http.get(...args) } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (match, key) => String(params[key])),
}))
vi.mock('@nextcloud/vue', () => ({ NcLoadingIcon: {}, NcTextField: {} }))

const panel = (await import('@/views/Synchronization/TablesColumnMapping.vue')).default

/**
 * A component context for the given props, with methods bound and emitted
 * events recorded.
 *
 * @param {object} props The sourceId, tableId and config.
 * @return {object} The context.
 */
function vm(props) {
	const ctx = { config: {}, ...props, events: [] }
	ctx.$emit = (event, payload) => ctx.events.push([event, payload])
	Object.assign(ctx, panel.data.call(ctx))
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

describe('fetching columns', () => {
	it('asks nothing and clears the list without a source or a table', async () => {
		const form = vm({ sourceId: 3, tableId: '' })
		form.columns = [{ id: 1 }]
		await form.fetchColumns()
		expect(form.columns).toEqual([])
		expect(http.get).not.toHaveBeenCalled()
	})

	it('asks the tables bridge for the columns of the table on the source', async () => {
		http.get.mockResolvedValue({ data: { results: [{ id: '5', title: 'Amount', type: 'number', mandatory: 1 }] } })
		const form = vm({ sourceId: 3, tableId: 9 })
		form.columnsError = 'old'
		await form.fetchColumns()
		expect(http.get).toHaveBeenCalledWith(
			'/apps/integriq/api/synchronizations/tables-bridge/tables/9/columns',
			{ params: { sourceId: 3 } },
		)
		expect(form.columns).toEqual([
			{ id: 5, title: 'Amount', type: 'number', subtype: null, mandatory: true, constraints: {} },
		])
		expect(form.columnsError).toBe('')
		expect(form.loading).toBe(false)
	})

	it('shows the server error and empties the list when the fetch fails', async () => {
		http.get.mockRejectedValue({ response: { data: { error: 'No access to table' } } })
		const form = vm({ sourceId: 3, tableId: 9 })
		form.columns = [{ id: 1 }]
		await form.fetchColumns()
		expect(form.columns).toEqual([])
		expect(form.columnsError).toBe('No access to table')
		expect(form.loading).toBe(false)
	})

	it('falls back to its own sentence when the failure carries no error', async () => {
		http.get.mockRejectedValue(new Error('Network Error'))
		const form = vm({ sourceId: 3, tableId: 9 })
		await form.fetchColumns()
		expect(form.columnsError).toBe('Could not load columns for this table.')
	})

	it('fetches when the table or the source changes', () => {
		const form = vm({ sourceId: 3, tableId: 9 })
		form.fetchColumns = vi.fn()
		expect(panel.watch.tableId.immediate).toBe(true)
		panel.watch.tableId.handler.call(form)
		panel.watch.sourceId.call(form)
		expect(form.fetchColumns).toHaveBeenCalledTimes(2)
	})
})

describe('column mapping', () => {
	const config = { mode: 'append', columnMapping: [{ column: 'Amount', value: '{{ total }}' }] }

	it('describes a column by its type', () => {
		const form = vm({})
		expect(form.typeHint({ type: 'number', constraints: { numberDecimals: 2 } })).toBe('number (2 decimals)')
		expect(form.typeHint({ type: 'usergroup' })).toBe('usergroup (read-only — not writable)')
	})

	it('reads the mapped value of a column, empty when unmapped', () => {
		const form = vm({ config })
		expect(form.mappedValue('Amount')).toBe('{{ total }}')
		expect(form.mappedValue('Date')).toBe('')
	})

	it('emits the config with the typed value trimmed and the other keys kept', () => {
		const form = vm({ config })
		form.onMappingUpdate('Date', '  {{ date }} ')
		expect(form.events).toEqual([['update:config', {
			mode: 'append',
			columnMapping: [{ column: 'Amount', value: '{{ total }}' }, { column: 'Date', value: '{{ date }}' }],
		}]])
		expect(config.columnMapping).toHaveLength(1)
	})

	it('drops the mapping list when the last value is cleared', () => {
		const form = vm({ config })
		form.onMappingUpdate('Amount', '')
		expect(form.events).toEqual([['update:config', { mode: 'append' }]])
	})
})

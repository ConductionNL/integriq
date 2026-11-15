/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for what a mount-mode leaf reports to its host: every leaf
 * state is run through leafHeader(), leafSummary() and the mixin's computed
 * properties and compared with tests/vitest/golden/leafReport.json.
 */
import { afterAll, describe, it } from 'vitest'
import { goldenFile } from './golden/goldenFile.js'
import {
	leafHeader,
	leafReportMixin,
	leafSummary,
} from '@/integration/leafReport.js'

const golden = goldenFile(new URL('./golden/leafReport.json', import.meta.url))
afterAll(() => golden.save())

const ACTIONS = [{ label: 'Add', run: function add() {} }]
const ROWS = [{ label: 'One', href: '/one' }]

/**
 * The result of a call, or the kind of error it threw.
 *
 * @param {Function} run The call.
 * @return {object} The outcome.
 */
function outcome(run) {
	try {
		return { result: run() }
	} catch (error) {
		return { threw: error.constructor.name }
	}
}

const STATES = {
	empty: {},
	loading: { loading: true, error: '', count: 3, emptyLabel: 'None' },
	loadingTruthy: { loading: 1, error: '', count: 3, emptyLabel: 'None' },
	loadingFalse: { loading: false, error: '', count: 0, emptyLabel: 'None' },
	failed: { loading: false, error: 'boom', count: 2, noun: 'items' },
	failedWhileLoading: { loading: true, error: 'boom', count: 2 },
	errorUndefined: { loading: false, count: 0, emptyLabel: 'None' },
	errorNull: { loading: false, error: null, count: 4, noun: 'n' },
	zero: {
		loading: false,
		error: '',
		count: 0,
		emptyLabel: 'Nothing yet',
		noun: 'rules',
		note: 'A note',
		rows: ROWS,
		actions: ACTIONS,
	},
	some: {
		loading: false,
		error: '',
		count: 2,
		emptyLabel: 'Nothing yet',
		noun: 'rules',
		note: 'A note',
		rows: ROWS,
		actions: ACTIONS,
	},
	countString: { loading: false, error: '', count: '0', rows: ROWS },
	countNegative: { loading: false, error: '', count: -1, rows: ROWS },
	countUndefined: { loading: false, error: '', rows: ROWS },
	nullDefaults: {
		loading: false,
		error: '',
		count: 1,
		note: null,
		rows: null,
		actions: null,
	},
	emptyDefaults: {
		loading: false,
		error: '',
		count: 1,
		note: '',
		rows: [],
		actions: [],
	},
}

/**
 * The mixin's computed header and summary for a component context.
 *
 * @param {object} context The component state and its leafReport.
 * @return {object} The header and summary.
 */
function computedOf(context) {
	const { hostHeader, hostSummary, carded, summarised } = leafReportMixin.computed
	return {
		hostHeader: outcome(() => hostHeader.call(context)),
		hostSummary: outcome(() => hostSummary.call(context)),
		carded: carded.call(context),
		summarised: summarised.call(context),
	}
}

describe('leafReport golden', () => {
	it.each(Object.entries(STATES))('state %s', (name, state) => {
		const header = outcome(() => leafHeader(state))
		const summary = outcome(() => leafSummary(state))
		golden.check(`leafHeader ${name}`, header)
		golden.check(`leafSummary ${name}`, summary)
		golden.check(`shared ${name}`, {
			headerActions: header.result?.actions === state.actions,
			summaryActions: summary.result?.actions === state.actions,
			summaryRows: summary.result?.rows === state.rows,
		})
		const { loading, error, ...report } = state
		golden.check(
			`computed ${name}`,
			computedOf({
				loading,
				error,
				leafReport: report,
				onHeader: name === 'zero' ? () => {} : null,
				onSummary: name === 'some' ? () => {} : 'x',
			}),
		)
	})

	it('throws without a state', () => {
		golden.check(
			'leafHeader undefined',
			outcome(() => leafHeader()),
		)
		golden.check(
			'leafSummary null',
			outcome(() => leafSummary(null)),
		)
	})

	it('reports through the watchers', () => {
		const seen = []
		const context = {
			carded: true,
			summarised: true,
			onHeader: (header) => seen.push(['header', header]),
			onSummary: (summary) => seen.push(['summary', summary]),
		}
		const { hostHeader, hostSummary } = leafReportMixin.watch
		hostHeader.handler.call(context, { count: 1 })
		hostSummary.handler.call(context, null)
		hostSummary.handler.call(context, { count: 2 })
		hostHeader.handler.call({ ...context, carded: false }, { count: 3 })
		hostSummary.handler.call({ ...context, summarised: false }, { count: 4 })
		golden.check('watchers', seen)
	})
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the consumer editor draft: rows of every shape are seeded
 * into a draft, and drafts with every credential choice are turned into the
 * payload, compared with tests/vitest/golden/consumerDraft.json.
 *
 * @spec openspec/specs/consumer-management/spec.md
 */
import { afterAll, describe, it } from 'vitest'
import { goldenFile } from './golden/goldenFile.js'
import {
	buildConsumerPayload,
	consumerDraftFromItem,
	positiveIntOrNull,
} from '@/modals/v2/consumerDraft.js'

const golden = goldenFile(new URL('./golden/consumerDraft.json', import.meta.url))
afterAll(() => golden.save())

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

const VALUES = {
	undefined,
	null: null,
	emptyString: '',
	zero: 0,
	one: 1,
	negative: -3,
	fraction: 2.9,
	numericString: '12',
	paddedString: ' 7 ',
	word: 'many',
	infinity: Infinity,
	nan: NaN,
	boolTrue: true,
	array: [5],
}

const ROWS = {
	null: null,
	undefined,
	string: 'consumer',
	number: 4,
	empty: {},
	full: {
		id: 3,
		name: 'Portal',
		description: 'Public portal',
		domains: ['a.example', ' b.example ', ''],
		ips: '10.0.0.1, 10.0.0.2',
		authorizationType: 'apiKey',
		authorizationConfiguration: { key: 'secret' },
		rateLimit: { requestsPerWindow: '60', windowSeconds: 30 },
		quota: { limit: 1000, period: 'day' },
	},
	emptyType: { authorizationType: '' },
	nullType: { authorizationType: null },
	offListType: { authorizationType: 'custom-x' },
	numericName: { name: 42, description: null },
	rateLimitNotObject: { rateLimit: 'fast', quota: 7 },
	rateLimitNull: { rateLimit: null, quota: null },
	badPeriod: { quota: { limit: 5, period: 'week' } },
	zeroLimits: {
		rateLimit: { requestsPerWindow: 0, windowSeconds: -1 },
		quota: { limit: 0, period: 'hour' },
	},
	monthQuota: { quota: { limit: '9', period: 'month' } },
	listsNotLists: { domains: 5, ips: { a: 1 } },
	arrayRow: [1, 2],
}

const DRAFTS = {
	empty: {},
	full: {
		name: ' Portal ',
		description: ' x ',
		domains: ['a.example', ''],
		ips: '10.0.0.1',
		authorizationType: 'bearer',
		rateLimitRequestsPerWindow: 60,
		rateLimitWindowSeconds: 30,
		quotaLimit: 100,
		quotaPeriod: 'hour',
	},
	noneType: { name: 'n', authorizationType: 'none' },
	blankType: { name: 'n', authorizationType: '' },
	upperNone: { name: 'n', authorizationType: ' NONE ' },
	offList: { name: 'n', authorizationType: 'custom-x' },
	halfRate: {
		rateLimitRequestsPerWindow: 5,
		rateLimitWindowSeconds: null,
		quotaLimit: 3,
		quotaPeriod: 'week',
	},
	emptyLists: { domains: [], ips: '' },
}

const ITEMS = {
	none: null,
	stored: {
		id: 9,
		uuid: 'u-9',
		domains: ['old.example'],
		ips: ['1.1.1.1'],
		authorizationConfiguration: { key: 'kept' },
		extra: 'x',
	},
}

const AUTH = {
	untouched: undefined,
	cleared: null,
	set: { key: 'new' },
}

describe('consumerDraft golden', () => {
	it('positiveIntOrNull', () => {
		for (const [name, value] of Object.entries(VALUES)) {
			golden.check(
				`positiveIntOrNull/${name}`,
				outcome(() => positiveIntOrNull(value)),
			)
		}
	})

	it('consumerDraftFromItem', () => {
		for (const [name, row] of Object.entries(ROWS)) {
			golden.check(
				`consumerDraftFromItem/${name}`,
				outcome(() => consumerDraftFromItem(row)),
			)
		}
	})

	it('buildConsumerPayload', () => {
		for (const [itemName, item] of Object.entries(ITEMS)) {
			for (const [draftName, draft] of Object.entries(DRAFTS)) {
				for (const [authName, auth] of Object.entries(AUTH)) {
					const result = outcome(() =>
						buildConsumerPayload(item, draft, auth),
					)
					golden.check(
						`buildConsumerPayload/${itemName}/${draftName}/${authName}`,
						{
							...result,
							keys: result.result ? Object.keys(result.result) : null,
							hasAuthKey: result.result
								? Object.hasOwn(
										result.result,
										'authorizationConfiguration',
									)
								: null,
						},
					)
				}
			}
		}
	})
})

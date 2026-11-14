/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The per-consumer and per-endpoint usage lists on the API product page.
 *
 * @spec openspec/changes/gateway-usage-per-consumer-and-endpoint/specs/api-product-gateway/spec.md#requirement-product-usage-is-split-per-consumer-and-per-endpoint-req-apgu-001
 */

import { describe, expect, it } from 'vitest'
import { usageLabel, usageSplits } from '../../src/views/ApiProducts/productUsage.js'

const translate = (_app, text) => `[${text}]`

describe('usageSplits', () => {
	it('lists consumers first, then endpoints, with the entries the response carries', () => {
		const byConsumer = [{ consumer: 'c-1', name: 'Partner', requestCount: 3 }]
		const byEndpoint = [{ endpoint: 'e-1', requestCount: 2 }, { endpoint: 'e-2', requestCount: 1 }]

		const splits = usageSplits({ requestCount: 3, byConsumer, byEndpoint }, translate)

		expect(splits).toEqual([
			{ key: 'consumer', title: '[Per consumer]', column: '[Consumer]', entries: byConsumer },
			{ key: 'endpoint', title: '[Per endpoint]', column: '[Endpoint]', entries: byEndpoint },
		])
	})

	it('gives empty lists when the response has no split or no response at all', () => {
		expect(usageSplits({ requestCount: 0, byConsumer: 'x' }, translate).map((s) => s.entries)).toEqual([[], []])
		expect(usageSplits(null, translate).map((s) => s.entries)).toEqual([[], []])
	})
})

describe('usageLabel', () => {
	it('prefers the name, then the id, then says the traffic is anonymous', () => {
		expect(usageLabel({ consumer: 'c-1', name: 'Partner' }, 'consumer', translate)).toBe('Partner')
		expect(usageLabel({ consumer: 'c-1', name: null }, 'consumer', translate)).toBe('c-1')
		expect(usageLabel({ endpoint: 'e-1', name: '' }, 'endpoint', translate)).toBe('e-1')
		expect(usageLabel({ consumer: null, name: null }, 'consumer', translate)).toBe('[Anonymous]')
	})
})

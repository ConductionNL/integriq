/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the OpenRegister collection GET the legacy EditEndpoint and EditRule modals send:
 * the URL per collection and the exact request options their inline fetch calls carried.
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { fetchOpenRegisterCollection } from '../../src/modals/openRegisterGet.js'

const INLINE_OPTIONS = {
	headers: {
		accept: '*/*',
		'accept-language': 'en-US,en;q=0.9,nl;q=0.8',
		'cache-control': 'no-cache',
		pragma: 'no-cache',
		'x-requested-with': 'XMLHttpRequest',
	},
	referrerPolicy: 'no-referrer',
	body: null,
	method: 'GET',
	mode: 'cors',
	credentials: 'include',
}

describe('fetchOpenRegisterCollection', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it.each(['registers', 'schemas', 'configurations'])('GETs /index.php/apps/openregister/api/%s with the inline options', async (collection) => {
		const answer = { ok: true }
		const fetchMock = vi.fn().mockResolvedValue(answer)
		vi.stubGlobal('fetch', fetchMock)

		await expect(fetchOpenRegisterCollection(collection)).resolves.toBe(answer)

		expect(fetchMock).toHaveBeenCalledTimes(1)
		expect(fetchMock.mock.calls[0]).toStrictEqual(['/index.php/apps/openregister/api/' + collection, INLINE_OPTIONS])
	})

	it('builds fresh options per call', async () => {
		const fetchMock = vi.fn().mockResolvedValue({ ok: true })
		vi.stubGlobal('fetch', fetchMock)

		await fetchOpenRegisterCollection('schemas')
		await fetchOpenRegisterCollection('schemas')

		expect(fetchMock.mock.calls[0][1]).not.toBe(fetchMock.mock.calls[1][1])
	})
})

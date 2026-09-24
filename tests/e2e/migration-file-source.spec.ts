/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/migration-source-adapters/specs/migration-sources/spec.md
 *
 * Two scenarios in that spec reach a running instance: a stored column mapping
 * reused across deliveries, and a mapping onto a target field the schema does
 * not have, refused when it is saved. Everything else there carries
 * `@e2e exclude`, because a live incumbent system cannot be staged on CI, and
 * is covered by PHPUnit against mock-mode fixtures.
 *
 * Nothing here writes to a target. The preview is a read, and the mapping
 * check is a refusal before a save.
 */

import { expect, test } from '@playwright/test'

const API_BASE = '/index.php/apps/integriq/api/migration-sources'

const DELIVERY =
	'zaaknummer,naam,plaats\nZ-1,De Vries,Utrecht\nZ-2,Jansen,Amsterdam\n'
const SECOND_DELIVERY = 'zaaknummer,naam,plaats\nZ-3,Bakker,Veenendaal\n'

/** The mapping an administrator authors once and stores. */
const STORED_MAPPING = {
	name: 'Zaken uit het oude systeem',
	kind: 'case',
	version: 2,
	identifierColumn: 'zaaknummer',
	columns: {
		zaaknummer: 'reference',
		naam: 'requesterName',
		plaats: 'city',
	},
}

test.describe('migration sources', () => {
	// @e2e migration-sources::describe-says-what-an-adapter-can-yield
	test('every adapter says what it yields and what it keys on', async ({
		request,
	}) => {
		const resp = await request.get(API_BASE, { failOnStatusCode: false })

		expect(resp.status()).toBe(200)
		const rows = (await resp.json()).results ?? []
		const ids = rows.map((row: { id: string }) => row.id)
		expect(ids).toContain('file')
		expect(ids).toContain('redmine')

		const redmine = rows.find((row: { id: string }) => row.id === 'redmine')
		const journal = redmine.kinds.find(
			(kind: { kind: string }) => kind.kind === 'journal',
		)
		// A kind with no stable key says so before any read is attempted.
		expect(journal.stableIdentifier).toBe(false)
	})

	// @e2e migration-sources::an-administrator-maps-a-delivered-file-once-and-runs-it-twice
	test('a second delivery of the same shape is read with the stored mapping', async ({
		request,
	}) => {
		const first = await request.post(`${API_BASE}/preview`, {
			failOnStatusCode: false,
			data: {
				source: 'file',
				config: { content: DELIVERY, mapping: STORED_MAPPING },
				sampleSize: 5,
			},
		})
		expect(first.status()).toBe(200)
		const firstBody = await first.json()
		expect(firstBody.wrote).toBe(false)
		expect(firstBody.kinds[0].count).toBe(2)

		// The same mapping, unchanged, reads the next delivery. No column is
		// mapped again.
		const second = await request.post(`${API_BASE}/preview`, {
			failOnStatusCode: false,
			data: {
				source: 'file',
				config: { content: SECOND_DELIVERY, mapping: STORED_MAPPING },
				sampleSize: 5,
			},
		})
		expect(second.status()).toBe(200)
		const secondBody = await second.json()
		expect(secondBody.kinds[0].count).toBe(1)
		expect(secondBody.kinds[0].sample[0].data.requesterName).toBe('Bakker')
		expect(secondBody.kinds[0].sample[0].provenance.sourceIdentifier).toBe('Z-3')
	})

	// @e2e migration-sources::a-mapping-onto-a-field-that-does-not-exist-is-refused-at-save
	test('a mapping onto a field the schema does not have is refused, naming the field', async ({
		request,
	}) => {
		const resp = await request.post(`${API_BASE}/column-mapping/validate`, {
			failOnStatusCode: false,
			data: {
				mapping: {
					...STORED_MAPPING,
					columns: { ...STORED_MAPPING.columns, plaats: 'woonplaats' },
				},
				schemaFields: ['reference', 'requesterName', 'city'],
				requiredFields: [],
			},
		})

		expect(resp.status()).toBe(400)
		const body = await resp.json()
		expect(body.valid).toBe(false)
		expect(String(body.errors[0])).toContain('woonplaats')
	})

	// @e2e migration-sources::a-required-field-left-unmapped-stops-the-run-before-it-starts
	test('a required field left unmapped is refused before anything runs', async ({
		request,
	}) => {
		const resp = await request.post(`${API_BASE}/column-mapping/validate`, {
			failOnStatusCode: false,
			data: {
				mapping: STORED_MAPPING,
				schemaFields: ['reference', 'requesterName', 'city', 'caseType'],
				requiredFields: ['caseType'],
			},
		})

		expect(resp.status()).toBe(400)
		expect(String((await resp.json()).errors[0])).toContain('caseType')
	})

	// @e2e migration-sources::an-unknown-source-id-fails-loudly
	test('a preview of a source nothing answers to fails naming the id', async ({
		request,
	}) => {
		const resp = await request.post(`${API_BASE}/preview`, {
			failOnStatusCode: false,
			data: { source: 'pw-e2e-no-such-incumbent', config: {} },
		})

		expect(resp.status()).toBe(404)
		expect(String((await resp.json()).error)).toContain(
			'pw-e2e-no-such-incumbent',
		)
	})
})

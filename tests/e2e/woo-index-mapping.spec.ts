/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/woo-index-mapping/spec.md (REQ-WOOM-003)
 *
 * The seeded woo-index-publication mapping is an ordinary mapping an
 * administrator edits: change the officieleTitel rule, save it through the
 * object API the mapping detail page uses, and the mapping test returns the
 * new source. The rule is put back afterwards. The event path a sibling uses
 * is PHPUnit's: MappingExecutionRequestedListenerTest.
 */

import { expect, test } from '@playwright/test'

const OBJECTS = '/index.php/apps/openregister/api/objects/integriq/mapping'
const TEST = '/index.php/apps/integriq/api/mappings/test'

test.describe('woo-index mapping', () => {
	// @e2e woo-index-mapping::an-administrator-changes-where-the-official-title-comes-from
	test('an edited officieleTitel rule shows in the mapping test', async ({
		request,
	}) => {
		const found = await request.get(`${OBJECTS}/woo-index-publication`, {
			failOnStatusCode: false,
		})
		expect(found.status()).toBe(200)
		const mapping = await found.json()
		expect(mapping.callableBy).toContain('opencatalogi')
		const id = mapping.id ?? mapping.uuid ?? mapping['@self']?.id
		const original = mapping.mapping.officieleTitel

		const edited = {
			...mapping,
			mapping: { ...mapping.mapping, officieleTitel: '{{ summary }}' },
		}
		const saved = await request.put(`${OBJECTS}/${id}`, {
			data: edited,
			failOnStatusCode: false,
		})
		expect(saved.status()).toBe(200)

		try {
			const run = await request.post(TEST, {
				data: {
					mapping: edited,
					inputObject: {
						title: 'Besluit parkeerbeleid',
						summary: 'Nieuw parkeerbeleid binnenstad',
					},
				},
				failOnStatusCode: false,
			})
			expect(run.status()).toBe(200)
			expect((await run.json()).resultObject.officieleTitel).toBe(
				'Nieuw parkeerbeleid binnenstad',
			)
		} finally {
			await request.put(`${OBJECTS}/${id}`, {
				data: {
					...mapping,
					mapping: { ...mapping.mapping, officieleTitel: original },
				},
				failOnStatusCode: false,
			})
		}
	})
})

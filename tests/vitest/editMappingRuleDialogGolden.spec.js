/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the initial drafts of EditMappingRuleDialog
 * (src/dialogs/EditMappingRuleDialog.vue): data() runs for every kind,
 * property and value shape, and the snapshot pins the drafts it seeds.
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcModal: {},
	NcSelect: {},
	NcTextField: {},
}))

const dialog = (await import('@/dialogs/EditMappingRuleDialog.vue')).default

const KINDS = ['mapping', 'cast', 'unset']
const PROPERTIES = [null, undefined, '', 'name']
const VALUES = ['', '  x  ', '{{ a }}', 0, 42, false, true, null, undefined, { a: 1 }]

describe('EditMappingRuleDialog drafts golden', () => {
	it('seeds the drafts as recorded', () => {
		const rows = []
		for (const kind of KINDS) {
			for (const property of PROPERTIES) {
				for (const value of VALUES) {
					rows.push([
						kind,
						property,
						value,
						dialog.data.call({ kind, property, value }),
					])
				}
			}
		}
		expect(rows).toMatchSnapshot()
	})
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An alignment result nobody can see is a check nobody acts on, and an
 * AVG opt-out that lives only in a database is a duty nobody can show they
 * met. Both need a page, and the private key must never be on one.
 *
 * Spec coverage: openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = join(__dirname, '..', '..')

const read = (relative) => JSON.parse(readFileSync(join(root, relative), 'utf8'))

const merged = buildManifest(
	read('src/manifest.json'),
	[read('src/manifest.d/sender-identity.json')],
	read('src/menu-layout.json'),
)

const identities = merged.pages.find((page) => page.id === 'SenderIdentities')
const optOuts = merged.pages.find((page) => page.id === 'RecipientOptOuts')

describe('sender identity manifest fragment', () => {
	it('adds the identities and the opt-outs pages', () => {
		expect(identities.config.schema).toBe('sender_identity')
		// The opt-outs live in integriq's own table, so the page is the custom
		// component that reads GET /api/outbound/opt-outs, not a schema page.
		expect(optOuts.type).toBe('custom')
		expect(optOuts.component).toBe('RecipientOptOutsPage')
		expect(optOuts.config?.schema).toBeUndefined()
	})

	it('never puts key material in a column', () => {
		const keys = identities.config.columns.map((column) => column.key)

		expect(keys).not.toContain('smimePrivateKey')
		expect(keys).not.toContain('smimeCertificate')
	})

	it('shows what each identity quotes, because quoting is a disclosure', () => {
		const keys = identities.config.columns.map((column) => column.key)

		expect(keys).toContain('quotingLevel')
		expect(keys).toContain('holdWindowSeconds')
	})

	it('registers the opt-out page component', () => {
		const registry = readFileSync(join(root, 'src/registry.js'), 'utf8')

		expect(registry).toMatch(/RecipientOptOutsPage: \{ kind: 'page', component: RecipientOptOutsPage \}/)
	})

	it('keeps the three quoting levels the schema declares', () => {
		const register = read('lib/Settings/integriq_register.json')

		expect(
			register.components.schemas.sender_identity.properties.quotingLevel.enum,
		).toEqual(['none', 'last-message', 'full-history'])
	})
})

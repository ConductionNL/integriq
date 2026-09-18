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
		expect(optOuts.config.schema).toBe('recipient_opt_out')
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

	it('colours only the opt-out scopes the schema can hold', () => {
		const register = read('lib/Settings/integriq_register.json')
		const allowed = register.components.schemas.recipient_opt_out.properties.scope.enum
		const scope = optOuts.config.columns.find((column) => column.key === 'scope')

		expect(Object.keys(scope.widgetProps.colorMap).sort()).toEqual([...allowed].sort())
	})

	it('keeps the three quoting levels the schema declares', () => {
		const register = read('lib/Settings/integriq_register.json')

		expect(register.components.schemas.sender_identity.properties.quotingLevel.enum).toEqual([
			'none',
			'last-message',
			'full-history',
		])
	})
})

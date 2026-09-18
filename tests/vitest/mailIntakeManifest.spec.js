/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The mail intake page is declarative, so what can go wrong is declarative
 * too: a fragment that never merges, a page over the wrong schema, a status
 * colour for a value the schema cannot hold, or a menu entry that lands
 * outside the group it was written for.
 *
 * Spec coverage: openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = join(__dirname, '..', '..')

const read = (relative) => JSON.parse(readFileSync(join(root, relative), 'utf8'))

const merged = buildManifest(
	read('src/manifest.json'),
	[read('src/manifest.d/mail-intake.json')],
	read('src/menu-layout.json'),
)

const page = merged.pages.find((candidate) => candidate.id === 'MailMessages')

describe('mail intake manifest fragment', () => {
	it('adds the mail intake page to the merged manifest', () => {
		expect(page).toBeDefined()
		expect(page.route).toBe('/messages/mail')
		expect(page.config.register).toBe('integriq')
		expect(page.config.schema).toBe('message')
	})

	it('shows the columns an operator triages on', () => {
		const keys = page.config.columns.map((column) => column.key)

		expect(keys).toContain('receivedAt')
		expect(keys).toContain('from')
		expect(keys).toContain('subject')
		expect(keys).toContain('detectedReference')
		expect(keys).toContain('status')
	})

	it('colours only the statuses the schema can hold', () => {
		const register = read('lib/Settings/integriq_register.json')
		const allowed = register.components.schemas.message.properties.status.enum
		const status = page.config.columns.find((column) => column.key === 'status')

		expect(Object.keys(status.widgetProps.colorMap).sort()).toEqual([...allowed].sort())
	})

	it('lands in the Connections group, because a mailbox is a source', () => {
		const group = merged.menu.find((entry) => entry.id === 'ConnectionsGroup')
		const ids = group.children.map((child) => child.id)

		expect(ids).toContain('MailMessages')
	})
})

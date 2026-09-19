/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The intake inbox is the page that makes "held, not dropped" true. If the
 * fragment never merges, or the held status has no colour, or the reason
 * column is missing, a message waiting for a human waits where nobody looks.
 *
 * Spec coverage: openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = join(__dirname, '..', '..')

const read = (relative) => JSON.parse(readFileSync(join(root, relative), 'utf8'))

const merged = buildManifest(
	read('src/manifest.json'),
	[read('src/manifest.d/intake-channels.json')],
	read('src/menu-layout.json'),
)

const inbox = merged.pages.find((page) => page.id === 'IntakeMessages')
const rules = merged.pages.find((page) => page.id === 'IntakeRoutingRules')

describe('intake channels manifest fragment', () => {
	it('adds the intake inbox and the routing rules pages', () => {
		expect(inbox.config.schema).toBe('intake_message')
		expect(rules.config.schema).toBe('intake_routing_rule')
		expect(inbox.route).toBe('/messages/intake')
	})

	it('shows the reason a message is waiting', () => {
		const keys = inbox.config.columns.map((column) => column.key)

		expect(keys).toContain('reason')
		expect(keys).toContain('channelId')
		expect(keys).toContain('status')
	})

	it('colours only the statuses the schema can hold', () => {
		const register = read('lib/Settings/integriq_register.json')
		const allowed =
			register.components.schemas.intake_message.properties.status.enum
		const status = inbox.config.columns.find((column) => column.key === 'status')

		expect(Object.keys(status.widgetProps.colorMap).sort()).toEqual(
			[...allowed].sort(),
		)
		expect(status.widgetProps.colorMap.held).toBe('warning')
	})

	it('orders the rules by the order they are evaluated in', () => {
		expect(rules.config.sortKey).toBe('order')
		expect(rules.config.sortOrder).toBe('asc')
	})

	it('puts both pages in the Connections group', () => {
		const group = merged.menu.find((entry) => entry.id === 'ConnectionsGroup')
		const ids = group.children.map((child) => child.id)

		expect(ids).toContain('IntakeMessages')
		expect(ids).toContain('IntakeRoutingRules')
	})
})

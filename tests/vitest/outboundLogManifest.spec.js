/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Did the ontvangstbevestiging leave the building" is a question about a
 * screen. If the page never merges, or the body leaks into a column, or a
 * status has no colour, the log is back in a container log nobody reaches.
 *
 * Spec coverage: openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = join(__dirname, '..', '..')

const read = (relative) => JSON.parse(readFileSync(join(root, relative), 'utf8'))

const merged = buildManifest(
	read('src/manifest.json'),
	[read('src/manifest.d/outbound-message-log.json')],
	read('src/menu-layout.json'),
)

const page = merged.pages.find((candidate) => candidate.id === 'OutboundMessages')

describe('outbound message log manifest fragment', () => {
	it('adds the sent messages page over the log schema', () => {
		expect(page.config.schema).toBe('outbound_message')
		expect(page.route).toBe('/messages/outbound')
		expect(page.config.sortKey).toBe('createdAt')
	})

	it('never puts the body in a column, because a cell is not a permission', () => {
		const keys = page.config.columns.map((column) => column.key)

		expect(keys).not.toContain('body')
		expect(keys).not.toContain('recipients')
		expect(keys).toContain('subjectRef')
		expect(keys).toContain('retryCount')
	})

	it('colours only the statuses the schema can hold', () => {
		const register = read('lib/Settings/integriq_register.json')
		const allowed = register.components.schemas.outbound_message.properties.status.enum
		const status = page.config.columns.find((column) => column.key === 'status')

		expect(Object.keys(status.widgetProps.colorMap).sort()).toEqual([...allowed].sort())
		expect(status.widgetProps.colorMap['partially failed']).toBe('warning')
	})

	it('keeps the three recipient states the schema declares', () => {
		const register = read('lib/Settings/integriq_register.json')
		const recipient = register.components.schemas.outbound_message.properties.recipients.items

		expect(recipient.properties.deliveryState.enum).toEqual([
			'reported',
			'not reported',
			'unsupported by this channel',
		])
		expect(recipient.properties.readState.enum).toEqual(recipient.properties.deliveryState.enum)
	})

	it('lands in Operations, next to the other run logs', () => {
		const group = merged.menu.find((entry) => entry.id === 'OperationsGroup')

		expect(group.children.map((child) => child.id)).toContain('OutboundMessages')
	})
})

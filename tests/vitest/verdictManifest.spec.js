/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Integriq stores what an outside checker said and acts on it never, so the
 * only way a verdict does any good is by being visible. Pending is
 * deliberately not green: a checker that has not finished has not passed.
 *
 * Spec coverage: openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = join(__dirname, '..', '..')

const read = (relative) => JSON.parse(readFileSync(join(root, relative), 'utf8'))

const register = read('lib/Settings/integriq_register.json')
const merged = buildManifest(
	read('src/manifest.json'),
	[read('src/manifest.d/outbound-call-log.json')],
	read('src/menu-layout.json'),
)

const page = merged.pages.find((candidate) => candidate.id === 'Verdicts')

describe('outbound call log manifest fragment', () => {
	it('adds a page for the verdicts', () => {
		expect(page.config.schema).toBe('verdict')
		expect(page.route).toBe('/verdicts')
	})

	it('shows the checker and its reason, not only the state', () => {
		const keys = page.config.columns.map((column) => column.key)

		expect(keys).toContain('source')
		expect(keys).toContain('reason')
		expect(keys).toContain('objectRef')
	})

	it('colours only the states the schema can hold, and pending is not green', () => {
		const allowed = register.components.schemas.verdict.properties.state.enum
		const state = page.config.columns.find((column) => column.key === 'state')

		expect(Object.keys(state.widgetProps.colorMap).sort()).toEqual([...allowed].sort())
		expect(state.widgetProps.colorMap.pending).not.toBe('success')
	})

	it('keeps the four call kinds a record can carry', () => {
		expect(register.components.schemas.call_log.properties.kind.enum).toEqual([
			'triggered',
			'replayed',
			'hand-fired',
			'dry-run',
		])
	})

	it('adds the call fields a replay needs, without touching the existing ones', () => {
		const properties = register.components.schemas.call_log.properties

		expect(properties.attempts).toBeDefined()
		expect(properties.retryPolicy).toBeDefined()
		expect(properties.mappingVersion).toBeDefined()
		expect(properties.statusCode, 'the fields the log pages already read stay').toBeDefined()
		expect(properties.request).toBeDefined()
	})
})

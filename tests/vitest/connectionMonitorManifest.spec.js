/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The connection monitor (board integriq/IqBrpMonitor): one page per source,
 * every widget reading the monitor endpoint for the source in the route, and
 * the BRP monitor a menu entry that names its source as configuration.
 *
 * Spec coverage: openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = join(__dirname, '..', '..')
const read = (relative) => JSON.parse(readFileSync(join(root, relative), 'utf8'))

const merged = buildManifest(
	read('src/manifest.json'),
	[read('src/manifest.d/connection-monitor.json')],
	read('src/menu-layout.json'),
)
const page = merged.pages.find((p) => p.id === 'ConnectionMonitor')
const nl = read('l10n/nl.json').translations

describe('connection monitor manifest fragment', () => {
	it('is an admin dashboard per source', () => {
		expect(page.type).toBe('dashboard')
		expect(page.route).toBe('/connection-monitor/:id')
		expect(page.permission).toBe('admin')
	})

	it('reads every widget from the monitor endpoint for the source in the route', () => {
		for (const widget of page.config.widgets) {
			expect(widget.content.endpointSource).toMatchObject({
				url: '/apps/integriq/api/connection-monitor',
				params: { source: '@route.id' },
			})
		}
		expect(page.config.kpiRow).toEqual([
			'monitor-calls',
			'monitor-slowest',
			'monitor-errors',
			'monitor-average',
		])
	})

	it('makes the BRP monitor a menu entry naming its source', () => {
		const entry = merged.menu.find((m) => m.id === 'BrpMonitor')
		expect(entry).toMatchObject({
			route: 'ConnectionMonitor',
			params: { id: 'brp-haalcentraal' },
			permission: 'admin',
		})
	})

	it('translates what the page shows into Dutch', () => {
		const strings = [
			page.title,
			page.config.description,
			entryLabel(),
			...page.config.headerActions.map((a) => a.label),
		]
		for (const widget of page.config.widgets) {
			strings.push(
				widget.title,
				widget.content.label,
				widget.content.emptyText,
				widget.content.link?.label,
			)
			for (const field of widget.content.fields || []) {
				strings.push(field.label)
			}
		}
		for (const s of strings.filter((x) => typeof x === 'string')) {
			expect([s, typeof nl[s]]).toEqual([s, 'string'])
		}
	})
})

/**
 * @return {string} The BRP monitor's menu label.
 */
function entryLabel() {
	return merged.menu.find((m) => m.id === 'BrpMonitor').label
}

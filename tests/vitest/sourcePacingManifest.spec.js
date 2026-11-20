/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The source page shows its pacing (sources-outbound-rate-limit-pacing,
 * task 3): the budget the source announced and the time calls waited for a
 * paced turn over the last day, summed from `call_log.pacedMs` for this
 * source, with the configured pace as a source field. The labels are
 * translated, and the aggregation the widget reads is declared on the
 * call log.
 *
 * @spec openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-calls-to-a-source-are-spaced-to-stay-under-its-limit-req-rlpc-001
 */

import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = join(__dirname, '..', '..')
const read = (relative) => JSON.parse(readFileSync(join(root, relative), 'utf8'))

const page = read('src/manifest.json').pages.find((p) => p.id === 'SourceDetail')
const widget = page.config.widgets.find((w) => w.id === 'src-kpis-pacing')
const fragment = read('lib/Settings/register.d/sources-outbound-rate-limit-pacing.json').components.schemas
const nl = read('l10n/nl.json').translations

describe('source page pacing', () => {
	it('sums the waiting time of this source over the last day', () => {
		const waited = widget.content.entries.find((e) => e.field === 'pacedMs')
		expect(waited).toMatchObject({
			register: 'integriq',
			schema: 'call_log',
			metric: 'sum',
			filter: { source: '@objectId', created: { gte: '@today-1d' } },
		})
	})

	it('shows the remaining budget the source announced', () => {
		const budget = widget.content.entries.find((e) => e.field === 'rateLimitRemaining')
		expect(budget).toMatchObject({ schema: 'source', metric: 'sum', filter: { id: '@objectId' } })
	})

	it('places the widget on the page grid without overlapping its neighbours', () => {
		const cells = page.config.layout
		const pacing = cells.find((c) => c.widgetId === 'src-kpis-pacing')
		expect(pacing).toBeDefined()
		const overlaps = (a, b) => a.gridX < b.gridX + b.gridWidth && b.gridX < a.gridX + a.gridWidth
			&& a.gridY < b.gridY + b.gridHeight && b.gridY < a.gridY + a.gridHeight
		for (const other of cells.filter((c) => c !== pacing)) {
			expect(overlaps(pacing, other), other.widgetId).toBe(false)
		}
		expect(new Set(cells.map((c) => c.id)).size).toBe(cells.length)
	})

	it('reads an aggregation the call log declares, and the pace is a source field', () => {
		expect(fragment.call_log['x-openregister-aggregations'].pacedMsPerSource).toEqual({
			metric: 'sum',
			field: 'pacedMs',
			groupBy: ['source'],
		})
		expect(Object.keys(fragment.source.properties.pace.properties)).toEqual(
			expect.arrayContaining(['maxCalls', 'perSeconds', 'burst']),
		)
	})

	it('has Dutch labels for the widget and the pace fields', () => {
		for (const label of [widget.title, ...widget.content.entries.map((e) => e.title), 'Pace', 'Paced (ms)']) {
			expect(nl[label], label).toBeTruthy()
			expect(nl[label]).not.toBe(label)
		}
	})
})

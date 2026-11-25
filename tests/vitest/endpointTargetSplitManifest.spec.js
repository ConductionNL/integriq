/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The endpoint page's "Calls per target" chart. On a detail page the widget
 * host hands a chart its `content` (CnDetailWidgetHost.rendererProps, with
 * `content.chartKind` read as the chart's type); a top-level `props` or
 * `dataSource`, the dashboard shape, never reaches it and the chart draws an
 * empty axis. Found on a live run of tests/e2e/endpoint-targets.spec.ts.
 *
 * Spec coverage: openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md (REQ-UPRT-003)
 */

import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(join(__dirname, '..', '..', 'src', 'manifest.json'), 'utf8'))
const widget = manifest.pages
	.filter((page) => page.type === 'detail')
	.flatMap((page) => page.config?.widgets ?? [])
	.find((w) => w.id === 'ep-target-split')

describe('the endpoint page chart of calls per target', () => {
	it('carries its kind and data source in content, where the detail host reads them', () => {
		expect(widget, 'the widget is on a detail page').toBeDefined()
		expect(widget.content.chartKind).toBe('donut')
		expect(widget.content.dataSource.filter).toEqual({ endpoint: '@objectId' })
		expect(widget.content.dataSource.aggregate.groupBy).toBe('routedTarget')
		expect(widget.content.dataSource.aggregate.labelResolve).toMatchObject({ schema: 'source', labelField: 'name' })
	})

	it('has nothing left in the dashboard shape that the detail host would drop', () => {
		expect(widget.props).toBeUndefined()
		expect(widget.dataSource).toBeUndefined()
	})
})

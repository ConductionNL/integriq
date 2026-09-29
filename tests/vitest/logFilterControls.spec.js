/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A filter control is only worth declaring when it filters on a property the
 * log row really carries, with the same key a "View logs" row action sends.
 * A control on a missing property renders an empty page; a picker whose key
 * differs from the row action's shows an empty picker on a filtered list.
 * These rules read the manifest, the row-action table and the merged register
 * schemas, so a rename on any side fails here rather than on a live page.
 *
 * @spec openspec/changes/observability-log-filters/specs/app-shell-and-logs-ui/spec.md#requirement-every-log-page-declares-its-filter-controls-req-logf-001
 * @spec openspec/changes/observability-log-filters/specs/app-shell-and-logs-ui/spec.md#requirement-source-and-endpoint-logs-show-only-their-own-direction-req-logf-003
 */

import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { VIEW_LOGS_TARGETS } from '../../src/handlers/logTargets.js'

const root = join(__dirname, '..', '..')
const read = (relative) => JSON.parse(readFileSync(join(root, relative), 'utf8'))

const manifest = read('src/manifest.json')

/** Every property a schema slug carries across the main register and its fragments. */
function propertiesOf(slug) {
	const files = ['lib/Settings/integriq_register.json']
		.concat(readdirSync(join(root, 'lib/Settings/register.d'))
			.filter((name) => name.endsWith('.json'))
			.map((name) => 'lib/Settings/register.d/' + name))
	const properties = {}
	for (const file of files) {
		const schema = read(file)?.components?.schemas?.[slug]
		Object.assign(properties, schema?.properties ?? {})
	}
	return properties
}

const LOG_PAGES = ['SourceLogs', 'EndpointLogs', 'JobLogs', 'SynchronizationLogs', 'CloudEventLogs', 'Traces']
const CALL_LOG_PAGES = ['SourceLogs', 'EndpointLogs', 'CloudEventLogs']
const TYPES = ['select', 'reference', 'dateRange']

const page = (id) => manifest.pages.find((candidate) => candidate.id === id)
const controls = (id) => page(id)?.config?.filterControls ?? []
const control = (id, key) => controls(id).find((candidate) => candidate.key === key)

describe('the log pages declare their filter controls', () => {
	it.each(LOG_PAGES)('%s declares filter controls with a known type and a label', (id) => {
		expect(controls(id).length).toBeGreaterThan(0)
		for (const entry of controls(id)) {
			expect(TYPES).toContain(entry.type)
			expect(typeof entry.label).toBe('string')
		}
	})

	it.each(LOG_PAGES)('every control on %s filters a property the log row has', (id) => {
		const properties = propertiesOf(page(id).config.schema)
		for (const entry of controls(id)) {
			expect(Object.keys(properties), `${id}.${entry.key}`).toContain(entry.key)
		}
	})

	it.each(LOG_PAGES)('%s has a date range over the column it sorts on', (id) => {
		const range = controls(id).find((entry) => entry.type === 'dateRange')
		expect(range?.key).toBe(page(id).config.sortKey)
	})

	it.each(CALL_LOG_PAGES)('%s offers a direction control', (id) => {
		const direction = control(id, 'direction')
		expect(direction?.type).toBe('select')
		expect(direction.options.map((option) => option.filter.direction).sort()).toEqual(['inbound', 'outbound'])
	})

	it.each(CALL_LOG_PAGES)('%s offers success, client error and server error as status code ranges', (id) => {
		const status = controls(id).find((entry) => entry.key === 'statusCode')
		expect(status?.type).toBe('select')
		expect(status.options.map((option) => option.filter.statusCode)).toEqual([
			{ gte: 200, lt: 400 },
			{ gte: 400, lt: 500 },
			{ gte: 500 },
		])
	})

	it('offers the trace status and entry point as their schema enums', () => {
		const properties = propertiesOf('execution_trace')
		for (const key of ['status', 'entryPoint']) {
			const values = control('Traces', key).options.map((option) => option.filter[key])
			expect(values).toEqual(properties[key].enum)
		}
	})

	it('offers the job log levels JobService writes', () => {
		expect(control('JobLogs', 'level').options.map((option) => option.filter.level)).toEqual(['SUCCESS', 'INFO', 'WARNING', 'ERROR'])
	})
})

describe('a row action and a picker agree', () => {
	it.each(Object.entries(VIEW_LOGS_TARGETS).filter(([, target]) => target.queryParam !== null))(
		'%s lands on a page whose picker uses the same key',
		(action, target) => {
			const picker = control(target.route, target.queryParam)
			expect(picker?.type, `${target.route} has no ${target.queryParam} picker`).toBe('reference')
			expect(picker.optionsFrom.register).toBe('integriq')
		},
	)
})

describe('endpoint logs show only their own direction', () => {
	it('scopes the endpoint logs to inbound calls through the page filter', () => {
		expect(page('EndpointLogs').config.filter).toEqual({ direction: 'inbound' })
	})
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The flow pages use the canonical form: an index over the `flows` source whose
 * rows open a page of type `flow`, on the addresses links already point at.
 *
 * @spec exclude Structural invariant of the shipped manifest; no behavioural spec.
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

const { pages } = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const byId = new Map(pages.map((page) => [page.id, page]))
const router = createRouter({
	history: createMemoryHistory(),
	routes: pages.map((page) => ({ name: page.id, path: page.route, component: {} })),
})

describe('flow pages', () => {
	it('names no deprecated flow page type', () => {
		expect(pages.filter((page) => ['flows', 'flow-detail'].includes(page.type)).map((page) => page.id)).toEqual([])
	})

	it('Flows is an index over the flows source, scoped to this app', () => {
		const { type, config } = byId.get('Flows')
		expect(type).toBe('index')
		expect(config.entitySource).toBe('flows')
		expect(config.app).toBe('openconnector')
	})

	it('Flows opens its rows on a flow page', () => {
		expect(byId.get(byId.get('Flows').config.rowRoute).type).toBe('flow')
	})

	it('keeps the flow addresses on the flow pages', () => {
		expect(router.resolve('/flows').name).toBe('Flows')
		expect(router.resolve('/flows/new').name).toBe('FlowDetail')
		expect(router.resolve('/flows/abc').name).toBe('FlowDetail')
	})
})

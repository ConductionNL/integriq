/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The registration targets page asks an administrator the settings of the
 * chosen kind only, and keeps the sources and settings out of the table.
 *
 * Spec coverage: openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = join(__dirname, '..', '..')

const read = (relative) => JSON.parse(readFileSync(join(root, relative), 'utf8'))

const merged = buildManifest(
	read('src/manifest.json'),
	[read('src/manifest.d/submission-registration-targets.json')],
	read('src/menu-layout.json'),
)

const page = merged.pages.find((candidate) => candidate.id === 'RegistrationTargets')

const schema = read('lib/Settings/register.d/registration-target.json').components.schemas.registrationTarget

describe('registration targets manifest fragment', () => {
	it('lists the registration targets of the integriq register', () => {
		expect(page.type).toBe('index')
		expect(page.route).toBe('/registration-targets')
		expect(page.config.register).toBe('integriq')
		expect(page.config.schema).toBe(schema.slug)
	})

	it('never puts the sources or the kind settings in a column', () => {
		for (const hidden of ['sources', 'mapping', 'zgw', 'objectsApi', 'stufZds', 'json']) {
			expect(page.config.columns).not.toContain(hidden)
		}
		expect(page.config.columns).toEqual(expect.arrayContaining(['title', 'slug', 'kind']))
	})

	it('shows each kind its own settings, and only those', () => {
		const settingsByKind = { zgw: 'zgw', 'objects-api': 'objectsApi', 'stuf-zds': 'stufZds', json: 'json' }

		expect(Object.keys(settingsByKind).sort()).toEqual([...schema.properties.kind.enum].sort())
		for (const [kind, field] of Object.entries(settingsByKind)) {
			expect(page.config.fieldOverrides[field].visibleWhen).toEqual({ field: 'kind', equals: kind })
		}
		for (const shared of ['title', 'slug', 'kind', 'sources', 'mapping', 'allowedApps', 'enabled']) {
			expect(page.config.fieldOverrides[shared].visibleWhen).toBeUndefined()
		}
	})

	it('asks only for properties the schema has', () => {
		for (const field of page.config.includeFields) {
			expect(Object.keys(schema.properties)).toContain(field)
		}
	})

	it('offers the dry run as a Test row action with a registered handler', () => {
		const handlers = readFileSync(join(root, 'src/handlers/actionHandlers.js'), 'utf8')
		const registry = readFileSync(join(root, 'src/registry.js'), 'utf8')
		const action = page.config.actions.find((candidate) => candidate.id === 'test')

		expect(action.handler).toBe('testRegistrationTargetHandler')
		expect(handlers).toContain(`export function ${action.handler}(`)
		expect(registry).toMatch(new RegExp(`^\\t${action.handler},$`, 'm'))
	})

	it('lands in Connections for administrators only', () => {
		const group = merged.menu.find((entry) => entry.id === 'ConnectionsGroup')
		const entry = group.children.find((child) => child.id === 'RegistrationTargets')

		expect(entry.permission).toBe('admin')
		expect(entry.route).toBe('RegistrationTargets')
	})
})

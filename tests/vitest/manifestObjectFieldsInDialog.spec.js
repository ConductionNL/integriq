// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An object property only reaches the generic add and edit dialog when a
 * field names a widget for it: `fieldsFromSchema` leaves out every
 * `type: object` property that has none. These tests run the library's own
 * field pipeline over the page config and the register schema, so a page
 * that declares an object field the dialog cannot show fails here rather
 * than on a live instance.
 *
 * Spec coverage:
 * openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md (REQ-SREG-001)
 * openspec/changes/archive/2026-11-24-automation-integration-regression-tests/specs/integration-regression-tests/spec.md (REQ-IRT-001)
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { fieldsFromSchema } from '@conduction/nextcloud-vue/src/utils/schema.js'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = join(__dirname, '..', '..')

const read = (relative) => JSON.parse(readFileSync(join(root, relative), 'utf8'))

function pageOf(fragment, id) {
	const merged = buildManifest(read('src/manifest.json'), [read(fragment)], read('src/menu-layout.json'))
	return merged.pages.find((candidate) => candidate.id === id)
}

function dialogFields(page, schema) {
	return fieldsFromSchema(schema, {
		include: page.config.includeFields ?? null,
		overrides: page.config.fieldOverrides ?? {},
	})
}

describe('registration target dialog', () => {
	const page = pageOf('src/manifest.d/submission-registration-targets.json', 'RegistrationTargets')
	const schema = read('lib/Settings/register.d/registration-target.json').components.schemas.registrationTarget
	const fields = dialogFields(page, schema)

	it('asks every field the page includes', () => {
		expect(fields.map((field) => field.key).sort()).toEqual([...page.config.includeFields].sort())
	})

	it('edits the sources and each kind\'s settings as JSON', () => {
		for (const key of ['sources', 'zgw', 'objectsApi', 'stufZds', 'json']) {
			expect(schema.properties[key].type).toBe('object')
			expect(fields.find((field) => field.key === key).widget).toBe('json')
		}
	})

	it('still shows each kind\'s settings only for that kind', () => {
		expect(fields.find((field) => field.key === 'stufZds').visibleWhen).toEqual({ field: 'kind', equals: 'stuf-zds' })
	})
})

describe('test case dialog', () => {
	const page = pageOf('src/manifest.d/integration-regression-tests.json', 'TestCases')
	const schema = read('lib/Settings/register.d/integration-regression-tests.json').components.schemas.integration_test_case
	const fields = dialogFields(page, schema)

	it('asks the input and the expected output, as JSON', () => {
		for (const key of ['input', 'expectedOutput']) {
			expect(schema.properties[key].type).toBe('object')
			expect(fields.find((field) => field.key === key)?.widget).toBe('json')
		}
	})

	it('asks every other property of a case', () => {
		const keys = fields.map((field) => field.key)
		for (const key of ['name', 'description', 'subjectType', 'subjectId', 'ignoredPaths']) {
			expect(keys).toContain(key)
		}
	})
})

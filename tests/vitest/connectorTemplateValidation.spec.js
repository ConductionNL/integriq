/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The connector template validator against good and bad libraries
 * (connectors-catalogue-expansion task 2, REQ-CCX-002 and REQ-CCX-003).
 *
 * @spec openspec/specs/connector-catalog/spec.md#requirement-a-back-office-template-names-its-standard-and-where-it-was-checked-req-ccx-002
 */
import fs from 'node:fs'
import { createRequire } from 'node:module'
import os from 'node:os'
import path from 'node:path'
import { afterEach, describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)
const { validateLibrary } = require('../validate-connector-templates.js')

/**
 * A sound curated template.
 *
 * @param {string} slug The template slug.
 * @param {object} extra Keys to add to or replace in the x-template block.
 * @return {object} The template.
 */
function good(slug, extra = {}) {
	return {
		'x-template': {
			slug,
			vendor: 'Hyland (Alfresco)',
			system: 'Alfresco Content Services',
			standard: 'CMIS 1.1 browser binding',
			verifiedAgainst:
				'https://docs.alfresco.com/content-services/7.1/develop/reference/cmis-ref/',
			tier: 'curated',
			...extra,
		},
		source: {
			name: 'Alfresco',
			type: 'api',
			location: 'https://alfresco.example.nl/cmis',
			auth: 'basic',
			isEnabled: false,
		},
	}
}

let dirs = []

/**
 * Write a library to a temporary directory.
 *
 * @param {object} files Relative path to template.
 * @return {string} The library directory.
 */
function library(files) {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'templates-'))
	dirs.push(dir)
	for (const [file, data] of Object.entries(files)) {
		fs.mkdirSync(path.join(dir, path.dirname(file)), { recursive: true })
		fs.writeFileSync(path.join(dir, file), JSON.stringify(data))
	}
	return dir
}

describe('the connector template validator', () => {
	afterEach(() => {
		for (const dir of dirs) {
			fs.rmSync(dir, { recursive: true, force: true })
		}
		dirs = []
	})

	it('accepts a curated template that cites where it was checked', () => {
		expect(
			validateLibrary(
				library({ 'backoffice/alfresco-cmis.json': good('alfresco-cmis') }),
			),
		).toEqual([])
	})

	it('refuses a curated template without verifiedAgainst, naming the file', () => {
		const template = good('alfresco-cmis')
		delete template['x-template'].verifiedAgainst

		const problems = validateLibrary(
			library({ 'backoffice/alfresco-cmis.json': template }),
		)

		expect(problems).toHaveLength(1)
		expect(problems[0]).toContain('backoffice/alfresco-cmis.json')
		expect(problems[0]).toContain('verifiedAgainst')
	})

	it('refuses a generated template with a secret-shaped field', () => {
		const template = good('slack', {
			tier: 'generated',
			snapshotDate: '2026-09-29',
		})
		template.source.configuration = {
			authentication: {
				tokenUrl: 'https://slack.com/api/oauth.access',
				client_secret: '',
			},
		}

		const problems = validateLibrary(library({ 'saas/slack.json': template }))

		expect(problems).toEqual([
			expect.stringContaining(
				'source.configuration.authentication.client_secret looks like a credential',
			),
		])
	})

	it('refuses a generated template without its snapshot date', () => {
		const problems = validateLibrary(
			library({ 'saas/slack.json': good('slack', { tier: 'generated' }) }),
		)

		expect(problems).toEqual([expect.stringContaining('snapshotDate')])
	})

	it('refuses an enabled template and a duplicate slug', () => {
		const enabled = good('alfresco-cmis')
		enabled.source.isEnabled = true
		const twin = good('alfresco-cmis')

		const problems = validateLibrary(
			library({
				'backoffice/alfresco-cmis.json': enabled,
				'saas/alfresco-cmis.json': twin,
			}),
		)

		expect(
			problems.some((problem) => problem.includes('isEnabled must be false')),
		).toBe(true)
		expect(problems.some((problem) => problem.includes('is also used by'))).toBe(
			true,
		)
	})

	it('skips the allow-list, which is not a template', () => {
		expect(
			validateLibrary(library({ 'saas/allow-list.json': { entries: [] } })),
		).toEqual([])
	})

	it('finds the shipped library sound', () => {
		expect(
			validateLibrary(
				path.resolve(__dirname, '../../lib/Settings/connector-templates'),
			),
		).toEqual([])
	})
})

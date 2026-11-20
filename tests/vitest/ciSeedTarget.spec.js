/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ciSeedTarget: tests/e2e/ci-seed.sh sends every request to the one target it
 * resolves at the top, so a run that sets only PLAYWRIGHT_BASE_URL never
 * writes to the shared dev container on localhost:8080.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const script = readFileSync(resolve(__dirname, '../e2e/ci-seed.sh'), 'utf8')
const code = script.split('\n').filter((line) => !line.trimStart().startsWith('#'))

describe('ci-seed.sh target', () => {
	it('builds every Nextcloud URL on the resolved BASE', () => {
		const urls = code.join('\n').match(/"\$\{?[A-Za-z_]+\}?\/index\.php[^"]*"/g) ?? []
		expect(urls.length).toBeGreaterThan(0)
		for (const url of urls) {
			expect(url).toMatch(/^"\$\{BASE\}\//)
		}
	})

	it('names localhost:8080 only as the CI fallback', () => {
		const assignments = code.filter((line) => line.includes('http://localhost:8080') && !line.includes('echo'))
		expect(assignments).toEqual(['\t\tBASE="http://localhost:8080"'])
	})
})

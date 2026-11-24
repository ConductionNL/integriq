/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The generated browser catalogue for the source locale (en) carries only the
 * entries that differ from their key; every other locale, and l10n/en.json
 * itself, stays complete.
 *
 * @spec PERF-HANDOFF.md (all apps: drop identity entries from the English catalogue at build time)
 */

import fs from 'fs'
import { createRequire } from 'module'
import path from 'path'
import { describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)
const {
	browserEntries,
	renderJs,
	SOURCE_LOCALE,
} = require('../../scripts/build-l10n-js.js')

const ROOT = path.resolve(__dirname, '..', '..')

describe('browserEntries', () => {
	it('drops entries equal to their key for the source locale', () => {
		const out = browserEntries(SOURCE_LOCALE, {
			Save: 'Save',
			'Import failed:': 'Import failed: ',
			'reload now': 'Reload now',
		})
		expect(out).toEqual({
			'Import failed:': 'Import failed: ',
			'reload now': 'Reload now',
		})
	})

	it('keeps plural entries, which are arrays and never equal their key', () => {
		const plural = { '_%n card_::_%n cards_': ['%n card', '%n cards'] }
		expect(browserEntries(SOURCE_LOCALE, plural)).toEqual(plural)
	})

	it('keeps every entry for any other locale, identity ones included', () => {
		const nl = { Save: 'Opslaan', OK: 'OK' }
		expect(browserEntries('nl', nl)).toEqual(nl)
	})

	it('returns an empty catalogue when every source entry is an identity', () => {
		expect(browserEntries(SOURCE_LOCALE, { A: 'A', B: 'B' })).toEqual({})
		expect(renderJs('integriq', {}, 'nplurals=2; plural=(n != 1);')).toContain(
			'OC.L10N.register(',
		)
	})
})

describe('committed catalogues', () => {
	it('l10n/en.js holds exactly the non-identity entries of l10n/en.json', () => {
		const json = JSON.parse(
			fs.readFileSync(path.join(ROOT, 'l10n', 'en.json'), 'utf8'),
		)
		const js = fs.readFileSync(path.join(ROOT, 'l10n', 'en.js'), 'utf8')
		const body = js.match(
			/OC\.L10N\.register\(\s*"[^"]+"\s*,\s*(\{.*\})\s*,\s*"nplurals/s,
		)
		expect(body).not.toBeNull()
		const served = JSON.parse(body[1])
		const expected = Object.fromEntries(
			Object.entries(json.translations).filter(
				([key, value]) => key !== value,
			),
		)
		expect(served).toEqual(expected)
		expect(Object.keys(json.translations).length).toBeGreaterThan(
			Object.keys(served).length,
		)
	})
})

describe('loadSourceCatalogue', () => {
	const { loadSourceCatalogue } = require('../../scripts/lib/l10n.js')

	/**
	 * Write an en.js / en.json pair into a fresh temp dir.
	 *
	 * @param {object|null} js - the en.js translations
	 * @param {object|null} json - the en.json translations, or null for none
	 * @return {string} the en.js path
	 */
	function pair(js, json) {
		const dir = fs.mkdtempSync(path.join(require('os').tmpdir(), 'l10n-src-'))
		fs.writeFileSync(
			path.join(dir, 'en.js'),
			renderJs('integriq', js, 'nplurals=2; plural=(n != 1);'),
		)
		if (json !== null) {
			fs.writeFileSync(
				path.join(dir, 'en.json'),
				JSON.stringify({ translations: json }),
			)
		}
		return path.join(dir, 'en.js')
	}

	it('completes en.js with the identity entries en.json holds', () => {
		const file = pair(
			{ 'reload now': 'Reload now' },
			{ Save: 'Save', 'reload now': 'Reload now' },
		)
		const out = loadSourceCatalogue(file)
		expect(out.app).toBe('integriq')
		expect(out.translations).toEqual({
			'reload now': 'Reload now',
			Save: 'Save',
		})
	})

	it('keeps the en.js value when both files carry the key', () => {
		const file = pair({ Save: 'Save it' }, { Save: 'Save' })
		expect(loadSourceCatalogue(file).translations).toEqual({ Save: 'Save it' })
	})

	it('reads en.js alone when there is no en.json beside it', () => {
		const file = pair({ Save: 'Save' }, null)
		expect(loadSourceCatalogue(file).translations).toEqual({ Save: 'Save' })
	})

	it('covers every key of the committed l10n/en.json', () => {
		const json = JSON.parse(
			fs.readFileSync(path.join(ROOT, 'l10n', 'en.json'), 'utf8'),
		)
		const out = loadSourceCatalogue(path.join(ROOT, 'l10n', 'en.js'))
		expect(Object.keys(out.translations).sort()).toEqual(
			Object.keys(json.translations).sort(),
		)
	})
})

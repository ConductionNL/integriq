/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A JSON golden file for characterisation specs. With GOLDEN_RECORD=1 every
 * case result is written to the file; otherwise each result is compared with
 * the stored one, and a stored case that no test checked fails the spec.
 *
 * Values are stored as plain JSON: `undefined` becomes "__undefined__", NaN
 * becomes "__NaN__" and a function becomes its name. A value holding an empty
 * or whitespace-only object key is stored as JSON text under "$json", because
 * the fixture lint rejects such keys. A recorded file is formatted with
 * `npx prettier --write` before it is committed.
 */
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { expect } from 'vitest'

const RECORDING = process.env.GOLDEN_RECORD === '1'

/**
 * One value made JSON-safe with its undefined, NaN and function parts named.
 *
 * @param {unknown} value The value.
 * @return {string} The JSON text.
 */
function jsonText(value) {
	return JSON.stringify({ value }, (key, item) => {
		if (item === undefined) {
			return '__undefined__'
		}
		if (typeof item === 'number' && Number.isNaN(item)) {
			return '__NaN__'
		}
		return typeof item === 'function' ? `[function ${item.name}]` : item
	})
}

/**
 * The stored form of one case result.
 *
 * @param {unknown} value The result.
 * @return {unknown} The stored form.
 */
function stored(value) {
	const text = jsonText(value)
	if (/"\s*":/.test(text)) {
		return { $json: text }
	}
	return JSON.parse(text).value
}

/**
 * Open a golden file.
 *
 * @param {URL} url Where the JSON file lives.
 * @return {{check: Function, save: Function}} The recorder.
 */
export function goldenFile(url) {
	const recorded = {}
	const expected =
		RECORDING || !existsSync(url) ? {} : JSON.parse(readFileSync(url, 'utf8'))
	return {
		check(name, value) {
			recorded[name] = stored(value)
			if (!RECORDING) {
				expect(Object.hasOwn(expected, name), `golden case ${name}`).toBe(
					true,
				)
				expect(recorded[name], name).toEqual(expected[name])
			}
		},
		save() {
			if (RECORDING) {
				writeFileSync(url, JSON.stringify(recorded, null, '\t') + '\n')
				return
			}
			expect(Object.keys(recorded).sort()).toEqual(
				Object.keys(expected).sort(),
			)
		},
	}
}

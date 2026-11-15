/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure helpers for regression test cases: which trace steps a case can be
 * recorded from, which fields of a snapshot still carry the redaction
 * marker, and replacing one of them before the case is saved.
 *
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
 */

/** The marker the trace writes in place of a sensitive value. */
export const REDACTED = '***REDACTED***'

/** The step types a case can be recorded from. */
export const RECORDABLE_STEP_TYPES = ['mapping', 'synchronization']

/**
 * The steps of a trace a case can be recorded from.
 *
 * @param {Array<object>} steps The trace's steps.
 * @return {Array<object>} The mapping and synchronization steps, in order.
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
 */
export function recordableSteps(steps) {
	if (!Array.isArray(steps)) return []
	return steps.filter((step) => step && RECORDABLE_STEP_TYPES.includes(step.type))
}

/**
 * The dot paths of every value that is the redaction marker.
 *
 * @param {object|Array|string|number|boolean|null} data The snapshot.
 * @param {string} prefix The path of `data` itself; empty at the top.
 * @return {Array<string>} The paths, in document order.
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
 */
export function redactedPaths(data, prefix = '') {
	if (data === null || typeof data !== 'object') return []
	const paths = []
	for (const [key, value] of Object.entries(data)) {
		const path = prefix === '' ? key : `${prefix}.${key}`
		if (value !== null && typeof value === 'object') {
			paths.push(...redactedPaths(value, path))
		} else if (value === REDACTED) {
			paths.push(path)
		}
	}
	return paths
}

/**
 * A deep copy of `data` with the value at `path` replaced.
 *
 * @param {object} data The snapshot; left unchanged.
 * @param {string} path A dot path that exists in the snapshot.
 * @param {string|number|boolean|null} value The new value.
 * @return {object} The copy.
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
 */
export function withValueAt(data, path, value) {
	const copy = JSON.parse(JSON.stringify(data ?? {}))
	const keys = path.split('.')
	let node = copy
	for (const key of keys.slice(0, -1)) {
		if (node === null || typeof node !== 'object' || !(key in node)) return copy
		node = node[key]
	}
	const last = keys[keys.length - 1]
	if (node !== null && typeof node === 'object' && last in node) {
		node[last] = value
	}
	return copy
}

/**
 * Split a comma- or newline-separated list of ignored paths.
 *
 * @param {string} text What the engineer typed.
 * @return {Array<string>} The trimmed, non-empty paths.
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-stores-an-input-an-expected-output-and-its-subject-req-irt-001
 */
export function parseIgnoredPaths(text) {
	return String(text ?? '')
		.split(/[,\n]/)
		.map((path) => path.trim())
		.filter((path) => path !== '')
}

/**
 * The id the cases of an object name it by: its slug when it has one, else its id.
 *
 * @param {object} object The mapping or synchronization.
 * @param {string} fallback The page's id.
 * @return {string} The subject id.
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-suite-run-replays-every-case-without-calling-the-source-or-writing-req-irt-003
 */
export function subjectIdOf(object, fallback) {
	return String(object?.slug || object?.['@self']?.slug || fallback || '')
}

/**
 * Show a value of a difference as short text.
 *
 * @param {object|Array|string|number|boolean|null|undefined} value The expected or actual value.
 * @return {string} The text.
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-suite-run-replays-every-case-without-calling-the-source-or-writing-req-irt-003
 */
export function formatValue(value) {
	if (value === undefined) return '—'
	return JSON.stringify(value)
}

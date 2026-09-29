// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the registry lookup dialog says about a resolved value. Kept free of
 * Vue so the wording rules can be tested: a value is only called live when
 * the read that produced it was within the provider's staleness budget, and
 * a value the registry did not answer for is shown with its age, never as a
 * fresh one.
 *
 * @spec openspec/specs/registry-field-source/spec.md#requirement-a-resolved-value-carries-its-provenance-req-rfs-003
 */

/**
 * The list a response carries, whichever of the two shapes it has.
 *
 * @param {object|Array} data The response body.
 *
 * @return {Array} The rows.
 *
 * @spec openspec/specs/registry-field-source/spec.md#requirement-a-property-source-is-resolved-through-one-provider-contract-req-rfs-001
 */
export function listOf(data) {
	if (Array.isArray(data?.results)) {
		return data.results
	}
	return Array.isArray(data) ? data : []
}

/**
 * A duration in seconds as a short phrase.
 *
 * @param {number} seconds The age.
 * @param {(app: string, text: string, vars?: object) => string} t The translate function.
 *
 * @return {string} The phrase.
 *
 * @spec openspec/specs/registry-field-source/spec.md#requirement-a-resolved-value-carries-its-provenance-req-rfs-003
 */
export function ageText(seconds, t) {
	const age = Math.max(0, Math.round(Number(seconds) || 0))
	if (age < 60) {
		return t('integriq', '{count} seconds', { count: age })
	}
	if (age < 3600) {
		return t('integriq', '{count} minutes', { count: Math.round(age / 60) })
	}
	if (age < 86400) {
		return t('integriq', '{count} hours', { count: Math.round(age / 3600) })
	}
	return t('integriq', '{count} days', { count: Math.round(age / 86400) })
}

/**
 * The state of a resolved value in one of three words, and the sentence
 * that goes with it.
 *
 * @param {object} provenance The `provenance` of a resolve answer.
 * @param {(app: string, text: string, vars?: object) => string} t The translate function.
 *
 * @return {{state: string, text: string}} `live`, `cached` or `unreachable`, and its sentence.
 *
 * @spec openspec/specs/registry-field-source/spec.md#requirement-a-resolved-value-carries-its-provenance-req-rfs-003
 */
export function provenanceState(provenance, t) {
	const age = provenance?.cacheAgeSeconds
	if (provenance?.unreachable) {
		if (age === null || age === undefined) {
			return {
				state: 'unreachable',
				text: t(
					'integriq',
					'The registry did not answer and nothing was read before.',
				),
			}
		}
		return {
			state: 'unreachable',
			text: t(
				'integriq',
				'The registry did not answer. This is the last value read, {age} ago.',
				{
					age: ageText(age, t),
				},
			),
		}
	}
	if (provenance?.live) {
		return {
			state: 'live',
			text: t('integriq', 'Read from the registry just now.'),
		}
	}
	return {
		state: 'cached',
		text: t('integriq', 'Read from the registry {age} ago.', {
			age: ageText(age, t),
		}),
	}
}

/**
 * The value as label and text rows, for a flat object; anything nested is
 * shown as JSON.
 *
 * @param {unknown} value The resolved value.
 *
 * @return {Array<{key: string, text: string}>} The rows.
 *
 * @spec openspec/specs/registry-field-source/spec.md#requirement-a-property-source-is-resolved-through-one-provider-contract-req-rfs-001
 */
export function valueRows(value) {
	if (value === null || value === undefined) {
		return []
	}
	if (typeof value !== 'object' || Array.isArray(value)) {
		return [
			{
				key: '',
				text: typeof value === 'string' ? value : JSON.stringify(value),
			},
		]
	}
	return Object.entries(value).map(([key, item]) => ({
		key,
		text:
			item !== null && typeof item === 'object'
				? JSON.stringify(item)
				: String(item ?? ''),
	}))
}

/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenConnector Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Deterministic stub for @nextcloud/l10n used by the Vitest unit suite.
 * Returns the English source string with {placeholder} substitution.
 */

function interpolate(text, vars) {
	if (!vars) return text
	return text.replace(/\{(\w+)\}/g, (match, key) =>
		Object.hasOwn(vars, key) ? String(vars[key]) : match,
	)
}

export function translate(app, text, vars) {
	return interpolate(text, vars)
}

export function translatePlural(app, singular, plural, count, vars) {
	return interpolate(count === 1 ? singular : plural, { count, ...vars })
}

export const t = translate
export const n = translatePlural

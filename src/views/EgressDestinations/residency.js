// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Data residency on the screen (connections-declare-data-residency): the
 * country list the picker offers, and the words for a zone, a verdict and a
 * refused EU-only switch. Pure, so the page and the dialog share it and the
 * tests read it without mounting anything.
 *
 * The country codes mirror `ResidencyZones::ISO_3166_1` in PHP, which the
 * backend validates against; `residency.spec.js` reads that file so the two
 * lists never drift.
 *
 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-a-source-records-its-residency-with-a-basis-and-evidence-req-res-001
 */

/** Every officially assigned ISO 3166-1 alpha-2 code, as the backend accepts. */
export const COUNTRY_CODES = [
	'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU',
	'AW', 'AX', 'AZ', 'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL',
	'BM', 'BN', 'BO', 'BQ', 'BR', 'BS', 'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CC',
	'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN', 'CO', 'CR', 'CU', 'CV',
	'CW', 'CX', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE', 'EG',
	'EH', 'ER', 'ES', 'ET', 'FI', 'FJ', 'FK', 'FM', 'FO', 'FR', 'GA', 'GB', 'GD',
	'GE', 'GF', 'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT',
	'GU', 'GW', 'GY', 'HK', 'HM', 'HN', 'HR', 'HT', 'HU', 'ID', 'IE', 'IL', 'IM',
	'IN', 'IO', 'IQ', 'IR', 'IS', 'IT', 'JE', 'JM', 'JO', 'JP', 'KE', 'KG', 'KH',
	'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ', 'LA', 'LB', 'LC', 'LI', 'LK',
	'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MH',
	'MK', 'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW',
	'MX', 'MY', 'MZ', 'NA', 'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR',
	'NU', 'NZ', 'OM', 'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM', 'PN', 'PR',
	'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RS', 'RU', 'RW', 'SA', 'SB', 'SC',
	'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS',
	'ST', 'SV', 'SX', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TL',
	'TM', 'TN', 'TO', 'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'UM', 'US', 'UY',
	'UZ', 'VA', 'VC', 'VE', 'VG', 'VI', 'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'ZA',
	'ZM', 'ZW',
]

/**
 * The picker options: each code with the country's name in the reader's
 * language, sorted by that name.
 *
 * @param {string} locale The language to name the countries in.
 * @return {Array<{id: string, label: string}>} The options.
 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-a-source-records-its-residency-with-a-basis-and-evidence-req-res-001
 */
export function countryOptions(locale) {
	let names = null
	try {
		names = new Intl.DisplayNames([locale || 'en'], { type: 'region' })
	} catch {
		names = null
	}
	return COUNTRY_CODES.map((code) => {
		const name = names?.of(code)
		return { id: code, label: name && name !== code ? `${name} (${code})` : code }
	}).sort((a, b) => a.label.localeCompare(b.label, locale || 'en'))
}

/**
 * The words for a destination's zone.
 *
 * @param {string} zone `eu`, `eea`, `outside` or `unknown`.
 * @param {(app: string, text: string, vars?: object) => string} t The translate function.
 * @return {string} The label.
 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
 */
export function zoneLabel(zone, t) {
	switch (zone) {
	case 'eu':
		return t('integriq', 'EU')
	case 'eea':
		return t('integriq', 'EEA, outside the EU')
	case 'outside':
		return t('integriq', 'Outside the EEA')
	default:
		return t('integriq', 'Unknown')
	}
}

/**
 * The words for the page's verdict.
 *
 * @param {string} verdict `eu-only` or `not-proven`.
 * @param {(app: string, text: string, vars?: object) => string} t The translate function.
 * @return {string} The sentence.
 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
 */
export function verdictText(verdict, t) {
	if (verdict === 'eu-only') {
		return t('integriq', 'EU only: every destination integriq calls is shown to be in the EU.')
	}
	return t('integriq', 'Not proven: at least one destination is not shown to be in the EU.')
}

/**
 * Why the EU-only switch stayed off, from the refused save's answer.
 *
 * @param {object|undefined} data The 409 answer: `error` and `blocking`.
 * @param {(app: string, text: string, vars?: object) => string} t The translate function.
 * @return {string} The message naming the blocking destinations.
 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-an-administrator-can-enforce-eu-only-egress-req-res-003
 */
export function blockingText(data, t) {
	if (typeof data?.error === 'string' && data.error !== '') {
		return data.error
	}
	const names = (Array.isArray(data?.blocking) ? data.blocking : [])
		.map((destination) => destination?.name || destination?.host || '')
		.filter((name) => name !== '')
	return t('integriq', 'EU only mode stays off. These destinations are not shown to be in the EU: {names}', {
		names: names.join(', '),
	})
}

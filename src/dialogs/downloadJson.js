/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Save a value as a pretty-printed JSON file in the browser.
 *
 * @spec openspec/specs/configuration-export-import/spec.md#scenario-exporting-a-configuration-from-the-ui-produces-a-redacted-downloadable-file
 */

/**
 * Offer `data` as a download named `fileName`: a temporary link to an object
 * URL is clicked, removed again and its URL revoked.
 *
 * @param {string} fileName The file name the browser saves under.
 * @param {object|Array|string|number|boolean|null} data The value to serialise.
 * @return {void}
 */
export function downloadJson(fileName, data) {
	const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
	const link = document.createElement('a')
	link.href = URL.createObjectURL(blob)
	link.download = fileName
	document.body.appendChild(link)
	link.click()
	document.body.removeChild(link)
	URL.revokeObjectURL(link.href)
}

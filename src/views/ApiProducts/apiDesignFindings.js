/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The API design findings of a product, as the findings panel shows them
 * (gateway-api-design-rules-check, task 3): grouped by severity, errors first.
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 */

/** The severities in the order the panel lists them. */
export const SEVERITIES = ['error', 'warning', 'info']

/**
 * The findings grouped by severity, errors first, each group in the order
 * given; a severity without findings is left out, and an unknown one is
 * listed with `info`.
 *
 * @param {Array<object>} findings The findings
 * @return {Array<{severity: string, findings: Array<object>}>} The groups
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 */
export function groupBySeverity(findings) {
	const groups = Object.fromEntries(SEVERITIES.map((severity) => [severity, []]))
	for (const finding of Array.isArray(findings) ? findings : []) {
		const severity = SEVERITIES.includes(finding?.severity) ? finding.severity : 'info'
		groups[severity].push(finding)
	}

	return SEVERITIES
		.filter((severity) => groups[severity].length > 0)
		.map((severity) => ({ severity, findings: groups[severity] }))
}

/**
 * Whether a finding still blocks publication: an open error (REQ-ADRC-003).
 *
 * @param {object} finding The finding
 * @return {boolean} Whether it blocks
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-a-product-with-an-open-error-is-not-published-req-adrc-003
 */
export function isBlocking(finding) {
	return finding?.severity === 'error' && (finding?.status ?? 'open') === 'open'
}

/**
 * The id a finding is addressed by.
 *
 * @param {object} finding The finding
 * @return {string} The id, or '' when it has none
 * @spec openspec/changes/gateway-api-design-rules-check/tasks.md
 */
export function findingId(finding) {
	return String(finding?.id ?? finding?.uuid ?? finding?.['@self']?.id ?? '')
}

/**
 * Put a waived finding, as the server answered it, in place of the one
 * that was waived.
 *
 * @param {Array<object>} findings The findings shown
 * @param {object} waived The finding the server returned
 * @return {Array<object>} The findings with that one replaced
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-a-product-with-an-open-error-is-not-published-req-adrc-003
 */
export function replaceFinding(findings, waived) {
	const id = findingId(waived)
	return findings.map((finding) => (id !== '' && findingId(finding) === id ? { ...finding, ...waived } : finding))
}

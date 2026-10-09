/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the gateway catalogue says about one gateway, as text.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-gateway-declares-where-its-endpoint-sits-req-sg-008
 */
import { translate as t } from '@nextcloud/l10n'

/**
 * The claim in the words the catalogue carries, so no screen has to remember it.
 *
 * @param {object} gateway A catalogue entry.
 * @return {string}
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-gateway-declares-its-standard-and-its-conformance-claim-req-sg-001
 */
export function claimText(gateway) {
	return (
		gateway?.claim?.wording || gateway?.claim?.level || gateway?.claimLevel || ''
	)
}

/**
 * Where the endpoint sits. An undeclared jurisdiction never reads as a place.
 *
 * @param {object} gateway A catalogue entry or an overview row.
 * @return {string}
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-gateway-declares-where-its-endpoint-sits-req-sg-008
 */
export function jurisdictionText(gateway) {
	if (gateway?.jurisdictionDeclared !== true) {
		return t('integriq', 'Not declared')
	}
	return String(gateway.jurisdiction ?? '')
}

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { rowId } from './rowId.js'

/**
 * Refresh one payment's status from its provider: one POST, no dialog.
 *
 * For an instance the provider's webhook cannot reach. The server re-derives
 * the status through the webhook's own path, so a refresh and a webhook apply
 * the same outcome guard and never announce a payment twice (design D2).
 *
 * @param {object} payment The payment_intent row.
 * @return {Promise<object|null>} The refresh result, or null when nothing ran.
 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payments-status-is-refreshed-from-the-provider-on-request-req-prv-002
 */
export async function refreshPayment(payment) {
	const id = rowId(payment)
	if (!id) {
		showError(t('integriq', 'This payment has no id, so its status cannot be refreshed.'))
		return null
	}

	try {
		const { data } = await axios.post(
			generateUrl(`/apps/integriq/api/payments/${encodeURIComponent(id)}/refresh`),
		)
		showSuccess(refreshedText(data))
		return data
	} catch (error) {
		const reason = error?.response?.data?.message || error?.message || ''
		showError(t('integriq', 'The payment status was not refreshed: {reason}', { reason }))
		return null
	}
}

/**
 * The notice after a refresh: the status the provider reported.
 *
 * @param {object|undefined} data The refresh response.
 * @return {string} The notice text.
 */
function refreshedText(data) {
	const status = data?.paymentStatus
	if (!status) {
		return t('integriq', 'The payment status was refreshed.')
	}
	return t('integriq', 'The provider reports this payment as {status}.', { status })
}

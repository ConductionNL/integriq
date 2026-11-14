// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { rowId } from './rowId.js'

/**
 * Release a message a send check held for review, and send it.
 *
 * The server records the release with the person who gave it, then sends the
 * message through the delivery pipeline, which asks the opt-out list again: a
 * recipient who still opted out is skipped, and the notice says when none went.
 *
 * @param {object} message The held outbound message row.
 * @return {Promise<object|null>} The server's answer, or null when nothing was released.
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-message-that-fails-a-send-check-is-held-for-review
 */
export async function releaseHeldMessage(message) {
	return act(message, 'release', releasedText)
}

/**
 * Withdraw a message a send check held for review: it is recorded as not sent.
 *
 * @param {object} message The held outbound message row.
 * @return {Promise<object|null>} The server's answer, or null when nothing was withdrawn.
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-message-that-fails-a-send-check-is-held-for-review
 */
export async function withdrawHeldMessage(message) {
	return act(message, 'withdraw', () => t('integriq', 'The message was withdrawn and recorded as not sent.'))
}

/**
 * POST one act on a held message and tell the user how it went.
 *
 * @param {object} message The held outbound message row.
 * @param {string} verb `release` or `withdraw`.
 * @param {(data: object) => string} successText The notice for a success.
 * @return {Promise<object|null>} The server's answer, or null on failure.
 */
async function act(message, verb, successText) {
	const id = rowId(message)
	if (!id) {
		showError(t('integriq', 'This row names no message.'))
		return null
	}

	try {
		const { data } = await axios.post(
			generateUrl(`/apps/integriq/api/outbound/messages/${encodeURIComponent(id)}/${verb}`),
		)
		showSuccess(successText(data || {}))
		return data || {}
	} catch (error) {
		const reason = error?.response?.data?.error || error?.message || ''
		showError(t('integriq', 'That did not work: {reason}', { reason }))
		return null
	}
}

/**
 * The notice after a release, with how many recipients the send reached.
 *
 * @param {object} data The release answer.
 * @return {string} The notice text.
 */
function releasedText(data) {
	const retried = data?.retry?.retried?.length || 0
	if (retried === 0) {
		return t('integriq', 'The message was released, but every recipient is still opted out, so nothing was sent.')
	}
	return t('integriq', 'The message was released and handed to the delivery pipeline.')
}

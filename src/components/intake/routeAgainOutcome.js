// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What "Route again" says about one message's outcome, for the row action's
// toast and the bulk dialog's per-message list.
//
// @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003

import { translate as t } from '@nextcloud/l10n'

/** The statuses a message can be routed again from. */
export const ROUTABLE_STATUSES = ['held', 'failed']

/**
 * Whether a message can be routed again.
 *
 * @param {object|null} message The `intake_message`.
 * @return {boolean} True for a held or failed message.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
 */
export function isRoutable(message) {
	return ROUTABLE_STATUSES.includes(message?.status)
}

/**
 * One outcome as a sentence.
 *
 * @param {object} outcome `{status, ruleName, targetRef, reason}` or `{error}`.
 * @return {{routed: boolean, text: string}} Whether it was routed, and what to say.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
 */
export function describeOutcome(outcome) {
	if (outcome && typeof outcome.error === 'string' && outcome.error !== '') {
		return { routed: false, text: outcome.error }
	}

	if (outcome?.status === 'routed') {
		if (outcome.targetRef) {
			return {
				routed: true,
				text: t('integriq', 'Routed by "{rule}" to {target}', { rule: outcome.ruleName || '', target: outcome.targetRef }),
			}
		}
		return { routed: true, text: t('integriq', 'Routed by "{rule}"', { rule: outcome.ruleName || '' }) }
	}

	const reason = outcome?.reason || t('integriq', 'No reason given')
	return { routed: false, text: t('integriq', 'Still {status}: {reason}', { status: outcome?.status || 'held', reason }) }
}

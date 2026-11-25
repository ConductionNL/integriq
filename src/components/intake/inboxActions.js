// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Assign and Reply on a message in the intake inbox. Which of the two the
// signed-in user may use, and which channels can carry a reply, are asked once
// per page load and shared by every row; the routes check the right again.
//
// @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The statuses a message can be assigned to a case from. */
export const ASSIGNABLE_STATUSES = ['held', 'routed']

/** Nothing allowed: the answer when the rights cannot be read. */
const NO_RIGHTS = Object.freeze({ assign: false, reply: false })

let rightsRequest = null
let replyChannelsRequest = null

/**
 * Which inbox actions the signed-in user may use; nothing when that cannot be read.
 *
 * @return {Promise<{assign: boolean, reply: boolean}>} The rights.
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
 */
export function fetchInboxRights() {
	if (rightsRequest === null) {
		rightsRequest = axios.get(generateUrl('/apps/integriq/api/intake/messages/rights'))
			.then(({ data }) => ({ assign: data?.assign === true, reply: data?.reply === true }))
			.catch(() => {
				rightsRequest = null
				return NO_RIGHTS
			})
	}
	return rightsRequest
}

/**
 * The ids of the channels a reply can leave over; none when that cannot be read.
 *
 * @return {Promise<Set<string>>} The channel ids.
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
 */
export function fetchReplyChannels() {
	if (replyChannelsRequest === null) {
		replyChannelsRequest = axios.get(generateUrl('/apps/integriq/api/intake/channels'))
			.then(({ data }) => new Set(
				(Array.isArray(data?.channels) ? data.channels : [])
					.filter((channel) => channel?.canReply === true)
					.map((channel) => String(channel.channelId)),
			))
			.catch(() => {
				replyChannelsRequest = null
				return new Set()
			})
	}
	return replyChannelsRequest
}

/**
 * Forget the shared answers, so the next row asks again.
 *
 * @return {void}
 */
export function resetInboxActions() {
	rightsRequest = null
	replyChannelsRequest = null
}

/**
 * Whether a message can be assigned to a case.
 *
 * @param {object|null} message The `intake_message`.
 * @return {boolean} True for a held or routed message.
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
 */
export function isAssignable(message) {
	return ASSIGNABLE_STATUSES.includes(message?.status)
}

/**
 * Whether a message arrived on a channel that can carry a reply.
 *
 * @param {object|null} message The `intake_message`.
 * @param {Set<string>} replyChannels The channels that can reply.
 * @return {boolean} True when a reply can go back.
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
 */
export function isAnswerable(message, replyChannels) {
	return typeof message?.channelId === 'string' && replyChannels.has(message.channelId)
}

/**
 * Assign a message to the case a handler names.
 *
 * @param {string} id The `intake_message` uuid.
 * @param {string} caseRef The case reference.
 * @return {Promise<object>} The assignment.
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
 */
export async function assignMessage(id, caseRef) {
	const { data } = await axios.post(
		generateUrl('/apps/integriq/api/intake/messages/' + encodeURIComponent(id) + '/assign'),
		{ caseRef },
	)
	return data
}

/**
 * Reply to a message on the channel it arrived on.
 *
 * @param {string} id The `intake_message` uuid.
 * @param {string} text What to say.
 * @return {Promise<object>} What happened to the reply.
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
 */
export async function replyToMessage(id, text) {
	const { data } = await axios.post(
		generateUrl('/apps/integriq/api/intake/messages/' + encodeURIComponent(id) + '/reply'),
		{ text },
	)
	return data
}

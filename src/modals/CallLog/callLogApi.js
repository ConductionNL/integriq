// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The call log acts the screens drive: preview, replay (single, bulk, dry
// run) and firing by hand, over CallLogController. Listing is OpenRegister's
// own objects endpoint, like the SourceLogs page itself.
//
// @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * What a replay of one call would send, and the mapping versions on offer.
 *
 * @param {string} id the call record uuid
 * @return {Promise<{request: object, versions: {recorded: string, current: string, differ: boolean}}>} the preview
 */
export async function previewCall(id) {
	const { data } = await axios.get(
		generateUrl(`/apps/integriq/api/calls/${encodeURIComponent(id)}/preview`),
	)
	return data
}

/**
 * Replay one or several calls, or dry run them.
 *
 * @param {string[]} ids the call record uuids
 * @param {{dryRun?: boolean, mappingVersion?: string}} options the replay options
 * @return {Promise<{succeeded: number, failed: number, items: object[]}>} the per-item outcomes
 */
export async function replayCalls(ids, options = {}) {
	const body = { dryRun: options.dryRun === true }
	if (options.mappingVersion) {
		body.mappingVersion = options.mappingVersion
	}
	if (ids.length === 1) {
		const { data } = await axios.post(
			generateUrl(
				`/apps/integriq/api/calls/${encodeURIComponent(ids[0])}/replay`,
			),
			body,
		)
		return data
	}
	const { data } = await axios.post(
		generateUrl('/apps/integriq/api/calls/replay'),
		{
			...body,
			calls: ids,
		},
	)
	return data
}

/**
 * Fire a call by hand.
 *
 * @param {string} target the source to call
 * @param {object} request the request to send
 * @param {boolean} dryRun show what would be sent instead of sending it
 * @return {Promise<object>} what happened
 */
export async function fireCall(target, request, dryRun) {
	const { data } = await axios.post(generateUrl('/apps/integriq/api/calls/fire'), {
		target,
		request,
		dryRun: dryRun === true,
	})
	return data
}

/**
 * Whether a call record's last attempt failed.
 *
 * @param {object} call a call_log object
 * @return {boolean} true when it is worth replaying
 */
export function callFailed(call) {
	const code = Number(call?.statusCode ?? 0)
	return !(code >= 200 && code < 300)
}

/**
 * The recent outbound calls whose last attempt failed.
 *
 * @param {number} limit how many recent calls to look through
 * @return {Promise<object[]>} the failed calls, newest first
 */
export async function recentFailedCalls(limit = 100) {
	const { data } = await axios.get(
		generateUrl('/apps/openregister/api/objects/integriq/call_log'),
		{ params: { _limit: limit, '_order[created]': 'desc' } },
	)
	const rows = Array.isArray(data?.results) ? data.results : []
	return rows.filter((row) => row.direction !== 'inbound' && callFailed(row))
}

/**
 * The server's reason for a failed request, or a fallback text.
 *
 * @param {Error} error the failed request
 * @param {string} fallback the translated text to show when the server gave none
 * @return {string} the text to show
 */
export function serverError(error, fallback) {
	return error?.response?.data?.error || fallback
}

/**
 * Why a replay could not be started.
 *
 * @param {Error} error the failed request
 * @return {string} the server's reason, or a translated fallback
 */
export function replayError(error) {
	return serverError(error, t('integriq', 'The replay could not be started.'))
}

/**
 * Why a hand-fired call could not be sent.
 *
 * @param {Error} error the failed request
 * @return {string} the server's reason, or a translated fallback
 */
export function fireError(error) {
	return serverError(error, t('integriq', 'The call could not be fired.'))
}

/**
 * The sentence for a dry run that would have succeeded.
 *
 * @return {string} the translated sentence
 */
export function dryRunText() {
	return t('integriq', 'Dry run: this is what would be sent. Nothing was sent.')
}

/**
 * The sources a call can be fired at, as select options.
 *
 * @return {Promise<Array<{id: string, label: string}>>} the options
 */
export async function listSourceOptions() {
	const { data } = await axios.get(
		generateUrl('/apps/openregister/api/objects/integriq/source'),
		// `_limit`, not `limit`: an unprefixed param is a property filter.
		{ params: { _limit: 500 } },
	)
	const list = Array.isArray(data?.results) ? data.results : []
	return list.map(sourceOption)
}

/**
 * One source as a select option.
 *
 * @param {object} item a source object
 * @return {{id: string, label: string}} the option
 */
function sourceOption(item) {
	return {
		id: String(item['@self']?.id || item.id || item.uuid),
		label: item.name || item.title || String(item.id),
	}
}

/**
 * Whether an outcome is a dry run that would have succeeded.
 *
 * @param {object} item a per-item outcome
 * @return {boolean} true when nothing was sent
 */
export function isDryRun(item) {
	return item.sent === false && item.succeeded === true
}

/**
 * The call record's uuid, whichever key the list or the row carries it under.
 *
 * @param {object} call a call_log object
 * @return {string} the uuid
 */
export function callId(call) {
	return String(call?.['@self']?.id ?? call?.id ?? call?.uuid ?? '')
}

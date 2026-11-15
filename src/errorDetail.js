// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Append the server's or the client's reason to a translated failure text.
 *
 * @param {string} message The translated failure text.
 * @param {Error} err The failed request.
 * @return {string} The message, with ": detail" when there is one.
 */
export function withDetail(message, err) {
	const detail = err?.response?.data?.error || err?.message || ''
	return message + (detail ? `: ${detail}` : '')
}

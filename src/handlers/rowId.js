/**
 * The single row-id resolver shared by every row-action consumer.
 *
 * There were two of these — one in `handlers/actionHandlers.js`, one in
 * `modals/v2/runTargets.js` — and they had already drifted: only the second
 * carried the `@self.id` fallback, so a row whose id lives only under `@self`
 * resolved inside the run/test modal and not in the handlers that build the
 * "Run" POST url or the "View logs" query. Same row, two answers.
 *
 * OpenRegister returns rows with `id` set; legacy call sites sometimes carry
 * only `uuid`, and objects reached through an embed carry it at `@self.id`.
 */

/**
 * Extract a stable id from an index-page row.
 *
 * @param {object} item Row payload from the index page.
 * @return {string|number|null} The id, uuid, or null when the row carries neither.
 */
export function rowId(item) {
	return item?.id || item?.uuid || item?.['@self']?.id || null
}

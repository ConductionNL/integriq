/**
 * Where each "View logs" surface navigates, as one table.
 *
 * Keyed by the manifest row-action id. `handlers/actionHandlers.js` resolves
 * the row action against it; `modals/v2/runTargets.js` builds the run/test
 * modal's "View full log" link from the same entries, so the two always land
 * on the same filtered page. They used to be separate literals with a comment
 * asserting the coupling — this makes the coupling the code.
 */

/**
 * Route name + query-param key per logs row action.
 *
 * `queryParam` MUST name a property the log rows are actually WRITTEN with, not
 * merely one the schema declares: CnLogsPage forwards every non-`_`-prefixed
 * query entry to OpenRegister as a property filter, so a key no writer sets
 * matches nothing and the destination page renders EMPTY. That is strictly
 * worse than not filtering, which is why the two entries below that have no
 * usable field carry `queryParam: null` — they navigate to the unfiltered page
 * instead of to a guaranteed-empty one.
 *
 * Verified against a populated instance (2026-08-11), `total` per filter:
 *
 * | action                     | schema              | param     | result       |
 * |----------------------------|---------------------|-----------|--------------|
 * | view-source-logs           | call_log            | source    | 11 of 15 ✓   |
 * | view-endpoint-logs         | call_log            | endpoint  | 0 of 0 ✓     |
 * | view-job-logs              | job_log             | jobId     | 3 of 5 ✓     |
 * | view-synchronization-logs  | synchronization_log | —         | 0 of 10 ✗    |
 * | view-cloud-event-logs      | call_log            | —         | 0 of 15 ✗    |
 *
 * `endpoint` filters nothing today only because no inbound rows exist yet: it
 * is a declared `call_log` property and `EndpointService::recordInboundCallLog()`
 * writes it, so the param is right and starts working the moment data arrives.
 *
 * The two nulls are blocked on the backend, not on this table:
 *
 * - **synchronization** — `SynchronizationRunLog::toArray()` carries
 *   `synchronizationId`, but `SynchronizationLogService::normalize()` drops it
 *   before the insert (it is null at that point), so persisted rows hold NO
 *   link to their synchronization at all. Neither `synchronization` nor
 *   `synchronizationId` matches a single one of the 10 stored rows. Restore the
 *   param once the writer persists the FK.
 * - **event** — `call_log` declares no event property whatsoever (its FKs are
 *   `source`/`sourceId`, `actionId`, `synchronizationId`/`synchronization`,
 *   `product` and `endpoint`), and nothing writes one. Cloud-event logging needs
 *   a field — or its own schema, `event_message` being the likely candidate —
 *   before this can be scoped.
 *
 * @type {{[key: string]: {route: string, queryParam: (string|null)}}}
 */
export const VIEW_LOGS_TARGETS = {
	'view-source-logs': { route: 'SourceLogs', queryParam: 'source' },
	'view-endpoint-logs': { route: 'EndpointLogs', queryParam: 'endpoint' },
	'view-job-logs': { route: 'JobLogs', queryParam: 'jobId' },
	'view-synchronization-logs': { route: 'SynchronizationLogs', queryParam: null },
	'view-cloud-event-logs': { route: 'CloudEventLogs', queryParam: null },
}

/**
 * Build a vue-router location for one of the logs pages, scoped to `id`.
 *
 * A target whose `queryParam` is null navigates to the unfiltered page — the
 * log rows carry no field to scope by, and filtering on a missing one would
 * land the user on an empty table (see `VIEW_LOGS_TARGETS`).
 *
 * @param {string} actionId The `VIEW_LOGS_TARGETS` key.
 * @param {string|number|null} id The parent row's id.
 * @return {object|null} A router location, or null when the action is unknown or the id is missing.
 */
export function logsLocation(actionId, id) {
	const target = VIEW_LOGS_TARGETS[actionId]
	if (!target || id === null || id === undefined) {
		return null
	}

	if (target.queryParam === null) {
		return { name: target.route }
	}

	return { name: target.route, query: { [target.queryParam]: id } }
}

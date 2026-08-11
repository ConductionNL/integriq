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
 * `queryParam` MUST name the property the log rows are actually WRITTEN with,
 * not the relation the schema would prefer: CnLogsPage forwards every
 * non-`_`-prefixed query entry to OpenRegister as a property filter, so a key
 * the writer never sets simply matches nothing. Hence `jobId` — the field
 * `JobService::saveJobLog()` persists — rather than the schema's `job`.
 *
 * KNOWN MISMATCH: `view-endpoint-logs` and `view-cloud-event-logs` both target
 * `call_log`, which has neither an `endpoint` nor an `event` property (its FKs
 * are `sourceId`/`source`, `actionId`, `synchronizationId`/`synchronization`).
 * Those two therefore still filter to nothing. Left as-is: picking the right
 * field for each needs its own look at what writes those rows.
 *
 * @type {{[key: string]: {route: string, queryParam: string}}}
 */
export const VIEW_LOGS_TARGETS = {
	'view-source-logs': { route: 'SourceLogs', queryParam: 'source' },
	'view-endpoint-logs': { route: 'EndpointLogs', queryParam: 'endpoint' },
	'view-job-logs': { route: 'JobLogs', queryParam: 'jobId' },
	'view-synchronization-logs': { route: 'SynchronizationLogs', queryParam: 'synchronization' },
	'view-cloud-event-logs': { route: 'CloudEventLogs', queryParam: 'event' },
}

/**
 * Build a vue-router location for one of the logs pages, filtered to `id`.
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

	return { name: target.route, query: { [target.queryParam]: id } }
}

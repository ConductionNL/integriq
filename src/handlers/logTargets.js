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
 * | action                     | schema              | param             |
 * |----------------------------|---------------------|-------------------|
 * | view-source-logs           | call_log            | source            |
 * | view-endpoint-logs         | call_log            | endpoint          |
 * | view-job-logs              | job_log             | jobId             |
 * | view-synchronization-logs  | synchronization_log | synchronizationId |
 * | view-cloud-event-logs      | call_log            | —                 |
 *
 * Two of these filter nothing *yet*, for reasons that are not this table's:
 * no inbound `call_log` rows exist (`EndpointsController::logs()` is still
 * unwired), and `synchronization_log` rows written before
 * `SynchronizationService` was fixed to read `$synchronization['id']` carry no
 * FK — OpenRegister exposes no top-level `uuid`, so the payload's
 * `synchronizationId` was always null and `normalize()` stripped it. New rows
 * carry it; the old ones stay unattributable.
 *
 * That last point has a deploy-day consequence worth stating plainly: because
 * this target is scoped rather than unfiltered, "View logs" on a synchronization
 * whose history predates the fix renders an EMPTY page — and those are exactly
 * the synchronizations an operator reaches for first. The alternative is an
 * unfiltered firehose forever, so scoped-and-empty is the deliberate trade, not
 * an oversight. It resolves itself as new runs accumulate.
 *
 * `event` stays null — filtering on a field nothing writes renders an EMPTY
 * page, which is strictly worse than the unfiltered listing. `call_log`
 * declares no event property whatsoever (its FKs are `source`/`sourceId`,
 * `actionId`, `synchronizationId`/`synchronization`, `product` and `endpoint`),
 * and nothing writes one. Cloud-event logging needs a field — or its own
 * schema, `event_message` being the likely candidate — before it can be scoped.
 *
 * @type {{[key: string]: {route: string, queryParam: (string|null)}}}
 */
export const VIEW_LOGS_TARGETS = {
	'view-source-logs': { route: 'SourceLogs', queryParam: 'source' },
	'view-endpoint-logs': { route: 'EndpointLogs', queryParam: 'endpoint' },
	'view-job-logs': { route: 'JobLogs', queryParam: 'jobId' },
	'view-synchronization-logs': {
		route: 'SynchronizationLogs',
		queryParam: 'synchronizationId',
	},
	'view-cloud-event-logs': { route: 'CloudEventLogs', queryParam: null },
}

/**
 * Build a vue-router location for one of the logs pages, scoped to `id`.
 *
 * A target whose `queryParam` is null navigates to the unfiltered page — the
 * log rows carry no field to scope by, and filtering on a missing one would
 * land the user on an empty table (see `VIEW_LOGS_TARGETS`).
 *
 * `id` is therefore required only by the scoped targets, and the unknown-action
 * and missing-id guards are separate for that reason: an unfiltered target needs
 * no id to build a location, so folding the two checks together made a row
 * without one fail to reach a page that never wanted it.
 *
 * @param {string} actionId The `VIEW_LOGS_TARGETS` key.
 * @param {string|number|null} id The parent row's id. Unused by an unfiltered target.
 * @return {object|null} A router location, or null when the action is unknown, or the id is missing on a scoped target.
 */
export function logsLocation(actionId, id) {
	const target = VIEW_LOGS_TARGETS[actionId]
	if (!target) {
		return null
	}

	if (target.queryParam === null) {
		return { name: target.route }
	}

	if (id === null || id === undefined) {
		return null
	}

	return { name: target.route, query: { [target.queryParam]: id } }
}

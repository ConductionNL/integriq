/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pure helpers for the Jobs create/edit form (`modals/v2/JobFormFields.vue`).
 *
 * Everything here is deliberately free of Vue, axios and DOM access so it can
 * be unit-tested directly — the SFC itself is not testable in this repo, since
 * `eslint src` prunes `src/modals/**` before the `!src/modals/v2/**` negation
 * in eslint.config.js can match, leaving these files unlinted. This module is
 * the compensating safety net, in the same spirit as `views/Rule/ruleDraft.js`
 * and `views/Synchronization/syncDraft.js`.
 *
 * The Jobs form is manifest- and schema-driven: field order and the
 * interval/logRetention defaults come from the `job` schema
 * (lib/Settings/register.d/job-form-fields.json), and the jobClass option
 * list, the `group` layout key and errorRetention's prefill come from
 * `pages[Jobs].config.fieldOverrides` in src/manifest.json. Nothing in this
 * module hardcodes a field name except the synchronization argument path,
 * which is a nested key no descriptor can reach.
 *
 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
 */

/**
 * Canonical FQN of the built-in synchronization-runner action. Lives in
 * lib/Action/SynchronizationAction.php; the form-side check has to use the
 * exact same string the backend resolves with
 * `containerInterface->get($jobClass)`.
 */
export const SYNCHRONIZATION_ACTION_CLASS =
	'OCA\\Integriq\\Action\\SynchronizationAction'

/**
 * The nested key inside a job's `arguments` object that
 * `SynchronizationAction::run()` reads to find the synchronization to run.
 */
export const SYNCHRONIZATION_ID_KEY = 'synchronizationId'

/**
 * Coalesce an ordered field list into render runs: consecutive fields sharing
 * the same non-empty `group` become a single run, and everything else is a run
 * of one. `group` rides in on the descriptor because `fieldsFromSchema` ends
 * with `Object.assign(field, overrides[key])`, so an arbitrary key declared in
 * the manifest's `fieldOverrides` reaches the form. That is what lets the four
 * scheduling flags be laid out two-up without the component knowing their
 * names.
 *
 * Consecutive-only is a deliberate constraint, not a simplification. A group
 * cannot reorder anything, so the schema's `order` stays the single source of
 * truth for sequence; and a group whose members are not adjacent renders as
 * two visibly separate runs rather than silently teleporting a field out of
 * its declared position. The fix for a split group is to correct `order`, and
 * this makes that visible instead of hiding it.
 *
 * @param {Array<object>} fields Ordered field descriptors from CnFormDialog.
 *
 * @return {Array<{key: string, group: string|null, fields: Array<object>}>} One
 *   entry per render run, in input order. `group` is null for ungrouped runs.
 *
 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
 */
export function groupFieldRuns(fields) {
	const runs = []
	for (const field of Array.isArray(fields) ? fields : []) {
		// A descriptor without a string key cannot be rendered or :key'd, so
		// skip it rather than emitting a run that would break the v-for.
		if (!field || typeof field.key !== 'string') continue
		// Only a non-empty STRING groups. `group: ''` or `group: 5` is treated
		// as ungrouped so a stray value can't silently merge unrelated fields.
		const group =
			typeof field.group === 'string' && field.group !== ''
				? field.group
				: null
		const last = runs[runs.length - 1]
		if (group !== null && last !== undefined && last.group === group) {
			last.fields.push(field)
			continue
		}
		runs.push({
			// Prefixed so a group run and a field run can never collide on the
			// same Vue :key, even if a group is named after a field.
			key: (group !== null ? `group:${group}:` : 'field:') + field.key,
			group,
			fields: [field],
		})
	}
	return runs
}

/**
 * Parse a stored date/date-time string into a local-time Date for
 * NcDateTimePickerNative. Accepts both ISO (`2026-10-15T14:30:00Z`) and
 * OpenRegister's space-separated persisted form (`2026-10-15 14:30:00`); a
 * trailing `Z`/offset is ignored because the picker operates in local time.
 *
 * Behaviourally identical to CnFormDialog's own `dateValueFor` — INCLUDING its
 * dropping of seconds, which the regex does not capture. Matching exactly is a
 * requirement rather than laziness: CnFormDialog's `normalizePersistedDates()`
 * has already run this same transform across formData by the time this form's
 * slot renders, so a helper that disagreed by even a second would make an
 * untouched field read as dirty, or shift its value, the moment the dialog
 * opened.
 *
 * @param {*} raw The stored value, in any shape it has been persisted in.
 *
 * @return {Date|null} null for empty or unparseable input.
 *
 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
 */
export function dateValueFromStored(raw) {
	if (!raw) return null
	const parts = String(raw).match(
		/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/,
	)
	if (parts !== null) {
		return new Date(
			Number(parts[1]),
			Number(parts[2]) - 1,
			Number(parts[3]),
			Number(parts[4] || 0),
			Number(parts[5] || 0),
		)
	}
	const fallback = new Date(raw)
	return Number.isNaN(fallback.getTime()) ? null : fallback
}

/**
 * Serialise a Date into the canonical stored string: `YYYY-MM-DD` for a `date`
 * widget, and a full RFC 3339 `YYYY-MM-DDTHH:mm:ss±hh:mm` for `datetime`.
 *
 * The seconds and the offset are mandatory, not cosmetic: ajv-formats'
 * `date-time` validator requires an offset, so the backend REJECTS a bare
 * `YYYY-MM-DDTHH:mm` on save. That is the whole reason `scheduleAfter` cannot
 * be a plain text input — which is what it was, via a widget branch that fed
 * `datetime-local` to NcTextField's `type` prop, whose validator only accepts
 * text/password/email/tel/url/search/number.
 *
 * @param {string} widget The field widget: 'date' or 'datetime'.
 * @param {Date}   date   A valid Date instance.
 *
 * @return {string} The serialised value.
 *
 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
 */
export function formatDateValue(widget, date) {
	const yyyy = String(date.getFullYear()).padStart(4, '0')
	const MM = String(date.getMonth() + 1).padStart(2, '0')
	const dd = String(date.getDate()).padStart(2, '0')
	if (widget !== 'datetime') {
		return `${yyyy}-${MM}-${dd}`
	}
	const hh = String(date.getHours()).padStart(2, '0')
	const mm = String(date.getMinutes()).padStart(2, '0')
	const ss = String(date.getSeconds()).padStart(2, '0')
	// getTimezoneOffset() reports minutes BEHIND UTC, so the sign inverts.
	const offMin = -date.getTimezoneOffset()
	const sign = offMin >= 0 ? '+' : '-'
	const offAbs = Math.abs(offMin)
	const offH = String(Math.floor(offAbs / 60)).padStart(2, '0')
	const offM = String(offAbs % 60).padStart(2, '0')
	return `${yyyy}-${MM}-${dd}T${hh}:${mm}:${ss}${sign}${offH}:${offM}`
}

/**
 * Coerce a number input's raw value to a number, or null when it is empty.
 *
 * Non-numeric text yields null rather than NaN. NaN survives assignment and
 * only degrades to null at JSON.stringify time, so an intermediate NaN would
 * sit in formData and defeat the dialog's numeric min/max validation — a
 * deliberate improvement over the inline `Number(value)` this replaces. Zero
 * is preserved: `'0'` is a legitimate interval, and a falsy check would eat it.
 *
 * @param {*} raw The raw input value.
 *
 * @return {number|null} The coerced number, or null for empty/non-numeric.
 *
 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
 */
export function coerceNumber(raw) {
	if (raw === '' || raw === null || raw === undefined) return null
	const value = Number(raw)
	return Number.isNaN(value) ? null : value
}

/**
 * Read the synchronization id a job's `arguments` carries.
 *
 * Defensive about shape because `arguments` is a free-form `type: object` in
 * the schema: anything that is not a plain object (a string, an array, null)
 * simply has no id.
 *
 * @param {*} args The job's `arguments` value.
 *
 * @return {string|null} The id as a string, or null when absent/empty.
 *
 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
 */
export function readSynchronizationId(args) {
	if (args === null || typeof args !== 'object' || Array.isArray(args)) return null
	const id = args[SYNCHRONIZATION_ID_KEY]
	return id === null || id === undefined || id === '' ? null : String(id)
}

/**
 * A NEW `arguments` object carrying the chosen synchronization id, or with the
 * key removed when `id` is empty.
 *
 * Never mutates its input — CnFormDialog tracks dirtiness by comparing against
 * the item it cloned, so mutating the live `arguments` object in place would
 * change both sides of that comparison and lose the edit. Every unrelated
 * argument the job carries is preserved; a non-object/array/null `arguments`
 * is replaced by a fresh object rather than spread, so an array can never
 * acquire a stray named key.
 *
 * @param {*} args The current `arguments` value.
 * @param {*} id   The chosen synchronization id, or null to clear it.
 *
 * @return {object} A new arguments object.
 *
 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
 */
export function writeSynchronizationId(args, id) {
	const next =
		args !== null && typeof args === 'object' && !Array.isArray(args)
			? { ...args }
			: {}
	if (id === null || id === undefined || id === '') {
		delete next[SYNCHRONIZATION_ID_KEY]
		return next
	}
	next[SYNCHRONIZATION_ID_KEY] = String(id)
	return next
}

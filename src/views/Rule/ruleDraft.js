/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Shared draft/option/condition logic for the rule editing surfaces:
 *
 *   - `views/Rule/RuleDetailPage.vue` — the full page
 *   - `views/Rule/RuleActionConfig.vue` — the action-type picker on that page
 *   - `modals/v2/RuleEditorModal.vue` — the wide create/edit modal on the
 *     Rules index
 *
 * It also owns the JsonLogic conditions normaliser that
 * `views/Synchronization/syncDraft.js` re-exports. Rules and synchronizations
 * share the JsonLogic condition shape deliberately, so power-users can paste
 * condition trees between them; the normaliser used to exist as a third copy
 * inside RuleDetailPage, which syncDraft's own docblock flagged as worth
 * folding in. This is that fold. Only the *serialisation* differs between the
 * two resources, and each keeps its own function for it — see
 * `serializeRuleConditions()` below.
 */

/**
 * Default empty root-group used when a rule has no conditions yet (or when the
 * persisted value is not a recognisable JsonLogic group node). Centralised so
 * the visual builder and the raw-JSON editor always agree on the round-trip
 * default shape.
 *
 * Read-only: never hand this object (or a `{ ...spread }` of it, which shares
 * the same `and` array) to a caller that may push into it. Use
 * `emptyRootGroup()` for that.
 */
export const EMPTY_ROOT_GROUP = { and: [] }

/**
 * A fresh empty root group. The builder appends children in place, so every
 * caller needs its own array — a shallow spread of EMPTY_ROOT_GROUP does not
 * give one, and the first `addLeaf()` would mutate the module constant that
 * every later "no conditions" default is derived from.
 *
 * @return {object} A new `{ and: [] }`.
 *
 * @spec openspec/specs/rule-editor-ui/spec.md
 */
export function emptyRootGroup() {
	return { and: [] }
}

/**
 * Canonical list of rule action types, as stored at the rule's **top-level**
 * `type` property.
 *
 * `EndpointService::handleRuleProcessing()` reads `$ruleData['type']` and
 * dispatches on it through a `match` whose `default` arm throws
 * `Unsupported rule type:` — so adding or renaming an id here without the
 * matching backend arm breaks evaluation of every rule using it.
 *
 * Not the full set the backend accepts: `audit_trail`, `override`, `custom`,
 * `composite_fanout`, `referentienummer`, `avg_bsn_policy`, `selfurl_hal` and
 * `flow` also have match arms but no authoring UI, so they are deliberately
 * not offered. Rules of those types are seeded from configurations, and a rule
 * carrying one keeps it — nothing here rewrites a type that was not picked.
 *
 * The reverse gap also exists, in one place — see UNDISPATCHED_ACTION_TYPES.
 */
export const ACTION_TYPES = [
	{ id: 'error', label: 'Error' },
	{ id: 'mapping', label: 'Mapping' },
	{ id: 'synchronization', label: 'Synchronization' },
	{ id: 'javascript', label: 'JavaScript' },
	{ id: 'authentication', label: 'Authentication' },
	{ id: 'download', label: 'Download' },
	{ id: 'upload', label: 'Upload' },
	{ id: 'locking', label: 'Locking' },
	{ id: 'fetch_file', label: 'Fetch File' },
	{ id: 'write_file', label: 'Write File' },
	{ id: 'fileparts_create', label: 'Fileparts Create' },
	{ id: 'filepart_upload', label: 'Filepart Upload' },
	{ id: 'save_object', label: 'Save object' },
	{ id: 'extend_input', label: 'Extend input' },
	{ id: 'extend_external_input', label: 'Extend external input' },
	{ id: 'webhook_signature', label: 'Webhook signature' },
	{ id: 'approval', label: 'Approval' },
]

/**
 * Action types this UI offers that NO backend pipeline can dispatch.
 *
 * There are two rule pipelines and they accept different sets:
 * `EndpointService::handleRuleProcessing()` for endpoint rules, and
 * `SynchronizationService::processRules()` for synchronization rules (which is
 * where `fetch_file` is handled — it has no endpoint arm). `upload` is in
 * neither, so a rule saved with that type throws
 * `Unsupported rule type: upload` the first time it is reached, even though it
 * has a bespoke form (`actionForms/UploadForm.vue`) and has been offered since
 * the pre-manifest modal.
 *
 * Left in the picker rather than silently dropped: removing it would strand
 * any rule already carrying the type, and the gap is a missing backend
 * handler, not a UI mistake. Tracked by `ruleDraft.spec.js`, which fails the
 * day the arm lands so this list gets trimmed with it.
 */
export const UNDISPATCHED_ACTION_TYPES = ['upload']

/**
 * `timing` options — the pipeline slot the rule runs in.
 *
 * `before` and `after` are the only two values that can ever match:
 * `EndpointService::handleRuleProcessing()` compares
 * `($ruleData['timing'] ?? 'before') === $timing` against a `$timing` that is
 * only ever called with one of those two literals. The `rule` schema's
 * description in `lib/Settings/openconnector_register.json` claims
 * "pre-request, post-request, pre-response, post-response" — that is stale
 * prose, not a vocabulary the engine understands.
 */
export const TIMING_OPTIONS = [
	{ id: 'before', label: 'Before' },
	{ id: 'after', label: 'After' },
]

/**
 * `action` options — the HTTP verb the rule is scoped to. Labels carry the CRUD
 * verb alongside the method because that is how the pre-manifest modal read and
 * how the endpoint docs describe them.
 */
export const ACTION_OPTIONS = [
	{ id: 'post', label: 'Post (Create)' },
	{ id: 'get', label: 'Get (Read)' },
	{ id: 'put', label: 'Put (Update)' },
	{ id: 'delete', label: 'Delete (Delete)' },
]

/**
 * Defaults for a freshly-opened error block. Carried over from the
 * pre-manifest modal, which pre-filled all four rather than leaving the user
 * to guess an HTTP status.
 */
export const DEFAULT_ERROR_CONFIG = {
	code: 500,
	name: 'Something went wrong',
	message: 'We encountered an unexpected problem',
	includeJsonLogicResult: false,
}

/**
 * Build an empty draft for the create case.
 *
 * `order` defaults to 100 to match the `rule` schema default rather than the
 * pre-manifest modal's 0 — rules sort ascending within a timing slot, and 0
 * silently pinned every new rule ahead of every seeded one.
 *
 * `type` defaults to `error` because it is the one action type whose full
 * configuration the modal can author; every other type needs the detail page.
 *
 * @return {object} A fresh rule draft.
 *
 * @spec openspec/specs/rule-editor-ui/spec.md
 */
export function emptyRuleDraft() {
	return {
		name: '',
		description: '',
		conditions: emptyRootGroup(),
		timing: 'before',
		order: 100,
		action: '',
		type: 'error',
		configuration: {},
	}
}

/**
 * Coerce a persisted `conditions` value into the JsonLogic group node the
 * visual builder works with. Accepts:
 *
 *   - null/undefined/empty string → empty AND group
 *   - JSON string (legacy raw-editor text) → parse and recurse
 *   - empty array → empty AND group
 *   - single-element array wrapping a group → unwrap and recurse
 *   - multi-element array of leaves → wrap with AND
 *   - bare leaf object → wrap with AND
 *   - group object (`and`/`or`) → returned as-is
 *
 * Centralising this means the visual builder gets a sane root even from rows
 * saved by the pre-manifest raw-JSON editor, which wrote whatever the textarea
 * happened to parse to.
 *
 * @param {*} raw The persisted conditions value.
 * @return {object} JsonLogic group node — `{ and: [...] }` or `{ or: [...] }`.
 *
 * @spec openspec/specs/rule-editor-ui/spec.md
 */
export function normaliseConditions(raw) {
	if (raw === null || raw === undefined || raw === '') {
		return emptyRootGroup()
	}
	if (typeof raw === 'string') {
		try { return normaliseConditions(JSON.parse(raw)) } catch (_e) { return emptyRootGroup() }
	}
	if (Array.isArray(raw)) {
		if (raw.length === 0) return emptyRootGroup()
		// Single-item array wrapping a group → unwrap and recurse.
		if (raw.length === 1 && raw[0] && typeof raw[0] === 'object' && !Array.isArray(raw[0])) {
			return normaliseConditions(raw[0])
		}
		// Multi-item array of leaves → wrap with AND.
		return { and: raw }
	}
	if (typeof raw === 'object') {
		const keys = Object.keys(raw)
		if (keys.length === 1 && (keys[0] === 'and' || keys[0] === 'or') && Array.isArray(raw[keys[0]])) {
			return raw
		}
		return { and: [raw] }
	}
	return emptyRootGroup()
}

/**
 * Inverse of `normaliseConditions()` for rules: hand back the group node
 * itself, because the `rule` schema types `conditions` as a bare **object**
 * (`{"and": [...]}`) — the canonical shape the visual builder emits and the
 * shape `jwadhams/json-logic-php` evaluates directly.
 *
 * Deliberately NOT the same as `syncDraft.serializeConditions()`, which wraps
 * the group in an array because the `synchronization` schema types the field as
 * `array<object>`. Do not swap one for the other; the schemas disagree.
 *
 * An empty AND/OR group serialises to an empty object so the field reads as
 * "no conditions" on the wire instead of carrying an empty operator.
 *
 * @param {object} group JsonLogic group node from the builder.
 * @return {object} Schema-conformant object for persistence.
 *
 * @spec openspec/specs/rule-editor-ui/spec.md
 */
export function serializeRuleConditions(group) {
	if (!group || typeof group !== 'object' || Array.isArray(group)) return {}
	const keys = Object.keys(group)
	if (keys.length === 1 && (keys[0] === 'and' || keys[0] === 'or')) {
		const children = Array.isArray(group[keys[0]]) ? group[keys[0]] : []
		if (children.length === 0) return {}
	}
	return group
}

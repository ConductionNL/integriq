/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Shared draft/option/condition logic for the two synchronization editing
 * surfaces:
 *
 *   - `views/Synchronization/SynchronizationDetailPage.vue` — the full page
 *   - `modals/v2/SynchronizationEditorModal.vue` — the wide create/edit modal
 *     on the Synchronizations index
 *
 * All of this used to live as module constants and instance methods inside the
 * detail page. It moved here when the modal became a second host: both need the
 * same empty draft, the same kind/mode vocabularies and the same conditions
 * round-trip, and a second copy would drift the moment either is touched.
 *
 * Note `views/Rule/RuleDetailPage.vue` carries a *third* copy of the conditions
 * normaliser — rules and syncs share the JsonLogic shape deliberately, so
 * power-users can paste condition trees between them. Folding that one in is
 * worth doing but is a separate change; it is not imported here so this module
 * stays synchronization-only.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

import { NEXTCLOUD_TABLE_KIND } from './tablesBridge.js'
import { NEXTCLOUD_FORM_KIND } from './formsBridge.js'

/**
 * Default empty root-group used when a synchronization has no conditions (or
 * when the persisted value isn't a recognisable JsonLogic group node).
 * Centralised so the visual builder and the raw-JSON textarea always agree on
 * the round-trip default shape — mirrors the EMPTY_ROOT_GROUP used in
 * RuleDetailPage so power-users can copy/paste between rules and syncs.
 */
export const EMPTY_ROOT_GROUP = { and: [] }

/**
 * Polymorphic type discriminator options shared between source and target.
 * Keep in sync with the `synchronization.sourceType` / `targetType`
 * descriptions in `lib/Settings/openconnector_register.json`.
 */
export const TYPE_OPTIONS = [
	{ id: 'api', label: 'API' },
	{ id: 'register/schema', label: 'Register/Schema' },
	{ id: 'file', label: 'File' },
]

/**
 * Option appended to TYPE_OPTIONS only when the backend reports the Tables app
 * is enabled (tables-bridge REQ-004 / sync-editor-ui REQ-SYNCUI-006).
 */
export const NEXTCLOUD_TABLE_OPTION = { id: NEXTCLOUD_TABLE_KIND, label: 'Nextcloud Table' }

/**
 * Option appended to the SOURCE-only type list when the backend reports the
 * Forms app is enabled (nextcloud-forms-connector REQ-001 / sync-editor-ui
 * REQ-SYNCUI-008). Never offered as a target option — nextcloud-form is a
 * source-only type (nextcloud-forms-connector REQ-002).
 */
export const NEXTCLOUD_FORM_OPTION = { id: NEXTCLOUD_FORM_KIND, label: 'Nextcloud Form' }

/**
 * `syncMode` options (REQ-016, change cdc-incremental-sync). Keep in sync with
 * the `syncMode` enum in `lib/Settings/openconnector_register.json`.
 */
export const SYNC_MODE_OPTIONS = [
	{ id: 'full', label: 'Full' },
	{ id: 'incremental', label: 'Incremental' },
]

/**
 * `sourceConfig.cursorComparator` options (REQ-016). Informational only — the
 * engine always takes the maximum observed `cursorField` value
 * (computeCursorWatermark()), regardless of which comparator is selected.
 */
export const CURSOR_COMPARATOR_OPTIONS = [
	{ id: 'gt', label: 'Greater than (gt)' },
	{ id: 'gte', label: 'Greater than or equal (gte)' },
]

/**
 * Build an empty draft for the create case. The legacy modal defaulted
 * sourceType:'api', targetType:'register/schema' — preserved here so a newly
 * created record without a stored type still renders a sensible config form.
 *
 * @return {object} A fresh, fully-keyed synchronization draft.
 *
 * @spec openspec/specs/sync-editor-ui/spec.md
 */
export function emptyDraft() {
	return {
		name: '',
		description: '',
		sourceType: 'api',
		sourceId: '',
		sourceConfig: {},
		sourceTargetMapping: '',
		sourceHashMapping: '',
		syncMode: 'full',
		targetType: 'register/schema',
		targetId: '',
		targetConfig: {},
		targetSourceMapping: '',
		actions: [],
		followUps: [],
		conditions: { ...EMPTY_ROOT_GROUP },
	}
}

/**
 * Coerce a persisted `conditions` value into the JsonLogic group node the
 * visual builder works with. The register schema types the field as
 * `array<object>`, but records exist carrying a bare string, a bare leaf, or a
 * single-element array wrapping a group — normalise the lot so the UI never
 * renders an inconsistent root. Inverted on save by `serializeConditions()`.
 *
 * @param {*} raw Persisted conditions value.
 * @return {object} JsonLogic group node — `{ and: [...] }` or `{ or: [...] }`.
 *
 * @spec openspec/specs/sync-editor-ui/spec.md
 */
export function normaliseConditions(raw) {
	if (raw === null || raw === undefined || raw === '') {
		return { ...EMPTY_ROOT_GROUP }
	}
	if (typeof raw === 'string') {
		try { return normaliseConditions(JSON.parse(raw)) } catch (_e) { return { ...EMPTY_ROOT_GROUP } }
	}
	if (Array.isArray(raw)) {
		if (raw.length === 0) return { ...EMPTY_ROOT_GROUP }
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
	return { ...EMPTY_ROOT_GROUP }
}

/**
 * Inverse of `normaliseConditions()`: serialise the visual builder's group node
 * into the array-of-objects shape the register schema expects. An empty AND/OR
 * group becomes the empty array so the field stays "unset" on the wire and
 * doesn't carry noise.
 *
 * @param {object} group JsonLogic group node from the builder.
 * @return {Array} Schema-conformant `array<object>` for persistence.
 *
 * @spec openspec/specs/sync-editor-ui/spec.md
 */
export function serializeConditions(group) {
	if (!group || typeof group !== 'object') return []
	const keys = Object.keys(group)
	if (keys.length === 1 && (keys[0] === 'and' || keys[0] === 'or')) {
		const children = Array.isArray(group[keys[0]]) ? group[keys[0]] : []
		if (children.length === 0) return []
	}
	return [group]
}

/**
 * Ask the backend whether a bridge's companion app is enabled for the acting
 * user; only then is its kind offered in the selectors (`nextcloud-table` per
 * tables-bridge REQ-004, `nextcloud-form` per nextcloud-forms-connector
 * REQ-001). Soft-fails to "disabled" so a backend without the endpoint simply
 * never offers the type.
 *
 * @param {'tables'|'forms'} bridge Which bridge to probe.
 * @return {Promise<boolean>} Whether the companion app is enabled.
 *
 * @spec openspec/specs/sync-editor-ui/spec.md
 */
export async function fetchBridgeStatus(bridge) {
	try {
		const response = await axios.get(
			generateUrl(`/apps/openconnector/api/synchronizations/${bridge}-bridge/status`),
		)
		return Boolean(response.data?.enabled)
	} catch (_err) {
		return false
	}
}

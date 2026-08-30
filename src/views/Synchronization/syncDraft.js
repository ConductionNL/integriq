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
 * The conditions normaliser used to exist here AND in
 * `views/Rule/RuleDetailPage.vue` — rules and syncs share the JsonLogic shape
 * deliberately, so power-users can paste condition trees between them. Both
 * copies now come from `views/Rule/ruleDraft.js` and are re-exported below, so
 * every current import path here keeps working. Only `serializeConditions()`
 * stays local: it wraps the group in an array because the `synchronization`
 * schema types `conditions` as `array<object>`, where the `rule` schema wants a
 * bare object. See `serializeRuleConditions()` for that side.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	EMPTY_ROOT_GROUP,
	emptyRootGroup,
	normaliseConditions,
} from '../Rule/ruleDraft.js'
import { NEXTCLOUD_FORM_KIND } from './formsBridge.js'
import { NEXTCLOUD_TABLE_KIND } from './tablesBridge.js'

// Re-exported, not redefined: SynchronizationDetailPage,
// SynchronizationEditorModal and SyncConfigWidget all import these from here.
// `emptyRootGroup()` is the one to reach for when the value is handed to the
// conditions builder — see its docblock in ruleDraft.js.
export { EMPTY_ROOT_GROUP, emptyRootGroup, normaliseConditions }

/**
 * Polymorphic type discriminator options shared between source and target.
 * Keep in sync with the `synchronization.sourceType` / `targetType`
 * descriptions in `lib/Settings/integriq_register.json`.
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
export const NEXTCLOUD_TABLE_OPTION = {
	id: NEXTCLOUD_TABLE_KIND,
	label: 'Nextcloud Table',
}

/**
 * Option appended to the SOURCE-only type list when the backend reports the
 * Forms app is enabled (nextcloud-forms-connector REQ-001 / sync-editor-ui
 * REQ-SYNCUI-008). Never offered as a target option — nextcloud-form is a
 * source-only type (nextcloud-forms-connector REQ-002).
 */
export const NEXTCLOUD_FORM_OPTION = {
	id: NEXTCLOUD_FORM_KIND,
	label: 'Nextcloud Form',
}

/**
 * `syncMode` options (REQ-016, change cdc-incremental-sync). Keep in sync with
 * the `syncMode` enum in `lib/Settings/integriq_register.json`.
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
		conditions: emptyRootGroup(),
	}
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
			generateUrl(
				`/apps/integriq/api/synchronizations/${bridge}-bridge/status`,
			),
		)
		return Boolean(response.data?.enabled)
	} catch (_err) {
		return false
	}
}

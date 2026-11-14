// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { NEXTCLOUD_FORM_KIND } from '../views/Synchronization/formsBridge.js'
import {
	FILE_PICKUP_OPTIONS,
	NEXTCLOUD_FORM_OPTION,
	NEXTCLOUD_TABLE_OPTION,
	SYNC_MODE_OPTIONS,
	TYPE_OPTIONS,
} from '../views/Synchronization/syncDraft.js'
import { NEXTCLOUD_TABLE_KIND } from '../views/Synchronization/tablesBridge.js'

/**
 * Shared source, target and sync-mode selectors of the synchronisation
 * detail page and the synchronisation editor modal. The component provides
 * `draft`, `tablesEnabled` and `formsEnabled`.
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-table-picker-for-the-nextcloud-table-sourcetarget-kind-req-syncui-006
 */
export default {
	computed: {
		/**
		 * Kind options shared by both selectors. The Tables kind is offered
		 * while the Tables app is enabled, and stays visible for a record
		 * that already uses it, so an existing record never loses its type.
		 *
		 * @return {Array<object>} Type options.
		 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-table-picker-for-the-nextcloud-table-sourcetarget-kind-req-syncui-006
		 */
		typeOptions() {
			const usesTable =
				this.draft?.sourceType === NEXTCLOUD_TABLE_KIND
				|| this.draft?.targetType === NEXTCLOUD_TABLE_KIND
			if (this.tablesEnabled || usesTable) {
				return [...TYPE_OPTIONS, NEXTCLOUD_TABLE_OPTION]
			}
			return TYPE_OPTIONS
		},

		/**
		 * Kind options of the source selector only. The Forms kind is a
		 * source-only type, so it is appended here and never to the list the
		 * target selector uses; like the Tables kind it stays visible for a
		 * record that already uses it.
		 *
		 * @return {Array<object>} Source type options.
		 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-form-picker-for-the-nextcloud-form-source-kind-req-syncui-008
		 */
		sourceTypeOptions() {
			const usesForm = this.draft?.sourceType === NEXTCLOUD_FORM_KIND
			const options = [...this.typeOptions]
			if (this.formsEnabled || usesForm) {
				options.push(NEXTCLOUD_FORM_OPTION)
			}
			// A file pickup only reads, so like the Forms kind it is source-only.
			return [...options, ...FILE_PICKUP_OPTIONS]
		},

		/**
		 * @return {object} The selected source kind, the first kind by default.
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		selectedSourceType() {
			return (
				this.sourceTypeOptions.find(
					(opt) => opt.id === this.draft?.sourceType,
				) || TYPE_OPTIONS[0]
			)
		},

		/**
		 * @return {object} The selected target kind, the second kind by default.
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		selectedTargetType() {
			return (
				this.typeOptions.find((opt) => opt.id === this.draft?.targetType)
				|| TYPE_OPTIONS[1]
			)
		},

		/**
		 * @return {Array<object>} The sync mode options.
		 * @spec openspec/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016
		 */
		syncModeOptions() {
			return SYNC_MODE_OPTIONS
		},

		/**
		 * @return {object} The selected sync mode, the first mode by default.
		 * @spec openspec/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016
		 */
		selectedSyncMode() {
			return (
				SYNC_MODE_OPTIONS.find((opt) => opt.id === this.draft?.syncMode)
				|| SYNC_MODE_OPTIONS[0]
			)
		},
	},
}

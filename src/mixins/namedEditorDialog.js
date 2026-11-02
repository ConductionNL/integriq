// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/** A name has to carry at least one letter or digit; punctuation alone is not a name. */
export const NAME_PATTERN = /[\p{L}\p{N}]/u

/**
 * Shared behaviour of the editor dialogs that edit a named draft through
 * CnIndexPage's form-dialog slot (props from editorSlotScopeProps). The
 * component provides `draft.name`, `nameTouched`, `saving` and `seedDraft()`.
 */
export default {
	computed: {
		/**
		 * @return {boolean} True when no row is being edited.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		isCreate() {
			return !this.item
		},

		/**
		 * Required marker on the label. NcTextField/NcInputField has no
		 * `required` prop and renders no marker of its own, so the ` *`
		 * suffix is appended here, the same convention CnFormDialog uses for
		 * schema-required fields.
		 *
		 * @return {string} Label text.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		nameLabel() {
			return this.t('integriq', 'Name') + ' *'
		},

		/**
		 * @return {string} Validation message for the name field, or ''. The
		 *   "required" message waits until the field has been left once.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		nameError() {
			if (!this.draft.name) {
				return this.nameTouched ? this.t('integriq', 'Name is required') : ''
			}
			return NAME_PATTERN.test(this.draft.name)
				? ''
				: this.t(
						'integriq',
						'Name must contain at least one letter or number',
					)
		},
	},

	watch: {
		show: {
			immediate: true,
			/**
			 * Re-seed the draft every time the dialog opens, so a cancelled
			 * edit leaves nothing behind for the next one.
			 *
			 * @param {boolean} value Whether the dialog is now open.
			 *
			 * @spec openspec/specs/mapping-editor-ui/spec.md
			 * @spec openspec/specs/sync-editor-ui/spec.md
			 */
			handler(value) {
				if (value) this.seedDraft()
			},
		},
	},

	methods: {
		/**
		 * Close the dialog unless a save is still running.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		onCancel() {
			if (this.saving) return
			this.close?.()
		},
	},
}

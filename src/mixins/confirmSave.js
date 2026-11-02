// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { showSuccess } from '@nextcloud/dialogs'

/**
 * Save of the editor dialogs that persist through CnIndexPage's form-dialog
 * slot `confirm` binding. The component provides `canSave`, `saving`,
 * `saveError`, and the hooks `savePayload()`, `savedText()` and
 * `saveFailedText()`.
 *
 * @spec openspec/specs/mapping-editor-ui/spec.md
 */
export default {
	methods: {
		/**
		 * Persist through the slot's `confirm` binding, then say so and close;
		 * a failure stays open with its reason.
		 *
		 * @return {Promise<void>} Resolves once the save has settled.
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		async onSave() {
			if (!this.canSave) return
			this.saving = true
			this.saveError = ''
			try {
				await this.confirm(this.savePayload())
				showSuccess(this.savedText())
				this.close?.()
			} catch (err) {
				this.saveError = err?.message || this.saveFailedText()
			} finally {
				this.saving = false
			}
		},
	},
}

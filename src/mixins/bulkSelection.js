// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * bulkSelection: Options-API mixin for the dead-letter pages. It holds the
 * bulk-selection set and the confirmed bulk verb, reloads the list debounced
 * from a filter field, and applies the confirmed verb to the selected ids.
 *
 * The page provides `reload()`, `bulkUrl(verb)` (the path of the bulk
 * endpoint) and `bulkSuccessText(ok)` (the toast after a clean run).
 *
 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-bulk-replay-and-discard-for-sync-item-dead-letters-req-dlr-011
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The toast after a failed bulk request, with the server's or the
 * transport's detail when there is one.
 *
 * @param {object} err The rejection of the bulk request.
 * @return {string} The toast text.
 */
function bulkFailureText(err) {
	const detail = err?.response?.data?.error || err?.message || ''
	return t('integriq', 'Bulk action failed') + (detail ? `: ${detail}` : '')
}

export default {
	data() {
		return {
			selected: [],
			bulkConfirm: null,
			busy: false,
			reloadTimer: null,
		}
	},

	methods: {
		/**
		 * Whether a row is in the bulk-selection set.
		 *
		 * @param {object} row The list row.
		 * @return {boolean} True when selected.
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		isSelected(row) {
			return this.selected.includes(row.uuid || row.id)
		},

		/**
		 * Toggle a row in the bulk-selection set.
		 *
		 * @param {object} row The list row.
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		toggleSelect(row) {
			const id = row.uuid || row.id
			if (this.selected.includes(id)) {
				this.selected = this.selected.filter((x) => x !== id)
			} else {
				this.selected = [...this.selected, id]
			}
		},

		/**
		 * Debounced reload used by the filter field.
		 *
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		reloadDebounced() {
			clearTimeout(this.reloadTimer)
			this.reloadTimer = setTimeout(() => this.reload(), 400)
		},

		/**
		 * Apply the confirmed bulk verb (replay/discard) to the selected ids.
		 *
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-bulk-replay-and-discard-for-sync-item-dead-letters-req-dlr-011
		 */
		async commitBulk() {
			if (!this.bulkConfirm || !this.selected.length) {
				return
			}
			this.busy = true
			try {
				this.reportBulkResults(await this.postBulk(this.bulkConfirm))
				await this.reload()
			} catch (err) {
				showError(bulkFailureText(err))
			} finally {
				this.busy = false
				this.bulkConfirm = null
			}
		},

		/**
		 * Send the selected ids to the bulk endpoint of a verb.
		 *
		 * @param {string} verb The bulk verb, `replay` or `discard`.
		 * @return {Promise<object>} The per-id outcome.
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		async postBulk(verb) {
			const res = await axios.post(generateUrl(this.bulkUrl(verb)), {
				ids: this.selected,
			})
			return res.data?.results || {}
		},

		/**
		 * Toast how many ids the bulk run processed and how many failed.
		 *
		 * @param {object} results The per-id outcome, `ok` for a success.
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		reportBulkResults(results) {
			const ok = Object.values(results).filter((r) => r === 'ok').length
			const failed = Object.keys(results).length - ok
			if (failed > 0) {
				showError(
					t('integriq', '{ok} processed, {failed} failed', { ok, failed }),
				)
			} else {
				showSuccess(this.bulkSuccessText(ok))
			}
		},
	},
}

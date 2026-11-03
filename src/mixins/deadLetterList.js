// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'

/**
 * Shared listing of the dead-letter pages: the rows, the loading flag, the
 * detail modal of one row, and the list request. The page provides the
 * methods `listPath()`, `detailKey()`, `listParams()` (the query of the
 * current filters) and `listFailedText()` (the toast when listing fails).
 *
 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-listing-with-filters-and-pagination-req-dlr-007
 */
export default {
	data() {
		return {
			rows: [],
			loading: false,
		}
	},

	mounted() {
		this.reload()
	},

	methods: {
		/**
		 * Open the detail modal on one row.
		 *
		 * @param {object} row The dead-letter row.
		 * @return {void}
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-ui-in-the-synchronizations-section-req-dlr-012
		 */
		openDetail(row) {
			this.detail = { open: true, [this.detailKey()]: row }
		},

		/**
		 * Close the detail modal.
		 *
		 * @return {void}
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-ui-in-the-synchronizations-section-req-dlr-012
		 */
		closeDetail() {
			this.detail = { open: false, [this.detailKey()]: null }
		},

		/**
		 * Fetch the rows for the current filters from the admin-only
		 * endpoint; the selection is cleared first.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-listing-with-filters-and-pagination-req-dlr-007
		 */
		async reload() {
			this.loading = true
			this.selected = []
			try {
				const res = await axios.get(generateUrl(this.listPath()), {
					params: this.listParams(),
				})
				this.rows = res.data?.results || []
			} catch {
				showError(this.listFailedText())
				this.rows = []
			} finally {
				this.loading = false
			}
		},
	},
}

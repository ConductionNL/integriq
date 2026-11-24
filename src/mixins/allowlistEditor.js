// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * Shared state and requests of an admin allowlist section: a list the
 * backend holds, read on mount, with changes that each reload it. The
 * component provides `load()` (read its list with `fetchList`) and calls
 * `changeList` for every add or remove.
 *
 * @spec openspec/changes/archive/2026-11-24-mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
 */
export default {
	data() {
		return {
			loading: true,
			busy: false,
			error: '',
		}
	},

	/** @spec openspec/changes/archive/2026-11-24-mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005 */
	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the list the backend holds.
		 *
		 * @param {string} url The list's address, before generateUrl.
		 * @param {string} field The answer's field that holds the entries.
		 * @return {Promise<Array|null>} The entries, or null when the read failed
		 *   (the reason is then shown).
		 *
		 * @spec openspec/changes/archive/2026-11-24-mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
		 */
		async fetchList(url, field) {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl(url))
				return Array.isArray(data?.[field]) ? data[field] : []
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('integriq', 'The allowlist could not be loaded.')
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Run one change, say it worked and reload the list; a refusal is shown
		 * as the backend says it.
		 *
		 * @param {function(): Promise<void>} request Makes the change; resolves once it is done.
		 * @param {string} success The translated text shown when it worked.
		 * @param {string} failure The translated text when the backend gave no reason.
		 * @return {Promise<void>} Resolves once the list is reloaded.
		 *
		 * @spec openspec/changes/archive/2026-11-24-mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
		 */
		async changeList(request, success, failure) {
			this.busy = true
			this.error = ''
			try {
				await request()
				showSuccess(success)
				await this.load()
			} catch (error) {
				this.error = error?.response?.data?.error || failure
			} finally {
				this.busy = false
			}
		},
	},
}

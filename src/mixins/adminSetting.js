// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The server's reason for a failed request, or a fallback text.
 *
 * @param {object} e The failed request.
 * @param {string} fallback The translated text when the server gave none.
 * @return {string} The text to show.
 */
function reasonOf(e, fallback) {
	return e?.response?.data?.error || fallback
}

/**
 * Shared load and save of an admin settings section that reads and writes
 * one setting endpoint. The component provides `settingUrl` (computed),
 * `applyStored(data)` (show what the server holds) and `settingBody()`
 * (what to store).
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
 */
export default {
	data() {
		return {
			loading: true,
			busy: false,
			error: '',
		}
	},

	/**
	 * Read the stored setting.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
	 */
	async mounted() {
		try {
			const { data } = await axios.get(generateUrl(this.settingUrl))
			this.applyStored(data)
		} catch (e) {
			this.error = reasonOf(e, t('integriq', 'The setting could not be read.'))
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * PUT the setting.
		 *
		 * @return {Promise<object>} The stored setting the server answers.
		 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
		 */
		async putSetting() {
			const { data } = await axios.put(
				generateUrl(this.settingUrl),
				this.settingBody(),
			)
			return data
		},

		/**
		 * Show what the server kept, and say it was saved.
		 *
		 * @param {object|null} data The stored setting.
		 * @return {void}
		 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
		 */
		applySaved(data) {
			this.applyStored(data)
			showSuccess(t('integriq', 'Saved.'))
		},

		/**
		 * Store the setting and show what the server kept; a refusal shows
		 * the server's reason.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
		 */
		async save() {
			this.busy = true
			this.error = ''
			try {
				this.applySaved(await this.putSetting())
			} catch (e) {
				this.error = reasonOf(
					e,
					t('integriq', 'The setting could not be saved.'),
				)
			} finally {
				this.busy = false
			}
		},
	},
}

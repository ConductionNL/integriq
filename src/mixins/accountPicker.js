// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * accountPicker: Options-API mixin for the account picker of the admin
 * connection editors. It holds the picker's options, busy flag and error,
 * and looks accounts up through Nextcloud's autocomplete.
 *
 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-administrator-chooses-a-webhooks-account
 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-4
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

export default {
	data() {
		return {
			accountOptions: [],
			searchingAccounts: false,
			accountError: '',
		}
	},

	methods: {
		/**
		 * Look up accounts for the picker through Nextcloud's autocomplete.
		 *
		 * @param {string} search The typed text.
		 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-administrator-chooses-a-webhooks-account
		 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-4
		 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
		 */
		async searchAccounts(search) {
			if (!search || search.length < 2) {
				return
			}

			this.searchingAccounts = true
			try {
				const { data } = await axios.get(
					generateOcsUrl('core/autocomplete/get'),
					{
						params: {
							search,
							itemType: '',
							itemId: '',
							shareTypes: [0],
							limit: 10,
						},
					},
				)
				this.accountOptions = (data.ocs.data || []).map((entry) => ({
					id: entry.id,
					user: entry.id,
					displayName: entry.label || entry.id,
				}))
			} catch {
				this.accountError = this.t('integriq', 'Searching accounts failed.')
			} finally {
				this.searchingAccounts = false
			}
		},
	},
}

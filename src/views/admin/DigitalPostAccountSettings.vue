<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="integriq-admin__section" data-testid="admin-digital-post-account">
		<h3>{{ t('integriq', 'Digital post account') }}</h3>
		<p class="integriq-admin__hint">
			{{
				t(
					'integriq',
					'Every letter sent as digital post is stored as this account, also when nobody is signed in. Without a usable account, digital post is refused.',
				)
			}}
		</p>

		<p v-if="loading" class="integriq-admin__hint">
			{{ t('integriq', 'Loading the digital post account…') }}
		</p>

		<template v-else>
			<p
				class="integriq-admin__hint"
				:class="{ 'integriq-admin__action-error': state.refused }"
				data-testid="admin-digital-post-account-state">
				{{ state.text }}
			</p>

			<NcSelectUsers
				v-model="selected"
				:inputLabel="t('integriq', 'Account digital post is stored as')"
				:options="accountOptions"
				:loading="searching"
				@search="search" />
			<p v-if="accountError" class="integriq-admin__action-error" role="alert">
				{{ accountError }}
			</p>

			<div class="integriq-admin__matrix-actions">
				<NcButton
					variant="primary"
					:disabled="saving"
					data-testid="admin-digital-post-account-save"
					@click="save">
					{{ saving ? t('integriq', 'Saving…') : t('integriq', 'Save') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { NcButton, NcSelectUsers } from '@nextcloud/vue'

/**
 * Admin editor for the digital post service account: the account of the
 * one `digital-post` consumer. Saving writes that consumer.
 *
 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
 */
export default {
	name: 'DigitalPostAccountSettings',

	components: {
		NcButton,
		NcSelectUsers,
	},

	data() {
		return {
			loading: true,
			saving: false,
			searching: false,
			account: null,
			selected: null,
			accountOptions: [],
			accountError: '',
		}
	},

	computed: {
		/**
		 * The one-line state of the account.
		 *
		 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
		 */
		state() {
			const account = this.account || { state: 'no_connection' }
			if (account.state === 'ok') {
				return {
					refused: false,
					text: this.t('integriq', 'Digital post is stored as {name}', {
						name: account.displayName,
					}),
				}
			}

			if (!account.userId) {
				return {
					refused: true,
					text: this.t(
						'integriq',
						'No account set: digital post is refused',
					),
				}
			}

			return {
				refused: true,
				text: this.t(
					'integriq',
					'Account {name} is not usable: digital post is refused',
					{ name: account.userId },
				),
			}
		},
	},

	/** @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007 */
	async mounted() {
		try {
			const { data } = await axios.get(
				generateUrl('/apps/integriq/api/admin/digital-post-account'),
			)
			this.apply(data.account)
		} catch {
			this.accountError = this.t(
				'integriq',
				'Failed to load the digital post account.',
			)
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * Show the account as the server describes it.
		 *
		 * @param {object} account The described account.
		 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
		 */
		apply(account) {
			this.account = account
			this.selected = account && account.userId
				? {
						id: account.userId,
						user: account.userId,
						displayName: account.displayName || account.userId,
					}
				: null
		},

		/**
		 * Look up accounts for the picker through Nextcloud's autocomplete.
		 *
		 * @param {string} text The typed text.
		 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
		 */
		async search(text) {
			if (!text || text.length < 2) {
				return
			}

			this.searching = true
			try {
				const { data } = await axios.get(
					generateOcsUrl('core/autocomplete/get'),
					{
						params: {
							search: text,
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
				this.searching = false
			}
		},

		/** @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007 */
		async save() {
			this.saving = true
			this.accountError = ''
			try {
				const { data } = await axios.put(
					generateUrl('/apps/integriq/api/admin/digital-post-account'),
					{ userId: this.selected ? this.selected.id : '' },
				)
				this.apply(data.account)
				showSuccess(this.t('integriq', 'Digital post account saved.'))
				for (const warning of data.warnings || []) {
					showWarning(warning)
				}
			} catch (e) {
				const body = (e.response && e.response.data) || {}
				this.accountError = (body.fieldErrors || {}).userId || ''
				showError(
					Array.isArray(body.errors)
						? body.errors.join(' ')
						: this.t('integriq', 'Failed to save the digital post account.'),
				)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

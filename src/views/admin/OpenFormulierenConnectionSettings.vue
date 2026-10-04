<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="integriq-admin__section" data-testid="admin-openformulieren-section">
		<h3>{{ t('integriq', 'Open Formulieren connection') }}</h3>
		<p class="integriq-admin__hint">
			{{
				t(
					'integriq',
					'Open Formulieren signs every submission with a shared secret. Integriq checks the signature and stores the submission as the account you choose here.',
				)
			}}
		</p>

		<div v-if="error" class="integriq-admin__action-error" role="alert">
			{{ error }}
		</div>

		<p v-if="loading" class="integriq-admin__hint">
			{{ t('integriq', 'Loading the Open Formulieren connection…') }}
		</p>

		<div v-else class="integriq-admin__openformulieren-form">
			<p
				class="integriq-admin__hint"
				:class="{ 'integriq-admin__action-error': accountState.refused }"
				data-testid="admin-openformulieren-account-state">
				{{ accountState.text }}
			</p>

			<NcSelectUsers
				v-model="account"
				:inputLabel="t('integriq', 'Account the intake acts as')"
				:options="accountOptions"
				:loading="searchingAccounts"
				data-testid="admin-openformulieren-account"
				@search="searchAccounts" />
			<p
				v-if="accountError"
				class="integriq-admin__action-error"
				role="alert"
				data-testid="admin-openformulieren-account-error">
				{{ accountError }}
			</p>

			<NcSelect
				v-model="scheme"
				:inputLabel="t('integriq', 'Signature scheme')"
				:options="schemeOptions"
				:reduce="(option) => option.value"
				:clearable="false"
				data-testid="admin-openformulieren-scheme" />

			<label for="openformulieren-secret">{{
				t('integriq', 'Shared secret')
			}}</label>
			<NcPasswordField
				id="openformulieren-secret"
				v-model="secret"
				:placeholder="
					secretConfigured
						? t('integriq', 'Configured (leave blank to keep unchanged)')
						: t('integriq', 'Not configured')
				"
				data-testid="admin-openformulieren-secret" />

			<NcTextField
				v-model="header"
				:label="t('integriq', 'Signature header')"
				data-testid="admin-openformulieren-header" />

			<NcTextField
				v-model="toleranceSeconds"
				type="number"
				:label="t('integriq', 'Allowed clock difference in seconds')"
				data-testid="admin-openformulieren-tolerance" />
		</div>

		<div class="integriq-admin__matrix-actions">
			<NcButton
				variant="primary"
				data-testid="admin-openformulieren-save"
				:disabled="loading || saving"
				@click="save">
				{{
					saving
						? t('integriq', 'Saving…')
						: t('integriq', 'Save Open Formulieren connection')
				}}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcPasswordField,
	NcSelect,
	NcSelectUsers,
	NcTextField,
} from '@nextcloud/vue'

/**
 * Admin editor for the Open Formulieren connection: the instance's one
 * `open-formulieren` consumer. It holds the webhook signature trust and the
 * account every submission is stored as. Mirrors DsoPkiSettings.
 *
 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-4
 */
export default {
	name: 'OpenFormulierenConnectionSettings',

	components: {
		NcButton,
		NcPasswordField,
		NcSelect,
		NcSelectUsers,
		NcTextField,
	},

	data() {
		return {
			loading: true,
			saving: false,
			error: '',
			scheme: 'openconnector',
			secret: '',
			secretConfigured: false,
			header: 'X-OpenFormulieren-Signature',
			toleranceSeconds: '300',
			account: null,
			accountInfo: { state: 'none', displayName: '' },
			accountOptions: [],
			searchingAccounts: false,
			accountError: '',
			schemeOptions: [
				{
					label: this.t('integriq', 'Timestamped HMAC (t=…,v1=…)'),
					value: 'openconnector',
				},
				{ label: this.t('integriq', 'Stripe style HMAC'), value: 'stripe' },
				{
					label: this.t('integriq', 'GitHub style HMAC (sha256=…)'),
					value: 'github',
				},
				{
					label: this.t('integriq', 'Microsoft Teams HMAC'),
					value: 'teams',
				},
			],
		}
	},

	computed: {
		/**
		 * The one-line state of the connection's account.
		 *
		 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-no-account-set-is-shown-plainly
		 */
		accountState() {
			const name = this.accountInfo.displayName
			if (this.accountInfo.state === 'ok') {
				return {
					refused: false,
					text: this.t('integriq', 'Intake acts as {name}', { name }),
				}
			}

			if (this.accountInfo.state === 'none') {
				return {
					refused: true,
					text: this.t(
						'integriq',
						'No account set: Open Formulieren submissions are refused with 503',
					),
				}
			}

			return {
				refused: true,
				text: this.t(
					'integriq',
					'Account {name} is not usable: Open Formulieren submissions are refused with 503',
					{ name },
				),
			}
		},
	},

	/** @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-4 */
	async mounted() {
		await this.load()
	},

	methods: {
		/** @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-4 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(
					generateUrl(
						'/apps/integriq/api/admin/open-formulieren-connection',
					),
				)
				this.scheme = data.scheme || 'openconnector'
				this.secretConfigured = data.secretConfigured === true
				this.header = data.header || 'X-OpenFormulieren-Signature'
				this.toleranceSeconds = String(data.toleranceSeconds || 300)
				this.accountInfo = data.account || { state: 'none', displayName: '' }
				this.account = data.userId
					? {
							id: data.userId,
							user: data.userId,
							displayName: this.accountInfo.displayName || data.userId,
						}
					: null
			} catch {
				this.error = this.t(
					'integriq',
					'Failed to load the Open Formulieren connection.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Look up accounts for the picker through Nextcloud's autocomplete.
		 *
		 * @param {string} search The typed text.
		 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-4
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

		/** @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-4 */
		async save() {
			this.saving = true
			this.error = ''
			this.accountError = ''
			try {
				const { data } = await axios.put(
					generateUrl(
						'/apps/integriq/api/admin/open-formulieren-connection',
					),
					{
						userId: this.account ? this.account.id : '',
						scheme: this.scheme,
						secret: this.secret,
						header: this.header,
						toleranceSeconds: Number(this.toleranceSeconds) || 300,
					},
				)
				this.secret = ''
				await this.load()
				showSuccess(this.t('integriq', 'Open Formulieren connection saved.'))
				for (const warning of data.warnings || []) {
					showWarning(warning)
				}
			} catch (e) {
				const fieldErrors =
					(e.response && e.response.data && e.response.data.fieldErrors)
					|| {}
				this.accountError = fieldErrors.userId || ''
				const errors =
					e.response
					&& e.response.data
					&& Array.isArray(e.response.data.errors)
						? e.response.data.errors.join(' ')
						: null
				this.error =
					errors
					|| this.t(
						'integriq',
						'Failed to save the Open Formulieren connection.',
					)
				showError(this.error)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.integriq-admin__openformulieren-form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 640px;
	margin-bottom: 16px;
}
</style>

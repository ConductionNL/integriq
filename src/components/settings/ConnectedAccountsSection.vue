<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ConnectedAccountsSection: the user's own accounts that integriq's per-user
  - sources call with, in the app's settings dialog (sources-per-user-oauth
  - task 3). Each row names the provider, the account identity the provider
  - returned and when it was connected; Disconnect revokes it in OpenRegister's
  - credential broker, after which the next call answers the connect prompt.
  -
  - @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-user-sees-and-disconnects-their-own-accounts-req-puoa-003
  -->
<template>
	<NcAppSettingsSection id="integriq-connected-accounts" :name="t('integriq', 'Connected accounts')">
		<p class="integriq-connected-accounts__intro">
			{{ t('integriq', 'Sources that call with your own account use these. Disconnect one and your next call asks you to connect it again.') }}
		</p>
		<p v-if="loading" class="integriq-connected-accounts__muted">
			{{ t('integriq', 'Loading your connected accounts …') }}
		</p>
		<p v-else-if="error" class="integriq-connected-accounts__error" role="alert">
			{{ error }}
		</p>
		<p v-else-if="accounts.length === 0" class="integriq-connected-accounts__muted">
			{{ t('integriq', 'You have not connected an account yet.') }}
		</p>
		<ul v-else class="integriq-connected-accounts__list">
			<li v-for="account in accounts" :key="account.id" class="integriq-connected-accounts__row">
				<span class="integriq-connected-accounts__body">
					<span class="integriq-connected-accounts__provider">{{ account.provider }}</span>
					<span class="integriq-connected-accounts__identity">{{ account.identity || t('integriq', 'Account name not given by the provider') }}</span>
					<span v-if="account.connectedOn" class="integriq-connected-accounts__muted">
						{{ t('integriq', 'Connected on {date}', { date: formatDate(account.connectedOn) }) }}
					</span>
				</span>
				<NcButton
					variant="secondary"
					:disabled="busy === account.id"
					:aria-label="t('integriq', 'Disconnect {account}', { account: account.identity || account.provider })"
					@click="disconnect(account)">
					{{ t('integriq', 'Disconnect') }}
				</NcButton>
			</li>
		</ul>
	</NcAppSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcAppSettingsSection, NcButton } from '@nextcloud/vue'
import { disconnectAccount, loadConnectedAccounts } from '../../services/connectedAccounts.js'

export default {
	name: 'ConnectedAccountsSection',
	components: { NcAppSettingsSection, NcButton },

	props: {
		/** An axios-like client; the default is the Nextcloud axios instance. */
		http: {
			type: Object,
			default: () => axios,
		},

		/** Makes an app-relative URL absolute; the default is generateUrl. */
		urlOf: {
			type: Function,
			default: (url) => generateUrl(url),
		},
	},

	data() {
		return { accounts: [], loading: true, error: '', busy: '' }
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the user's connected accounts from the broker.
		 *
		 * @return {Promise<void>} Resolves once loaded.
		 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-user-sees-and-disconnects-their-own-accounts-req-puoa-003
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.accounts = await loadConnectedAccounts(this.http, this.urlOf)
			} catch {
				this.accounts = []
				this.error = t('integriq', 'Your connected accounts could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Disconnect one account and drop it from the list.
		 *
		 * @param {{id: string}} account The account.
		 * @return {Promise<void>} Resolves once the broker answered.
		 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-user-sees-and-disconnects-their-own-accounts-req-puoa-003
		 */
		async disconnect(account) {
			this.busy = account.id
			this.error = ''
			try {
				await disconnectAccount(this.http, account.id, this.urlOf)
				this.accounts = this.accounts.filter((a) => a.id !== account.id)
			} catch {
				this.error = t('integriq', 'The account could not be disconnected. Try again.')
			} finally {
				this.busy = ''
			}
		},

		/**
		 * A date the reader reads in their own locale.
		 *
		 * @param {string} value An ISO date-time.
		 * @return {string} The date, or the value as given when it is not a date.
		 */
		formatDate(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.integriq-connected-accounts__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.integriq-connected-accounts__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.integriq-connected-accounts__body {
	display: flex;
	flex-direction: column;
}

.integriq-connected-accounts__provider {
	font-weight: bold;
}

.integriq-connected-accounts__muted {
	color: var(--color-text-maxcontrast);
}

.integriq-connected-accounts__error {
	color: var(--color-error-text);
}
</style>

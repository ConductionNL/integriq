<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div
		class="integriq-admin__webhook-row"
		:data-testid="'admin-webhook-' + connection.authorizationType">
		<h4>{{ connection.label }}</h4>
		<p
			class="integriq-admin__hint"
			:class="{ 'integriq-admin__action-error': accountState.refused }"
			:data-testid="'admin-webhook-state-' + connection.authorizationType">
			{{ accountState.text }}
		</p>

		<NcNoteCard
			v-if="connection.handlerGroup && connection.handlerGroup.empty"
			type="warning"
			:data-testid="'admin-webhook-handlers-empty-' + connection.authorizationType">
			{{
				t(
					'integriq',
					'Nobody can read what this webhook stores yet. Add handlers to the group {group} under Accounts.',
					{ group: connection.handlerGroup.id },
				)
			}}
		</NcNoteCard>

		<NcSelectUsers
			v-model="account"
			:inputLabel="t('integriq', 'Account the webhook acts as')"
			:options="accountOptions"
			:loading="searchingAccounts"
			@search="searchAccounts" />
		<p v-if="accountError" class="integriq-admin__action-error" role="alert">
			{{ accountError }}
		</p>

		<NcSelect
			v-model="scheme"
			:inputLabel="t('integriq', 'Signature scheme')"
			:options="schemeOptions"
			:reduce="(option) => option.value"
			:clearable="false" />

		<NcPasswordField
			v-model="secret"
			:label="t('integriq', 'Shared secret')"
			:placeholder="
				connection.secretConfigured
					? t('integriq', 'Configured (leave blank to keep unchanged)')
					: t('integriq', 'Not configured')
			" />

		<NcTextField v-model="header" :label="t('integriq', 'Signature header')" />

		<div class="integriq-admin__matrix-actions">
			<NcButton
				variant="primary"
				:disabled="saving"
				:data-testid="'admin-webhook-save-' + connection.authorizationType"
				@click="save">
				{{ saving ? t('integriq', 'Saving…') : t('integriq', 'Save') }}
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
	NcNoteCard,
	NcPasswordField,
	NcSelect,
	NcSelectUsers,
	NcTextField,
} from '@nextcloud/vue'

/**
 * One signed public webhook's connection: its account and its signature
 * trust. Saving writes the webhook's consumer.
 *
 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-administrator-chooses-a-webhooks-account
 */
export default {
	name: 'WebhookConnectionRow',

	components: {
		NcButton,
		NcNoteCard,
		NcPasswordField,
		NcSelect,
		NcSelectUsers,
		NcTextField,
	},

	props: {
		connection: {
			type: Object,
			required: true,
		},
	},

	emits: ['saved'],

	data() {
		return {
			saving: false,
			scheme: this.connection.scheme || 'openconnector',
			secret: '',
			header: this.connection.header || 'X-OpenConnector-Signature',
			account: this.connection.userId
				? {
						id: this.connection.userId,
						user: this.connection.userId,
						displayName:
							this.connection.account.displayName
							|| this.connection.userId,
					}
				: null,

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
		 * The one-line state of the webhook's account.
		 *
		 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-the-settings-list-every-webhook-without-its-secret
		 */
		accountState() {
			const state = this.connection.account.state
			const name = this.connection.account.displayName
			if (state === 'ok') {
				return {
					refused: false,
					text: this.t('integriq', 'Deliveries are stored as {name}', {
						name,
					}),
				}
			}

			if (state === 'none') {
				return {
					refused: true,
					text: this.t(
						'integriq',
						'No account set: deliveries are refused with 503',
					),
				}
			}

			return {
				refused: true,
				text: this.t(
					'integriq',
					'Account {name} is not usable: deliveries are refused with 503',
					{ name },
				),
			}
		},
	},

	methods: {
		/**
		 * Look up accounts for the picker through Nextcloud's autocomplete.
		 *
		 * @param {string} search The typed text.
		 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-administrator-chooses-a-webhooks-account
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

		/** @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-administrator-chooses-a-webhooks-account */
		async save() {
			this.saving = true
			this.accountError = ''
			try {
				const { data } = await axios.put(
					generateUrl(
						'/apps/integriq/api/admin/webhook-connections/{type}',
						{ type: this.connection.authorizationType },
					),
					{
						userId: this.account ? this.account.id : '',
						scheme: this.scheme,
						secret: this.secret,
						header: this.header,
					},
				)
				this.secret = ''
				showSuccess(
					this.t('integriq', '{label} connection saved.', {
						label: this.connection.label,
					}),
				)
				for (const warning of data.warnings || []) {
					showWarning(warning)
				}
				this.$emit('saved', data.connection)
			} catch (e) {
				const body = (e.response && e.response.data) || {}
				this.accountError = (body.fieldErrors || {}).userId || ''
				showError(
					Array.isArray(body.errors)
						? body.errors.join(' ')
						: this.t(
								'integriq',
								'Failed to save the {label} connection.',
								{
									label: this.connection.label,
								},
							),
				)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.integriq-admin__webhook-row {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 640px;
	padding-block: 12px;
	border-bottom: 1px solid var(--color-border);
}
</style>

<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="integriq-admin__section" data-testid="admin-dso-pki-section">
		<h3>{{ t('integriq', 'DSO STAM webhook signature verification') }}</h3>
		<p class="integriq-admin__hint">
			{{
				t(
					'integriq',
					'Configure how inbound DSO-LV STAM webhook requests are cryptographically verified. HMAC uses a shared secret (pre-production); PKIoverheid uses a certificate chain (production).',
				)
			}}
		</p>

		<div v-if="error" class="integriq-admin__action-error" role="alert">
			{{ error }}
		</div>

		<p v-if="loading" class="integriq-admin__hint">
			{{ t('integriq', 'Loading DSO signature configuration…') }}
		</p>

		<div v-else class="integriq-admin__dso-pki-form">
			<p
				class="integriq-admin__hint"
				:class="{ 'integriq-admin__action-error': accountState.refused }"
				data-testid="admin-dso-account-state">
				{{ accountState.text }}
			</p>

			<NcNoteCard
				v-if="handlerGroup.empty"
				type="warning"
				data-testid="admin-dso-handlers-empty">
				{{
					t(
						'integriq',
						'Nobody can read the DSO requests yet. Add handlers to the group {group} under Accounts.',
						{ group: handlerGroup.id },
					)
				}}
			</NcNoteCard>

			<NcSelectUsers
				v-model="account"
				:inputLabel="t('integriq', 'Account the intake acts as')"
				:options="accountOptions"
				:loading="searchingAccounts"
				data-testid="admin-dso-account"
				@search="searchAccounts" />
			<p
				v-if="accountError"
				class="integriq-admin__action-error"
				role="alert"
				data-testid="admin-dso-account-error">
				{{ accountError }}
			</p>

			<NcSelect
				v-model="mode"
				:inputLabel="t('integriq', 'Signing mode')"
				:options="modeOptions"
				:reduce="(option) => option.value"
				:clearable="false"
				data-testid="admin-dso-pki-mode" />

			<template v-if="mode === 'hmac'">
				<label for="dso-pki-hmac-secret">{{
					t('integriq', 'HMAC shared secret')
				}}</label>
				<NcPasswordField
					id="dso-pki-hmac-secret"
					v-model="hmacSecret"
					:placeholder="
						hmacSecretConfigured
							? t(
									'integriq',
									'Configured (leave blank to keep unchanged)',
								)
							: t('integriq', 'Not configured')
					"
					data-testid="admin-dso-pki-hmac-secret" />
			</template>

			<template v-else>
				<label for="dso-pki-signing-cert">{{
					t('integriq', 'Signing certificate (PEM)')
				}}</label>
				<textarea
					id="dso-pki-signing-cert"
					v-model="signingCertificate"
					class="integriq-admin__dso-pki-textarea"
					data-testid="admin-dso-pki-signing-cert" />

				<label for="dso-pki-intermediate-chain">{{
					t('integriq', 'Intermediate certificate chain (PEM, optional)')
				}}</label>
				<textarea
					id="dso-pki-intermediate-chain"
					v-model="intermediateChain"
					class="integriq-admin__dso-pki-textarea"
					data-testid="admin-dso-pki-intermediate-chain" />

				<label for="dso-pki-root-ca">{{
					t(
						'integriq',
						'Trusted root CA (PKIoverheid Private Root CA, PEM)',
					)
				}}</label>
				<textarea
					id="dso-pki-root-ca"
					v-model="rootCa"
					class="integriq-admin__dso-pki-textarea"
					data-testid="admin-dso-pki-root-ca" />
			</template>
		</div>

		<div class="integriq-admin__matrix-actions">
			<NcButton
				variant="primary"
				data-testid="admin-dso-pki-save"
				:disabled="loading || saving"
				@click="save">
				{{
					saving
						? t('integriq', 'Saving…')
						: t('integriq', 'Save DSO signature configuration')
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
	NcNoteCard,
	NcPasswordField,
	NcSelect,
	NcSelectUsers,
} from '@nextcloud/vue'

/**
 * Admin editor for the DSO connection: the instance's one `dso-stam`
 * consumer. It holds the STAM signature trust (HMAC or PKIoverheid chain)
 * and the account every DSO-LV push is stored as.
 *
 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-2
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
 */
export default {
	name: 'DsoPkiSettings',

	components: {
		NcButton,
		NcNoteCard,
		NcPasswordField,
		NcSelect,
		NcSelectUsers,
	},

	data() {
		return {
			loading: true,
			saving: false,
			error: '',
			mode: 'hmac',
			hmacSecret: '',
			hmacSecretConfigured: false,
			signingCertificate: '',
			intermediateChain: '',
			rootCa: '',
			account: null,
			accountInfo: { state: 'none', displayName: '' },
			handlerGroup: { id: '', empty: false },
			accountOptions: [],
			searchingAccounts: false,
			accountError: '',
			modeOptions: [
				{
					label: this.t('integriq', 'HMAC shared secret (pre-production)'),

					value: 'hmac',
				},
				{
					label: this.t(
						'integriq',
						'PKIoverheid certificate chain (production)',
					),

					value: 'pkioverheid',
				},
			],
		}
	},

	computed: {
		/**
		 * The one-line state of the connection's account.
		 *
		 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-no-account-set-is-shown-plainly
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
						'No account set: DSO-LV pushes are refused with 503',
					),
				}
			}

			return {
				refused: true,
				text: this.t(
					'integriq',
					'Account {name} is not usable: DSO-LV pushes are refused with 503',
					{ name },
				),
			}
		},
	},

	/** @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-2 */
	async mounted() {
		await this.load()
	},

	methods: {
		/** @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-2 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(
					generateUrl('/apps/integriq/api/admin/dso-pki-config'),
				)
				this.mode = data.mode === 'pkioverheid' ? 'pkioverheid' : 'hmac'
				this.hmacSecretConfigured = data.hmacSecretConfigured === true
				this.signingCertificate = data.signingCertificate || ''
				this.intermediateChain = data.intermediateChain || ''
				this.rootCa = data.rootCa || ''
				this.handlerGroup = data.handlerGroup || { id: '', empty: false }
				this.accountInfo = data.account || {
					state: 'none',
					displayName: '',
				}
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
					'Failed to load the DSO signature configuration.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Look up accounts for the picker through Nextcloud's autocomplete.
		 *
		 * @param {string} search The typed text.
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

		/** @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-2 */
		async save() {
			this.saving = true
			this.error = ''
			this.accountError = ''
			try {
				const { data } = await axios.put(
					generateUrl('/apps/integriq/api/admin/dso-pki-config'),
					{
						userId: this.account ? this.account.id : '',
						mode: this.mode,
						hmacSecret: this.hmacSecret,
						signingCertificate: this.signingCertificate,
						intermediateChain: this.intermediateChain,
						rootCa: this.rootCa,
					},
				)
				this.hmacSecret = ''
				await this.load()
				showSuccess(this.t('integriq', 'DSO signature configuration saved.'))
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
						'Failed to save the DSO signature configuration.',
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
.integriq-admin__dso-pki-form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 640px;
	margin-bottom: 16px;
}

.integriq-admin__dso-pki-textarea {
	width: 100%;
	min-height: 120px;
	font-family: monospace;
	color: var(--color-main-text);
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
}
</style>

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
import {
	NcButton,
	NcNoteCard,
	NcPasswordField,
	NcSelect,
	NcSelectUsers,
} from '@nextcloud/vue'
import accountPicker from '../../mixins/accountPicker.js'
import intakeConnectionEditor from '../../mixins/intakeConnectionEditor.js'

/**
 * The signature trust modes an administrator chooses between.
 *
 * @param {(app: string, text: string) => string} t the translate function
 * @return {Array<{label: string, value: string}>} the select options
 */
function modeOptionList(t) {
	return [
		{
			label: t('integriq', 'HMAC shared secret (pre-production)'),

			value: 'hmac',
		},
		{
			label: t('integriq', 'PKIoverheid certificate chain (production)'),

			value: 'pkioverheid',
		},
	]
}

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

	mixins: [accountPicker, intakeConnectionEditor],

	data() {
		return {
			settingsPath: '/apps/integriq/api/admin/dso-pki-config',
			mode: 'hmac',
			hmacSecret: '',
			hmacSecretConfigured: false,
			signingCertificate: '',
			intermediateChain: '',
			rootCa: '',
			modeOptions: modeOptionList(this.t),
		}
	},

	methods: {
		/**
		 * Show the stored signature trust.
		 *
		 * @param {object} data the stored connection
		 * @return {void}
		 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-2
		 */
		applySettings(data) {
			this.mode = data.mode === 'pkioverheid' ? 'pkioverheid' : 'hmac'
			this.hmacSecretConfigured = data.hmacSecretConfigured === true
			this.signingCertificate = data.signingCertificate || ''
			this.intermediateChain = data.intermediateChain || ''
			this.rootCa = data.rootCa || ''
		},

		/**
		 * The signature trust to store, besides the account.
		 *
		 * @return {object} the request fields
		 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-2
		 */
		settingsBody() {
			return {
				mode: this.mode,
				hmacSecret: this.hmacSecret,
				signingCertificate: this.signingCertificate,
				intermediateChain: this.intermediateChain,
				rootCa: this.rootCa,
			}
		},

		/**
		 * Forget the typed secret once it is stored.
		 *
		 * @return {void}
		 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-2
		 */
		clearSecret() {
			this.hmacSecret = ''
		},

		/**
		 * The text of a failed load.
		 *
		 * @return {string}
		 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-2
		 */
		loadFailedText() {
			return this.t(
				'integriq',
				'Failed to load the DSO signature configuration.',
			)
		},

		/**
		 * The text of a failed save.
		 *
		 * @return {string}
		 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-2
		 */
		saveFailedText() {
			return this.t(
				'integriq',
				'Failed to save the DSO signature configuration.',
			)
		},

		/**
		 * The text of a successful save.
		 *
		 * @return {string}
		 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-2
		 */
		savedText() {
			return this.t('integriq', 'DSO signature configuration saved.')
		},

		/**
		 * The account state when none is set.
		 *
		 * @return {string}
		 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-no-account-set-is-shown-plainly
		 */
		noAccountText() {
			return this.t(
				'integriq',
				'No account set: DSO-LV pushes are refused with 503',
			)
		},

		/**
		 * The account state when the set account is not usable.
		 *
		 * @param {string} name the account's display name
		 * @return {string}
		 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-no-account-set-is-shown-plainly
		 */
		refusalText(name) {
			return this.t(
				'integriq',
				'Account {name} is not usable: DSO-LV pushes are refused with 503',
				{ name },
			)
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

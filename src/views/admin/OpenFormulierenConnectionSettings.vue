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

			<NcNoteCard
				v-if="handlerGroup.empty"
				type="warning"
				data-testid="admin-openformulieren-handlers-empty">
				{{
					t(
						'integriq',
						'Nobody can read the submissions yet. Add handlers to the group {group} under Accounts.',
						{ group: handlerGroup.id },
					)
				}}
			</NcNoteCard>

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
import {
	NcButton,
	NcNoteCard,
	NcPasswordField,
	NcSelect,
	NcSelectUsers,
	NcTextField,
} from '@nextcloud/vue'
import accountPicker from '../../mixins/accountPicker.js'
import intakeConnectionEditor from '../../mixins/intakeConnectionEditor.js'

/**
 * The webhook signature schemes an administrator chooses between.
 *
 * @param {(app: string, text: string) => string} t the translate function
 * @return {Array<{label: string, value: string}>} the select options
 */
function schemeOptionList(t) {
	return [
		{
			label: t('integriq', 'Timestamped HMAC (t=…,v1=…)'),
			value: 'openconnector',
		},
		{ label: t('integriq', 'Stripe style HMAC'), value: 'stripe' },
		{ label: t('integriq', 'GitHub style HMAC (sha256=…)'), value: 'github' },
		{ label: t('integriq', 'Microsoft Teams HMAC'), value: 'teams' },
	]
}

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
		NcNoteCard,
		NcPasswordField,
		NcSelect,
		NcSelectUsers,
		NcTextField,
	},

	mixins: [accountPicker, intakeConnectionEditor],

	data() {
		return {
			settingsPath: '/apps/integriq/api/admin/open-formulieren-connection',
			scheme: 'openconnector',
			secret: '',
			secretConfigured: false,
			header: 'X-OpenFormulieren-Signature',
			toleranceSeconds: '300',
			schemeOptions: schemeOptionList(this.t),
		}
	},

	methods: {
		/**
		 * Show the stored signature trust.
		 *
		 * @param {object} data the stored connection
		 * @return {void}
		 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-4
		 */
		applySettings(data) {
			this.scheme = data.scheme || 'openconnector'
			this.secretConfigured = data.secretConfigured === true
			this.header = data.header || 'X-OpenFormulieren-Signature'
			this.toleranceSeconds = String(data.toleranceSeconds || 300)
		},

		/**
		 * The signature trust to store, besides the account.
		 *
		 * @return {object} the request fields
		 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-4
		 */
		settingsBody() {
			return {
				scheme: this.scheme,
				secret: this.secret,
				header: this.header,
				toleranceSeconds: Number(this.toleranceSeconds) || 300,
			}
		},

		/**
		 * Forget the typed secret once it is stored.
		 *
		 * @return {void}
		 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-4
		 */
		clearSecret() {
			this.secret = ''
		},

		/**
		 * The text of a failed load.
		 *
		 * @return {string}
		 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-4
		 */
		loadFailedText() {
			return this.t(
				'integriq',
				'Failed to load the Open Formulieren connection.',
			)
		},

		/**
		 * The text of a failed save.
		 *
		 * @return {string}
		 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-4
		 */
		saveFailedText() {
			return this.t(
				'integriq',
				'Failed to save the Open Formulieren connection.',
			)
		},

		/**
		 * The text of a successful save.
		 *
		 * @return {string}
		 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-4
		 */
		savedText() {
			return this.t('integriq', 'Open Formulieren connection saved.')
		},

		/**
		 * The account state when none is set.
		 *
		 * @return {string}
		 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-no-account-set-is-shown-plainly
		 */
		noAccountText() {
			return this.t(
				'integriq',
				'No account set: Open Formulieren submissions are refused with 503',
			)
		},

		/**
		 * The account state when the set account is not usable.
		 *
		 * @param {string} name the account's display name
		 * @return {string}
		 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-no-account-set-is-shown-plainly
		 */
		refusalText(name) {
			return this.t(
				'integriq',
				'Account {name} is not usable: Open Formulieren submissions are refused with 503',
				{ name },
			)
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

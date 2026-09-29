<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
  BrokerConnectionFields: where a broker subscription publishes to, and with
  which credential. Writes `protocolSettings.broker` as base URL, virtual host
  (RabbitMQ), username and a `credentialRef`. There is no password or token
  field: the secret stays in the OpenRegister credential broker and integriq
  resolves the reference when it publishes.

  When the subscription still stores a password or token from before, the
  app's own subscription endpoint returns it masked; this component reads that
  and says so, and saving with a credential picked drops the stored secret.

  @spec openspec/specs/events-cloudevents/spec.md#requirement-broker-credentials-are-a-credential-reference-resolved-at-publish-req-ebsc-003
  @spec openspec/specs/events-cloudevents/spec.md#requirement-stored-broker-secrets-are-masked-on-the-apps-subscription-endpoints-req-ebsc-004
-->
<template>
	<div class="cn-broker-connection-fields">
		<NcTextField
			:label="t('integriq', 'Broker address')"
			:modelValue="connection.baseUrl || ''"
			type="url"
			:helperText="
				t(
					'integriq',
					'The base URL of the broker\'s HTTP interface, for example the RabbitMQ management API or the Kafka REST Proxy.',
				)
			"
			@update:modelValue="(value) => onConnectionField('baseUrl', value)" />
		<NcTextField
			v-if="brokerId === 'rabbitmq'"
			:label="t('integriq', 'Virtual host')"
			:modelValue="connection.vhost || ''"
			:helperText="t('integriq', 'Leave empty for the default virtual host.')"
			@update:modelValue="(value) => onConnectionField('vhost', value)" />
		<NcTextField
			:label="t('integriq', 'Username')"
			:modelValue="connection.username || ''"
			:helperText="
				t(
					'integriq',
					'With a username the credential is sent as the password. Without one it is sent as a bearer token.',
				)
			"
			@update:modelValue="(value) => onConnectionField('username', value)" />

		<NcSelect
			inputId="cn-broker-credential"
			:inputLabel="t('integriq', 'Credential')"
			:aria-label-combobox="t('integriq', 'Credential')"
			:modelValue="selectedCredential"
			:options="credentialOptions"
			:loading="credentialsLoading"
			:placeholder="t('integriq', 'Select a credential')"
			@update:modelValue="onCredentialPick" />
		<span class="cn-broker-connection-fields__helper">
			{{
				t(
					'integriq',
					'The password or token is kept by the OpenRegister credential broker and read when an event is published. It is never stored on the subscription.',
				)
			}}
		</span>
		<span
			v-if="credentialsUnavailable"
			class="cn-broker-connection-fields__helper">
			{{
				t(
					'integriq',
					'The OpenRegister credential broker is not available, so no credentials can be listed.',
				)
			}}
		</span>
		<NcNoteCard v-if="storedSecret" type="warning">
			{{
				t(
					'integriq',
					'A password is stored on this subscription. Pick a credential to replace it; saving then removes the stored password.',
				)
			}}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import {
	extractCredentialResults,
	mapCredentialOptions,
} from '../v2/sourceCredentialRef.js'
import {
	buildBrokerSettings,
	readBrokerConnection,
	storesBrokerSecret,
} from './brokerFields.js'

export default {
	name: 'BrokerConnectionFields',

	components: {
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The subscription form data owned by CnFormDialog. */
		formData: { type: Object, default: () => ({}) },
		/** The dialog's mutator, `(key, value)`. */
		updateField: { type: Function, required: true },
		/** The picked broker id. */
		brokerId: { type: String, default: null },
	},

	data() {
		return {
			credentialOptions: [],
			credentialsLoading: false,
			credentialsUnavailable: false,
			storedSecret: false,
		}
	},

	computed: {
		/**
		 * The broker connection as the form holds it.
		 *
		 * @return {object}
		 * @spec openspec/specs/events-cloudevents/spec.md#requirement-broker-credentials-are-a-credential-reference-resolved-at-publish-req-ebsc-003
		 */
		connection() {
			return readBrokerConnection(this.formData)
		},

		/**
		 * The picked credential, or a stand-in naming its id when the list does not hold it.
		 *
		 * @return {object|null}
		 * @spec openspec/specs/events-cloudevents/spec.md#requirement-broker-credentials-are-a-credential-reference-resolved-at-publish-req-ebsc-003
		 */
		selectedCredential() {
			const id = this.connection.credentialRef?.credentialId
			if (!id) return null
			return (
				this.credentialOptions.find((option) => option.id === id) || {
					id,
					label: id,
				}
			)
		},
	},

	/**
	 * Load the credentials, and on an existing subscription ask the app whether a secret is stored.
	 *
	 * @return {void}
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-stored-broker-secrets-are-masked-on-the-apps-subscription-endpoints-req-ebsc-004
	 */
	created() {
		this.fetchCredentials()
		this.checkStoredSecret()
	},

	methods: {
		t,

		/**
		 * Write one connection field.
		 *
		 * @param {string} key The field.
		 * @param {string} value Its new value.
		 * @return {void}
		 * @spec openspec/specs/events-cloudevents/spec.md#requirement-broker-credentials-are-a-credential-reference-resolved-at-publish-req-ebsc-003
		 */
		onConnectionField(key, value) {
			this.updateField(
				'protocolSettings',
				buildBrokerSettings(
					this.formData?.protocolSettings,
					{ [key]: value },
					this.brokerId,
				),
			)
		},

		/**
		 * Write the picked credential as a reference.
		 *
		 * @param {object|null} option The picked credential.
		 * @return {void}
		 * @spec openspec/specs/events-cloudevents/spec.md#requirement-broker-credentials-are-a-credential-reference-resolved-at-publish-req-ebsc-003
		 */
		onCredentialPick(option) {
			this.updateField(
				'protocolSettings',
				buildBrokerSettings(
					this.formData?.protocolSettings,
					{
						credentialRef: option?.id
							? { credentialId: String(option.id) }
							: null,
					},
					this.brokerId,
				),
			)
		},

		/**
		 * List the signed-in user's credentials from the OpenRegister credential broker.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/events-cloudevents/spec.md#requirement-broker-credentials-are-a-credential-reference-resolved-at-publish-req-ebsc-003
		 */
		async fetchCredentials() {
			this.credentialsLoading = true
			this.credentialsUnavailable = false
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/credentials'),
				)
				this.credentialOptions = mapCredentialOptions(
					extractCredentialResults(response.data),
				)
			} catch (err) {
				this.credentialsUnavailable = true
				this.credentialOptions = []
				// eslint-disable-next-line no-console
				console.warn('[BrokerConnectionFields] credential fetch failed', err)
			} finally {
				this.credentialsLoading = false
			}
		},

		/**
		 * Ask the app's subscription endpoint, which masks secrets, whether this
		 * subscription still stores a broker password or token. The generic
		 * object API the form reads through never returns protocolSettings.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/events-cloudevents/spec.md#requirement-stored-broker-secrets-are-masked-on-the-apps-subscription-endpoints-req-ebsc-004
		 */
		async checkStoredSecret() {
			const id =
				this.formData?.id
				|| this.formData?.uuid
				|| this.formData?.['@self']?.id
			if (!id) return
			try {
				const response = await axios.get(
					generateUrl('/apps/integriq/api/events/subscriptions'),
					{ params: { uuid: String(id) } },
				)
				const row = Array.isArray(response.data?.results)
					? response.data.results[0]
					: null
				this.storedSecret = storesBrokerSecret(row?.protocolSettings?.broker)
			} catch {
				this.storedSecret = false
			}
		},
	},
}
</script>

<style scoped>
.cn-broker-connection-fields {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.cn-broker-connection-fields__helper {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>

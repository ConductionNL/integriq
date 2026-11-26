<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  CredentialKeysPanel: the credentials behind one credential route. The
  developer portal shows it for an application the developer owns
  (/api/portal/applications/{id}/keys); the consumer detail page shows it for
  an administrator (/api/consumers/{id}/credentials), with the expiry of a
  new key and the client certificates pinned on the consumer.

  A generated key is in the create response only, so it is shown here once,
  with a copy button, until the panel is reloaded or another key is
  generated. The list shows each key by its label and first characters, with
  when it was last used, so two keys can live side by side while a partner
  switches to the new one.

  @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
  @spec openspec/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
-->
<template>
	<div class="credentialKeys" data-testid="credential-keys">
		<h5>{{ t('integriq', 'API keys') }}</h5>

		<p v-if="loadError" class="credentialKeys__error" role="alert">
			{{ loadError }}
		</p>
		<p v-else-if="!loading && !apiKeys.length" class="credentialKeys__muted">
			{{ t('integriq', 'There are no API keys yet.') }}
		</p>

		<ul class="credentialKeys__list">
			<li
				v-for="key in apiKeys"
				:key="key.id"
				class="credentialKeys__item"
				data-testid="credential-key">
				<span>
					<strong>{{ key.label }}</strong>
					<code>{{ key.keyPrefix }}…</code>
					<span class="credentialKeys__muted">{{ usedText(key) }}</span>
					<span v-if="key.expiresAt" class="credentialKeys__muted">{{ t('integriq', 'valid until {date}', { date: key.expiresAt }) }}</span>
					<span v-if="key.expired" class="credentialKeys__muted">{{ t('integriq', 'expired') }}</span>
				</span>
				<NcButton
					variant="tertiary"
					data-testid="credential-revoke"
					:disabled="busy"
					@click="revoke(key)">
					{{ t('integriq', 'Revoke') }}
				</NcButton>
			</li>
		</ul>

		<div
			v-if="newKey"
			class="credentialKeys__new"
			data-testid="credential-new-key">
			<p>
				{{ t('integriq', 'This is the only time the key is shown. Copy it now and store it in your system.') }}
			</p>
			<code class="credentialKeys__key">{{ newKey }}</code>
			<NcButton data-testid="credential-copy-key" @click="copyKey">
				{{ copied ? t('integriq', 'Copied') : t('integriq', 'Copy key') }}
			</NcButton>
		</div>

		<div class="credentialKeys__row">
			<NcTextField
				v-model="label"
				data-testid="credential-key-label"
				:label="t('integriq', 'Key label')"
				@keyup.enter="generate" />
			<NcTextField
				v-if="administrator"
				v-model="expiresAt"
				data-testid="credential-key-expiry"
				:label="t('integriq', 'Valid until (optional)')"
				placeholder="2027-12-31T23:59:59+01:00" />
			<NcButton
				data-testid="credential-generate-key"
				:disabled="busy || !label.trim()"
				@click="generate">
				{{ t('integriq', 'Generate key') }}
			</NcButton>
		</div>

		<template v-if="administrator">
			<h5>{{ t('integriq', 'Client certificates') }}</h5>
			<p v-if="!loading && !certificates.length" class="credentialKeys__muted">
				{{ t('integriq', 'No client certificate is pinned on this consumer.') }}
			</p>
			<ul class="credentialKeys__list">
				<li
					v-for="certificate in certificates"
					:key="certificate.id"
					class="credentialKeys__item"
					data-testid="credential-certificate">
					<span>
						<strong>{{ certificate.label }}</strong>
						<code v-if="certificate.fingerprint">{{ certificate.fingerprint }}</code>
						<span v-if="certificate.subjectPattern" class="credentialKeys__muted">{{ certificate.subjectPattern }}</span>
						<span v-if="certificate.oin" class="credentialKeys__muted">{{ t('integriq', 'OIN {oin}', { oin: certificate.oin }) }}</span>
					</span>
					<NcButton
						variant="tertiary"
						data-testid="credential-revoke"
						:disabled="busy"
						@click="revoke(certificate)">
						{{ t('integriq', 'Revoke') }}
					</NcButton>
				</li>
			</ul>

			<NcTextArea
				v-model="pem"
				data-testid="credential-certificate-pem"
				:label="t('integriq', 'Certificate (PEM)')"
				:helperText="t('integriq', 'The partner\'s certificate, or the issuing CA\'s certificate together with a subject pattern.')" />
			<NcTextField
				v-model="subjectPattern"
				data-testid="credential-certificate-subject"
				:label="t('integriq', 'Subject pattern (with an issuing CA)')"
				placeholder="*/serialNumber=00000001234567890000" />
			<NcButton
				data-testid="credential-pin-certificate"
				:disabled="busy || !label.trim() || !pem.trim()"
				@click="pin">
				{{ t('integriq', 'Pin certificate') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcTextArea, NcTextField } from '@nextcloud/vue'
import { API_KEY, CLIENT_CERTIFICATE, createCredential, listCredentials, revokeCredential } from './credentialKeys.js'

export default {
	name: 'CredentialKeysPanel',

	components: { NcButton, NcTextArea, NcTextField },

	props: {
		/** The credential route: the portal's key route or the administrator's credential route. */
		credentialsUrl: { type: String, required: true },
		/** Whether an administrator is looking: offers the expiry and the client certificates. */
		administrator: { type: Boolean, default: false },
	},

	data() {
		return {
			credentials: [],
			loading: false,
			loadError: '',
			busy: false,
			label: '',
			expiresAt: '',
			pem: '',
			subjectPattern: '',
			newKey: '',
			copied: false,
		}
	},

	computed: {
		/**
		 * The API keys.
		 *
		 * @return {object[]} the keys
		 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003
		 */
		apiKeys() {
			return this.credentials.filter((credential) => credential?.type === API_KEY)
		},

		/**
		 * The pinned client certificates.
		 *
		 * @return {object[]} the certificates
		 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
		 */
		certificates() {
			return this.credentials.filter((credential) => credential?.type === CLIENT_CERTIFICATE)
		},
	},

	watch: {
		/**
		 * Read the credentials of another route.
		 *
		 * @return {void}
		 * @spec exclude reload on a changed route
		 */
		credentialsUrl() {
			this.newKey = ''
			this.load()
		},
	},

	/**
	 * Load the credentials.
	 *
	 * @return {void}
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003
	 */
	created() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the credentials. A failure says so in the panel.
		 *
		 * @return {Promise<void>} resolves once read
		 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003
		 */
		async load() {
			this.loading = true
			try {
				this.credentials = await listCredentials(this.credentialsUrl)
				this.loadError = ''
			} catch (e) {
				this.credentials = []
				this.loadError = e?.response?.data?.error || t('integriq', 'The keys could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Generate a key and show it once.
		 *
		 * @return {Promise<void>} resolves once generated
		 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
		 */
		async generate() {
			const body = { label: this.label.trim() }
			if (this.administrator && this.expiresAt.trim() !== '') {
				body.expiresAt = this.expiresAt.trim()
			}
			const created = await this.create(body, t('integriq', 'The key could not be generated.'))
			if (created) {
				this.newKey = created.key
				this.copied = false
				this.expiresAt = ''
			}
		},

		/**
		 * Pin a client certificate: the certificate itself, or an issuing CA with a subject pattern.
		 *
		 * @return {Promise<void>} resolves once pinned
		 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
		 */
		async pin() {
			const pattern = this.subjectPattern.trim()
			const body = { label: this.label.trim(), type: CLIENT_CERTIFICATE }
			if (pattern === '') {
				body.certificate = this.pem.trim()
			} else {
				body.issuerCertificate = this.pem.trim()
				body.subjectPattern = pattern
			}
			const created = await this.create(body, t('integriq', 'The certificate could not be pinned.'))
			if (created) {
				this.pem = ''
				this.subjectPattern = ''
			}
		},

		/**
		 * Post one new credential and add it to the list.
		 *
		 * @param {object} body the request body, with its label
		 * @param {string} failure what to say when the server gives no reason
		 * @return {Promise<object|null>} the answer, or null after a failure
		 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
		 */
		async create(body, failure) {
			if (!body.label || this.busy) return null
			this.busy = true
			try {
				const created = await createCredential(this.credentialsUrl, body)
				if (created.credential) {
					this.credentials = [...this.credentials, created.credential]
				}
				this.label = ''
				return created
			} catch (e) {
				showError(e?.response?.data?.error || failure)
				return null
			} finally {
				this.busy = false
			}
		},

		/**
		 * Revoke one credential. Calls with it are refused from then on.
		 *
		 * @param {object} credential the credential to revoke
		 * @return {Promise<void>} resolves once revoked
		 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003
		 */
		async revoke(credential) {
			if (this.busy) return
			this.busy = true
			try {
				await revokeCredential(this.credentialsUrl, credential.id)
				this.credentials = this.credentials.filter((entry) => entry.id !== credential.id)
				showSuccess(t('integriq', 'Key {label} is revoked.', { label: credential.label }))
			} catch (e) {
				showError(e?.response?.data?.error || t('integriq', 'The key could not be revoked.'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Copy the key shown once to the clipboard.
		 *
		 * @return {Promise<void>} resolves once copied
		 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
		 */
		async copyKey() {
			try {
				await navigator.clipboard.writeText(this.newKey)
				this.copied = true
			} catch {
				showError(t('integriq', 'The key could not be copied. Select it and copy it by hand.'))
			}
		},

		/**
		 * When a key was last used, or that it never was.
		 *
		 * @param {object} key the key
		 * @return {string} the text
		 * @spec exclude presentation text
		 */
		usedText(key) {
			return key.lastUsedAt
				? t('integriq', 'last used {date}', { date: key.lastUsedAt })
				: t('integriq', 'never used')
		},
	},
}
</script>

<style scoped>
.credentialKeys {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-top: 8px;
}

.credentialKeys__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.credentialKeys__item {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.credentialKeys__row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
}

.credentialKeys__muted {
	color: var(--color-text-maxcontrast);
}

.credentialKeys__error {
	color: var(--color-error-text, var(--color-error));
}

.credentialKeys__new {
	padding: 8px;
	border: 1px solid var(--color-warning, var(--color-border));
	border-radius: var(--border-radius);
}

.credentialKeys__key {
	overflow-wrap: anywhere;
	user-select: all;
}
</style>

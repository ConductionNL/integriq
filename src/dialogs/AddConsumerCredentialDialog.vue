<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  AddConsumerCredentialDialog: give a consumer a new credential
  (access-consumer-credentials, tasks 2 and 4).

  For an API key: a label and an optional expiry; the server generates the
  key, and this dialog shows it exactly once with a copy button. Closing the
  dialog forgets it: the page shows only its prefix afterwards (REQ-CRED-002).
  For a client certificate: a label, an optional expiry, and either the PEM
  certificate to pin, or the issuing CA certificate and a subject pattern.
  Emits `created` with the credential as the server listed it, and `close`.

  @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
-->
<template>
	<NcDialog
		:open="open"
		:name="isCertificate ? t('integriq', 'Add client certificate') : t('integriq', 'Generate API key')"
		size="normal"
		data-testid="add-credential-dialog"
		@update:open="onOpenChanged">
		<div class="add-credential-dialog">
			<template v-if="key">
				<NcNoteCard type="warning">
					{{ t('integriq', 'Copy the key now. It is shown only this once; afterwards only its first characters are shown.') }}
				</NcNoteCard>
				<code class="add-credential-dialog__key" data-testid="add-credential-key">{{ key }}</code>
				<p v-if="copied" data-testid="add-credential-copied">
					{{ t('integriq', 'Copied') }}
				</p>
			</template>

			<template v-else>
				<NcTextField
					v-model="form.label"
					:label="t('integriq', 'Label')"
					data-testid="add-credential-label" />
				<NcTextField
					v-model="form.expiresAt"
					type="datetime-local"
					:label="t('integriq', 'Expires (optional)')"
					data-testid="add-credential-expires" />

				<template v-if="isCertificate">
					<NcTextArea
						v-model="form.certificate"
						:label="t('integriq', 'Certificate to pin (PEM)')"
						data-testid="add-credential-certificate" />
					<p class="add-credential-dialog__or">
						{{ t('integriq', 'Or accept every certificate an issuing CA gives to a matching subject:') }}
					</p>
					<NcTextArea
						v-model="form.issuerCertificate"
						:label="t('integriq', 'Issuing CA certificate (PEM)')"
						data-testid="add-credential-issuer" />
					<NcTextField
						v-model="form.subjectPattern"
						:label="t('integriq', 'Subject pattern')"
						data-testid="add-credential-subject" />
				</template>
			</template>

			<NcNoteCard v-if="error" type="error" data-testid="add-credential-error">
				{{ error }}
			</NcNoteCard>
		</div>

		<template #actions>
			<template v-if="key">
				<NcButton data-testid="add-credential-copy" @click="copy">
					{{ t('integriq', 'Copy key') }}
				</NcButton>
				<NcButton variant="primary" data-testid="add-credential-done" @click="close">
					{{ t('integriq', 'Done') }}
				</NcButton>
			</template>
			<template v-else>
				<NcButton :disabled="busy" @click="close">
					{{ t('integriq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="busy || !canSubmit"
					data-testid="add-credential-submit"
					@click="submit">
					{{ isCertificate ? t('integriq', 'Add certificate') : t('integriq', 'Generate key') }}
				</NcButton>
			</template>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard, NcTextArea, NcTextField } from '@nextcloud/vue'
import { createBody, credentialsPath, TYPE_CLIENT_CERTIFICATE } from '../views/Consumer/consumerCredentials.js'

/**
 * An empty form.
 *
 * @return {object}
 */
function emptyForm() {
	return { label: '', expiresAt: '', certificate: '', issuerCertificate: '', subjectPattern: '' }
}

export default {
	name: 'AddConsumerCredentialDialog',

	components: { NcButton, NcDialog, NcNoteCard, NcTextArea, NcTextField },

	props: {
		/** Whether the dialog is shown. */
		open: {
			type: Boolean,
			default: false,
		},

		/** The consumer's object id. */
		consumerId: {
			type: String,
			required: true,
		},

		/** `apiKey` or `clientCertificate`. */
		type: {
			type: String,
			default: 'apiKey',
		},
	},

	emits: ['created', 'close'],

	data() {
		return {
			form: emptyForm(),
			key: '',
			copied: false,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Whether this adds a client certificate rather than a key.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
		 */
		isCertificate() {
			return this.type === TYPE_CLIENT_CERTIFICATE
		},

		/**
		 * Whether the form holds enough to send.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/access-consumer-credentials/tasks.md
		 */
		canSubmit() {
			if (this.form.label.trim() === '') {
				return false
			}

			if (!this.isCertificate) {
				return true
			}

			return this.form.certificate.trim() !== ''
				|| (this.form.issuerCertificate.trim() !== '' && this.form.subjectPattern.trim() !== '')
		},
	},

	methods: {
		t,

		/**
		 * Create the credential; for a key, keep it to show once.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
		 */
		async submit() {
			this.busy = true
			this.error = ''
			try {
				const res = await axios.post(
					generateUrl(credentialsPath(this.consumerId)),
					createBody({ ...this.form, type: this.type }),
				)
				this.$emit('created', res.data?.credential ?? null)
				if (res.data?.key) {
					this.key = res.data.key
				} else {
					this.close()
				}
			} catch (err) {
				this.error = err?.response?.data?.error || t('integriq', 'The credential could not be added.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Copy the shown key to the clipboard.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
		 */
		async copy() {
			try {
				await navigator.clipboard.writeText(this.key)
				this.copied = true
			} catch {
				this.error = t('integriq', 'The key could not be copied. Select it and copy it by hand.')
			}
		},

		/**
		 * Close, forgetting the key and the form.
		 *
		 * @return {void}
		 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
		 */
		close() {
			this.form = emptyForm()
			this.key = ''
			this.copied = false
			this.error = ''
			this.$emit('close')
		},

		/**
		 * Close when the dialog asks to.
		 *
		 * @param {boolean} value Whether it should stay open
		 * @return {void}
		 * @spec openspec/changes/access-consumer-credentials/tasks.md
		 */
		onOpenChanged(value) {
			if (value === false) {
				this.close()
			}
		},
	},
}
</script>

<style scoped>
.add-credential-dialog {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.add-credential-dialog__key {
	word-break: break-all;
	user-select: all;
}

.add-credential-dialog__or {
	color: var(--color-text-maxcontrast);
}
</style>

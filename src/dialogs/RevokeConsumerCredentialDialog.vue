<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  RevokeConsumerCredentialDialog: revoke one credential of a consumer
  (access-consumer-credentials, task 2). A revoked credential stops working at
  once. Emits `revoked` with the credential's id, and `close`.

  @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003
-->
<template>
	<NcDialog
		:open="open"
		:name="t('integriq', 'Revoke credential')"
		size="small"
		data-testid="revoke-credential-dialog"
		@update:open="onOpenChanged">
		<p v-if="credential">
			{{ t('integriq', 'Calls with "{label}" are refused from now on. This cannot be undone.', { label: credential.label }) }}
		</p>
		<NcNoteCard v-if="error" type="error" data-testid="revoke-credential-error">
			{{ error }}
		</NcNoteCard>

		<template #actions>
			<NcButton :disabled="busy" @click="close">
				{{ t('integriq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				:disabled="busy"
				data-testid="revoke-credential-submit"
				@click="submit">
				{{ t('integriq', 'Revoke') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'
import { credentialsPath } from '../views/Consumer/consumerCredentials.js'

export default {
	name: 'RevokeConsumerCredentialDialog',

	components: { NcButton, NcDialog, NcNoteCard },

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

		/** The credential to revoke. */
		credential: {
			type: Object,
			default: null,
		},
	},

	emits: ['revoked', 'close'],

	data() {
		return {
			busy: false,
			error: '',
		}
	},

	methods: {
		t,

		/**
		 * Revoke the credential.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003
		 */
		async submit() {
			this.busy = true
			this.error = ''
			try {
				await axios.delete(generateUrl(credentialsPath(this.consumerId, this.credential.id)))
				this.$emit('revoked', this.credential.id)
			} catch (err) {
				this.error = err?.response?.data?.error || t('integriq', 'The credential could not be revoked.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Close without revoking.
		 *
		 * @return {void}
		 * @spec openspec/changes/access-consumer-credentials/tasks.md
		 */
		close() {
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

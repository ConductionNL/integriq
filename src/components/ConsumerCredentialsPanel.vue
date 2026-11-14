<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  ConsumerCredentialsPanel: a consumer's credentials on its detail page
  (access-consumer-credentials, tasks 2 to 4).

  Lists the consumer's API keys and client certificates, newest first, each
  with its label, what identifies it (a key's prefix, never the key), when it
  was created, when it expires and when it was last used. Several can be
  active at once, so a partner can switch keys without downtime. Generate a
  key (shown once), add a client certificate, or revoke a credential.
  Administrators only, as the endpoints are.

  @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001
-->
<template>
	<section class="consumerCredentials" data-testid="consumer-credentials">
		<div class="consumerCredentials__header">
			<h3>{{ t('integriq', 'Credentials') }}</h3>
			<div class="consumerCredentials__actions">
				<NcButton
					variant="primary"
					:disabled="!consumerId"
					data-testid="consumer-credentials-generate"
					@click="adding = 'apiKey'">
					{{ t('integriq', 'Generate API key') }}
				</NcButton>
				<NcButton
					:disabled="!consumerId"
					data-testid="consumer-credentials-certificate"
					@click="adding = 'clientCertificate'">
					{{ t('integriq', 'Add client certificate') }}
				</NcButton>
			</div>
		</div>

		<NcNoteCard v-if="error" type="error" data-testid="consumer-credentials-error">
			{{ error }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="24" />

		<p v-else-if="credentials.length === 0" class="consumerCredentials__empty" data-testid="consumer-credentials-empty">
			{{ t('integriq', 'This consumer has no credentials yet.') }}
		</p>

		<table v-else class="consumerCredentials__table">
			<thead>
				<tr>
					<th>{{ t('integriq', 'Label') }}</th>
					<th>{{ t('integriq', 'Type') }}</th>
					<th>{{ t('integriq', 'Identified by') }}</th>
					<th>{{ t('integriq', 'Created') }}</th>
					<th>{{ t('integriq', 'Expires') }}</th>
					<th>{{ t('integriq', 'Last used') }}</th>
					<th><span class="hidden-visually">{{ t('integriq', 'Actions') }}</span></th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="credential in credentials"
					:key="credential.id"
					:data-testid="`consumer-credential-${credential.id}`">
					<td>{{ credential.label }}</td>
					<td>{{ typeLabel(credential.type) }}</td>
					<td><code>{{ identifies(credential) }}</code></td>
					<td>{{ formatDate(credential.createdAt) }}</td>
					<td :class="{ 'consumerCredentials__expired': isExpired(credential) }">
						{{ credential.expiresAt ? formatDate(credential.expiresAt) : t('integriq', 'Never') }}
					</td>
					<td>{{ credential.lastUsedAt ? formatDate(credential.lastUsedAt) : t('integriq', 'Not used yet') }}</td>
					<td>
						<NcButton
							variant="tertiary"
							:data-testid="`consumer-credential-revoke-${credential.id}`"
							@click="revoking = credential">
							{{ t('integriq', 'Revoke') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<AddConsumerCredentialDialog
			v-if="consumerId"
			:open="adding !== null"
			:consumerId="consumerId"
			:type="adding || 'apiKey'"
			@created="onCreated"
			@close="adding = null" />
		<RevokeConsumerCredentialDialog
			v-if="consumerId"
			:open="revoking !== null"
			:consumerId="consumerId"
			:credential="revoking"
			@revoked="onRevoked"
			@close="revoking = null" />
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import AddConsumerCredentialDialog from '../dialogs/AddConsumerCredentialDialog.vue'
import RevokeConsumerCredentialDialog from '../dialogs/RevokeConsumerCredentialDialog.vue'
import {
	credentialsPath,
	identifies,
	isExpired,
	sortedCredentials,
	TYPE_CLIENT_CERTIFICATE,
} from '../views/Consumer/consumerCredentials.js'

export default {
	name: 'ConsumerCredentialsPanel',

	components: { AddConsumerCredentialDialog, NcButton, NcLoadingIcon, NcNoteCard, RevokeConsumerCredentialDialog },

	inject: {
		cnSectionContext: {
			from: 'cnSectionContext',
			default: () => ({ value: { objectId: null, object: null } }),
		},
	},

	data() {
		return {
			credentials: [],
			loading: false,
			error: '',
			adding: null,
			revoking: null,
		}
	},

	computed: {
		/**
		 * The consumer the page shows.
		 *
		 * @return {string}
		 * @spec openspec/changes/access-consumer-credentials/tasks.md
		 */
		consumerId() {
			const ctx = this.cnSectionContext
			const value = ctx && ctx.value !== undefined ? ctx.value : ctx
			const object = (value && value.object) || {}
			return String((value && value.objectId) || object['@self']?.id || object.uuid || object.id || '')
		},
	},

	watch: {
		consumerId: {
			immediate: true,
			/**
			 * Load the credentials of the consumer shown.
			 *
			 * @return {void}
			 * @spec openspec/changes/access-consumer-credentials/tasks.md
			 */
			handler() {
				if (this.consumerId) {
					this.load()
				}
			},
		},
	},

	methods: {
		t,
		identifies,
		isExpired,

		/**
		 * The consumer's credentials, without any key or hash.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const res = await axios.get(generateUrl(credentialsPath(this.consumerId)))
				this.credentials = sortedCredentials(res.data?.results)
			} catch (err) {
				this.credentials = []
				this.error = err?.response?.data?.error || t('integriq', 'The credentials could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Show a new credential at the top of the list.
		 *
		 * @param {object|null} credential The credential as the server listed it
		 * @return {void}
		 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
		 */
		onCreated(credential) {
			if (credential) {
				this.credentials = sortedCredentials([credential, ...this.credentials])
			}
		},

		/**
		 * Drop a revoked credential from the list.
		 *
		 * @param {string} id The revoked credential's id
		 * @return {void}
		 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003
		 */
		onRevoked(id) {
			this.credentials = this.credentials.filter((credential) => credential.id !== id)
			this.revoking = null
		},

		/**
		 * The name of a credential type.
		 *
		 * @param {string} type The type
		 * @return {string}
		 * @spec openspec/changes/access-consumer-credentials/tasks.md
		 */
		typeLabel(type) {
			return type === TYPE_CLIENT_CERTIFICATE ? t('integriq', 'Client certificate') : t('integriq', 'API key')
		},

		/**
		 * A moment as a local date and time.
		 *
		 * @param {string} value An ISO-8601 moment
		 * @return {string}
		 * @spec openspec/changes/access-consumer-credentials/tasks.md
		 */
		formatDate(value) {
			const date = value ? new Date(value) : null
			if (date === null || Number.isNaN(date.getTime())) {
				return '—'
			}

			return date.toLocaleString()
		},
	},
}
</script>

<style scoped>
.consumerCredentials__header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}

.consumerCredentials__actions {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
}

.consumerCredentials__table {
	width: 100%;
	border-collapse: collapse;
}

.consumerCredentials__table th,
.consumerCredentials__table td {
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.consumerCredentials__expired,
.consumerCredentials__empty {
	color: var(--color-text-maxcontrast);
}
</style>

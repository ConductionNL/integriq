<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="integriq-admin__section" data-testid="admin-webhook-connections">
		<h3>{{ t('integriq', 'Webhook connections') }}</h3>
		<p class="integriq-admin__hint">
			{{
				t(
					'integriq',
					'Partners sign every delivery with a shared secret. Integriq checks the signature and stores the delivery as the account you choose per webhook.',
				)
			}}
		</p>

		<div v-if="error" class="integriq-admin__action-error" role="alert">
			{{ error }}
		</div>

		<p v-if="loading" class="integriq-admin__hint">
			{{ t('integriq', 'Loading the webhook connections…') }}
		</p>

		<WebhookConnectionRow
			v-for="connection in connections"
			v-else
			:key="connection.authorizationType"
			:connection="connection"
			@saved="replace" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import WebhookConnectionRow from './WebhookConnectionRow.vue'

/**
 * Admin editor for the signed public webhooks on the consumer model: one
 * consumer and account per webhook (Peppol, NotifyNL, ROD, OSO, UWLR/Edu-V,
 * Verzuimloket, iWMO/iJW, StUF-ZKN, verdicts and the intake channels).
 *
 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#requirement-an-administrator-chooses-each-webhooks-account-req-cm-021
 */
export default {
	name: 'WebhookConnectionsSettings',

	components: {
		WebhookConnectionRow,
	},

	data() {
		return {
			loading: true,
			error: '',
			connections: [],
		}
	},

	/** @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-the-settings-list-every-webhook-without-its-secret */
	async mounted() {
		this.loading = true
		try {
			const { data } = await axios.get(
				generateUrl('/apps/integriq/api/admin/webhook-connections'),
			)
			this.connections = data.connections || []
		} catch {
			this.error = this.t(
				'integriq',
				'Failed to load the webhook connections.',
			)
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * Put a saved connection's new state in the list.
		 *
		 * @param {object} saved The connection as the server describes it now.
		 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-administrator-chooses-a-webhooks-account
		 */
		replace(saved) {
			this.connections = this.connections.map((connection) =>
				connection.authorizationType === saved.authorizationType
					? saved
					: connection,
			)
		},
	},
}
</script>

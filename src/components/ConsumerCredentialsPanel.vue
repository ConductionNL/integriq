<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  ConsumerCredentialsPanel: the credentials of a consumer on its detail page
  (a ConsumerDetail body widget). An administrator generates a key, shown
  once with a copy button, sees each key with when it was last used so two
  keys can run side by side while a partner switches, revokes a key, and
  pins a client certificate. It reads and writes
  /api/consumers/{id}/credentials, which only an administrator may call; no
  route answers a key after it was generated.

  @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
-->
<template>
	<CredentialKeysPanel
		v-if="consumerId"
		:credentialsUrl="credentialsUrl"
		:administrator="true"
		data-testid="consumer-credentials" />
</template>

<script>
import CredentialKeysPanel from './CredentialKeysPanel.vue'
import { consumerCredentialsUrl } from './credentialKeys.js'

export default {
	name: 'ConsumerCredentialsPanel',

	components: { CredentialKeysPanel },

	inject: {
		cnSectionContext: { default: null },
	},

	computed: {
		/**
		 * The consumer's id, from the detail page the panel sits on.
		 *
		 * @return {string} the id, or '' while it is not loaded
		 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
		 */
		consumerId() {
			const ctx = this.cnSectionContext
			const value = ctx && ctx.value !== undefined ? ctx.value : ctx
			const object = (value && value.object) || {}
			return String((value && value.objectId) || object['@self']?.id || object.uuid || object.id || '')
		},

		/**
		 * The consumer's credential route.
		 *
		 * @return {string} the URL
		 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
		 */
		credentialsUrl() {
			return consumerCredentialsUrl(this.consumerId)
		},
	},
}
</script>

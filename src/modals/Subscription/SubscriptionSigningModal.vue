<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  SubscriptionSigningModal — manage a webhook subscription's signing secret.

  Lives in its own file under src/modals/ per the modal-isolation gate.
  Driven by the shared modalBus (EVENT_OPEN_SUBSCRIPTION_SIGNING) via the
  app-shell ModalHost, like the Test mapping / Add endpoint rule modals.

  Generate produces a server-side secret returned exactly once; the modal
  shows it with a copy action and a shown-only-once warning. Rotate moves
  the current secret to the previous secret (24h dual-sign grace) and again
  reveals the new value once. Every other read shows the redaction marker.

  @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5
-->
<template>
	<NcModal v-if="open"
		label-id="subscription-signing"
		data-testid="subscription-signing-modal"
		@close="$emit('close')">
		<div class="signing">
			<h2>{{ t('openconnector', 'Webhook signing') }}</h2>

			<p class="signing__intro">
				{{ t('openconnector', 'When a signing secret is set, every delivery to this subscription carries an X-OpenConnector-Signature header receivers can verify.') }}
			</p>

			<div v-if="revealed" class="signing__reveal" data-testid="signing-reveal">
				<p class="signing__warn">
					{{ t('openconnector', 'Copy this secret now — it is shown only once.') }}
				</p>
				<code class="signing__secret">{{ revealed }}</code>
				<NcButton @click="copy">
					{{ t('openconnector', 'Copy') }}
				</NcButton>
			</div>
			<p v-else class="signing__status">
				{{ hasSecret
					? t('openconnector', 'A signing secret is configured (hidden).')
					: t('openconnector', 'No signing secret configured.') }}
			</p>

			<div class="signing__actions">
				<NcButton type="primary" :disabled="busy" @click="generate">
					{{ t('openconnector', 'Generate signing secret') }}
				</NcButton>
				<NcButton v-if="hasSecret" :disabled="busy" @click="rotate">
					{{ t('openconnector', 'Rotate secret') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcModal, NcButton } from '@nextcloud/vue'

export default {
	name: 'SubscriptionSigningModal',

	components: { NcModal, NcButton },

	props: {
		open: {
			type: Boolean,
			default: false,
		},
		subscription: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'changed'],

	data() {
		return {
			revealed: '',
			busy: false,
			hasSecret: false,
		}
	},

	watch: {
		/**
		 * Reset reveal state and recompute hasSecret when opened.
		 * @param {boolean} next The new open value.
		 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5
		 */
		open(next) {
			if (next) {
				this.revealed = ''
				this.hasSecret = !!(this.subscription?.protocolSettings?.signingSecret)
			}
		},
	},

	methods: {
		t,
		/**
		 * Resolve the subscription UUID for the API path.
		 * @return {string|undefined}
		 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5
		 */
		subId() {
			return this.subscription?.uuid || this.subscription?.id
		},
		/**
		 * Generate a fresh signing secret.
		 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5
		 */
		async generate() {
			await this.call('signing-secret', t('openconnector', 'Signing secret generated'))
		},
		/**
		 * Rotate the current signing secret.
		 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5
		 */
		async rotate() {
			await this.call('signing-secret/rotate', t('openconnector', 'Signing secret rotated'))
		},
		/**
		 * POST a signing lifecycle action and reveal the returned secret once.
		 * @param {string} path The endpoint suffix (signing-secret[/rotate]).
		 * @param {string} successMsg The toast on success.
		 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5
		 */
		async call(path, successMsg) {
			const id = this.subId()
			if (!id) {
				return
			}
			this.busy = true
			try {
				const res = await axios.post(generateUrl(`/apps/openconnector/api/events/subscriptions/${id}/${path}`))
				this.revealed = res.data?.signingSecret || ''
				this.hasSecret = true
				showSuccess(successMsg)
				this.$emit('changed')
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				showError(t('openconnector', 'Signing operation failed') + (detail ? `: ${detail}` : ''))
			} finally {
				this.busy = false
			}
		},
		/**
		 * Copy the revealed secret to the clipboard.
		 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5
		 */
		copy() {
			if (navigator?.clipboard && this.revealed) {
				navigator.clipboard.writeText(this.revealed)
				showSuccess(t('openconnector', 'Copied to clipboard'))
			}
		},
	},
}
</script>

<style scoped>
.signing {
	padding: 20px;
	min-width: 420px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.signing__reveal {
	background: var(--color-background-dark);
	padding: 12px;
	border-radius: var(--border-radius);
}

.signing__warn {
	color: var(--color-warning-text, #8a6d3b);
	font-weight: 600;
}

.signing__secret {
	display: block;
	word-break: break-all;
	margin: 8px 0;
	font-family: monospace;
}

.signing__actions {
	display: flex;
	gap: 8px;
}
</style>

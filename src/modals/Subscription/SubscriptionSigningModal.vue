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

  signed-outbound-webhooks: the verification recipe is always shown, and the
  signed/unsigned state is read from `signingPosture` and `unsignedReason`,
  the readable mirror SubscriptionSigningDefaultListener writes on save:
  `protocolSettings` is writeOnly, so it never reaches this page.

  @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5
  @spec openspec/specs/webhook-signing/spec.md#requirement-the-subscription-page-states-what-a-receiver-must-compute-req-sow-002
-->
<template>
	<NcModal
		v-if="open"
		labelId="subscription-signing"
		data-testid="subscription-signing-modal"
		@close="$emit('close')">
		<div class="signing">
			<h2>{{ t('integriq', 'Webhook signing') }}</h2>

			<p class="signing__intro">
				{{
					t(
						'integriq',
						'When a signing secret is set, every delivery to this subscription carries an X-OpenConnector-Signature header receivers can verify.',
					)
				}}
			</p>

			<div
				v-if="revealed"
				class="signing__reveal"
				data-testid="signing-reveal">
				<p class="signing__warn">
					{{
						t('integriq', 'Copy this secret now. It is shown only once.')
					}}
				</p>
				<code class="signing__secret">{{ revealed }}</code>
				<NcButton @click="copy">
					{{ t('integriq', 'Copy') }}
				</NcButton>
			</div>
			<p v-else class="signing__status" data-testid="signing-status">
				{{ statusText }}
			</p>

			<div class="signing__actions">
				<NcButton variant="primary" :disabled="busy" @click="generate">
					{{ t('integriq', 'Generate signing secret') }}
				</NcButton>
				<NcButton v-if="hasSecret" :disabled="busy" @click="rotate">
					{{ t('integriq', 'Rotate secret') }}
				</NcButton>
			</div>

			<section class="signing__recipe" data-testid="signing-recipe">
				<h3>{{ t('integriq', 'How a receiver checks the signature') }}</h3>
				<ul>
					<li v-for="(line, index) in recipe" :key="index">
						{{ line }}
					</li>
				</ul>
			</section>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcModal } from '@nextcloud/vue'

// Vue's text interpolation escapes already; letting translate() escape too
// would print `&lt;t&gt;` where the recipe says `<t>`.
const NO_ESCAPE = { escape: false }

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

	computed: {
		/**
		 * The one line that says whether this webhook signs, and why not.
		 *
		 * @return {string}
		 * @spec openspec/specs/webhook-signing/spec.md#requirement-an-unsigned-subscription-and-an-unsigned-attempt-are-marked-req-sow-003
		 */
		statusText() {
			if (this.hasSecret) {
				return t(
					'integriq',
					'This webhook is signed. Nobody sees the secret after it is made, so if the receiver lacks it, generate a new one.',
				)
			}
			if (this.subscription?.signingPosture === 'unsigned') {
				const reason = this.subscription?.unsignedReason || ''
				return reason
					? t(
							'integriq',
							'This webhook delivers unsigned. Reason given: {reason}',
							{ reason },
							undefined,
							NO_ESCAPE,
						)
					: t(
							'integriq',
							'This webhook delivers unsigned. Nobody recorded why.',
						)
			}
			return t(
				'integriq',
				'This webhook was saved before signing was recorded. Save it again to see whether it signs.',
			)
		},

		/**
		 * The verification recipe, one fact per line (REQ-SOW-002).
		 *
		 * @return {string[]}
		 * @spec openspec/specs/webhook-signing/spec.md#requirement-the-subscription-page-states-what-a-receiver-must-compute-req-sow-002
		 */
		recipe() {
			return [
				t('integriq', 'Each delivery carries the header {header}.', {
					header: 'X-OpenConnector-Signature',
				}),
				t(
					'integriq',
					'Its value looks like {shape}.',
					{ shape: 't=<unix-ts>,v1=<hex>' },
					undefined,
					NO_ESCAPE,
				),
				t(
					'integriq',
					'v1 is HMAC-SHA256 with the secret as key, computed over {signed}.',
					{ signed: '<t>.<rawBody>' },
					undefined,
					NO_ESCAPE,
				),
				t(
					'integriq',
					'Use the body exactly as received, before you parse it.',
				),
				t(
					'integriq',
					'Choose your own timestamp tolerance and reject requests older than that.',
				),
				t(
					'integriq',
					'For 24 hours after a rotation the header carries two v1 values. Accept the request when either one matches.',
				),
			]
		},
	},

	watch: {
		/**
		 * Reset reveal state and recompute hasSecret when opened.
		 *
		 * @param {boolean} next The new open value.
		 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5
		 */
		open(next) {
			if (next) {
				this.revealed = ''
				this.hasSecret =
					this.subscription?.signingPosture === 'signed'
					|| !!this.subscription?.protocolSettings?.signingSecret
			}
		},
	},

	methods: {
		t,
		/**
		 * Resolve the subscription UUID for the API path.
		 *
		 * @return {string|undefined}
		 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5
		 */
		subId() {
			return this.subscription?.uuid || this.subscription?.id
		},

		/**
		 * Generate a fresh signing secret.
		 *
		 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5
		 */
		async generate() {
			await this.call(
				'signing-secret',
				t('integriq', 'Signing secret generated'),
			)
		},

		/**
		 * Rotate the current signing secret.
		 *
		 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5
		 */
		async rotate() {
			await this.call(
				'signing-secret/rotate',
				t('integriq', 'Signing secret rotated'),
			)
		},

		/**
		 * POST a signing lifecycle action and reveal the returned secret once.
		 *
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
				const res = await axios.post(
					generateUrl(
						`/apps/integriq/api/events/subscriptions/${id}/${path}`,
					),
				)
				this.revealed = res.data?.signingSecret || ''
				this.hasSecret = true
				showSuccess(successMsg)
				this.$emit('changed')
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				showError(
					t('integriq', 'Signing operation failed')
						+ (detail ? `: ${detail}` : ''),
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Copy the revealed secret to the clipboard.
		 *
		 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5
		 */
		copy() {
			if (navigator?.clipboard && this.revealed) {
				navigator.clipboard.writeText(this.revealed)
				showSuccess(t('integriq', 'Copied to clipboard'))
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

.signing__recipe ul {
	margin: 0;
	padding-inline-start: 20px;
	list-style: disc;
}

.signing__recipe code,
.signing__recipe li {
	overflow-wrap: anywhere;
}
</style>

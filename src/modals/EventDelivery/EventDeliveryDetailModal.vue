<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  EventDeliveryDetailModal — dead-letter message inspection + per-message
  Replay / Discard actions.

  Lives in its own file under src/modals/ per the modal-isolation hydra gate.
  It is page-local (driven by the parent EventDeliveriesPage `open`/`message`
  props rather than the shared modalBus) because the detail is only ever
  opened from the deliveries list and carries that row's resolved payload.

  Shows: the CloudEvent payload (pretty-printed), the full attempt timeline
  (REQ-DLR-002), and the replay/discard audit stamps when present. The
  Replay / Discard buttons are guarded by an inline confirmation step
  (REQ-DLR-006) before any request is sent.

  @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
-->
<template>
	<NcModal v-if="open"
		label-id="event-delivery-detail"
		data-testid="event-delivery-detail-modal"
		@close="$emit('close')">
		<div class="deliveryDetail">
			<h2>{{ t('openconnector', 'Event delivery') }}</h2>

			<div v-if="message" class="deliveryDetail__body">
				<div class="deliveryDetail__meta">
					<span class="deliveryDetail__badge" :class="badgeClass">{{ message.status }}</span>
					<span class="deliveryDetail__actionBadge" data-testid="detail-action-kind-badge">
						{{ t('openconnector', 'Action: {kind}', { kind: actionKind }) }}
					</span>
					<span v-if="message.nextcloudEvent" class="deliveryDetail__provenanceBadge" data-testid="detail-provenance-badge">
						{{ t('openconnector', 'Nextcloud event') }}
					</span>
					<span class="deliveryDetail__retry">
						{{ t('openconnector', 'Attempts: {count}', { count: attempts.length }) }}
					</span>
				</div>

				<div v-if="message.replayedBy || message.discardedBy" class="deliveryDetail__audit">
					<p v-if="message.replayedBy">
						{{ t('openconnector', 'Replayed by {who} at {when}', { who: message.replayedBy, when: message.replayedAt }) }}
					</p>
					<p v-if="message.discardedBy">
						{{ t('openconnector', 'Discarded by {who} at {when}', { who: message.discardedBy, when: message.discardedAt }) }}
					</p>
				</div>

				<h3>{{ t('openconnector', 'Attempt timeline') }}</h3>
				<ol v-if="attempts.length" class="deliveryDetail__timeline" data-testid="attempt-timeline">
					<li v-for="(attempt, idx) in attempts" :key="idx">
						<span class="deliveryDetail__time">{{ attempt.at }}</span>
						<span v-if="attempt.statusCode" class="deliveryDetail__status">HTTP {{ attempt.statusCode }}</span>
						<span v-else-if="attempt.error" class="deliveryDetail__error">{{ attempt.error }}</span>
					</li>
				</ol>
				<p v-else class="deliveryDetail__empty">{{ t('openconnector', 'No attempts recorded yet') }}</p>

				<h3>{{ t('openconnector', 'Payload') }}</h3>
				<pre class="deliveryDetail__payload" data-testid="payload-viewer">{{ prettyPayload }}</pre>
			</div>

			<div class="deliveryDetail__actions">
				<template v-if="confirming">
					<span class="deliveryDetail__confirm">
						{{ confirming === 'replay'
							? t('openconnector', 'Replay this message now?')
							: t('openconnector', 'Discard this message? It will not be deleted.') }}
					</span>
					<NcButton type="primary" :disabled="busy" @click="commit">
						{{ t('openconnector', 'Confirm') }}
					</NcButton>
					<NcButton :disabled="busy" @click="confirming = null">
						{{ t('openconnector', 'Cancel') }}
					</NcButton>
				</template>
				<template v-else>
					<NcButton type="primary" :disabled="!canAct || busy" @click="confirming = 'replay'">
						{{ t('openconnector', 'Replay') }}
					</NcButton>
					<NcButton :disabled="!canAct || busy" @click="confirming = 'discard'">
						{{ t('openconnector', 'Discard') }}
					</NcButton>
				</template>
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
	name: 'EventDeliveryDetailModal',

	components: { NcModal, NcButton },

	props: {
		open: {
			type: Boolean,
			default: false,
		},
		message: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'changed'],

	data() {
		return {
			confirming: null,
			busy: false,
		}
	},

	computed: {
		/**
		 * The attempt audit trail for the timeline (REQ-DLR-002).
		 * @return {Array}
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		attempts() {
			return Array.isArray(this.message?.attempts) ? this.message.attempts : []
		},
		/**
		 * Whether replay/discard verbs are permitted on this message.
		 * @return {boolean}
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		canAct() {
			return ['failed', 'abandoned'].includes(this.message?.status)
		},

		/**
		 * The message's resolved delivery action kind, as surfaced by the
		 * backend (`EventsController::deadLetterShow`); defaults to
		 * 'webhook' when the backend field is absent (e.g. an older cached
		 * row) mirroring the server's own REQ-008 default.
		 * @return {string}
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-dead-letter-listing-and-detail-must-surface-action-kind-and-nextcloud-event-provenance-req-dlr-013
		 */
		actionKind() {
			return this.message?.actionKind || 'webhook'
		},
		/**
		 * Status-badge CSS modifier class.
		 * @return {string}
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		badgeClass() {
			return `deliveryDetail__badge--${this.message?.status || 'unknown'}`
		},
		/**
		 * Pretty-printed CloudEvent payload for the viewer.
		 * @return {string}
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		prettyPayload() {
			try {
				return JSON.stringify(this.message?.payload ?? {}, null, 2)
			} catch (e) {
				return String(this.message?.payload ?? '')
			}
		},
	},

	methods: {
		t,
		/**
		 * Commit the confirmed per-message verb (replay/discard).
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		async commit() {
			const verb = this.confirming
			const id = this.message?.uuid || this.message?.id
			if (!verb || !id) {
				return
			}
			this.busy = true
			try {
				await axios.post(generateUrl(`/apps/openconnector/api/events/dead-letter/${id}/${verb}`))
				showSuccess(verb === 'replay'
					? t('openconnector', 'Message replayed')
					: t('openconnector', 'Message discarded'))
				this.$emit('changed')
				this.$emit('close')
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				showError((verb === 'replay'
					? t('openconnector', 'Replay failed')
					: t('openconnector', 'Discard failed')) + (detail ? `: ${detail}` : ''))
			} finally {
				this.busy = false
				this.confirming = null
			}
		},
	},
}
</script>

<style scoped>
.deliveryDetail {
	padding: 20px;
	min-width: 480px;
}

.deliveryDetail__meta {
	display: flex;
	gap: 12px;
	align-items: center;
	margin-bottom: 12px;
}

.deliveryDetail__badge {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	font-weight: 600;
}

.deliveryDetail__badge--failed {
	background: var(--color-warning, #e9a13b);
	color: var(--color-primary-text);
}

.deliveryDetail__badge--abandoned {
	background: var(--color-error, #e9322d);
	color: var(--color-primary-text);
}

.deliveryDetail__badge--discarded {
	background: var(--color-text-maxcontrast);
	color: var(--color-main-background);
}

.deliveryDetail__actionBadge,
.deliveryDetail__provenanceBadge {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-darker, var(--color-background-dark));
	color: var(--color-text-maxcontrast);
	text-transform: capitalize;
}

.deliveryDetail__timeline {
	margin: 0 0 16px;
	padding-left: 18px;
}

.deliveryDetail__timeline li {
	display: flex;
	gap: 12px;
	padding: 2px 0;
}

.deliveryDetail__error {
	color: var(--color-error);
}

.deliveryDetail__payload {
	max-height: 240px;
	overflow: auto;
	background: var(--color-background-dark);
	padding: 12px;
	border-radius: var(--border-radius);
	font-family: monospace;
	white-space: pre;
}

.deliveryDetail__actions {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-top: 16px;
}

.deliveryDetail__confirm {
	margin-right: auto;
}
</style>

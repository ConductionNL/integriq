<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  EventDeliveryDetailModal — dead-letter message inspection + per-message
  Replay / Discard actions.

  Lives in its own file under src/modals/ per the modal-isolation hydra gate.
  It is page-local (driven by the parent EventDeliveriesPage open and message
  props rather than the shared modalBus) because the detail is only ever
  opened from the deliveries list and carries the resolved payload of that row.

  Shows: the CloudEvent payload (pretty-printed), the full attempt timeline
  (REQ-DLR-002), and the replay/discard audit stamps when present. The
  Replay / Discard buttons are guarded by an inline confirmation step
  (REQ-DLR-006) before any request is sent.

  @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
-->
<template>
	<NcModal
		v-if="open"
		labelId="event-delivery-detail"
		data-testid="event-delivery-detail-modal"
		@close="$emit('close')">
		<div class="deliveryDetail">
			<h2>{{ t('integriq', 'Event delivery') }}</h2>

			<div v-if="message" class="deliveryDetail__body">
				<div class="deliveryDetail__meta">
					<span class="deliveryDetail__badge" :class="badgeClass">{{
						message.status
					}}</span>
					<span
						class="deliveryDetail__actionBadge"
						data-testid="detail-action-kind-badge">
						{{
							t('integriq', 'Action: {kind}', {
								kind: actionKind,
							})
						}}
					</span>
					<span
						v-if="message.nextcloudEvent"
						class="deliveryDetail__provenanceBadge"
						data-testid="detail-provenance-badge">
						{{ t('integriq', 'Nextcloud event') }}
					</span>
					<span class="deliveryDetail__retry">
						{{
							t('integriq', 'Attempts: {count}', {
								count: attempts.length,
							})
						}}
					</span>
				</div>

				<div
					v-if="message.replayedBy || message.discardedBy"
					class="deliveryDetail__audit">
					<p v-if="message.replayedBy">
						{{
							t('integriq', 'Replayed by {who} at {when}', {
								who: message.replayedBy,
								when: message.replayedAt,
							})
						}}
					</p>
					<p v-if="message.discardedBy">
						{{
							t('integriq', 'Discarded by {who} at {when}', {
								who: message.discardedBy,
								when: message.discardedAt,
							})
						}}
					</p>
				</div>

				<h3>{{ t('integriq', 'Attempt timeline') }}</h3>
				<ol
					v-if="attempts.length"
					class="deliveryDetail__timeline"
					data-testid="attempt-timeline">
					<li v-for="(attempt, idx) in attempts" :key="idx">
						<span class="deliveryDetail__time">{{ attempt.at }}</span>
						<span
							v-if="attempt.statusCode"
							class="deliveryDetail__status"
							>HTTP {{ attempt.statusCode }}</span
						>
						<span
							v-else-if="attempt.error"
							class="deliveryDetail__error"
							>{{ attempt.error }}</span
						>
					</li>
				</ol>
				<p v-else class="deliveryDetail__empty">
					{{ t('integriq', 'No attempts recorded yet') }}
				</p>

				<h3>{{ t('integriq', 'Payload') }}</h3>
				<pre class="deliveryDetail__payload" data-testid="payload-viewer">{{
					prettyPayload
				}}</pre>
			</div>

			<div class="deliveryDetail__actions">
				<template v-if="confirming">
					<span class="deliveryDetail__confirm">
						{{
							confirming === 'replay'
								? t('integriq', 'Replay this message now?')
								: t(
										'integriq',
										'Discard this message? It will not be deleted.',
									)
						}}
					</span>
					<NcButton variant="primary" :disabled="busy" @click="commit">
						{{ t('integriq', 'Confirm') }}
					</NcButton>
					<NcButton :disabled="busy" @click="confirming = null">
						{{ t('integriq', 'Cancel') }}
					</NcButton>
				</template>
				<template v-else>
					<NcButton
						variant="primary"
						:disabled="!canAct || busy"
						@click="confirming = 'replay'">
						{{ t('integriq', 'Replay') }}
					</NcButton>
					<NcButton
						:disabled="!canAct || busy"
						@click="confirming = 'discard'">
						{{ t('integriq', 'Discard') }}
					</NcButton>
				</template>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcModal } from '@nextcloud/vue'
import deadLetterDetail from '../../mixins/deadLetterDetail.js'

export default {
	name: 'EventDeliveryDetailModal',

	components: { NcModal, NcButton },

	mixins: [deadLetterDetail],

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

	computed: {
		/**
		 * The dead-letter row the shared detail behaviour reads.
		 *
		 * @return {object|null}
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		record() {
			return this.message
		},

		/**
		 * Whether replay/discard verbs are permitted on this message.
		 *
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
		 *
		 * @return {string}
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-dead-letter-listing-and-detail-must-surface-action-kind-and-nextcloud-event-provenance-req-dlr-013
		 */
		actionKind() {
			return this.message?.actionKind || 'webhook'
		},

		/**
		 * Status-badge CSS modifier class.
		 *
		 * @return {string}
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		badgeClass() {
			return `deliveryDetail__badge--${this.message?.status || 'unknown'}`
		},
	},

	methods: {
		t,
		/**
		 * The app-relative URL of a verb on one event delivery.
		 *
		 * @param {string|number} id the message uuid or id
		 * @param {string} verb 'replay' or 'discard'
		 * @return {string} the URL
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		verbPath(id, verb) {
			return `/apps/integriq/api/events/dead-letter/${id}/${verb}`
		},

		/**
		 * The toast after a verb went through.
		 *
		 * @param {string} verb 'replay' or 'discard'
		 * @return {string} the translated text
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		doneText(verb) {
			return verb === 'replay'
				? t('integriq', 'Message replayed')
				: t('integriq', 'Message discarded')
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
	padding-inline-start: 18px;
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
	margin-inline-end: auto;
}
</style>

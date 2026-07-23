<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  SyncDeadLetterDetailModal — dead-lettered sync-item inspection + per-item
  Replay / Discard actions.

  Lives in its own file under src/modals/ per the modal-isolation hydra gate.
  It is page-local (driven by the parent SyncDeadLetterPage `open`/`entry`
  props rather than the shared modalBus) because the detail is only ever
  opened from the dead-letter list and carries that row's resolved payload.

  Shows: the raw source payload (pretty-printed), the full attempt timeline
  (REQ-DLR-008), and the replay/discard audit stamps when present. The
  Replay / Discard buttons are guarded by an inline confirmation step before
  any request is sent.

  @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-ui-in-the-synchronizations-section-req-dlr-012
-->
<template>
	<NcModal v-if="open"
		label-id="sync-dead-letter-detail"
		data-testid="sync-dead-letter-detail-modal"
		@close="$emit('close')">
		<div class="deadLetterDetail">
			<h2>{{ t('openconnector', 'Sync dead letter') }}</h2>

			<div v-if="entry" class="deadLetterDetail__body">
				<div class="deadLetterDetail__meta">
					<span class="deadLetterDetail__badge" :class="badgeClass">{{ entry.status }}</span>
					<span class="deadLetterDetail__retry">
						{{ t('openconnector', 'Attempts: {count}', { count: attempts.length }) }}
					</span>
				</div>

				<div v-if="entry.replayedBy || entry.discardedBy" class="deadLetterDetail__audit">
					<p v-if="entry.replayedBy">
						{{ t('openconnector', 'Replayed by {who} at {when}', { who: entry.replayedBy, when: entry.replayedAt }) }}
					</p>
					<p v-if="entry.discardedBy">
						{{ t('openconnector', 'Discarded by {who} at {when}', { who: entry.discardedBy, when: entry.discardedAt }) }}
					</p>
				</div>

				<h3>{{ t('openconnector', 'Error') }}</h3>
				<p class="deadLetterDetail__error">{{ entry.error }}</p>

				<h3>{{ t('openconnector', 'Attempt timeline') }}</h3>
				<ol v-if="attempts.length" class="deadLetterDetail__timeline" data-testid="attempt-timeline">
					<li v-for="(attempt, idx) in attempts" :key="idx">
						<span class="deadLetterDetail__time">{{ attempt.at }}</span>
						<span v-if="attempt.error" class="deadLetterDetail__attemptError">{{ attempt.error }}</span>
						<span v-else class="deadLetterDetail__attemptOk">{{ t('openconnector', 'OK') }}</span>
					</li>
				</ol>
				<p v-else class="deadLetterDetail__empty">{{ t('openconnector', 'No attempts recorded yet') }}</p>

				<h3>{{ t('openconnector', 'Payload') }}</h3>
				<pre class="deadLetterDetail__payload" data-testid="payload-viewer">{{ prettyPayload }}</pre>
			</div>

			<div class="deadLetterDetail__actions">
				<template v-if="confirming">
					<span class="deadLetterDetail__confirm">
						{{ confirming === 'replay'
							? t('openconnector', 'Replay this item now?')
							: t('openconnector', 'Discard this item? It will not be deleted.') }}
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
	name: 'SyncDeadLetterDetailModal',

	components: { NcModal, NcButton },

	props: {
		open: {
			type: Boolean,
			default: false,
		},
		entry: {
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
		 * The attempt audit trail for the timeline (REQ-DLR-008).
		 * @return {Array}
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-inspection-req-dlr-008
		 */
		attempts() {
			return Array.isArray(this.entry?.attempts) ? this.entry.attempts : []
		},
		/**
		 * Whether replay/discard verbs are permitted on this entry.
		 * @return {boolean}
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-audited-manual-replay-of-a-dead-lettered-sync-item-req-dlr-009
		 */
		canAct() {
			return this.entry?.status === 'failed'
		},
		/**
		 * Status-badge CSS modifier class.
		 * @return {string}
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-ui-in-the-synchronizations-section-req-dlr-012
		 */
		badgeClass() {
			return `deadLetterDetail__badge--${this.entry?.status || 'unknown'}`
		},
		/**
		 * Pretty-printed raw source payload for the viewer.
		 * @return {string}
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-inspection-req-dlr-008
		 */
		prettyPayload() {
			try {
				return JSON.stringify(this.entry?.payload ?? {}, null, 2)
			} catch (e) {
				return String(this.entry?.payload ?? '')
			}
		},
	},

	methods: {
		t,
		/**
		 * Commit the confirmed per-item verb (replay/discard).
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-audited-manual-replay-of-a-dead-lettered-sync-item-req-dlr-009
		 */
		async commit() {
			const verb = this.confirming
			const id = this.entry?.uuid || this.entry?.id
			if (!verb || !id) {
				return
			}
			this.busy = true
			try {
				await axios.post(generateUrl(`/apps/openconnector/api/sync-dead-letter/${id}/${verb}`))
				showSuccess(verb === 'replay'
					? t('openconnector', 'Item replayed')
					: t('openconnector', 'Item discarded'))
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
.deadLetterDetail {
	padding: 20px;
	min-width: 480px;
}

.deadLetterDetail__meta {
	display: flex;
	gap: 12px;
	align-items: center;
	margin-bottom: 12px;
}

.deadLetterDetail__badge {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	font-weight: 600;
}

.deadLetterDetail__badge--failed {
	background: var(--color-warning, #e9a13b);
	color: var(--color-primary-text);
}

.deadLetterDetail__badge--replayed {
	background: var(--color-success, #46ba61);
	color: var(--color-primary-text);
}

.deadLetterDetail__badge--discarded {
	background: var(--color-text-maxcontrast);
	color: var(--color-main-background);
}

.deadLetterDetail__error {
	color: var(--color-error);
	margin: 0 0 12px;
}

.deadLetterDetail__timeline {
	margin: 0 0 16px;
	padding-left: 18px;
}

.deadLetterDetail__timeline li {
	display: flex;
	gap: 12px;
	padding: 2px 0;
}

.deadLetterDetail__attemptError {
	color: var(--color-error);
}

.deadLetterDetail__payload {
	max-height: 240px;
	overflow: auto;
	background: var(--color-background-dark);
	padding: 12px;
	border-radius: var(--border-radius);
	font-family: monospace;
	white-space: pre;
}

.deadLetterDetail__actions {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-top: 16px;
}

.deadLetterDetail__confirm {
	margin-right: auto;
}
</style>

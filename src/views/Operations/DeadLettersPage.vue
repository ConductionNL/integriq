<!--
  DeadLettersPage — one operations surface for every dead letter.

  OpenConnector shipped TWO dead-letter pages with identical operator verbs
  (Replay / Discard, per-row and bulk) that differed only in which table they
  read: `event_message` (CloudEvent delivery failures, /api/events/dead-letter)
  and `sync_item_dead_letter` (sync items that exhausted retry + circuit
  breaker, /api/sync-dead-letter). SyncDeadLetterPage's own manifest note said
  "Mirrors EventDeliveries", and both rendered the same EmailAlertOutline glyph
  in the navigation — so the menu showed one icon twice for what looked like
  two unrelated things.

  This is a NAVIGATION merge, deliberately not a rewrite. The two queues stay
  separate underneath because they must: different schemas, different
  admin-only endpoints, different replay semantics. Merging their internals
  would risk two working operations surfaces for cosmetic gain. Instead this
  shell owns the queue switch and delegates wholesale to the existing,
  already-tested components.

  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  @spec openspec/specs/dead-letter-replay/spec.md
-->
<template>
	<div class="dead-letters-page">
		<NcAppContent>
			<div class="dead-letters-page__header">
				<h2 class="dead-letters-page__title">
					{{ t('openconnector', 'Dead letters') }}
				</h2>
				<!--
					Queue switch. A real control, not a cosmetic tab strip:
					each queue is a different backend with its own admin
					endpoint, so switching unmounts one surface and mounts
					the other rather than refiltering one list.
				-->
				<div
					class="dead-letters-page__queues"
					role="tablist"
					:aria-label="t('openconnector', 'Dead letter queues')">
					<NcButton
						v-for="queue in queues"
						:key="queue.id"
						role="tab"
						:aria-selected="activeQueue === queue.id ? 'true' : 'false'"
						:variant="activeQueue === queue.id ? 'primary' : 'tertiary'"
						:data-testid="`dead-letters-queue-${queue.id}`"
						@click="selectQueue(queue.id)">
						{{ queue.label }}
					</NcButton>
				</div>
			</div>

			<EventDeliveriesPage
				v-if="activeQueue === 'events'"
				data-testid="dead-letters-events" />
			<SyncDeadLetterPage v-else data-testid="dead-letters-sync" />
		</NcAppContent>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcAppContent, NcButton } from '@nextcloud/vue'
import EventDeliveriesPage from '../EventDelivery/EventDeliveriesPage.vue'
import SyncDeadLetterPage from '../Synchronization/SyncDeadLetterPage.vue'

/**
 * Query-param key carrying the active queue, so a link into a specific
 * queue survives a reload and can be deep-linked from an alert.
 */
const QUEUE_PARAM = 'queue'

export default {
	name: 'DeadLettersPage',

	components: {
		NcAppContent,
		NcButton,
		EventDeliveriesPage,
		SyncDeadLetterPage,
	},

	data() {
		return {
			/**
			 * Which queue is shown. Seeded from the route query so
			 * `/dead-letters?queue=sync` lands directly on the sync queue —
			 * the old `/synchronizations/dead-letters` route redirects here
			 * with exactly that param, so existing links keep working.
			 *
			 * @type {'events'|'sync'}
			 */
			activeQueue:
				this.$route?.query?.[QUEUE_PARAM] === 'sync' ? 'sync' : 'events',
		}
	},

	computed: {
		/**
		 * The selectable queues.
		 *
		 * @return {Array<{id: string, label: string}>}
		 *
		 * @spec openspec/specs/dead-letter-replay/spec.md
		 */
		queues() {
			return [
				{ id: 'events', label: t('openconnector', 'Event deliveries') },
				{ id: 'sync', label: t('openconnector', 'Synchronization items') },
			]
		},
	},

	watch: {
		/**
		 * Keep the mounted queue in step with the URL.
		 *
		 * `activeQueue` is seeded in data() at CREATION only, so without this
		 * a route change that keeps the component mounted — arriving at
		 * `?queue=sync` from `?queue=events`, which is exactly what the
		 * `/synchronizations/dead-letters` redirect does for anyone already on
		 * this page — would leave the previous queue rendered while the URL
		 * claimed the other one. Caught by the e2e legacy-route test.
		 *
		 * @param {string|undefined} value The new queue query param.
		 * @return {void}
		 *
		 * @spec openspec/specs/dead-letter-replay/spec.md
		 */
		'$route.query.queue': function (value) {
			const next = value === 'sync' ? 'sync' : 'events'
			if (next !== this.activeQueue) {
				this.activeQueue = next
			}
		},
	},

	methods: {
		t,

		/**
		 * Switch queue and reflect it in the URL, without stacking a history
		 * entry per click (`replace`, not `push`).
		 *
		 * @param {string} id The queue id.
		 * @return {void}
		 *
		 * @spec openspec/specs/dead-letter-replay/spec.md
		 */
		selectQueue(id) {
			if (this.activeQueue === id) return
			this.activeQueue = id
			const query = { ...(this.$route?.query || {}), [QUEUE_PARAM]: id }
			this.$router?.replace({ query }).catch(() => {})
		},
	},
}
</script>

<style scoped>
.dead-letters-page__header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--cn-space-m, 12px);
	padding: var(--cn-space-m, 12px) var(--cn-space-l, 16px) 0;
}

.dead-letters-page__title {
	margin: 0;
}

.dead-letters-page__queues {
	display: flex;
	gap: var(--cn-space-s, 8px);
}
</style>

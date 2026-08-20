<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  EventDeliveriesPage — the dead-letter operations view in the Cloud events
  section (manifest `type: custom`, `component: EventDeliveriesPage`).

  Why a custom page rather than a plain CnIndexPage: the dead-letter surface
  is a *filtered* operational view over event_message (default failed +
  abandoned) backed by a dedicated admin-only endpoint
  (`GET /api/events/dead-letter`), with bulk selection and per-row Replay /
  Discard verbs that hit the bespoke `/api/events/dead-letter/*` routes — not
  the generic OR object CRUD a CnIndexPage drives. It owns:

    - status / subscription filters,
    - loading + empty states,
    - a per-message detail modal (EventDeliveryDetailModal, its own file
      under src/modals/ per the modal-isolation gate),
    - bulk Replay / Discard with a confirmation step and per-item feedback.

  @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
-->
<template>
	<div class="eventDeliveries">
		<div class="eventDeliveries__header">
			<h2>{{ t('openconnector', 'Event deliveries') }}</h2>
			<div class="eventDeliveries__filters">
				<NcSelect
					v-model="statusFilter"
					:inputLabel="t('openconnector', 'Status')"
					:options="statusOptions"
					@update:modelValue="reload" />
				<NcTextField
					v-model="subscriptionFilter"
					:label="t('openconnector', 'Subscription')"
					@update:modelValue="reloadDebounced" />
				<NcCheckboxRadioSwitch
					:modelValue="nextcloudOnly"
					type="switch"
					data-testid="nextcloud-event-filter"
					@update:modelValue="(value) => (nextcloudOnly = value)">
					{{ t('openconnector', 'Nextcloud event') }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<div
			v-if="selected.length"
			class="eventDeliveries__bulk"
			data-testid="bulk-bar">
			<span>{{
				t('openconnector', '{count} selected', { count: selected.length })
			}}</span>
			<template v-if="bulkConfirm">
				<span>{{
					bulkConfirm === 'replay'
						? t('openconnector', 'Replay {count} messages now?', {
								count: selected.length,
							})
						: t('openconnector', 'Discard {count} messages?', {
								count: selected.length,
							})
				}}</span>
				<NcButton variant="primary" :disabled="busy" @click="commitBulk">
					{{ t('openconnector', 'Confirm') }}
				</NcButton>
				<NcButton :disabled="busy" @click="bulkConfirm = null">
					{{ t('openconnector', 'Cancel') }}
				</NcButton>
			</template>
			<template v-else>
				<NcButton
					variant="primary"
					:disabled="busy"
					@click="bulkConfirm = 'replay'">
					{{ t('openconnector', 'Replay selected') }}
				</NcButton>
				<NcButton :disabled="busy" @click="bulkConfirm = 'discard'">
					{{ t('openconnector', 'Discard selected') }}
				</NcButton>
			</template>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" class="eventDeliveries__loading" />

		<p
			v-else-if="!filteredRows.length"
			class="eventDeliveries__empty"
			data-testid="empty-state">
			{{ t('openconnector', 'No dead-lettered event deliveries') }}
		</p>

		<table v-else class="eventDeliveries__table" data-testid="deliveries-table">
			<thead>
				<tr>
					<th />
					<th scope="col">{{ t('openconnector', 'Event') }}</th>
					<th scope="col">{{ t('openconnector', 'Subscription') }}</th>
					<th scope="col">{{ t('openconnector', 'Action') }}</th>
					<th scope="col">{{ t('openconnector', 'Status') }}</th>
					<th scope="col">{{ t('openconnector', 'Retries') }}</th>
					<th scope="col">{{ t('openconnector', 'Last attempt') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in filteredRows" :key="row.uuid || row.id">
					<td>
						<NcCheckboxRadioSwitch
							:modelValue="isSelected(row)"
							:aria-label="
								t('openconnector', 'Select delivery {id}', {
									id: row.uuid || row.id,
								})
							"
							@update:modelValue="toggleSelect(row)" />
					</td>
					<td>{{ rowEventType(row) }}</td>
					<td>{{ row.subscription }}</td>
					<td>
						<span
							class="eventDeliveries__actionBadge"
							data-testid="action-kind-badge">
							{{ row.actionKind || 'webhook' }}
						</span>
					</td>
					<td>
						<span
							class="eventDeliveries__badge"
							:class="`eventDeliveries__badge--${row.status}`">
							{{ row.status }}
						</span>
					</td>
					<td>{{ row.retryCount || 0 }}</td>
					<td>{{ row.lastAttempt }}</td>
					<td>
						<NcButton variant="tertiary" @click="openDetail(row)">
							{{ t('openconnector', 'Inspect') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<EventDeliveryDetailModal
			:open="detail.open"
			:message="detail.message"
			@close="closeDetail"
			@changed="reload" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import EventDeliveryDetailModal from '../../modals/EventDelivery/EventDeliveryDetailModal.vue'

export default {
	name: 'EventDeliveriesPage',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		EventDeliveryDetailModal,
	},

	data() {
		return {
			rows: [],
			loading: false,
			statusFilter: 'failed,abandoned',
			subscriptionFilter: '',
			statusOptions: ['failed,abandoned', 'failed', 'abandoned', 'discarded'],
			// Nextcloud-event provenance filter (dead-letter-replay REQ-DLR-007):
			// client-side toggle over the already-fetched page — the backend
			// enriches every row with `nextcloudEvent` (derived from
			// `event.source` starting with `/nextcloud/`, NOT `event.type`,
			// because the pre-existing OR-object producer shares the
			// `com.nextcloud.` type prefix with these new producers).
			nextcloudOnly: false,
			selected: [],
			detail: { open: false, message: null },
			bulkConfirm: null,
			busy: false,
			reloadTimer: null,
		}
	},

	computed: {
		/**
		 * The visible rows after applying the Nextcloud-event provenance
		 * filter on top of the server-side status/subscription filters.
		 *
		 * @return {object[]}
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-dead-letter-listing-and-detail-must-surface-action-kind-and-nextcloud-event-provenance-req-dlr-013
		 */
		filteredRows() {
			if (!this.nextcloudOnly) return this.rows
			return this.rows.filter((row) => row.nextcloudEvent === true)
		},
	},

	mounted() {
		this.reload()
	},

	methods: {
		t,
		/**
		 * Resolve a row's CloudEvent type for the list column.
		 *
		 * @param {object} row Dead-letter message row.
		 * @return {string}
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		rowEventType(row) {
			return row?.payload?.type || row?.payload?.data?.type || '—'
		},

		isSelected(row) {
			return this.selected.includes(row.uuid || row.id)
		},

		/**
		 * Toggle a row in the bulk-selection set.
		 *
		 * @param {object} row Dead-letter message row.
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		toggleSelect(row) {
			const id = row.uuid || row.id
			if (this.selected.includes(id)) {
				this.selected = this.selected.filter((x) => x !== id)
			} else {
				this.selected = [...this.selected, id]
			}
		},

		/**
		 * Open the per-message detail modal.
		 *
		 * @param {object} row Dead-letter message row.
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		openDetail(row) {
			this.detail = { open: true, message: row }
		},

		/**
		 * Close the detail modal.
		 *
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		closeDetail() {
			this.detail = { open: false, message: null }
		},

		/**
		 * Debounced reload used by the subscription filter field.
		 *
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		reloadDebounced() {
			clearTimeout(this.reloadTimer)
			this.reloadTimer = setTimeout(() => this.reload(), 400)
		},

		/**
		 * Fetch the dead-letter listing from the admin-only endpoint.
		 *
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		async reload() {
			this.loading = true
			this.selected = []
			try {
				const params = { status: this.statusFilter }
				if (this.subscriptionFilter) {
					params.subscriptionId = this.subscriptionFilter
				}
				const res = await axios.get(
					generateUrl('/apps/openconnector/api/events/dead-letter'),
					{ params },
				)
				this.rows = res.data?.results || []
			} catch (err) {
				showError(t('openconnector', 'Failed to load event deliveries'))
				this.rows = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Apply the confirmed bulk verb (replay/discard) to the selected ids.
		 *
		 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-4
		 */
		async commitBulk() {
			const verb = this.bulkConfirm
			if (!verb || !this.selected.length) {
				return
			}
			this.busy = true
			try {
				const res = await axios.post(
					generateUrl(
						`/apps/openconnector/api/events/dead-letter/${verb}`,
					),
					{ ids: this.selected },
				)
				const results = res.data?.results || {}
				const ok = Object.values(results).filter((r) => r === 'ok').length
				const failed = Object.keys(results).length - ok
				if (failed > 0) {
					showError(
						t('openconnector', '{ok} processed, {failed} failed', {
							ok,
							failed,
						}),
					)
				} else {
					showSuccess(
						t('openconnector', '{ok} messages processed', { ok }),
					)
				}
				await this.reload()
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				showError(
					t('openconnector', 'Bulk action failed')
						+ (detail ? `: ${detail}` : ''),
				)
			} finally {
				this.busy = false
				this.bulkConfirm = null
			}
		},
	},
}
</script>

<style scoped>
.eventDeliveries {
	padding: 20px;
}

.eventDeliveries__header {
	display: flex;
	justify-content: space-between;
	align-items: flex-end;
	gap: 16px;
	flex-wrap: wrap;
	margin-bottom: 16px;
}

.eventDeliveries__filters {
	display: flex;
	gap: 12px;
	align-items: flex-end;
}

.eventDeliveries__bulk {
	display: flex;
	gap: 8px;
	align-items: center;
	padding: 8px 12px;
	margin-bottom: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.eventDeliveries__table {
	width: 100%;
	border-collapse: collapse;
}

.eventDeliveries__table th,
.eventDeliveries__table td {
	text-align: left;
	padding: 6px 10px;
	border-bottom: 1px solid var(--color-border);
}

.eventDeliveries__badge {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
}

.eventDeliveries__actionBadge {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-darker, var(--color-background-dark));
	color: var(--color-text-maxcontrast);
	text-transform: capitalize;
}

.eventDeliveries__badge--failed {
	background: var(--color-warning, #e9a13b);
	color: var(--color-primary-text);
}

.eventDeliveries__badge--abandoned {
	background: var(--color-error, #e9322d);
	color: var(--color-primary-text);
}

.eventDeliveries__badge--discarded {
	background: var(--color-text-maxcontrast);
	color: var(--color-main-background);
}

.eventDeliveries__loading,
.eventDeliveries__empty {
	margin: 32px auto;
	text-align: center;
	color: var(--color-text-maxcontrast);
}
</style>

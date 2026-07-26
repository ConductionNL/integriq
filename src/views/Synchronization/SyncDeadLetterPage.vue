<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  SyncDeadLetterPage — the sync-item dead-letter operations view in the
  Synchronizations section (manifest `type: custom`, `component:
  SyncDeadLetterPage`).

  Mirrors EventDeliveriesPage exactly, adapted for `sync_item_dead_letter`:
  a *filtered* operational view (default failed) backed by the admin-only
  `GET /api/sync-dead-letter` endpoint, with bulk selection and per-row
  Replay / Discard verbs hitting the `/api/sync-dead-letter/*` routes. It
  owns:

    - status / synchronization / time filters,
    - loading + empty states,
    - a per-entry detail modal (SyncDeadLetterDetailModal, its own file under
      src/modals/ per the modal-isolation gate),
    - bulk Replay / Discard with a confirmation step and per-item feedback.

  @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-ui-in-the-synchronizations-section-req-dlr-012
-->
<template>
	<div class="syncDeadLetters">
		<div class="syncDeadLetters__header">
			<h2>{{ t('openconnector', 'Sync dead letters') }}</h2>
			<div class="syncDeadLetters__filters">
				<NcSelect :input-label="t('openconnector', 'Status')"
					:options="statusOptions"
					v-model="statusFilter"
					@update:model-value="reload" />
				<NcTextField :label="t('openconnector', 'Synchronization')"
					v-model="synchronizationFilter"
					@update:model-value="reloadDebounced" />
			</div>
		</div>

		<div v-if="selected.length" class="syncDeadLetters__bulk" data-testid="bulk-bar">
			<span>{{ t('openconnector', '{count} selected', { count: selected.length }) }}</span>
			<template v-if="bulkConfirm">
				<span>{{ bulkConfirm === 'replay'
					? t('openconnector', 'Replay {count} items now?', { count: selected.length })
					: t('openconnector', 'Discard {count} items?', { count: selected.length }) }}</span>
				<NcButton type="primary" :disabled="busy" @click="commitBulk">
					{{ t('openconnector', 'Confirm') }}
				</NcButton>
				<NcButton :disabled="busy" @click="bulkConfirm = null">
					{{ t('openconnector', 'Cancel') }}
				</NcButton>
			</template>
			<template v-else>
				<NcButton type="primary" :disabled="busy" @click="bulkConfirm = 'replay'">
					{{ t('openconnector', 'Replay selected') }}
				</NcButton>
				<NcButton :disabled="busy" @click="bulkConfirm = 'discard'">
					{{ t('openconnector', 'Discard selected') }}
				</NcButton>
			</template>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" class="syncDeadLetters__loading" />

		<p v-else-if="!rows.length" class="syncDeadLetters__empty" data-testid="empty-state">
			{{ t('openconnector', 'No dead-lettered sync items') }}
		</p>

		<table v-else class="syncDeadLetters__table" data-testid="dead-letters-table">
			<thead>
				<tr>
					<th />
					<th>{{ t('openconnector', 'Synchronization') }}</th>
					<th>{{ t('openconnector', 'Origin ID') }}</th>
					<th>{{ t('openconnector', 'Status') }}</th>
					<th>{{ t('openconnector', 'Retries') }}</th>
					<th>{{ t('openconnector', 'Created') }}</th>
					<th>{{ t('openconnector', 'Error') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rows" :key="row.uuid || row.id">
					<td>
						<NcCheckboxRadioSwitch :model-value="isSelected(row)"
							@update:model-value="toggleSelect(row)" />
					</td>
					<td>{{ row.synchronization }}</td>
					<td>{{ row.originId || '—' }}</td>
					<td>
						<span class="syncDeadLetters__badge" :class="`syncDeadLetters__badge--${row.status}`">
							{{ row.status }}
						</span>
					</td>
					<td>{{ row.retryCount || 0 }}</td>
					<td>{{ row.created }}</td>
					<td class="syncDeadLetters__errorCell">
						{{ row.errorPreview || row.error }}
					</td>
					<td>
						<NcButton type="tertiary" @click="openDetail(row)">
							{{ t('openconnector', 'Inspect') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<SyncDeadLetterDetailModal :open="detail.open"
			:entry="detail.entry"
			@close="closeDetail"
			@changed="reload" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import SyncDeadLetterDetailModal from '../../modals/Synchronization/SyncDeadLetterDetailModal.vue'

export default {
	name: 'SyncDeadLetterPage',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		SyncDeadLetterDetailModal,
	},

	data() {
		return {
			rows: [],
			loading: false,
			statusFilter: 'failed',
			synchronizationFilter: '',
			statusOptions: ['failed', 'replayed', 'discarded'],
			selected: [],
			detail: { open: false, entry: null },
			bulkConfirm: null,
			busy: false,
			reloadTimer: null,
		}
	},

	mounted() {
		this.reload()
	},

	methods: {
		t,
		isSelected(row) {
			return this.selected.includes(row.uuid || row.id)
		},
		/**
		 * Toggle a row in the bulk-selection set.
		 * @param {object} row Dead-letter entry row.
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-ui-in-the-synchronizations-section-req-dlr-012
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
		 * Open the per-entry detail modal.
		 * @param {object} row Dead-letter entry row.
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-ui-in-the-synchronizations-section-req-dlr-012
		 */
		openDetail(row) {
			this.detail = { open: true, entry: row }
		},
		/**
		 * Close the detail modal.
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-ui-in-the-synchronizations-section-req-dlr-012
		 */
		closeDetail() {
			this.detail = { open: false, entry: null }
		},
		/**
		 * Debounced reload used by the synchronization filter field.
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-ui-in-the-synchronizations-section-req-dlr-012
		 */
		reloadDebounced() {
			clearTimeout(this.reloadTimer)
			this.reloadTimer = setTimeout(() => this.reload(), 400)
		},
		/**
		 * Fetch the sync dead-letter listing from the admin-only endpoint.
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-listing-with-filters-and-pagination-req-dlr-007
		 */
		async reload() {
			this.loading = true
			this.selected = []
			try {
				const params = { status: this.statusFilter }
				if (this.synchronizationFilter) {
					params.synchronizationId = this.synchronizationFilter
				}
				const res = await axios.get(generateUrl('/apps/openconnector/api/sync-dead-letter'), { params })
				this.rows = res.data?.results || []
			} catch (err) {
				showError(t('openconnector', 'Failed to load sync dead letters'))
				this.rows = []
			} finally {
				this.loading = false
			}
		},
		/**
		 * Apply the confirmed bulk verb (replay/discard) to the selected ids.
		 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-bulk-replay-and-discard-for-sync-item-dead-letters-req-dlr-011
		 */
		async commitBulk() {
			const verb = this.bulkConfirm
			if (!verb || !this.selected.length) {
				return
			}
			this.busy = true
			try {
				const res = await axios.post(
					generateUrl(`/apps/openconnector/api/sync-dead-letter/${verb}`),
					{ ids: this.selected },
				)
				const results = res.data?.results || {}
				const ok = Object.values(results).filter((r) => r === 'ok').length
				const failed = Object.keys(results).length - ok
				if (failed > 0) {
					showError(t('openconnector', '{ok} processed, {failed} failed', { ok, failed }))
				} else {
					showSuccess(t('openconnector', '{ok} items processed', { ok }))
				}
				await this.reload()
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				showError(t('openconnector', 'Bulk action failed') + (detail ? `: ${detail}` : ''))
			} finally {
				this.busy = false
				this.bulkConfirm = null
			}
		},
	},
}
</script>

<style scoped>
.syncDeadLetters {
	padding: 20px;
}

.syncDeadLetters__header {
	display: flex;
	justify-content: space-between;
	align-items: flex-end;
	gap: 16px;
	flex-wrap: wrap;
	margin-bottom: 16px;
}

.syncDeadLetters__filters {
	display: flex;
	gap: 12px;
	align-items: flex-end;
}

.syncDeadLetters__bulk {
	display: flex;
	gap: 8px;
	align-items: center;
	padding: 8px 12px;
	margin-bottom: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.syncDeadLetters__table {
	width: 100%;
	border-collapse: collapse;
}

.syncDeadLetters__table th,
.syncDeadLetters__table td {
	text-align: left;
	padding: 6px 10px;
	border-bottom: 1px solid var(--color-border);
}

.syncDeadLetters__errorCell {
	max-width: 320px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.syncDeadLetters__badge {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
}

.syncDeadLetters__badge--failed {
	background: var(--color-warning, #e9a13b);
	color: var(--color-primary-text);
}

.syncDeadLetters__badge--replayed {
	background: var(--color-success, #46ba61);
	color: var(--color-primary-text);
}

.syncDeadLetters__badge--discarded {
	background: var(--color-text-maxcontrast);
	color: var(--color-main-background);
}

.syncDeadLetters__loading,
.syncDeadLetters__empty {
	margin: 32px auto;
	text-align: center;
	color: var(--color-text-maxcontrast);
}
</style>

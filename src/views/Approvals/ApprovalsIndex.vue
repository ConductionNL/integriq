<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  ApprovalsIndex — the Pending Approvals operations view (manifest
  `type: custom`, `component: ApprovalsIndex`).

  Why a custom page rather than a plain CnIndexPage: the Approvals surface is
  a *filtered* operational view over approval_request backed by a dedicated
  two-layer-authorized endpoint (`GET /api/approvals`, which returns only the
  rows the caller may act on), with a status filter
  (pending/approved/rejected/expired/dead_letter) and per-row navigation into
  a detail page whose Approve/Reject verbs hit the bespoke
  `/api/approvals/{id}/approve|reject` routes — not the generic OR object CRUD
  a CnIndexPage drives. Mirrors the established EventDeliveriesPage precedent.

  @spec openspec/specs/approval-workflow/spec.md
-->
<template>
	<div class="approvals">
		<div class="approvals__header">
			<h2>{{ t('openconnector', 'Approvals') }}</h2>
			<div class="approvals__filters">
				<NcSelect v-model="statusFilter"
					:input-label="t('openconnector', 'Status')"
					:options="statusOptions"
					@update:model-value="reload" />
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" class="approvals__loading" />

		<NcEmptyContent v-else-if="!rows.length"
			data-testid="empty-state"
			:name="t('openconnector', 'No approval requests')"
			:description="t('openconnector', 'There are no approval requests matching this filter.')">
			<template #icon>
				<CheckboxMarkedCircleOutline :size="48" />
			</template>
		</NcEmptyContent>

		<table v-else class="approvals__table" data-testid="approvals-table">
			<thead>
				<tr>
					<th scope="col">{{ t('openconnector', 'Status') }}</th>
					<th scope="col">{{ t('openconnector', 'Approver group') }}</th>
					<th scope="col">{{ t('openconnector', 'Requester') }}</th>
					<th scope="col">{{ t('openconnector', 'Created') }}</th>
					<th scope="col">{{ t('openconnector', 'Expires') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rows" :key="row.id">
					<td>
						<span class="approvals__badge" :class="`approvals__badge--${row.status}`">
							{{ row.status }}
						</span>
					</td>
					<td>{{ row.approverGroup }}</td>
					<td>{{ row.requester || '—' }}</td>
					<td>{{ row.createdAt || '—' }}</td>
					<td>{{ row.expiresAt || '—' }}</td>
					<td>
						<NcButton type="tertiary" @click="openDetail(row)">
							{{ t('openconnector', 'Open') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcSelect,
} from '@nextcloud/vue'
import CheckboxMarkedCircleOutline from 'vue-material-design-icons/CheckboxMarkedCircleOutline.vue'

export default {
	name: 'ApprovalsIndex',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		CheckboxMarkedCircleOutline,
	},

	data() {
		return {
			rows: [],
			loading: false,
			statusFilter: 'pending',
			statusOptions: ['pending', 'approved', 'rejected', 'expired', 'dead_letter'],
		}
	},

	mounted() {
		this.reload()
	},

	methods: {
		t,
		/**
		 * Navigate to a request's detail page.
		 * @param {object} row Approval request row.
		 * @spec openspec/specs/approval-workflow/spec.md
		 */
		openDetail(row) {
			this.$router.push(`/approvals/${row.id}`)
		},
		/**
		 * Fetch the caller-visible approval requests from the two-layer-authorized endpoint.
		 * @spec openspec/specs/approval-workflow/spec.md
		 */
		async reload() {
			this.loading = true
			try {
				const params = {}
				if (this.statusFilter) {
					params.status = this.statusFilter
				}
				const res = await axios.get(generateUrl('/apps/openconnector/api/approvals'), { params })
				this.rows = res.data?.results || []
			} catch (err) {
				showError(t('openconnector', 'Failed to load approval requests'))
				this.rows = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.approvals {
	padding: 20px;
}

.approvals__header {
	display: flex;
	justify-content: space-between;
	align-items: flex-end;
	gap: 16px;
	flex-wrap: wrap;
	margin-bottom: 16px;
}

.approvals__filters {
	display: flex;
	gap: 12px;
	align-items: flex-end;
}

.approvals__table {
	width: 100%;
	border-collapse: collapse;
}

.approvals__table th,
.approvals__table td {
	text-align: left;
	padding: 6px 10px;
	border-bottom: 1px solid var(--color-border);
}

.approvals__badge {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
}

.approvals__badge--pending {
	background: var(--color-warning, #e9a13b);
	color: var(--color-primary-text);
}

.approvals__badge--approved {
	background: var(--color-success, #46ba61);
	color: var(--color-primary-text);
}

.approvals__badge--rejected,
.approvals__badge--dead_letter {
	background: var(--color-error, #e9322d);
	color: var(--color-primary-text);
}

.approvals__badge--expired {
	background: var(--color-text-maxcontrast);
	color: var(--color-main-background);
}

.approvals__loading {
	margin: 32px auto;
}
</style>

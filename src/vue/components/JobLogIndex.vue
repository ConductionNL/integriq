<template>
	<div class="job-logs">
		<div v-if="logStore.loading" class="loading">
			<span class="icon icon-loading" />
			{{ t('openconnector', 'Loading job logs...') }}
		</div>
		<div v-else-if="logStore.error" class="error">
			{{ logStore.error }}
		</div>
		<div v-else>
			<div v-if="logs.length === 0" class="empty">
				{{ t('openconnector', 'No logs found') }}
			</div>
			<div v-else>
				<div :class="['table-container', { 'loading': logStore.loading }]">
					<table class="job-logs-table">
						<thead>
							<tr>
								<th>{{ t('openconnector', 'Created') }}</th>
								<th>{{ t('openconnector', 'Status') }}</th>
								<th>{{ t('openconnector', 'Message') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="log in logs" :key="log.id">
								<td>{{ new Date(log.created_at).toLocaleString() }}</td>
								<td>
									<span :class="['status-badge', log.status.toLowerCase()]">
										{{ log.status }}
									</span>
								</td>
								<td>{{ log.message }}</td>
							</tr>
						</tbody>
					</table>
					<div v-if="logStore.loading" class="table-loading-overlay">
						<span class="icon icon-loading" />
					</div>
				</div>

				<!-- Pagination Controls -->
				<div v-if="pagination.pages > 1" class="pagination">
					<button
						:disabled="pagination.currentPage === 1 || logStore.loading"
						class="pagination-button"
						@click="changePage(pagination.currentPage - 1)">
						{{ t('openconnector', 'Previous') }}
					</button>

					<span class="pagination-info">
						{{ t('openconnector', 'Page {current} of {total}', {
							current: pagination.currentPage,
							total: pagination.pages
						}) }}
					</span>

					<button
						:disabled="pagination.currentPage === pagination.pages || logStore.loading"
						class="pagination-button"
						@click="changePage(pagination.currentPage + 1)">
						{{ t('openconnector', 'Next') }}
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { defineComponent } from 'vue'
import { showError } from '@nextcloud/dialogs'
import { logStore } from '../../store/store.js'

export default defineComponent({
	name: 'JobLogIndex',

	props: {
		jobId: {
			type: Number,
			required: true,
		},
	},

	data() {
		return {
			logs: [],
			pagination: {
				total: 0,
				pages: 0,
				currentPage: 1,
				perPage: 10,
			},
		}
	},

	mounted() {
		this.fetchLogs()
	},

	methods: {
		async fetchLogs() {
			try {
				const response = await fetch(`/index.php/apps/openconnector/api/jobs/${this.jobId}/logs?page=${this.pagination.currentPage}&limit=${this.pagination.perPage}`)
				const data = await response.json()

				if (!response.ok) {
					throw new Error(data.error || 'Failed to fetch job logs')
				}

				this.logs = data.data
				this.pagination = data.pagination
			} catch (error) {
				showError(t('openconnector', 'Failed to load job logs'))
			}
		},

		async changePage(page) {
			if (logStore.loading) return

			this.pagination.currentPage = page
			await this.fetchLogs()
		},
	},
})
</script>

<style scoped>
.job-logs {
	padding: 20px;
}

.loading, .error, .empty {
	text-align: center;
	padding: 20px;
	color: var(--color-text-lighter);
}

.table-container {
	position: relative;
	margin-bottom: 20px;
}

.table-loading-overlay {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(255, 255, 255, 0.7);
	display: flex;
	justify-content: center;
	align-items: center;
	z-index: 1;
}

.job-logs-table {
	width: 100%;
	border-collapse: collapse;
}

.job-logs-table th,
.job-logs-table td {
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.job-logs-table th {
	font-weight: bold;
	color: var(--color-text-lighter);
}

.status-badge {
	padding: 4px 8px;
	border-radius: 12px;
	font-size: 0.9em;
}

.status-badge.success {
	background-color: var(--color-success);
	color: white;
}

.status-badge.error {
	background-color: var(--color-error);
	color: white;
}

.status-badge.warning {
	background-color: var(--color-warning);
	color: white;
}

.pagination {
	display: flex;
	justify-content: center;
	align-items: center;
	gap: 16px;
	margin-top: 20px;
}

.pagination-button {
	padding: 8px 16px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background: var(--color-background);
	cursor: pointer;
}

.pagination-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.pagination-info {
	color: var(--color-text-lighter);
}
</style>

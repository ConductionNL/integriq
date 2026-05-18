<script setup>
import { synchronizationStore, navigationStore } from '../../store/store.js'
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openconnector', 'Synchronization Logs')"
			:description="t('openconnector', 'Monitor and analyze synchronization logs and their performance')"
			:show-title="true"
			:objects="filteredLogs"
			:columns="tableColumns"
			:pagination="paginationData"
			:loading="synchronizationStore.loading"
			:refreshing="refreshing"
			:inline-action-count="3"
			view-mode="table"
			:show-view-toggle="false"
			:show-add="false"
			:selectable="true"
			:selected-ids="selectedLogs"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-delete-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			row-key="id"
			:empty-text="emptyContentName"
			:row-class="getLogStatusClass"
			@refresh="refreshLogs"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@select="onSelect">
			<template #action-items>
				<NcActionButton
					v-if="selectedLogs.length > 0"
					type="error"
					:disabled="deletingLogs"
					close-after-click
					@click="bulkDeleteLogs">
					<template #icon>
						<NcLoadingIcon v-if="deletingLogs" :size="20" />
						<Delete v-else :size="20" />
					</template>
					{{ deletingLogs ? t('openconnector', 'Deleting...') : t('openconnector', 'Delete ({count})', { count: selectedLogs.length }) }}
				</NcActionButton>
				<NcActionButton close-after-click @click="exportLogs">
					<template #icon>
						<FileExportOutline :size="20" />
					</template>
					{{ t('openconnector', 'Export') }}
				</NcActionButton>
			</template>

			<template #column-status="{ row: log }">
				<CnStatusBadge
					:label="log.message === 'Success' ? t('openconnector', 'Success') : t('openconnector', 'Error')"
					:variant="log.message === 'Success' ? 'success' : 'error'" />
			</template>

			<template #column-synchronization="{ row: log }">
				{{ getSynchronizationName(log.synchronizationId) }}
			</template>

			<template #column-details="{ row: log }">
				<span v-if="log.message === 'Success'">{{ getObjectsSummary(log) }}</span>
				<span v-else>{{ log.message }}</span>
			</template>

			<template #column-executionTime="{ row: log }">
				<span :class="getExecutionTimeClass(log)">
					{{ (getExecutionTime(log) / 1000).toFixed(3) }}s
				</span>
			</template>

			<template #column-created="{ row: log }">
				<NcDateTime v-if="log.created" :timestamp="new Date(log.created)" />
			</template>

			<template #row-actions="{ row: log }">
				<NcActions>
					<NcActionButton close-after-click @click="viewLogDetails(log)">
						<template #icon>
							<Eye :size="20" />
						</template>
						{{ t('openconnector', 'View Details') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="copyLogData(log)">
						<template #icon>
							<Check v-if="copyStates[log.id]" :size="20" class="copySuccessIcon" />
							<ContentCopy v-else :size="20" />
						</template>
						{{ copyStates[log.id] ? t('openconnector', 'Copied!') : t('openconnector', 'Copy Data') }}
					</NcActionButton>
					<NcActionButton
						:disabled="deletingLogs"
						close-after-click
						class="deleteAction"
						@click="deleteLog(log)">
						<template #icon>
							<NcLoadingIcon v-if="deletingLogs" :size="20" />
							<Delete v-else :size="20" />
						</template>
						{{ deletingLogs ? t('openconnector', 'Deleting...') : t('openconnector', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActions, NcActionButton, NcDateTime, NcLoadingIcon } from '@nextcloud/vue'
import { CnIndexPage, CnStatusBadge } from '@conduction/nextcloud-vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Check from 'vue-material-design-icons/Check.vue'

export default {
	name: 'SynchronizationLogIndex',
	components: {
		NcAppContent,
		NcActions,
		NcActionButton,
		NcDateTime,
		NcLoadingIcon,
		CnIndexPage,
		CnStatusBadge,
		Delete,
		Eye,
		FileExportOutline,
		ContentCopy,
		Check,
	},
	data() {
		return {
			synchronizationStore,
			navigationStore,
			selectedLogs: [],
			copyStates: {},
			refreshing: false,
			deletingLogs: false,
			filters: {},
			pagination: {
				page: 1,
				limit: 20,
			},
		}
	},
	computed: {
		filteredLogs() {
			const logs = this.synchronizationStore.synchronizationLogs
			if (!logs) return []
			if (Array.isArray(logs.results)) return logs.results
			return Array.isArray(logs) ? logs : []
		},
		totalLogs() {
			return this.synchronizationStore.synchronizationLogs?.total ?? this.filteredLogs.length
		},
		tableColumns() {
			return [
				{ key: 'status', label: t('openconnector', 'Status') },
				{ key: 'synchronization', label: t('openconnector', 'Synchronization') },
				{ key: 'details', label: t('openconnector', 'Details') },
				{ key: 'executionTime', label: t('openconnector', 'Execution Time') },
				{ key: 'created', label: t('openconnector', 'Created') },
			]
		},
		paginationData() {
			const limit = this.pagination.limit || 20
			const total = this.totalLogs
			const apiPage = this.synchronizationStore.synchronizationLogs?.page
			const apiPages = this.synchronizationStore.synchronizationLogs?.pages
			return {
				page: apiPage || this.pagination.page || 1,
				pages: apiPages || Math.max(1, Math.ceil(total / limit)),
				total,
				limit,
			}
		},
		emptyContentName() {
			if (this.synchronizationStore.error) return this.synchronizationStore.error
			if (!this.filteredLogs.length) return t('openconnector', 'No synchronization logs are available.')
			return t('openconnector', 'Loading synchronization logs...')
		},
	},
	watch: {
		filteredLogs(logs) {
			this.$root.$emit('logs-filtered-count', logs.length)
		},
	},
	mounted() {
		this.loadLogs()
		this.$root.$emit('logs-filtered-count', this.filteredLogs.length)
		this.$root.$emit('logs-selection-count', this.selectedLogs.length)
		this.$root.$on('logs-filters-changed', this.onFiltersChanged)
		this.$root.$on('logs-bulk-delete', this.bulkDeleteLogs)
		this.$root.$on('logs-export-filtered', this.exportLogs)
	},
	beforeDestroy() {
		this.$root.$off('logs-filters-changed', this.onFiltersChanged)
		this.$root.$off('logs-bulk-delete', this.bulkDeleteLogs)
		this.$root.$off('logs-export-filtered', this.exportLogs)
	},
	methods: {
		async loadLogs() {
			await this.synchronizationStore.refreshSynchronizationLogs({
				page: this.pagination.page,
				limit: this.pagination.limit,
				...this.filters,
			})
		},
		onFiltersChanged(filters) {
			this.filters = filters || {}
			this.pagination.page = 1
			this.loadLogs()
		},
		onSelect(ids) {
			this.selectedLogs = ids
			this.$root.$emit('logs-selection-count', ids.length)
		},
		async onPageChanged(page) {
			this.pagination.page = page
			await this.loadLogs()
		},
		async onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
			await this.loadLogs()
		},
		getLogStatusClass(log) {
			return log.message === 'Success' ? 'successStatus' : 'clientErrorStatus'
		},
		getExecutionTime(log) {
			return log.result?.timing?.total_ms || log.executionTime || 0
		},
		getExecutionTimeClass(log) {
			const ms = this.getExecutionTime(log)
			if (ms < 5000) return 'fast-response'
			if (ms < 30000) return 'medium-response'
			return 'slow-response'
		},
		viewLogDetails() {
			// TODO: Implement view log details modal
		},
		async copyLogData(log) {
			try {
				const data = JSON.stringify(log, null, 2)
				await navigator.clipboard.writeText(data)

				this.$set(this.copyStates, log.id, true)
				setTimeout(() => {
					this.$set(this.copyStates, log.id, false)
				}, 2000)

				OC.Notification.showSuccess(t('openconnector', 'Log data copied to clipboard'))
			} catch (error) {
				console.error('Error copying to clipboard:', error)
				OC.Notification.showError(t('openconnector', 'Failed to copy data to clipboard'))
			}
		},
		async deleteLog(log) {
			this.deletingLogs = true
			try {
				const result = await this.synchronizationStore.deleteSynchronizationLog(log.id.toString())
				if (result.response.ok) {
					OC.Notification.showSuccess(t('openconnector', 'Log deleted successfully'))
					this.selectedLogs = this.selectedLogs.filter(id => id !== log.id)
					let targetPage = this.pagination.page
					if (this.filteredLogs.length === 1 && this.pagination.page > 1) {
						targetPage = this.pagination.page - 1
						this.pagination.page = targetPage
					}
					await this.synchronizationStore.refreshSynchronizationLogs({
						page: targetPage,
						limit: this.pagination.limit,
						...this.filters,
					})
				} else {
					const errorData = await result.response.json()
					OC.Notification.showError(t('openconnector', 'Failed to delete log: {error}', { error: errorData.error || 'Unknown error' }))
				}
			} catch (error) {
				OC.Notification.showError(t('openconnector', 'Failed to delete log'))
			} finally {
				this.deletingLogs = false
			}
		},
		async bulkDeleteLogs() {
			if (this.selectedLogs.length === 0) {
				OC.Notification.showError(t('openconnector', 'No logs selected'))
				return
			}

			this.deletingLogs = true
			try {
				const totalLogs = this.selectedLogs.length
				let deletedCount = 0
				const errors = []

				for (const logId of this.selectedLogs) {
					try {
						const result = await this.synchronizationStore.deleteSynchronizationLog(logId.toString())
						if (result.response.ok) {
							deletedCount++
						} else {
							const errorData = await result.response.json()
							errors.push(`Log ${logId}: ${errorData.error || 'Unknown error'}`)
						}
					} catch (error) {
						console.error(`Error deleting log ${logId}:`, error)
						errors.push(`Log ${logId}: Network error`)
					}
				}

				if (deletedCount === totalLogs) {
					OC.Notification.showSuccess(t('openconnector', 'All {count} selected logs deleted successfully', { count: deletedCount }))
				} else if (deletedCount > 0) {
					OC.Notification.showError(t('openconnector', 'Deleted {deleted} of {total} logs. Errors: {errors}', {
						deleted: deletedCount,
						total: totalLogs,
						errors: errors.slice(0, 3).join(', ') + (errors.length > 3 ? '...' : ''),
					}))
				} else {
					OC.Notification.showError(t('openconnector', 'Failed to delete any logs: {errors}', {
						errors: errors.slice(0, 3).join(', ') + (errors.length > 3 ? '...' : ''),
					}))
				}

				const remainingLogsOnPage = this.filteredLogs.length - deletedCount
				let targetPage = this.pagination.page
				if (remainingLogsOnPage === 0 && this.pagination.page > 1) {
					targetPage = this.pagination.page - 1
					this.pagination.page = targetPage
				}
				await this.synchronizationStore.refreshSynchronizationLogs({
					page: targetPage,
					limit: this.pagination.limit,
					...this.filters,
				})
			} finally {
				this.deletingLogs = false
				this.selectedLogs = []
				this.$root.$emit('logs-selection-count', 0)
			}
		},
		async exportLogs() {
			try {
				const result = await this.synchronizationStore.exportSynchronizationLogs()
				if (result.response.ok) {
					OC.Notification.showSuccess(t('openconnector', 'Logs exported successfully'))
				} else {
					OC.Notification.showError(t('openconnector', 'Failed to export logs'))
				}
			} catch (error) {
				console.error('Error exporting logs:', error)
				OC.Notification.showError(t('openconnector', 'Failed to export logs'))
			}
		},
		async refreshLogs() {
			this.refreshing = true
			try {
				await this.loadLogs()
				this.selectedLogs = []
				this.$root.$emit('logs-selection-count', 0)
			} catch (error) {
				OC.Notification.showError(t('openconnector', 'Failed to refresh logs'))
			} finally {
				this.refreshing = false
			}
		},
		getSynchronizationName(synchronizationId) {
			if (!synchronizationId) return t('openconnector', 'Unknown')
			const sync = this.synchronizationStore.synchronizationList?.find(s => String(s.id) === String(synchronizationId))
			return sync?.name || `Sync ${synchronizationId}`
		},
		getObjectsSummary(log) {
			const o = log.result?.objects || {}
			return `Found: ${o.found ?? 0}, Created: ${o.created ?? 0}, Updated: ${o.updated ?? 0}, Deleted: ${o.deleted ?? 0}, Skipped: ${o.skipped ?? 0}, Invalid: ${o.invalid ?? 0}`
		},
	},
}
</script>

<style scoped>
.fast-response {
	color: var(--color-success);
	font-weight: 600;
}

.medium-response {
	color: var(--color-warning);
	font-weight: 600;
}

.slow-response {
	color: var(--color-error);
	font-weight: 600;
}

.copySuccessIcon {
	color: var(--color-success) !important;
}

:deep(.deleteAction) {
	color: var(--color-error) !important;
}

:deep(.deleteAction:hover) {
	background-color: var(--color-error) !important;
	color: var(--color-main-background) !important;
}
</style>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { logStore, navigationStore, jobStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openconnector', 'Job Logs')"
			:description="t('openconnector', 'Monitor and analyze job execution logs and their performance')"
			:show-title="true"
			:objects="filteredLogs"
			:columns="tableColumns"
			:pagination="paginationData"
			:loading="logStore.loading"
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
			:row-class="getLogLevelClass"
			@refresh="refreshLogs"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@select="onSelect">
			<!-- Action bar extras: Export and bulk Delete (kept inline via :inline-action-count) -->
			<template #action-items>
				<NcActionButton
					v-if="selectedLogs.length > 0"
					type="error"
					close-after-click
					@click="bulkDeleteLogs">
					<template #icon>
						<Delete :size="20" />
					</template>
					{{ t('openconnector', 'Delete ({count})', { count: selectedLogs.length }) }}
				</NcActionButton>
				<NcActionButton
					close-after-click
					@click="exportLogs">
					<template #icon>
						<FileExportOutline :size="20" />
					</template>
					{{ t('openconnector', 'Export') }}
				</NcActionButton>
			</template>

			<!-- Level column -->
			<template #column-level="{ row: log }">
				<span class="levelBadge" :class="getLogLevelClass(log)">
					<CheckCircle v-if="log.level === 'SUCCESS'" :size="16" />
					<AlertCircle v-else-if="log.level === 'WARNING'" :size="16" />
					<CloseCircle v-else-if="['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'].includes(log.level)" :size="16" />
					<InformationOutline v-else :size="16" />
					{{ log.level }}
				</span>
			</template>

			<!-- Job column -->
			<template #column-job="{ row: log }">
				<div class="jobInfo">
					<span class="jobName">{{ getJobName(log.jobId) }}</span>
					<span v-if="log.jobType" class="jobType" :title="t('openconnector', 'Job type')">
						{{ log.jobType }}
					</span>
				</div>
			</template>

			<!-- Message column -->
			<template #column-message="{ row: log }">
				<div class="messageInfo">
					<span class="messageText">{{ log.message }}</span>
					<span v-if="log.context" class="contextIndicator" :title="JSON.stringify(log.context)">
						<DatabaseSearch :size="14" />
					</span>
				</div>
			</template>

			<!-- Execution time column -->
			<template #column-executionTime="{ row: log }">
				<div class="executionInfo">
					<span v-if="log.executionTime" :class="getExecutionTimeClass(log.executionTime)">
						{{ (log.executionTime / 1000).toFixed(3) }}s
					</span>
					<span v-if="log.memoryUsage" class="memoryUsage" :title="t('openconnector', 'Memory usage')">
						{{ formatBytes(log.memoryUsage) }}
					</span>
					<span v-else>-</span>
				</div>
			</template>

			<!-- Created column -->
			<template #column-created="{ row: log }">
				<div class="timestampInfo">
					<NcDateTime v-if="log.created" class="createdTime" :timestamp="new Date(log.created)" />
					<span v-if="log.expires" class="expiresTime" :title="t('openconnector', 'Expires at')">
						<NcDateTime :timestamp="new Date(log.expires)" />
					</span>
				</div>
			</template>

			<!-- Row actions -->
			<template #row-actions="{ row: log }">
				<NcActions>
					<NcActionButton close-after-click @click="viewLogDetails(log)">
						<template #icon>
							<Eye :size="20" />
						</template>
						{{ t('openconnector', 'View details') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="copyLogData(log)">
						<template #icon>
							<Check v-if="copyStates[log.id]" :size="20" class="copySuccessIcon" />
							<ContentCopy v-else :size="20" />
						</template>
						{{ copyStates[log.id] ? t('openconnector', 'Copied!') : t('openconnector', 'Copy data') }}
					</NcActionButton>
					<NcActionButton close-after-click class="deleteAction" @click="deleteLog(log)">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t('openconnector', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActions, NcActionButton, NcDateTime } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Check from 'vue-material-design-icons/Check.vue'
import DatabaseSearch from 'vue-material-design-icons/DatabaseSearch.vue'

export default {
	name: 'JobLogIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		NcActions,
		NcActionButton,
		NcDateTime,
		Delete,
		Eye,
		FileExportOutline,
		CheckCircle,
		AlertCircle,
		CloseCircle,
		InformationOutline,
		ContentCopy,
		Check,
		DatabaseSearch,
	},
	data() {
		return {
			selectedLogs: [],
			copyStates: {},
			refreshing: false,
			pagination: {
				page: 1,
				limit: 20,
			},
		}
	},
	computed: {
		filteredLogs() {
			return (jobStore.logs && Array.isArray(jobStore.logs.results)) ? jobStore.logs.results : []
		},
		totalLogs() {
			return (jobStore.logs && jobStore.logs.total) ? jobStore.logs.total : this.filteredLogs.length
		},
		tableColumns() {
			return [
				{ key: 'level', label: t('openconnector', 'Level') },
				{ key: 'job', label: t('openconnector', 'Job') },
				{ key: 'message', label: t('openconnector', 'Message') },
				{ key: 'executionTime', label: t('openconnector', 'Execution time') },
				{ key: 'created', label: t('openconnector', 'Created') },
			]
		},
		paginationData() {
			const page = this.pagination.page || 1
			const limit = this.pagination.limit || 20
			const total = this.totalLogs
			const pages = Math.max(1, jobStore.logs?.pages || Math.ceil(total / limit))
			return { page, pages, total, limit }
		},
		emptyContentName() {
			if (logStore.error) return logStore.error
			if (!this.filteredLogs.length) return t('openconnector', 'No logs found')
			return t('openconnector', 'Loading job logs...')
		},
	},
	mounted() {
		jobStore.refreshList()
		// Plugin's refreshLogs uses jobStore.item?.id automatically; null item → all-jobs mode.
		jobStore.refreshLogs()
		this.$root.$on('job-log-filters-changed', this.handleFiltersChanged)
	},
	beforeDestroy() {
		this.$root.$off('job-log-filters-changed')
	},
	methods: {
		handleFiltersChanged(filters) {
			logStore.setLogFilters(filters)
			jobStore.refreshLogs(filters)
		},
		async onPageChanged(page) {
			this.pagination.page = page
			await jobStore.refreshLogs({ ...logStore.logFilters, _page: page, _limit: this.pagination.limit })
			this.selectedLogs = []
		},
		async onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
			await jobStore.refreshLogs({ ...logStore.logFilters, _page: 1, _limit: pageSize })
			this.selectedLogs = []
		},
		onSelect(ids) {
			this.selectedLogs = ids
		},
		getJobName(jobId) {
			if (!jobId) return t('openconnector', 'Unknown Job')
			const job = jobStore.list?.find(j => j.id === jobId)
			return job?.name || `Job ${jobId}`
		},
		getLogLevelClass(log) {
			if (!log.level) return 'unknownLevel'
			if (log.level === 'SUCCESS') return 'successLevel'
			if (log.level === 'WARNING') return 'warningLevel'
			if (['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'].includes(log.level)) return 'errorLevel'
			return 'infoLevel'
		},
		getExecutionTimeClass(executionTime) {
			if (!executionTime) return ''
			const timeInSeconds = executionTime / 1000
			if (timeInSeconds < 1) return 'fast-execution'
			if (timeInSeconds < 3) return 'medium-execution'
			return 'slow-execution'
		},
		viewLogDetails(log) {
			logStore.setViewLogItem(log)
			navigationStore.setModal('viewJobLog')
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
		deleteLog(log) {
			logStore.setViewLogItem(log)
			navigationStore.setDialog('deleteLog')
		},
		async bulkDeleteLogs() {
			if (this.selectedLogs.length === 0) return

			if (!confirm(t('openconnector', 'Are you sure you want to delete the selected logs? This action cannot be undone.'))) {
				return
			}

			try {
				OC.Notification.showSuccess(t('openconnector', 'Selected logs deleted successfully'))
				this.selectedLogs = []
				await this.refreshLogs()
			} catch (error) {
				console.error('Error deleting logs:', error)
				OC.Notification.showError(t('openconnector', 'Error deleting logs'))
			}
		},
		async exportLogs() {
			try {
				OC.Notification.showSuccess(t('openconnector', 'Export started - you will be notified when ready'))
			} catch (error) {
				console.error('Error exporting logs:', error)
				OC.Notification.showError(t('openconnector', 'Export failed'))
			}
		},
		async refreshLogs() {
			this.refreshing = true
			try {
				await jobStore.refreshLogs(logStore.logFilters)
				this.selectedLogs = []
			} finally {
				this.refreshing = false
			}
		},
		formatBytes(bytes) {
			if (!bytes) return '0 B'
			const k = 1024
			const sizes = ['B', 'KB', 'MB', 'GB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))
			return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
		},
	},
}
</script>

<style scoped>
/* Level badge styling — mirrors SourceLogIndex statusBadge pattern */
.levelBadge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 4px 8px;
	border-radius: 12px;
	font-size: 0.75rem;
	font-weight: 600;
	color: white;
	background: var(--color-text-maxcontrast);
}

.levelBadge.successLevel {
	background: var(--color-success);
}

.levelBadge.warningLevel {
	background: var(--color-warning);
}

.levelBadge.errorLevel {
	background: var(--color-error);
}

.levelBadge.infoLevel {
	background: var(--color-info, var(--color-primary-element));
}

/* Execution time coloring */
.fast-execution {
	color: var(--color-success);
	font-weight: 600;
}

.medium-execution {
	color: var(--color-warning);
	font-weight: 600;
}

.slow-execution {
	color: var(--color-error);
	font-weight: 600;
}

.jobInfo,
.messageInfo,
.executionInfo {
	display: flex;
	align-items: center;
	gap: 8px;
}

.jobType,
.memoryUsage {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

.contextIndicator {
	color: var(--color-text-maxcontrast);
	cursor: help;
}

.timestampInfo {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.createdTime {
	font-weight: 500;
}

.expiresTime {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
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

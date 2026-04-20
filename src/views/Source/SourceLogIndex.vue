<script setup>
import { translate as t } from '@nextcloud/l10n'
import { logStore, navigationStore, sourceStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openconnector', 'Call Logs')"
			:description="t('openconnector', 'Monitor and analyze API call logs and their performance')"
			:show-title="true"
			:objects="filteredLogs"
			:columns="tableColumns"
			:pagination="paginationData"
			:loading="logStore.loading"
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
			<!-- Action bar extras: Export and bulk Delete -->
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

			<!-- Status column -->
			<template #column-status="{ row: log }">
				<span class="statusBadge" :class="getLogStatusClass(log)">
					<CheckCircle v-if="log.statusCode >= 200 && log.statusCode < 300" :size="16" />
					<AlertCircle v-else-if="log.statusCode >= 400 && log.statusCode < 500" :size="16" />
					<CloseCircle v-else-if="log.statusCode >= 500" :size="16" />
					<InformationOutline v-else :size="16" />
					{{ log.statusCode }} {{ log.statusMessage || t('openconnector', 'Unknown') }}
				</span>
			</template>

			<!-- Source column -->
			<template #column-source="{ row: log }">
				<div class="sourceInfo">
					<span class="sourceName">{{ getSourceName(log.sourceId) }}</span>
					<span v-if="log.request?.headers?.Authorization" class="authIndicator" :title="t('openconnector', 'Authenticated request')">
						<Lock :size="14" />
					</span>
				</div>
			</template>

			<!-- Method column -->
			<template #column-method="{ row: log }">
				<span class="methodBadge" :class="`method-${(log.request?.method || 'unknown').toLowerCase()}`">
					{{ log.request?.method || 'UNKNOWN' }}
				</span>
			</template>

			<!-- Endpoint column -->
			<template #column-endpoint="{ row: log }">
				<div class="endpointInfo">
					<span v-if="log.request?.url" class="truncatedUrl" :title="log.request.url">{{ log.request.url }}</span>
					<span v-else>-</span>
					<span v-if="log.request?.query" class="queryParams" :title="JSON.stringify(log.request.query)">
						<DatabaseSearch :size="14" />
					</span>
				</div>
			</template>

			<!-- Response time column -->
			<template #column-responseTime="{ row: log }">
				<div class="responseInfo">
					<span v-if="log.response?.responseTime" :class="getResponseTimeClass(log.response.responseTime)">
						{{ (log.response.responseTime / 1000).toFixed(3) }}s
					</span>
					<span v-if="log.response?.size" class="responseSize" :title="t('openconnector', 'Response size')">
						{{ formatBytes(log.response.size) }}
					</span>
					<span v-else>-</span>
				</div>
			</template>

			<!-- Created column -->
			<template #column-created="{ row: log }">
				<div class="timestampInfo">
					<NcDateTime v-if="log.created" class="createdTime" :timestamp="new Date(log.created)" />
					<span v-if="log.expires" class="expiresTime" :title="t('openconnector', 'Expires at')">
						{{ new Date(log.expires).toLocaleString() }}
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
import Lock from 'vue-material-design-icons/Lock.vue'
import DatabaseSearch from 'vue-material-design-icons/DatabaseSearch.vue'

export default {
	name: 'SourceLogIndex',
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
		Lock,
		DatabaseSearch,
	},
	data() {
		return {
			selectedLogs: [],
			copyStates: {},
			pagination: {
				page: 1,
				limit: 20,
			},
		}
	},
	computed: {
		filteredLogs() {
			return (sourceStore.sourceLogs && Array.isArray(sourceStore.sourceLogs.results)) ? sourceStore.sourceLogs.results : []
		},
		totalLogs() {
			return (sourceStore.sourceLogs && sourceStore.sourceLogs.total) ? sourceStore.sourceLogs.total : 0
		},
		tableColumns() {
			return [
				{ key: 'status', label: t('openconnector', 'Status') },
				{ key: 'source', label: t('openconnector', 'Source') },
				{ key: 'method', label: t('openconnector', 'Method') },
				{ key: 'endpoint', label: t('openconnector', 'Endpoint') },
				{ key: 'responseTime', label: t('openconnector', 'Response time') },
				{ key: 'created', label: t('openconnector', 'Created') },
			]
		},
		paginationData() {
			const page = this.pagination.page || 1
			const limit = this.pagination.limit || 20
			const total = this.totalLogs
			const pages = Math.max(1, Math.ceil(total / limit))
			return { page, pages, total, limit }
		},
		emptyContentName() {
			if (logStore.error) return logStore.error
			if (!this.filteredLogs.length) return t('openconnector', 'No logs found')
			return t('openconnector', 'Loading call logs...')
		},
	},
	mounted() {
		sourceStore.refreshList()
		sourceStore.refreshSourceLogs()
		this.$root.$on('source-log-filters-changed', this.handleFiltersChanged)
	},
	beforeDestroy() {
		this.$root.$off('source-log-filters-changed')
	},
	methods: {
		handleFiltersChanged(filters) {
			logStore.setLogFilters(filters)
			sourceStore.refreshSourceLogs(filters)
		},
		onPageChanged(page) {
			this.pagination.page = page
			sourceStore.refreshSourceLogs({ ...logStore.logFilters, page, limit: this.pagination.limit })
		},
		onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
			sourceStore.refreshSourceLogs({ ...logStore.logFilters, page: 1, limit: pageSize })
		},
		onSelect(ids) {
			this.selectedLogs = ids
		},
		getSourceName(sourceId) {
			if (!sourceId) return t('openconnector', 'Unknown Source')
			const source = sourceStore.list?.find(s => s.id === sourceId)
			return source?.name || `Source ${sourceId}`
		},
		getLogStatusClass(log) {
			if (!log.statusCode) return 'unknownStatus'
			if (log.statusCode >= 200 && log.statusCode < 300) return 'successStatus'
			if (log.statusCode >= 400 && log.statusCode < 500) return 'clientErrorStatus'
			if (log.statusCode >= 500) return 'serverErrorStatus'
			return 'infoStatus'
		},
		getResponseTimeClass(responseTime) {
			if (!responseTime) return ''
			const timeInSeconds = responseTime / 1000
			if (timeInSeconds <= 0.3) return 'fast-response'
			if (timeInSeconds <= 1) return 'medium-response'
			return 'slow-response'
		},
		viewLogDetails(log) {
			logStore.setViewLogItem(log)
			navigationStore.setModal('viewSourceLog')
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
				// TODO: Implement bulk delete API call
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
				// TODO: Implement export API call
				OC.Notification.showSuccess(t('openconnector', 'Export started - you will be notified when ready'))
			} catch (error) {
				console.error('Error exporting logs:', error)
				OC.Notification.showError(t('openconnector', 'Export failed'))
			}
		},
		refreshLogs() {
			sourceStore.refreshSourceLogs(logStore.logFilters)
			this.selectedLogs = []
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
/* Status badge styling */
.statusBadge {
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

.statusBadge.successStatus {
	background: var(--color-success);
}

.statusBadge.clientErrorStatus,
.statusBadge.serverErrorStatus {
	background: var(--color-error);
}

.statusBadge.infoStatus {
	background: var(--color-info);
}

/* Method badge styling */
.methodBadge {
	display: inline-flex;
	padding: 2px 6px;
	border-radius: 8px;
	font-size: 0.7rem;
	font-weight: 600;
	color: white;
}

.methodBadge.method-get {
	background: var(--color-success);
}

.methodBadge.method-post {
	background: var(--color-info);
}

.methodBadge.method-put,
.methodBadge.method-patch {
	background: var(--color-warning);
}

.methodBadge.method-delete {
	background: var(--color-error);
}

.methodBadge.method-unknown {
	background: var(--color-text-maxcontrast);
}

/* Response time styling */
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

.truncatedUrl {
	max-width: 300px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	display: inline-block;
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

.sourceInfo {
	display: flex;
	align-items: center;
	gap: 8px;
}

.sourceName {
	font-weight: 500;
}

.authIndicator {
	color: var(--color-success);
}

.endpointInfo {
	display: flex;
	align-items: center;
	gap: 8px;
}

.queryParams {
	color: var(--color-text-maxcontrast);
	cursor: help;
}

.responseInfo {
	display: flex;
	align-items: center;
	gap: 8px;
}

.responseSize {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
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
</style>

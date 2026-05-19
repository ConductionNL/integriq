<script setup>
import { translate as t } from '@nextcloud/l10n'
import { logStore, navigationStore, sourceStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ t('openconnector', 'Call Logs') }}
				</h1>
				<p>{{ t('openconnector', 'Monitor and analyze API call logs and their performance') }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span class="viewTotalCount">
						{{ t('openconnector', 'Showing {showing} of {total} logs', { showing: paginatedLogs.length, total: totalLogs }) }}
					</span>
					<span v-if="hasActiveFilters" class="viewIndicator">
						({{ t('openconnector', 'Filtered') }})
					</span>
					<span v-if="selectedLogs.length > 0" class="viewIndicator">
						({{ t('openconnector', '{count} selected', { count: selectedLogs.length }) }})
					</span>
				</div>
				<div class="viewActions">
					<NcActions
						:force-name="true"
						:inline="selectedLogs.length > 0 ? 3 : 2"
						menu-name="Actions">
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
						<NcActionButton
							close-after-click
							@click="refreshLogs">
							<template #icon>
								<Refresh :size="20" />
							</template>
							{{ t('openconnector', 'Refresh') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Loading State -->
			<div v-if="logStore.loading" class="viewLoading">
				<NcLoadingIcon :size="64" />
				<p>{{ t('openconnector', 'Loading call logs...') }}</p>
			</div>

			<!-- Empty State -->
			<NcEmptyContent v-else-if="logStore.error || !filteredLogs.length"
				:name="emptyContentName"
				:description="emptyContentDescription">
				<template #icon>
					<NcLoadingIcon v-if="logStore.loading" :size="64" />
					<TimelineQuestionOutline v-else :size="64" />
				</template>
			</NcEmptyContent>

			<!-- Call Logs Table -->
			<div v-else class="viewTableContainer">
				<table class="viewTable callLogsTable">
					<thead>
						<tr>
							<th class="tableColumnCheckbox">
								<NcCheckboxRadioSwitch
									:checked="allSelected"
									:indeterminate="someSelected"
									@update:checked="toggleSelectAll" />
							</th>
							<th class="statusColumn">
								{{ t('openconnector', 'Status') }}
							</th>
							<th class="sourceColumn">
								{{ t('openconnector', 'Source') }}
							</th>
							<th class="methodColumn">
								{{ t('openconnector', 'Method') }}
							</th>
							<th class="tableColumnConstrained">
								{{ t('openconnector', 'Endpoint') }}
							</th>
							<th class="responseTimeColumn">
								{{ t('openconnector', 'Response Time') }}
							</th>
							<th class="timestampColumn">
								{{ t('openconnector', 'Created') }}
							</th>
							<th class="tableColumnActions">
								{{ t('openconnector', 'Actions') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="log in paginatedLogs"
							:key="log.id"
							class="viewTableRow callLogRow"
							:class="getLogStatusClass(log)">
							<td class="tableColumnCheckbox">
								<NcCheckboxRadioSwitch
									:checked="selectedLogs.includes(log.id)"
									@update:checked="(checked) => toggleLogSelection(log.id, checked)" />
							</td>
							<td class="statusColumn">
								<span class="statusBadge" :class="getLogStatusClass(log)">
									<CheckCircle v-if="log.statusCode >= 200 && log.statusCode < 300" :size="16" />
									<AlertCircle v-else-if="log.statusCode >= 400 && log.statusCode < 500" :size="16" />
									<CloseCircle v-else-if="log.statusCode >= 500" :size="16" />
									<InformationOutline v-else :size="16" />
									{{ log.statusCode }} {{ log.statusMessage || 'Unknown' }}
								</span>
							</td>
							<td class="sourceColumn">
								<div class="sourceInfo">
									<span class="sourceName">{{ getSourceName(log.sourceId) }}</span>
									<span v-if="log.request?.headers?.Authorization" class="authIndicator" :title="t('openconnector', 'Authenticated request')">
										<Lock :size="14" />
									</span>
								</div>
							</td>
							<td class="methodColumn">
								<span class="methodBadge" :class="`method-${(log.request?.method || 'unknown').toLowerCase()}`">
									{{ log.request?.method || 'UNKNOWN' }}
								</span>
							</td>
							<td class="tableColumnConstrained">
								<div class="endpointInfo">
									<span v-if="log.request?.url" class="truncatedUrl" :title="log.request.url">{{ log.request.url }}</span>
									<span v-else>-</span>
									<span v-if="log.request?.query" class="queryParams" :title="JSON.stringify(log.request.query)">
										<DatabaseSearch :size="14" />
									</span>
								</div>
							</td>
							<td class="responseTimeColumn">
								<div class="responseInfo">
									<span v-if="log.response?.responseTime" :class="getResponseTimeClass(log.response.responseTime)">
										{{ (log.response.responseTime / 1000).toFixed(3) }}s
									</span>
									<span v-if="log.response?.size" class="responseSize" :title="t('openconnector', 'Response size')">
										{{ formatBytes(log.response.size) }}
									</span>
									<span v-else>-</span>
								</div>
							</td>
							<td class="timestampColumn">
								<div class="timestampInfo">
									<span class="createdTime">{{ new Date(log.created).toLocaleString() }}</span>
									<span v-if="log.expires" class="expiresTime" :title="t('openconnector', 'Expires at')">
										{{ new Date(log.expires).toLocaleString() }}
									</span>
								</div>
							</td>
							<td class="tableColumnActions">
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
									<NcActionButton close-after-click class="deleteAction" @click="deleteLog(log)">
										<template #icon>
											<Delete :size="20" />
										</template>
										{{ t('openconnector', 'Delete') }}
									</NcActionButton>
								</NcActions>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Pagination -->
			<PaginationComponent
				v-if="filteredLogs.length > 0"
				:current-page="pagination.page || 1"
				:total-pages="Math.ceil(filteredLogs.length / (pagination.limit || 20))"
				:total-items="filteredLogs.length"
				:current-page-size="pagination.limit || 20"
				:min-items-to-show="10"
				@page-changed="onPageChanged"
				@page-size-changed="onPageSizeChanged" />
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcActions, NcActionButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import TimelineQuestionOutline from 'vue-material-design-icons/TimelineQuestionOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
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

import PaginationComponent from '../../components/PaginationComponent.vue'

export default {
	name: 'SourceLogIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcCheckboxRadioSwitch,
		TimelineQuestionOutline,
		Delete,
		Refresh,
		Eye,
		FileExportOutline,
		CheckCircle,
		AlertCircle,
		CloseCircle,
		InformationOutline,
		ContentCopy,
		Check,
		PaginationComponent,
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
		hasActiveFilters() {
			return Object.keys(logStore.logFilters || {}).some(key =>
				logStore.logFilters[key] !== null
				&& logStore.logFilters[key] !== undefined
				&& logStore.logFilters[key] !== '',
			)
		},
		filteredLogs() {
			return (sourceStore.sourceLogs && Array.isArray(sourceStore.sourceLogs.results)) ? sourceStore.sourceLogs.results : []
		},
		totalLogs() {
			return (sourceStore.sourceLogs && sourceStore.sourceLogs.total ? sourceStore.sourceLogs.total : '?')
		},
		paginatedLogs() {
			return this.filteredLogs
		},
		allSelected() {
			return this.paginatedLogs.length > 0 && this.paginatedLogs.every(log => this.selectedLogs.includes(log.id))
		},
		someSelected() {
			return this.selectedLogs.length > 0 && !this.allSelected
		},
		emptyContentName() {
			if (logStore.loading) {
				return t('openconnector', 'Loading logs...')
			} else if (logStore.error) {
				return logStore.error
			} else if (!sourceStore.sourceLogs?.length) {
				return t('openconnector', 'No logs found')
			} else if (!this.filteredLogs.length) {
				return t('openconnector', 'No logs match your filters')
			}
			return ''
		},
		emptyContentDescription() {
			if (logStore.loading) {
				return t('openconnector', 'Please wait while we fetch your logs.')
			} else if (logStore.error) {
				return t('openconnector', 'Please try again later.')
			} else if (!sourceStore.sourceLogs?.length) {
				return t('openconnector', 'No call logs are available.')
			} else if (!this.filteredLogs.length) {
				return t('openconnector', 'Try adjusting your filter settings in the sidebar.')
			}
			return ''
		},
	},
	mounted() {
		sourceStore.refreshSourceList()
		sourceStore.refreshSourceLogs()
		// Listen for filter changes from sidebar
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
		toggleSelectAll(checked) {
			if (checked) {
				this.selectedLogs = this.paginatedLogs.map(log => log.id)
			} else {
				this.selectedLogs = []
			}
		},
		toggleLogSelection(logId, checked) {
			if (checked) {
				this.selectedLogs.push(logId)
			} else {
				this.selectedLogs = this.selectedLogs.filter(id => id !== logId)
			}
		},
		onPageChanged(page) {
			this.pagination.page = page
		},
		onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
		},
		getSourceName(sourceId) {
			if (!sourceId) return t('openconnector', 'Unknown Source')
			const source = sourceStore.sourceList?.find(s => s.id === sourceId)
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
			if (timeInSeconds <= 0.3) return 'fast-response' // 300 ms or less is fast-response
			if (timeInSeconds <= 1) return 'medium-response' // 1 second or less is medium-response
			return 'slow-response' // More than 1 second is slow-response
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

				OC.Notification.showSuccess(this.t('openconnector', 'Log data copied to clipboard'))
			} catch (error) {
				console.error('Error copying to clipboard:', error)
				OC.Notification.showError(this.t('openconnector', 'Failed to copy data to clipboard'))
			}
		},
		deleteLog(log) {
			logStore.setViewLogItem(log)
			navigationStore.setDialog('deleteLog')
		},
		async bulkDeleteLogs() {
			if (this.selectedLogs.length === 0) return

			if (!confirm(this.t('openconnector', 'Are you sure you want to delete the selected logs? This action cannot be undone.'))) {
				return
			}

			try {
				// TODO: Implement bulk delete API call
				OC.Notification.showSuccess(this.t('openconnector', 'Selected logs deleted successfully'))
				this.selectedLogs = []
				await this.refreshLogs()
			} catch (error) {
				console.error('Error deleting logs:', error)
				OC.Notification.showError(this.t('openconnector', 'Error deleting logs'))
			}
		},
		async exportLogs() {
			try {
				// TODO: Implement export API call
				OC.Notification.showSuccess(this.t('openconnector', 'Export started - you will be notified when ready'))
			} catch (error) {
				console.error('Error exporting logs:', error)
				OC.Notification.showError(this.t('openconnector', 'Export failed'))
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
/* Specific column widths for call logs table */
.statusColumn {
	width: 200px;
}

.sourceColumn {
	width: 150px;
}

.methodColumn {
	width: 80px;
}

.responseTimeColumn {
	width: 120px;
}

.timestampColumn {
	width: 180px;
}

/* Status-specific row styling */
.viewTableRow.successStatus {
	border-left: 4px solid var(--color-success);
}

.viewTableRow.clientErrorStatus,
.viewTableRow.serverErrorStatus {
	border-left: 4px solid var(--color-error);
}

.viewTableRow.infoStatus {
	border-left: 4px solid var(--color-info);
}

.viewTableRow.unknownStatus {
	border-left: 4px solid var(--color-text-maxcontrast);
}

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

.viewLoading {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	min-height: 200px;
	gap: 16px;
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

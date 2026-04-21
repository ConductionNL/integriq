<script setup>
import { endpointStore } from '../../store/store.js'
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openconnector', 'Endpoint Logs')"
			:description="t('openconnector', 'Monitor and analyze endpoint logs and their performance')"
			:show-title="true"
			:objects="paginatedLogs"
			:columns="tableColumns"
			:pagination="paginationData"
			:loading="endpointStore.loading"
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

			<template #column-status="{ row: log }">
				<span class="statusBadge" :class="getLogStatusClass(log)">
					<CheckCircle v-if="log.statusCode >= 200 && log.statusCode < 300" :size="16" />
					<AlertCircle v-else-if="log.statusCode >= 400 && log.statusCode < 500" :size="16" />
					<CloseCircle v-else-if="log.statusCode >= 500" :size="16" />
					<InformationOutline v-else :size="16" />
					{{ log.statusCode }} {{ log.statusMessage || t('openconnector', 'Unknown') }}
				</span>
			</template>

			<template #column-method="{ row: log }">
				<span class="methodBadge" :class="`method-${(log.method || 'unknown').toLowerCase()}`">
					{{ log.method || 'UNKNOWN' }}
				</span>
			</template>

			<template #column-endpoint="{ row: log }">
				<span class="truncatedUrl" :title="log.endpoint">{{ log.endpoint }}</span>
			</template>

			<template #column-created="{ row: log }">
				<NcDateTime v-if="log.created" :timestamp="new Date(log.created)" />
				<span v-else>-</span>
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

export default {
	name: 'EndpointLogIndex',
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
		endpointStore() {
			return endpointStore
		},
		filteredLogs() {
			return (endpointStore.logs && Array.isArray(endpointStore.logs)) ? endpointStore.logs : []
		},
		paginatedLogs() {
			const start = ((this.pagination.page || 1) - 1) * (this.pagination.limit || 20)
			const end = start + (this.pagination.limit || 20)
			return this.filteredLogs.slice(start, end)
		},
		tableColumns() {
			return [
				{ key: 'status', label: t('openconnector', 'Status') },
				{ key: 'method', label: t('openconnector', 'Method') },
				{ key: 'endpoint', label: t('openconnector', 'Endpoint') },
				{ key: 'created', label: t('openconnector', 'Created') },
			]
		},
		paginationData() {
			const page = this.pagination.page || 1
			const limit = this.pagination.limit || 20
			const total = this.filteredLogs.length
			const pages = Math.max(1, Math.ceil(total / limit))
			return { page, pages, total, limit }
		},
		emptyContentName() {
			if (endpointStore.error) return endpointStore.error
			if (!this.filteredLogs.length) return t('openconnector', 'No logs found')
			return t('openconnector', 'Loading endpoint logs...')
		},
	},
	mounted() {
		endpointStore.refreshLogs()
	},
	methods: {
		onPageChanged(page) {
			this.pagination.page = page
		},
		onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
		},
		onSelect(ids) {
			this.selectedLogs = ids
		},
		getLogStatusClass(log) {
			if (!log.statusCode) return 'unknownStatus'
			if (log.statusCode >= 200 && log.statusCode < 300) return 'successStatus'
			if (log.statusCode >= 400 && log.statusCode < 500) return 'clientErrorStatus'
			if (log.statusCode >= 500) return 'serverErrorStatus'
			return 'infoStatus'
		},
		viewLogDetails(log) {
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
		deleteLog(log) {
			// TODO: Implement delete log
		},
		async bulkDeleteLogs() {
			// TODO: Implement bulk delete
		},
		async exportLogs() {
			// TODO: Implement export
		},
		refreshLogs() {
			endpointStore.refreshLogs()
			this.selectedLogs = []
		},
	},
}
</script>

<style scoped>
/* All CSS is provided by main.css */
</style>

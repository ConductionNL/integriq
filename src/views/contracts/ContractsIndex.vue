<script setup>
import { translate as t } from '@nextcloud/l10n'
import { contractStore, synchronizationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openconnector', 'Synchronization Contracts')"
			:description="t('openconnector', 'Manage and monitor synchronization contracts')"
			:show-title="true"
			:objects="filteredItems"
			:columns="tableColumns"
			:pagination="paginationData"
			:loading="contractStore.contractsLoading"
			:refreshing="refreshing"
			:inline-action-count="2"
			view-mode="table"
			:show-view-toggle="false"
			:show-add="false"
			:selectable="true"
			:selected-ids="selectedItems"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-delete-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="true"
			:name-formatter="getContractName"
			row-key="id"
			:empty-text="emptyContentName"
			:row-class="getSyncStatusClass"
			@refresh="onRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@select="onSelect"
			@mass-delete="onMassDelete">
			<!-- Action bar extras: Export -->
			<template #action-items>
				<NcActionButton
					close-after-click
					@click="exportFiltered">
					<template #icon>
						<FileExportOutline :size="20" />
					</template>
					{{ t('openconnector', 'Export') }}
				</NcActionButton>
			</template>

			<!-- Contract column -->
			<template #column-contract="{ row }">
				<div class="contractInfo">
					<span class="contractName">{{ getContractName(row) }}</span>
					<span v-if="row.uuid" class="contractUuid" :title="row.uuid">
						{{ row.uuid }}
					</span>
				</div>
			</template>

			<!-- Synchronization column -->
			<template #column-synchronization="{ row }">
				<span class="synchronizationName">{{ getSynchronizationName(row.synchronizationId) }}</span>
			</template>

			<!-- Sync status column -->
			<template #column-syncStatus="{ row }">
				<CnStatusBadge
					:label="getSyncStatusLabel(row.getSyncStatus())"
					:color-map="syncStatusColorMap">
					<template #icon>
						<CheckCircle v-if="row.getSyncStatus() === 'synced'" :size="14" />
						<AlertCircle v-else-if="row.getSyncStatus() === 'stale'" :size="14" />
						<CloseCircle v-else-if="row.getSyncStatus() === 'error'" :size="14" />
						<InformationOutline v-else :size="14" />
					</template>
				</CnStatusBadge>
			</template>

			<!-- Last synced column -->
			<template #column-lastSynced="{ row }">
				<NcDateTime v-if="row.getLastSyncDate()" :timestamp="new Date(row.getLastSyncDate())" />
				<span v-else>{{ t('openconnector', 'Never') }}</span>
			</template>

			<!-- Last action column -->
			<template #column-lastAction="{ row }">
				<CnStatusBadge
					:label="getLastActionLabel(row.getLastAction())"
					:color-map="lastActionColorMap" />
			</template>

			<!-- Row actions -->
			<template #row-actions="{ row }">
				<NcActions>
					<NcActionButton close-after-click @click="enforceContract(row)">
						<template #icon>
							<PlayCircle :size="20" />
						</template>
						{{ t('openconnector', 'Enforce Contract') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="viewLogs(row)">
						<template #icon>
							<TextBoxOutline :size="20" />
						</template>
						{{ t('openconnector', 'View Logs') }}
					</NcActionButton>
					<NcActionButton close-after-click class="deleteAction" @click="deleteContract(row)">
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
import { CnIndexPage, CnStatusBadge } from '@conduction/nextcloud-vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import PlayCircle from 'vue-material-design-icons/PlayCircle.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'

export default {
	name: 'ContractsIndex',
	components: {
		NcAppContent,
		NcActions,
		NcActionButton,
		NcDateTime,
		CnIndexPage,
		CnStatusBadge,
		Delete,
		PlayCircle,
		TextBoxOutline,
		FileExportOutline,
		CheckCircle,
		AlertCircle,
		CloseCircle,
		InformationOutline,
	},
	data() {
		return {
			selectedItems: [],
			refreshing: false,
			syncStatusColorMap: {
				synced: 'success',
				stale: 'warning',
				error: 'error',
				unsynced: 'default',
			},
			lastActionColorMap: {
				created: 'success',
				updated: 'primary',
				deleted: 'error',
				inserted: 'info',
				none: 'default',
			},
		}
	},
	computed: {
		filteredItems() {
			return contractStore.contractsList || []
		},
		tableColumns() {
			return [
				{ key: 'contract', label: t('openconnector', 'Contract') },
				{ key: 'synchronization', label: t('openconnector', 'Synchronization') },
				{ key: 'syncStatus', label: t('openconnector', 'Sync Status') },
				{ key: 'lastSynced', label: t('openconnector', 'Last Synced') },
				{ key: 'lastAction', label: t('openconnector', 'Last Action') },
			]
		},
		paginationData() {
			const p = contractStore.contractsPagination || {}
			const page = p.page || 1
			const limit = p.limit || 20
			const total = p.total ?? this.filteredItems.length
			const pages = Math.max(1, p.pages || Math.ceil(total / limit))
			return { page, pages, total, limit }
		},
		emptyContentName() {
			if (contractStore.contractsError) return contractStore.contractsError
			if (!contractStore.contractsList?.length) return t('openconnector', 'No contracts found')
			return ''
		},
	},
	mounted() {
		this.loadItems()
		this.$root.$on('contracts-filters-changed', this.handleFiltersChanged)
		this.$root.$on('contracts-export-filtered', this.exportFiltered)
	},
	beforeDestroy() {
		this.$root.$off('contracts-filters-changed', this.handleFiltersChanged)
		this.$root.$off('contracts-export-filtered', this.exportFiltered)
	},
	methods: {
		async loadItems() {
			try {
				await contractStore.fetchContracts()

				if (!synchronizationStore.synchronizationList.length) {
					await synchronizationStore.refreshSynchronizationList()
				}
			} catch (error) {
				console.error('Error loading contracts:', error)
			}
		},
		async handleFiltersChanged(filters) {
			contractStore.setContractsFilters(filters)

			try {
				await contractStore.fetchContracts({
					page: 1,
					filters,
				})
				this.selectedItems = []
			} catch (error) {
				console.error('Error applying filters:', error)
			}
		},
		getContractName(contract) {
			return contract.getDisplayName ? contract.getDisplayName() : `Contract ${contract.id}`
		},
		getSynchronizationName(synchronizationId) {
			if (!synchronizationId) return t('openconnector', 'Unknown Synchronization')

			const synchronization = synchronizationStore.synchronizationList.find(s => s.id === parseInt(synchronizationId))
			return synchronization?.name || `Synchronization ${synchronizationId}`
		},
		getSyncStatusClass(item) {
			const status = item.getSyncStatus ? item.getSyncStatus() : 'unsynced'
			switch (status) {
			case 'synced':
				return 'successStatus'
			case 'stale':
				return 'warningStatus'
			case 'error':
				return 'errorStatus'
			case 'unsynced':
			default:
				return 'secondaryStatus'
			}
		},
		getSyncStatusLabel(status) {
			switch (status) {
			case 'synced':
				return t('openconnector', 'Synced')
			case 'stale':
				return t('openconnector', 'Stale')
			case 'unsynced':
				return t('openconnector', 'Unsynced')
			case 'error':
				return t('openconnector', 'Error')
			default:
				return t('openconnector', 'Unknown')
			}
		},
		getLastActionLabel(action) {
			switch (action) {
			case 'create':
			case 'created':
				return t('openconnector', 'Created')
			case 'update':
			case 'updated':
				return t('openconnector', 'Updated')
			case 'delete':
			case 'deleted':
				return t('openconnector', 'Deleted')
			case 'insert':
				return t('openconnector', 'Inserted')
			default:
				return t('openconnector', 'None')
			}
		},
		onSelect(ids) {
			this.selectedItems = ids
		},
		async onMassDelete(ids) {
			if (!ids?.length) return
			try {
				await contractStore.deleteMultiple(ids)
				this.$refs.indexPage?.setMassDeleteResult({ success: true, count: ids.length })
				this.selectedItems = []
				await this.loadItems()
			} catch (error) {
				console.error('Error deleting contracts:', error)
				this.$refs.indexPage?.setMassDeleteResult({ success: false, error: error.message })
			}
		},
		async enforceContract(contract) {
			try {
				await contractStore.enforceContract(contract.id)
				await this.loadItems()
			} catch (error) {
				console.error('Error enforcing contract:', error)
			}
		},
		async deleteContract(contract) {
			try {
				await contractStore.deleteContract(contract.id)
				await this.loadItems()
			} catch (error) {
				console.error('Error deleting contract:', error)
			}
		},
		viewLogs(contract) {
			this.$router.push('/synchronizations/logs?contract=' + contract.id)
			this.$root.$emit('logs-filter-by-contract', contract.id)
		},
		async onPageChanged(page) {
			try {
				await contractStore.fetchContracts({ page })
				this.selectedItems = []
			} catch (error) {
				console.error('Error changing page:', error)
			}
		},
		async onPageSizeChanged(pageSize) {
			try {
				await contractStore.fetchContracts({ page: 1, limit: pageSize })
				this.selectedItems = []
			} catch (error) {
				console.error('Error changing page size:', error)
			}
		},
		async onRefresh() {
			this.refreshing = true
			try {
				await this.loadItems()
				this.selectedItems = []
			} finally {
				this.refreshing = false
			}
		},
		async exportFiltered() {
			try {
				await contractStore.exportFiltered()
			} catch (error) {
				console.error('Error exporting contracts:', error)
			}
		},
	},
}
</script>

<style scoped>
.contractInfo {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.contractUuid {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
	font-family: var(--font-face-monospace, monospace);
}

:deep(.deleteAction) {
	color: var(--color-error) !important;
}

:deep(.deleteAction:hover) {
	background-color: var(--color-error) !important;
	color: var(--color-main-background) !important;
}
</style>

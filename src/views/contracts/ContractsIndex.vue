<script setup>
import { translate as t } from '@nextcloud/l10n'
import { contractStore, synchronizationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ t('openconnector', 'Synchronization Contracts') }}
				</h1>
				<p>{{ t('openconnector', 'Manage and monitor synchronization contracts') }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span class="viewTotalCount">
						{{ t('openconnector', 'Showing {showing} of {total} contracts', { showing: paginatedItems.length, total: filteredItems.length }) }}
					</span>
					<span v-if="hasActiveFilters" class="viewIndicator">
						({{ t('openconnector', 'Filtered') }})
					</span>
					<span v-if="selectedItems.length > 0" class="viewIndicator">
						({{ t('openconnector', '{count} selected', { count: selectedItems.length }) }})
					</span>
				</div>
				<div class="viewActions">
					<NcActions
						:force-name="true"
						:inline="selectedItems.length > 0 ? 3 : 2"
						menu-name="Actions">
						<NcActionButton
							v-if="selectedItems.length > 0"
							type="error"
							close-after-click
							@click="bulkDelete">
							<template #icon>
								<Delete :size="20" />
							</template>
							{{ t('openconnector', 'Delete ({count})', { count: selectedItems.length }) }}
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="exportFiltered">
							<template #icon>
								<FileExportOutline :size="20" />
							</template>
							{{ t('openconnector', 'Export') }}
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="refreshItems">
							<template #icon>
								<Refresh :size="20" />
							</template>
							{{ t('openconnector', 'Refresh') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Loading State -->
			<div v-if="contractStore.contractsLoading" class="viewLoading">
				<NcLoadingIcon :size="64" />
				<p>{{ t('openconnector', 'Loading contracts...') }}</p>
			</div>

			<!-- Empty State -->
			<NcEmptyContent v-else-if="!filteredItems.length"
				:name="emptyContentName"
				:description="emptyContentDescription">
				<template #icon>
					<FileDocumentOutline :size="64" />
				</template>
			</NcEmptyContent>

			<!-- Contracts Table -->
			<div v-else class="viewTableContainer">
				<table class="viewTable contractsTable">
					<thead>
						<tr>
							<th class="tableColumnCheckbox">
								<NcCheckboxRadioSwitch
									:checked="allSelected"
									:indeterminate="someSelected"
									@update:checked="toggleSelectAll" />
							</th>
							<th class="contractColumn">
								{{ t('openconnector', 'Contract') }}
							</th>
							<th class="synchronizationColumn">
								{{ t('openconnector', 'Synchronization') }}
							</th>
							<th class="statusColumn">
								{{ t('openconnector', 'Sync Status') }}
							</th>
							<th class="timestampColumn">
								{{ t('openconnector', 'Last Synced') }}
							</th>
							<th class="actionColumn">
								{{ t('openconnector', 'Last Action') }}
							</th>
							<th class="tableColumnActions">
								{{ t('openconnector', 'Actions') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="item in paginatedItems"
							:key="item.id"
							class="viewTableRow contractRow"
							:class="getSyncStatusClass(item)">
							<td class="tableColumnCheckbox">
								<NcCheckboxRadioSwitch
									:checked="selectedItems.includes(item.id)"
									@update:checked="(checked) => toggleItemSelection(item.id, checked)" />
							</td>
							<td class="contractColumn">
								<div class="contractInfo">
									<span class="contractName">{{ getContractName(item) }}</span>
									<span v-if="item.uuid" class="contractUuid" :title="item.uuid">
										{{ item.uuid }}
									</span>
								</div>
							</td>
							<td class="synchronizationColumn">
								<span class="synchronizationName">{{ getSynchronizationName(item.synchronizationId) }}</span>
							</td>
							<td class="statusColumn">
								<span class="statusBadge" :class="getSyncStatusClass(item)">
									<CheckCircle v-if="item.getSyncStatus() === 'synced'" :size="16" />
									<AlertCircle v-else-if="item.getSyncStatus() === 'stale'" :size="16" />
									<CloseCircle v-else-if="item.getSyncStatus() === 'error'" :size="16" />
									<InformationOutline v-else :size="16" />
									{{ getSyncStatusLabel(item.getSyncStatus()) }}
								</span>
							</td>
							<td class="timestampColumn">
								<div class="timestampInfo">
									<span v-if="item.getLastSyncDate()" class="lastSyncTime">
										{{ new Date(item.getLastSyncDate()).toLocaleString() }}
									</span>
									<span v-else class="neverSynced">
										{{ t('openconnector', 'Never') }}
									</span>
								</div>
							</td>
							<td class="actionColumn">
								<span class="actionBadge" :class="getActionClass(item.getLastAction())">
									{{ getLastActionLabel(item.getLastAction()) }}
								</span>
							</td>
							<td class="tableColumnActions">
								<NcActions>
									<NcActionButton close-after-click @click="enforceContract(item)">
										<template #icon>
											<PlayCircle :size="20" />
										</template>
										{{ t('openconnector', 'Enforce Contract') }}
									</NcActionButton>
									<NcActionButton close-after-click @click="viewLogs(item)">
										<template #icon>
											<TextBoxOutline :size="20" />
										</template>
										{{ t('openconnector', 'View Logs') }}
									</NcActionButton>
									<NcActionButton close-after-click class="deleteAction" @click="deleteContract(item)">
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
				v-if="filteredItems.length > 0"
				:current-page="currentPage"
				:total-pages="totalPages"
				:total-items="filteredItems.length"
				:current-page-size="pagination.limit || 20"
				:min-items-to-show="10"
				@page-changed="changePage"
				@page-size-changed="onPageSizeChanged" />
		</div>
	</NcAppContent>
</template>

<script>
import {
	NcAppContent,
	NcEmptyContent,
	NcLoadingIcon,
	NcActions,
	NcActionButton,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import PlayCircle from 'vue-material-design-icons/PlayCircle.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import PaginationComponent from '../../components/PaginationComponent.vue'

export default {
	name: 'ContractsIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcCheckboxRadioSwitch,
		FileDocumentOutline,
		Delete,
		Refresh,
		PlayCircle,
		TextBoxOutline,
		FileExportOutline,
		CheckCircle,
		AlertCircle,
		CloseCircle,
		InformationOutline,
		PaginationComponent,
	},
	data() {
		return {
			selectedItems: [],
			pagination: {
				page: 1,
				limit: 20,
			},
		}
	},
	computed: {
		hasActiveFilters() {
			return Object.keys(contractStore.contractsFilters || {}).some(key =>
				contractStore.contractsFilters[key] !== null
				&& contractStore.contractsFilters[key] !== undefined
				&& contractStore.contractsFilters[key] !== '',
			)
		},
		filteredItems() {
			return contractStore.contractsList || []
		},
		paginatedItems() {
			return this.filteredItems
		},
		allSelected() {
			return this.paginatedItems.length > 0 && this.paginatedItems.every(item => this.selectedItems.includes(item.id))
		},
		someSelected() {
			return this.selectedItems.length > 0 && !this.allSelected
		},
		totalPages() {
			return contractStore.contractsPagination.pages || 1
		},
		currentPage() {
			return contractStore.contractsPagination.page || 1
		},
		emptyContentName() {
			if (contractStore.contractsLoading) {
				return t('openconnector', 'Loading contracts...')
			} else if (contractStore.contractsError) {
				return contractStore.contractsError
			} else if (!contractStore.contractsList?.length) {
				return t('openconnector', 'No contracts found')
			} else if (!this.filteredItems.length) {
				return t('openconnector', 'No contracts match your filters')
			}
			return ''
		},
		emptyContentDescription() {
			if (contractStore.contractsLoading) {
				return t('openconnector', 'Please wait while we fetch your contracts.')
			} else if (contractStore.contractsError) {
				return t('openconnector', 'Please try again later.')
			} else if (!contractStore.contractsList?.length) {
				return t('openconnector', 'No synchronization contracts are available.')
			} else if (!this.filteredItems.length) {
				return t('openconnector', 'Try adjusting your filter settings in the sidebar.')
			}
			return ''
		},
	},
	mounted() {
		this.loadItems()
		this.$root.$on('contracts-filters-changed', this.handleFiltersChanged)
		this.$root.$on('contracts-bulk-delete', this.bulkDelete)
		this.$root.$on('contracts-export-filtered', this.exportFiltered)
	},
	beforeDestroy() {
		this.$root.$off('contracts-filters-changed')
		this.$root.$off('contracts-bulk-delete')
		this.$root.$off('contracts-export-filtered')
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
		getActionClass(action) {
			switch (action) {
			case 'create':
			case 'created':
				return 'createAction'
			case 'update':
			case 'updated':
				return 'updateAction'
			case 'delete':
			case 'deleted':
				return 'deleteAction'
			case 'insert':
				return 'insertAction'
			default:
				return 'noneAction'
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
		toggleSelectAll(checked) {
			if (checked) {
				this.selectedItems = this.paginatedItems.map(item => item.id)
			} else {
				this.selectedItems = []
			}
		},
		toggleItemSelection(itemId, checked) {
			if (checked) {
				if (!this.selectedItems.includes(itemId)) {
					this.selectedItems.push(itemId)
				}
			} else {
				const index = this.selectedItems.indexOf(itemId)
				if (index > -1) {
					this.selectedItems.splice(index, 1)
				}
			}
		},
		async bulkDelete() {
			if (this.selectedItems.length === 0) return

			if (!confirm(this.t('openconnector', 'Are you sure you want to delete the selected contracts? This action cannot be undone.'))) {
				return
			}

			try {
				await contractStore.deleteMultiple(this.selectedItems)
				this.selectedItems = []
				await this.loadItems()
			} catch (error) {
				console.error('Error deleting contracts:', error)
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
		async changePage(page) {
			try {
				await contractStore.fetchContracts({ page })
				this.selectedItems = []
			} catch (error) {
				console.error('Error changing page:', error)
			}
		},
		async onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
			try {
				await contractStore.fetchContracts({ page: 1, limit: pageSize })
				this.selectedItems = []
			} catch (error) {
				console.error('Error changing page size:', error)
			}
		},
		async refreshItems() {
			await this.loadItems()
			this.selectedItems = []
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
/* All CSS is provided by main.css */
</style>

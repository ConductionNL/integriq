<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openconnector', 'Synchronizations')"
			:description="t('openconnector', 'Manage your data synchronizations and their configurations')"
			:show-title="true"
			:objects="filteredSynchronizations"
			:columns="tableColumns"
			:pagination="paginationData"
			:loading="loading"
			:refreshing="refreshing"
			:inline-action-count="1"
			:view-mode="synchronizationStore.viewMode"
			:selectable="true"
			:selected-ids="selectedSynchronizations"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-mass-copy="false"
			show-view-toggle
			:add-label="t('openconnector', 'Add synchronization')"
			row-key="id"
			:empty-text="emptyContentName"
			name-field="name"
			@add="onAdd"
			@delete="onDelete"
			@mass-delete="onMassDelete"
			@mass-export="onMassExport"
			@mass-import="onMassImport"
			@refresh="onRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="synchronizationStore.setViewMode($event)"
			@select="onSelect">
			<!-- Card view -->
			<template #card="{ object: synchronization }">
				<div class="card">
					<div class="cardHeader">
						<h2 v-tooltip.bottom="synchronization.description">
							<VectorPolylinePlus :size="20" />
							{{ synchronization.name }}
						</h2>
						<NcActions :primary="true" :menu-name="t('openconnector', 'Actions')">
							<template #icon>
								<DotsHorizontal :size="20" />
							</template>
							<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationItem(synchronization); navigationStore.setModal('viewSynchronization')">
								<template #icon>
									<Eye :size="20" />
								</template>
								{{ t('openconnector', 'View details') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationItem(synchronization); navigationStore.setModal('editSynchronization')">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('openconnector', 'Edit') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="viewContract(synchronization)">
								<template #icon>
									<FileDocumentOutline :size="20" />
								</template>
								{{ t('openconnector', 'View contract') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationItem(synchronization); navigationStore.setModal('runSynchronization')">
								<template #icon>
									<Play :size="20" />
								</template>
								{{ t('openconnector', 'Run') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationItem(synchronization); $router.push('/synchronizations/logs?synchronization=' + synchronization.id)">
								<template #icon>
									<TextBoxOutline :size="20" />
								</template>
								{{ t('openconnector', 'View logs') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="addSourceConfig(synchronization)">
								<template #icon>
									<DatabaseSettingsOutline :size="20" />
								</template>
								{{ t('openconnector', 'Add source config') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="addTargetConfig(synchronization)">
								<template #icon>
									<CardBulletedSettingsOutline :size="20" />
								</template>
								{{ t('openconnector', 'Add target config') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="synchronizationStore.exportSynchronization(synchronization.id)">
								<template #icon>
									<FileExportOutline :size="20" />
								</template>
								{{ t('openconnector', 'Export') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="$refs.indexPage.openDeleteDialog(synchronization)">
								<template #icon>
									<TrashCanOutline :size="20" />
								</template>
								{{ t('openconnector', 'Delete') }}
							</NcActionButton>
						</NcActions>
					</div>
					<div class="synchronizationDetails">
						<p v-if="synchronization.description" class="synchronizationDescription">
							{{ synchronization.description }}
						</p>
						<!-- Toggle between stats, source configs, and target configs -->
						<div v-if="!getSyncViewState(synchronization).showSourceConfigs && !getSyncViewState(synchronization).showTargetConfigs">
							<table class="statisticsTable synchronizationStats">
								<thead>
									<tr>
										<th>{{ t('openconnector', 'Property') }}</th>
										<th>{{ t('openconnector', 'Source') }}</th>
										<th>{{ t('openconnector', 'Target') }}</th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td>{{ t('openconnector', 'Type') }}</td>
										<td>{{ synchronization.sourceType || t('openconnector', 'Unknown') }}</td>
										<td>{{ synchronization.targetType || t('openconnector', 'Unknown') }}</td>
									</tr>
									<tr>
										<td>{{ t('openconnector', 'ID') }}</td>
										<td>{{ synchronization.sourceId || '-' }}</td>
										<td>{{ synchronization.targetId || '-' }}</td>
									</tr>
									<tr>
										<td>{{ t('openconnector', 'Hash') }}</td>
										<td>{{ synchronization.sourceHash || '-' }}</td>
										<td>{{ synchronization.targetHash || '-' }}</td>
									</tr>
									<tr>
										<td>{{ t('openconnector', 'Configurations') }}</td>
										<td>
											<div class="configCell">
												<span>{{ getSourceConfigCount(synchronization) }}</span>
												<NcButton @click="showSourceConfigs(synchronization)">
													<template #icon>
														<DatabaseSettingsOutline :size="16" />
													</template>
													{{ t('openconnector', 'Show') }}
												</NcButton>
											</div>
										</td>
										<td>
											<div class="configCell">
												<span>{{ getTargetConfigCount(synchronization) }}</span>
												<NcButton @click="showTargetConfigs(synchronization)">
													<template #icon>
														<CardBulletedSettingsOutline :size="16" />
													</template>
													{{ t('openconnector', 'Show') }}
												</NcButton>
											</div>
										</td>
									</tr>
									<tr>
										<td>{{ t('openconnector', 'Last synced') }}</td>
										<td>
											<NcDateTime v-if="synchronization.sourceLastSynced" :timestamp="new Date(synchronization.sourceLastSynced)" />
											<span v-else>-</span>
										</td>
										<td>
											<NcDateTime v-if="synchronization.targetLastSynced" :timestamp="new Date(synchronization.targetLastSynced)" />
											<span v-else>-</span>
										</td>
									</tr>
									<tr>
										<td>{{ t('openconnector', 'Last checked') }}</td>
										<td>
											<NcDateTime v-if="synchronization.sourceLastChecked" :timestamp="new Date(synchronization.sourceLastChecked)" />
											<span v-else>-</span>
										</td>
										<td>
											<NcDateTime v-if="synchronization.targetLastChecked" :timestamp="new Date(synchronization.targetLastChecked)" />
											<span v-else>-</span>
										</td>
									</tr>
									<tr>
										<td>{{ t('openconnector', 'Version') }}</td>
										<td colspan="2">
											{{ synchronization.version || '-' }}
										</td>
									</tr>
									<tr>
										<td>{{ t('openconnector', 'Created') }}</td>
										<td colspan="2">
											<NcDateTime v-if="synchronization.created" :timestamp="new Date(synchronization.created)" />
											<span v-else>-</span>
										</td>
									</tr>
									<tr>
										<td>{{ t('openconnector', 'Updated') }}</td>
										<td colspan="2">
											<NcDateTime v-if="synchronization.updated" :timestamp="new Date(synchronization.updated)" />
											<span v-else>-</span>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
						<!-- Source configurations view -->
						<div v-else-if="getSyncViewState(synchronization).showSourceConfigs" class="configsView">
							<div class="configsTableWrapper">
								<table class="statisticsTable synchronizationStats">
									<thead>
										<tr>
											<th>{{ t('openconnector', 'Key') }}</th>
											<th>{{ t('openconnector', 'Value') }}</th>
											<th>{{ t('openconnector', 'Actions') }}</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="(value, key) in synchronization.sourceConfig" :key="key">
											<td>{{ key }}</td>
											<td class="truncatedText">
												{{ typeof value === 'object' ? JSON.stringify(value) : value }}
											</td>
											<td>
												<NcActions :primary="false">
													<template #icon>
														<DotsHorizontal :size="16" />
													</template>
													<NcActionButton close-after-click @click="editSourceConfig(synchronization, key)">
														<template #icon>
															<Pencil :size="16" />
														</template>
														{{ t('openconnector', 'Edit') }}
													</NcActionButton>
													<NcActionButton close-after-click @click="deleteSourceConfig(synchronization, key)">
														<template #icon>
															<TrashCanOutline :size="16" />
														</template>
														{{ t('openconnector', 'Delete') }}
													</NcActionButton>
												</NcActions>
											</td>
										</tr>
										<tr v-if="!synchronization.sourceConfig || !Object.keys(synchronization.sourceConfig).length">
											<td colspan="3">
												{{ t('openconnector', 'No source configurations found') }}
											</td>
										</tr>
									</tbody>
								</table>
							</div>
							<div class="configsViewFooter">
								<NcButton @click="showSyncStats(synchronization)">
									<template #icon>
										<ArrowLeft :size="16" />
									</template>
									{{ t('openconnector', 'Back') }}
								</NcButton>
								<NcButton :primary="true" @click="addSourceConfig(synchronization)">
									<template #icon>
										<Plus :size="16" />
									</template>
									{{ t('openconnector', 'Add source config') }}
								</NcButton>
							</div>
						</div>
						<!-- Target configurations view -->
						<div v-else-if="getSyncViewState(synchronization).showTargetConfigs" class="configsView">
							<div class="configsTableWrapper">
								<table class="statisticsTable synchronizationStats">
									<thead>
										<tr>
											<th>{{ t('openconnector', 'Key') }}</th>
											<th>{{ t('openconnector', 'Value') }}</th>
											<th>{{ t('openconnector', 'Actions') }}</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="(value, key) in synchronization.targetConfig" :key="key">
											<td>{{ key }}</td>
											<td class="truncatedText">
												{{ typeof value === 'object' ? JSON.stringify(value) : value }}
											</td>
											<td>
												<NcActions :primary="false">
													<template #icon>
														<DotsHorizontal :size="16" />
													</template>
													<NcActionButton close-after-click @click="editTargetConfig(synchronization, key)">
														<template #icon>
															<Pencil :size="16" />
														</template>
														{{ t('openconnector', 'Edit') }}
													</NcActionButton>
													<NcActionButton close-after-click @click="deleteTargetConfig(synchronization, key)">
														<template #icon>
															<TrashCanOutline :size="16" />
														</template>
														{{ t('openconnector', 'Delete') }}
													</NcActionButton>
												</NcActions>
											</td>
										</tr>
										<tr v-if="!synchronization.targetConfig || !Object.keys(synchronization.targetConfig).length">
											<td colspan="3">
												{{ t('openconnector', 'No target configurations found') }}
											</td>
										</tr>
									</tbody>
								</table>
							</div>
							<div class="configsViewFooter">
								<NcButton @click="showSyncStats(synchronization)">
									<template #icon>
										<ArrowLeft :size="16" />
									</template>
									{{ t('openconnector', 'Back') }}
								</NcButton>
								<NcButton :primary="true" @click="addTargetConfig(synchronization)">
									<template #icon>
										<Plus :size="16" />
									</template>
									{{ t('openconnector', 'Add target config') }}
								</NcButton>
							</div>
						</div>
					</div>
				</div>
			</template>

			<!-- Table column slots -->
			<template #column-name="{ row }">
				<div class="titleContent">
					<strong>{{ row.name }}</strong>
					<span v-if="row.description" class="textDescription textEllipsis">{{ row.description }}</span>
				</div>
			</template>

			<template #column-sourceType="{ row }">
				{{ row.sourceType || t('openconnector', 'Unknown') }}
			</template>

			<template #column-targetType="{ row }">
				{{ row.targetType || t('openconnector', 'Unknown') }}
			</template>

			<template #column-version="{ row }">
				{{ row.version || '-' }}
			</template>

			<template #column-configs="{ row }">
				{{ getSourceConfigCount(row) + getTargetConfigCount(row) }}
			</template>

			<template #column-lastSynced="{ row }">
				<NcDateTime v-if="getLatestSyncedTimestamp(row)" :timestamp="getLatestSyncedTimestamp(row)" />
				<span v-else>-</span>
			</template>

			<template #column-updated="{ row }">
				<NcDateTime v-if="row.updated" :timestamp="new Date(row.updated)" />
				<span v-else>-</span>
			</template>

			<!-- Row actions (table view) -->
			<template #row-actions="{ row: synchronization }">
				<NcActions :primary="false">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationItem(synchronization); navigationStore.setModal('viewSynchronization')">
						<template #icon>
							<Eye :size="20" />
						</template>
						{{ t('openconnector', 'View details') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationItem(synchronization); navigationStore.setModal('editSynchronization')">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('openconnector', 'Edit') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="viewContract(synchronization)">
						<template #icon>
							<FileDocumentOutline :size="20" />
						</template>
						{{ t('openconnector', 'View contract') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationItem(synchronization); navigationStore.setModal('runSynchronization')">
						<template #icon>
							<Play :size="20" />
						</template>
						{{ t('openconnector', 'Run') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationItem(synchronization); $router.push('/synchronizations/logs?synchronization=' + synchronization.id)">
						<template #icon>
							<TextBoxOutline :size="20" />
						</template>
						{{ t('openconnector', 'View logs') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="addSourceConfig(synchronization)">
						<template #icon>
							<DatabaseSettingsOutline :size="20" />
						</template>
						{{ t('openconnector', 'Add source config') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="addTargetConfig(synchronization)">
						<template #icon>
							<CardBulletedSettingsOutline :size="20" />
						</template>
						{{ t('openconnector', 'Add target config') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="synchronizationStore.exportSynchronization(synchronization.id)">
						<template #icon>
							<FileExportOutline :size="20" />
						</template>
						{{ t('openconnector', 'Export') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationItem(synchronization); navigationStore.setDialog('deleteSynchronization')">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						{{ t('openconnector', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</template>
		</CnIndexPage>
		<CnDeleteDialog
			v-if="sourceConfigToDelete"
			ref="deleteSourceConfigDialog"
			:item="{ id: sourceConfigToDelete.key, name: sourceConfigToDelete.key }"
			name-field="name"
			:dialog-title="t('openconnector', 'Delete source config')"
			@confirm="onConfirmDeleteSourceConfig"
			@close="sourceConfigToDelete = null" />
		<CnDeleteDialog
			v-if="targetConfigToDelete"
			ref="deleteTargetConfigDialog"
			:item="{ id: targetConfigToDelete.key, name: targetConfigToDelete.key }"
			name-field="name"
			:dialog-title="t('openconnector', 'Delete target config')"
			@confirm="onConfirmDeleteTargetConfig"
			@close="targetConfigToDelete = null" />
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActions, NcActionButton, NcButton, NcDateTime } from '@nextcloud/vue'
import { CnIndexPage, CnDeleteDialog } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import VectorPolylinePlus from 'vue-material-design-icons/VectorPolylinePlus.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Play from 'vue-material-design-icons/Play.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import DatabaseSettingsOutline from 'vue-material-design-icons/DatabaseSettingsOutline.vue'
import CardBulletedSettingsOutline from 'vue-material-design-icons/CardBulletedSettingsOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'

import { Synchronization } from '../../entities/index.js'
import { synchronizationStore, navigationStore, importExportStore } from '../../store/store.js'

export default {
	name: 'SynchronizationsIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		CnDeleteDialog,
		NcActions,
		NcActionButton,
		NcButton,
		NcDateTime,
		VectorPolylinePlus,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Plus,
		Eye,
		Play,
		TextBoxOutline,
		DatabaseSettingsOutline,
		CardBulletedSettingsOutline,
		FileDocumentOutline,
		FileExportOutline,
		ArrowLeft,
	},
	data() {
		return {
			synchronizationStore,
			navigationStore,
			selectedSynchronizations: [],
			loading: false,
			refreshing: false,
			loadError: null,
			pagination: {
				page: 1,
				limit: 20,
			},
			syncViewStates: {},
			sourceConfigToDelete: null,
			targetConfigToDelete: null,
		}
	},
	computed: {
		filteredSynchronizations() {
			return synchronizationStore.synchronizationList || []
		},
		tableColumns() {
			return [
				{ key: 'name', label: t('openconnector', 'Name'), sortable: true },
				{ key: 'sourceType', label: t('openconnector', 'Source type') },
				{ key: 'targetType', label: t('openconnector', 'Target type') },
				{ key: 'version', label: t('openconnector', 'Version') },
				{ key: 'configs', label: t('openconnector', 'Configs') },
				{ key: 'lastSynced', label: t('openconnector', 'Last synced'), sortable: true },
				{ key: 'updated', label: t('openconnector', 'Updated'), sortable: true },
			]
		},
		paginationData() {
			const page = this.pagination.page || 1
			const limit = this.pagination.limit || 20
			const total = this.filteredSynchronizations.length
			const pages = Math.max(1, Math.ceil(total / limit))
			return { page, pages, total, limit }
		},
		emptyContentName() {
			if (this.loadError) return this.loadError
			if (this.loading) return t('openconnector', 'Loading synchronizations...')
			if (!this.filteredSynchronizations.length) return t('openconnector', 'No synchronizations found')
			return ''
		},
	},
	async mounted() {
		this.loading = true
		this.loadError = null
		try {
			await synchronizationStore.refreshSynchronizationList()
		} catch (e) {
			this.loadError = e.message || t('openconnector', 'Failed to load synchronizations')
		} finally {
			this.loading = false
		}
	},
	methods: {
		t,
		onAdd() {
			synchronizationStore.setSynchronizationItem({})
			navigationStore.setModal('editSynchronization')
		},
		async onRefresh() {
			this.refreshing = true
			this.loadError = null
			try {
				await synchronizationStore.refreshSynchronizationList()
			} catch (e) {
				this.loadError = e.message || t('openconnector', 'Failed to load synchronizations')
			} finally {
				this.refreshing = false
			}
		},
		onPageChanged(page) {
			this.pagination.page = page
		},
		onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
		},
		onSelect(ids) {
			this.selectedSynchronizations = ids
		},
		getSourceConfigCount(synchronization) {
			const config = synchronization.sourceConfig || {}
			return Object.keys(config).length
		},
		getTargetConfigCount(synchronization) {
			const config = synchronization.targetConfig || {}
			return Object.keys(config).length
		},
		getLatestSyncedTimestamp(synchronization) {
			const sourceSynced = synchronization.sourceLastSynced
			const targetSynced = synchronization.targetLastSynced
			if (sourceSynced && targetSynced) {
				const sourceDate = new Date(sourceSynced)
				const targetDate = new Date(targetSynced)
				return sourceDate > targetDate ? sourceDate : targetDate
			}
			if (sourceSynced) return new Date(sourceSynced)
			if (targetSynced) return new Date(targetSynced)
			return null
		},
		addSourceConfig(synchronization) {
			synchronizationStore.setSynchronizationItem(synchronization)
			synchronizationStore.setSynchronizationSourceConfigKey(null)
			navigationStore.setModal('editSynchronizationSourceConfig')
		},
		addTargetConfig(synchronization) {
			synchronizationStore.setSynchronizationItem(synchronization)
			synchronizationStore.setSynchronizationTargetConfigKey(null)
			navigationStore.setModal('editSynchronizationTargetConfig')
		},
		viewContract(synchronization) {
			synchronizationStore.setSynchronizationItem(synchronization)
			this.$router.push('/synchronizations/contracts?synchronization=' + synchronization.id)
		},
		getSyncViewState(synchronization) {
			if (!this.syncViewStates[synchronization.id]) {
				this.$set(this.syncViewStates, synchronization.id, {
					showSourceConfigs: false,
					showTargetConfigs: false,
				})
			}
			return this.syncViewStates[synchronization.id]
		},
		showSourceConfigs(synchronization) {
			const viewState = this.getSyncViewState(synchronization)
			viewState.showSourceConfigs = true
			viewState.showTargetConfigs = false
		},
		showTargetConfigs(synchronization) {
			const viewState = this.getSyncViewState(synchronization)
			viewState.showTargetConfigs = true
			viewState.showSourceConfigs = false
		},
		showSyncStats(synchronization) {
			const viewState = this.getSyncViewState(synchronization)
			viewState.showSourceConfigs = false
			viewState.showTargetConfigs = false
		},
		editSourceConfig(synchronization, key) {
			synchronizationStore.setSynchronizationItem(synchronization)
			synchronizationStore.setSynchronizationSourceConfigKey(key)
			navigationStore.setModal('editSynchronizationSourceConfig')
		},
		deleteSourceConfig(synchronization, key) {
			this.sourceConfigToDelete = { synchronization, key }
		},
		editTargetConfig(synchronization, key) {
			synchronizationStore.setSynchronizationItem(synchronization)
			synchronizationStore.setSynchronizationTargetConfigKey(key)
			navigationStore.setModal('editSynchronizationTargetConfig')
		},
		deleteTargetConfig(synchronization, key) {
			this.targetConfigToDelete = { synchronization, key }
		},
		async onDelete(id) {
			try {
				await synchronizationStore.deleteSynchronization(id)
				await synchronizationStore.refreshSynchronizationList()
				this.$refs.indexPage.setSingleDeleteResult({ success: true })
			} catch (e) {
				this.$refs.indexPage.setSingleDeleteResult({
					error: e.message || t('openconnector', 'An error occurred while deleting the synchronization'),
				})
			}
		},
		async onMassDelete(ids) {
			const errors = []
			for (const id of ids) {
				try {
					await synchronizationStore.deleteSynchronization(id)
				} catch (e) {
					errors.push(e.message || id)
				}
			}
			await synchronizationStore.refreshSynchronizationList()
			this.selectedSynchronizations = []
			if (errors.length) {
				this.$refs.indexPage.setMassDeleteResult({
					error: t('openconnector', 'Failed to delete some synchronizations: {list}', { list: errors.join(', ') }),
				})
			} else {
				this.$refs.indexPage.setMassDeleteResult({ success: true })
			}
		},
		async onMassExport({ ids }) {
			try {
				for (const id of ids) {
					await synchronizationStore.exportSynchronization(id)
				}
				this.$refs.indexPage.setExportResult({ success: true })
			} catch (e) {
				this.$refs.indexPage.setExportResult({
					error: e.message || t('openconnector', 'Export failed'),
				})
			}
		},
		async onMassImport({ file }) {
			try {
				await importExportStore.importFile({ value: [file] }, () => {})
				await synchronizationStore.refreshSynchronizationList()
				this.$refs.indexPage.setImportResult({ success: true })
			} catch (e) {
				this.$refs.indexPage.setImportResult({
					error: e?.response?.data?.error || e.message || t('openconnector', 'Import failed'),
				})
			}
		},
		async onConfirmDeleteSourceConfig() {
			const { synchronization, key } = this.sourceConfigToDelete
			try {
				const cloneSource = synchronization.cloneRaw ? synchronization.cloneRaw() : { ...synchronization }
				const sourceConfigClone = { ...(cloneSource.sourceConfig || {}) }
				delete sourceConfigClone[key]
				const updated = new Synchronization({ ...cloneSource, sourceConfig: sourceConfigClone })
				const { response } = await synchronizationStore.saveSynchronization(updated)
				if (!response.ok) {
					throw new Error(t('openconnector', 'Failed to delete source config'))
				}
				await synchronizationStore.refreshSynchronizationList()
				this.$refs.deleteSourceConfigDialog.setResult({ success: true })
				setTimeout(() => { this.sourceConfigToDelete = null }, 2000)
			} catch (e) {
				this.$refs.deleteSourceConfigDialog.setResult({
					error: e.message || t('openconnector', 'Failed to delete source config'),
				})
			}
		},
		async onConfirmDeleteTargetConfig() {
			const { synchronization, key } = this.targetConfigToDelete
			try {
				const cloneSource = synchronization.cloneRaw ? synchronization.cloneRaw() : { ...synchronization }
				const targetConfigClone = { ...(cloneSource.targetConfig || {}) }
				delete targetConfigClone[key]
				const updated = new Synchronization({ ...cloneSource, targetConfig: targetConfigClone })
				const { response } = await synchronizationStore.saveSynchronization(updated)
				if (!response.ok) {
					throw new Error(t('openconnector', 'Failed to delete target config'))
				}
				await synchronizationStore.refreshSynchronizationList()
				this.$refs.deleteTargetConfigDialog.setResult({ success: true })
				setTimeout(() => { this.targetConfigToDelete = null }, 2000)
			} catch (e) {
				this.$refs.deleteTargetConfigDialog.setResult({
					error: e.message || t('openconnector', 'Failed to delete target config'),
				})
			}
		},
	},
}
</script>

<style scoped>
.configCell {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.configsView {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.configsTableWrapper {
	flex: 1;
}

.configsViewFooter {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	gap: 8px;
	margin-top: auto;
	padding-top: 10px;
}
</style>

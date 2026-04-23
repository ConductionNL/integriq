<script setup>
import { translate as t } from '@nextcloud/l10n'
import { sourceStore, navigationStore } from '../../store/store.js'
import { sourceSchema } from './sourceSchema.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openconnector', 'Sources')"
			:description="t('openconnector', 'Manage your data sources and their configurations')"
			:show-title="true"
			:objects="filteredSources"
			:schema="schema"
			:columns="tableColumns"
			:pagination="paginationData"
			:loading="sourceStore.loading"
			:view-mode="sourceStore.viewMode"
			:selectable="true"
			:selected-ids="selectedSources"
			:show-copy-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			show-view-toggle
			:add-label="t('openconnector', 'Add source')"
			row-key="id"
			:empty-text="emptyContentName"
			name-field="name"
			@add="onAdd"
			@create="onSave"
			@edit="onSave"
			@delete="onDelete"
			@refresh="sourceStore.refreshList()"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="sourceStore.setViewMode($event)"
			@select="onSelect">
			<!-- Custom card template: preserves per-card stats / configurations / authentication toggle -->
			<template #card="{ object: source }">
				<div class="card">
					<div class="cardHeader">
						<h2 v-tooltip.bottom="source.description">
							<DatabaseArrowLeftOutline :size="20" />
							{{ source.name }}
						</h2>
						<NcActions :primary="true" :menu-name="t('openconnector', 'Actions')">
							<template #icon>
								<DotsHorizontal :size="20" />
							</template>
							<NcActionButton close-after-click @click="sourceStore.setItem(source); navigationStore.setModal('viewSource')">
								<template #icon>
									<Eye :size="20" />
								</template>
								{{ t('openconnector', 'View') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="editSource(source)">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('openconnector', 'Edit') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="sourceStore.setItem(source); navigationStore.setModal('testSource')">
								<template #icon>
									<Sync :size="20" />
								</template>
								{{ t('openconnector', 'Test') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="sourceStore.setItem(source); $router.push('/sources/logs?source=' + source.id)">
								<template #icon>
									<TextBoxOutline :size="20" />
								</template>
								{{ t('openconnector', 'View logs') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="addSourceConfiguration(source)">
								<template #icon>
									<Plus :size="20" />
								</template>
								{{ t('openconnector', 'Add configuration') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="addSourceAuthentication(source)">
								<template #icon>
									<Plus :size="20" />
								</template>
								{{ t('openconnector', 'Add authentication') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="$refs.indexPage.openDeleteDialog(source)">
								<template #icon>
									<TrashCanOutline :size="20" />
								</template>
								{{ t('openconnector', 'Delete') }}
							</NcActionButton>
						</NcActions>
					</div>
					<!-- Source Details -->
					<div class="sourceDetails">
						<p v-if="source.description" class="sourceDescription">
							{{ source.description }}
						</p>
						<!-- Toggle between stats, configurations, and authentication -->
						<div v-if="!getSourceViewState(source).showConfigurations && !getSourceViewState(source).showAuthentication">
							<table class="statisticsTable sourceStats">
								<thead>
									<tr>
										<th>{{ t('openconnector', 'Property') }}</th>
										<th>{{ t('openconnector', 'Value') }}</th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td>{{ t('openconnector', 'Status') }}</td>
										<td>{{ source.status || t('openconnector', 'Unknown') }}</td>
									</tr>
									<tr>
										<td>{{ t('openconnector', 'Enabled') }}</td>
										<td>{{ source.isEnabled ? t('openconnector', 'Enabled') : t('openconnector', 'Disabled') }}</td>
									</tr>
									<tr>
										<td>{{ t('openconnector', 'Type') }}</td>
										<td>{{ source.type || t('openconnector', 'Unknown') }}</td>
									</tr>
									<tr v-if="source.location">
										<td>{{ t('openconnector', 'Location') }}</td>
										<td class="truncatedUrl">
											{{ source.location }}
										</td>
									</tr>
									<tr v-if="source.version">
										<td>{{ t('openconnector', 'Version') }}</td>
										<td>{{ source.version }}</td>
									</tr>
									<tr>
										<td>{{ t('openconnector', 'Configurations') }}</td>
										<td class="sourceDetails__value">
											<span>{{ getConfigurationCount(source) }}</span>
											<NcButton @click="showSourceConfigurations(source)">
												<template #icon>
													<FileCogOutline :size="16" />
												</template>
												{{ t('openconnector', 'Show') }}
											</NcButton>
										</td>
									</tr>
									<tr>
										<td>{{ t('openconnector', 'Authentication') }}</td>
										<td class="sourceDetails__value">
											<span>{{ getAuthenticationCount(source) }}</span>
											<NcButton @click="showSourceAuthentication(source)">
												<template #icon>
													<KeyOutline :size="16" />
												</template>
												{{ t('openconnector', 'Show') }}
											</NcButton>
										</td>
									</tr>
									<tr v-if="source.lastCall">
										<td>{{ t('openconnector', 'Last call') }}</td>
										<td><NcDateTime :timestamp="new Date(source.lastCall)" /></td>
									</tr>
									<tr v-if="source.lastSync">
										<td>{{ t('openconnector', 'Last sync') }}</td>
										<td><NcDateTime :timestamp="new Date(source.lastSync)" /></td>
									</tr>
									<tr>
										<td>{{ t('openconnector', 'Created') }}</td>
										<td>
											<NcDateTime v-if="source.dateCreated" :timestamp="new Date(source.dateCreated)" />
											<span v-else>-</span>
										</td>
									</tr>
									<tr>
										<td>{{ t('openconnector', 'Updated') }}</td>
										<td>
											<NcDateTime v-if="source.dateModified" :timestamp="new Date(source.dateModified)" />
											<span v-else>-</span>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
						<!-- Configurations view -->
						<div v-else-if="getSourceViewState(source).showConfigurations" class="sourceDetails__viewBody">
							<div class="sourceDetails__viewContent">
								<table class="statisticsTable sourceStats">
									<thead>
										<tr>
											<th>{{ t('openconnector', 'Key') }}</th>
											<th>{{ t('openconnector', 'Value') }}</th>
											<th>{{ t('openconnector', 'Actions') }}</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="(value, key) in source.configuration" :key="key">
											<td>{{ key }}</td>
											<td class="truncatedText">
												{{ value }}
											</td>
											<td>
												<NcActions :primary="false">
													<template #icon>
														<DotsHorizontal :size="16" />
													</template>
													<NcActionButton close-after-click @click="editSourceConfiguration(source, key)">
														<template #icon>
															<Pencil :size="16" />
														</template>
														{{ t('openconnector', 'Edit') }}
													</NcActionButton>
													<NcActionButton close-after-click @click="deleteSourceConfiguration(source, key)">
														<template #icon>
															<TrashCanOutline :size="16" />
														</template>
														{{ t('openconnector', 'Delete') }}
													</NcActionButton>
												</NcActions>
											</td>
										</tr>
										<tr v-if="!source.configuration || !Object.keys(source.configuration).length">
											<td colspan="3">
												{{ t('openconnector', 'No configurations found') }}
											</td>
										</tr>
									</tbody>
								</table>
							</div>
							<div class="sourceDetails__footer">
								<NcButton @click="showSourceStats(source)">
									<template #icon>
										<ArrowLeft :size="16" />
									</template>
									{{ t('openconnector', 'Back') }}
								</NcButton>
								<NcButton :primary="true" @click="addSourceConfiguration(source)">
									<template #icon>
										<Plus :size="16" />
									</template>
									{{ t('openconnector', 'Add configuration') }}
								</NcButton>
							</div>
						</div>
						<!-- Authentication view -->
						<div v-else-if="getSourceViewState(source).showAuthentication" class="sourceDetails__viewBody">
							<div class="sourceDetails__viewContent">
								<table class="statisticsTable sourceStats">
									<thead>
										<tr>
											<th>{{ t('openconnector', 'Property') }}</th>
											<th>{{ t('openconnector', 'Value') }}</th>
											<th>{{ t('openconnector', 'Actions') }}</th>
										</tr>
									</thead>
									<tbody>
										<tr v-if="source.auth">
											<td>{{ t('openconnector', 'Auth type') }}</td>
											<td>{{ source.auth }}</td>
											<td>
												<NcActions :primary="false">
													<template #icon>
														<DotsHorizontal :size="16" />
													</template>
													<NcActionButton close-after-click @click="editSourceAuthentication(source, 'auth')">
														<template #icon>
															<Pencil :size="16" />
														</template>
														{{ t('openconnector', 'Edit') }}
													</NcActionButton>
													<NcActionButton close-after-click @click="deleteSourceAuthentication(source, 'auth')">
														<template #icon>
															<TrashCanOutline :size="16" />
														</template>
														{{ t('openconnector', 'Delete') }}
													</NcActionButton>
												</NcActions>
											</td>
										</tr>
										<tr v-if="source.username">
											<td>{{ t('openconnector', 'Username') }}</td>
											<td>{{ source.username }}</td>
											<td>
												<NcActions :primary="false">
													<template #icon>
														<DotsHorizontal :size="16" />
													</template>
													<NcActionButton close-after-click @click="editSourceAuthentication(source, 'username')">
														<template #icon>
															<Pencil :size="16" />
														</template>
														{{ t('openconnector', 'Edit') }}
													</NcActionButton>
													<NcActionButton close-after-click @click="deleteSourceAuthentication(source, 'username')">
														<template #icon>
															<TrashCanOutline :size="16" />
														</template>
														{{ t('openconnector', 'Delete') }}
													</NcActionButton>
												</NcActions>
											</td>
										</tr>
										<tr v-if="source.apikey">
											<td>{{ t('openconnector', 'API key') }}</td>
											<td class="truncatedText">
												{{ source.apikey }}
											</td>
											<td>
												<NcActions :primary="false">
													<template #icon>
														<DotsHorizontal :size="16" />
													</template>
													<NcActionButton close-after-click @click="editSourceAuthentication(source, 'apikey')">
														<template #icon>
															<Pencil :size="16" />
														</template>
														{{ t('openconnector', 'Edit') }}
													</NcActionButton>
													<NcActionButton close-after-click @click="deleteSourceAuthentication(source, 'apikey')">
														<template #icon>
															<TrashCanOutline :size="16" />
														</template>
														{{ t('openconnector', 'Delete') }}
													</NcActionButton>
												</NcActions>
											</td>
										</tr>
										<tr v-if="source.jwt">
											<td>{{ t('openconnector', 'JWT') }}</td>
											<td class="truncatedText">
												{{ source.jwt }}
											</td>
											<td>
												<NcActions :primary="false">
													<template #icon>
														<DotsHorizontal :size="16" />
													</template>
													<NcActionButton close-after-click @click="editSourceAuthentication(source, 'jwt')">
														<template #icon>
															<Pencil :size="16" />
														</template>
														{{ t('openconnector', 'Edit') }}
													</NcActionButton>
													<NcActionButton close-after-click @click="deleteSourceAuthentication(source, 'jwt')">
														<template #icon>
															<TrashCanOutline :size="16" />
														</template>
														{{ t('openconnector', 'Delete') }}
													</NcActionButton>
												</NcActions>
											</td>
										</tr>
										<tr v-if="source.secret">
											<td>{{ t('openconnector', 'Secret') }}</td>
											<td class="truncatedText">
												{{ source.secret }}
											</td>
											<td>
												<NcActions :primary="false">
													<template #icon>
														<DotsHorizontal :size="16" />
													</template>
													<NcActionButton close-after-click @click="editSourceAuthentication(source, 'secret')">
														<template #icon>
															<Pencil :size="16" />
														</template>
														{{ t('openconnector', 'Edit') }}
													</NcActionButton>
													<NcActionButton close-after-click @click="deleteSourceAuthentication(source, 'secret')">
														<template #icon>
															<TrashCanOutline :size="16" />
														</template>
														{{ t('openconnector', 'Delete') }}
													</NcActionButton>
												</NcActions>
											</td>
										</tr>
										<tr v-if="source.authorizationHeader">
											<td>{{ t('openconnector', 'Authorization header') }}</td>
											<td class="truncatedText">
												{{ source.authorizationHeader }}
											</td>
											<td>
												<NcActions :primary="false">
													<template #icon>
														<DotsHorizontal :size="16" />
													</template>
													<NcActionButton close-after-click @click="editSourceAuthentication(source, 'authorizationHeader')">
														<template #icon>
															<Pencil :size="16" />
														</template>
														{{ t('openconnector', 'Edit') }}
													</NcActionButton>
													<NcActionButton close-after-click @click="deleteSourceAuthentication(source, 'authorizationHeader')">
														<template #icon>
															<TrashCanOutline :size="16" />
														</template>
														{{ t('openconnector', 'Delete') }}
													</NcActionButton>
												</NcActions>
											</td>
										</tr>
										<tr v-for="(config, index) in source.authenticationConfig" :key="`auth-${index}`">
											<td>{{ t('openconnector', 'Auth config {index}', { index: index + 1 }) }}</td>
											<td class="truncatedText">
												{{ typeof config === 'object' ? JSON.stringify(config) : config }}
											</td>
											<td>
												<NcActions :primary="false">
													<template #icon>
														<DotsHorizontal :size="16" />
													</template>
													<NcActionButton close-after-click @click="editSourceAuthentication(source, `authenticationConfig.${index}`)">
														<template #icon>
															<Pencil :size="16" />
														</template>
														{{ t('openconnector', 'Edit') }}
													</NcActionButton>
													<NcActionButton close-after-click @click="deleteSourceAuthentication(source, `authenticationConfig.${index}`)">
														<template #icon>
															<TrashCanOutline :size="16" />
														</template>
														{{ t('openconnector', 'Delete') }}
													</NcActionButton>
												</NcActions>
											</td>
										</tr>
										<tr v-if="!hasAuthenticationData(source)">
											<td colspan="3">
												{{ t('openconnector', 'No authentication configured') }}
											</td>
										</tr>
									</tbody>
								</table>
							</div>
							<div class="sourceDetails__footer">
								<NcButton @click="showSourceStats(source)">
									<template #icon>
										<ArrowLeft :size="16" />
									</template>
									{{ t('openconnector', 'Back') }}
								</NcButton>
								<NcButton :primary="true" @click="addSourceAuthentication(source)">
									<template #icon>
										<Plus :size="16" />
									</template>
									{{ t('openconnector', 'Add authentication') }}
								</NcButton>
							</div>
						</div>
					</div>
				</div>
			</template>

			<!-- Table column: name with description subtitle -->
			<template #column-name="{ row }">
				<div class="titleContent">
					<strong>{{ row.name }}</strong>
					<span v-if="row.description" class="textDescription textEllipsis">{{ row.description }}</span>
				</div>
			</template>

			<!-- Table column: type -->
			<template #column-type="{ row }">
				{{ row.type || t('openconnector', 'Unknown') }}
			</template>

			<!-- Table column: location -->
			<template #column-location="{ row }">
				<span v-if="row.location" class="truncatedUrl">{{ row.location }}</span>
				<span v-else>-</span>
			</template>

			<!-- Table column: version -->
			<template #column-version="{ row }">
				{{ row.version || '-' }}
			</template>

			<!-- Table column: configurations count -->
			<template #column-configurations="{ row }">
				{{ getConfigurationCount(row) }}
			</template>

			<!-- Table column: created -->
			<template #column-created="{ row }">
				<NcDateTime v-if="row.dateCreated" :timestamp="new Date(row.dateCreated)" />
				<span v-else>-</span>
			</template>

			<!-- Table column: updated -->
			<template #column-updated="{ row }">
				<NcDateTime v-if="row.dateModified" :timestamp="new Date(row.dateModified)" />
				<span v-else>-</span>
			</template>

			<!-- Custom row actions for table view -->
			<template #row-actions="{ row: source }">
				<NcActions :primary="false">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton close-after-click @click="sourceStore.setItem(source); navigationStore.setModal('viewSource')">
						<template #icon>
							<Eye :size="20" />
						</template>
						{{ t('openconnector', 'View') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="editSource(source)">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('openconnector', 'Edit') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="sourceStore.setItem(source); navigationStore.setModal('testSource')">
						<template #icon>
							<Sync :size="20" />
						</template>
						{{ t('openconnector', 'Test') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="sourceStore.setItem(source); $router.push('/sources/logs?source=' + source.id)">
						<template #icon>
							<TextBoxOutline :size="20" />
						</template>
						{{ t('openconnector', 'View logs') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="addSourceConfiguration(source)">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('openconnector', 'Add configuration') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="addSourceAuthentication(source)">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('openconnector', 'Add authentication') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="$refs.indexPage.openDeleteDialog(source)">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						{{ t('openconnector', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActions, NcActionButton, NcButton, NcDateTime } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import DatabaseArrowLeftOutline from 'vue-material-design-icons/DatabaseArrowLeftOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import FileCogOutline from 'vue-material-design-icons/FileCogOutline.vue'
import KeyOutline from 'vue-material-design-icons/KeyOutline.vue'

export default {
	name: 'SourcesIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		NcActions,
		NcActionButton,
		NcButton,
		NcDateTime,
		DatabaseArrowLeftOutline,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Plus,
		Eye,
		Sync,
		TextBoxOutline,
		ArrowLeft,
		FileCogOutline,
		KeyOutline,
	},
	data() {
		return {
			selectedSources: [],
			pagination: {
				page: 1,
				limit: 20,
			},
			sourceViewStates: {},
		}
	},
	computed: {
		schema() {
			return sourceSchema()
		},
		filteredSources() {
			return sourceStore.list || []
		},
		tableColumns() {
			return [
				{ key: 'name', label: t('openconnector', 'Name'), sortable: true },
				{ key: 'type', label: t('openconnector', 'Type') },
				{ key: 'location', label: t('openconnector', 'Location') },
				{ key: 'version', label: t('openconnector', 'Version') },
				{ key: 'configurations', label: t('openconnector', 'Configurations') },
				{ key: 'created', label: t('openconnector', 'Created'), sortable: true },
				{ key: 'updated', label: t('openconnector', 'Updated'), sortable: true },
			]
		},
		paginationData() {
			const page = this.pagination.page || 1
			const limit = this.pagination.limit || 20
			const total = this.filteredSources.length
			const pages = Math.max(1, Math.ceil(total / limit))
			return { page, pages, total, limit }
		},
		emptyContentName() {
			if (sourceStore.error) return sourceStore.error
			if (!sourceStore.list?.length) return t('openconnector', 'No sources found')
			return t('openconnector', 'Loading sources...')
		},
	},
	mounted() {
		sourceStore.refreshList()
	},
	methods: {
		onAdd() {
			this.$refs.indexPage.openFormDialog(null)
		},
		editSource(source) {
			this.$refs.indexPage.openFormDialog(source)
		},
		async onSave(formData) {
			try {
				const payload = { ...formData, location: (formData.location || '').replace(/\/+$/, '') }
				await sourceStore.save(payload)
				this.$refs.indexPage.setFormResult({ success: true })
			} catch (e) {
				this.$refs.indexPage.setFormResult({
					error: e.message || t('openconnector', 'An error occurred while saving the source'),
				})
			}
		},
		async onDelete(id) {
			try {
				await sourceStore.deleteOne({ id })
				this.$refs.indexPage.setSingleDeleteResult({ success: true })
			} catch (e) {
				this.$refs.indexPage.setSingleDeleteResult({
					error: e.message || t('openconnector', 'An error occurred while deleting the source'),
				})
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
			this.selectedSources = ids
		},
		getConfigurationCount(source) {
			const config = source.configuration || {}
			const { authentication, ...configWithoutAuth } = config
			return Object.keys(configWithoutAuth).length
		},
		getAuthenticationCount(source) {
			let count = 0
			if (source.auth) count++
			if (source.username) count++
			if (source.apikey) count++
			if (source.jwt) count++
			if (source.secret) count++
			if (source.authorizationHeader) count++
			if (source.authenticationConfig && source.authenticationConfig.length > 0) {
				count += source.authenticationConfig.length
			}
			return count
		},
		addSourceConfiguration(source) {
			sourceStore.setItem(source)
			sourceStore.setSourceConfigurationKey(null)
			navigationStore.setModal('editSourceConfiguration')
		},
		addSourceAuthentication(source) {
			sourceStore.setItem(source)
			sourceStore.setSourceConfigurationKey(null)
			navigationStore.setModal('editSourceConfigurationAuthentication')
		},
		hasAuthenticationData(source) {
			return !!(source.auth || source.username || source.apikey || source.jwt
				|| source.secret || source.authorizationHeader
				|| (source.authenticationConfig && source.authenticationConfig.length > 0))
		},
		getSourceViewState(source) {
			if (!this.sourceViewStates[source.id]) {
				this.$set(this.sourceViewStates, source.id, {
					showConfigurations: false,
					showAuthentication: false,
				})
			}
			return this.sourceViewStates[source.id]
		},
		showSourceConfigurations(source) {
			const viewState = this.getSourceViewState(source)
			viewState.showConfigurations = true
			viewState.showAuthentication = false
		},
		showSourceAuthentication(source) {
			const viewState = this.getSourceViewState(source)
			viewState.showAuthentication = true
			viewState.showConfigurations = false
		},
		showSourceStats(source) {
			const viewState = this.getSourceViewState(source)
			viewState.showConfigurations = false
			viewState.showAuthentication = false
		},
		editSourceAuthentication(source, field) {
			sourceStore.setItem(source)
			sourceStore.setSourceConfigurationKey(field)
			navigationStore.setModal('editSourceConfigurationAuthentication')
		},
		deleteSourceAuthentication(source, field) {
			sourceStore.setItem(source)
			sourceStore.setSourceConfigurationKey(field)
			navigationStore.setDialog('deleteSourceConfigurationAuthentication')
		},
		editSourceConfiguration(source, key) {
			sourceStore.setItem(source)
			sourceStore.setSourceConfigurationKey(key)
			navigationStore.setModal('editSourceConfiguration')
		},
		deleteSourceConfiguration(source, key) {
			sourceStore.setItem(source)
			sourceStore.setSourceConfigurationKey(key)
			navigationStore.setModal('deleteSourceConfiguration')
		},
	},
}
</script>

<style scoped>
.sourceDetails__value {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.sourceDetails__viewBody {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.sourceDetails__viewContent {
	flex: 1;
}

.sourceDetails__footer {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	gap: 8px;
	margin-top: auto;
	padding-top: 10px;
}
</style>

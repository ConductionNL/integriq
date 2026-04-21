<script setup>
import { translate as t } from '@nextcloud/l10n'
import { endpointStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openconnector', 'Endpoints')"
			:description="t('openconnector', 'Manage your endpoints and their rules')"
			:show-title="true"
			:objects="filteredEndpoints"
			:columns="tableColumns"
			:pagination="paginationData"
			:loading="endpointStore.loading"
			:view-mode="endpointStore.viewMode"
			:selectable="true"
			:selected-ids="selectedEndpoints"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-delete-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			show-view-toggle
			:add-label="t('openconnector', 'Add endpoint')"
			row-key="id"
			:empty-text="emptyContentName"
			@add="addEndpoint"
			@refresh="endpointStore.refreshEndpointList()"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="endpointStore.setViewMode($event)"
			@select="onSelect"
			@row-click="openEndpoint">
			<template #action-items>
				<NcActionButton close-after-click @click="navigationStore.setModal('importFile')">
					<template #icon>
						<FileImportOutline :size="20" />
					</template>
					{{ t('openconnector', 'Import') }}
				</NcActionButton>
			</template>

			<!-- Card view -->
			<template #card="{ object: endpoint }">
				<div class="card">
					<div class="cardHeader">
						<h2 v-tooltip.bottom="endpoint.description">
							<Api :size="20" :fill-color="getEndpointColor(endpoint.method)" />
							{{ endpoint.name }}
						</h2>
						<NcActions :primary="true" :menu-name="t('openconnector', 'Actions')">
							<template #icon>
								<DotsHorizontal :size="20" />
							</template>
							<NcActionButton close-after-click @click="endpointStore.setEndpointItem(endpoint); navigationStore.setModal('viewEndpoint')">
								<template #icon>
									<Eye :size="20" />
								</template>
								{{ t('openconnector', 'View') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="endpointStore.setEndpointItem(endpoint); navigationStore.setModal('editEndpoint')">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('openconnector', 'Edit') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="endpointStore.exportEndpoint(endpoint.id)">
								<template #icon>
									<FileExportOutline :size="20" />
								</template>
								{{ t('openconnector', 'Export endpoint') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="endpointStore.setEndpointItem(endpoint); navigationStore.setModal('addEndpointRule')">
								<template #icon>
									<Plus :size="20" />
								</template>
								{{ t('openconnector', 'Add Rule') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="endpointStore.setEndpointItem(endpoint); navigationStore.setDialog('deleteEndpoint')">
								<template #icon>
									<TrashCanOutline :size="20" />
								</template>
								{{ t('openconnector', 'Delete') }}
							</NcActionButton>
						</NcActions>
					</div>
					<div class="endpointDetails">
						<p v-if="endpoint.description" class="endpointDescription">
							{{ endpoint.description }}
						</p>
						<table class="statisticsTable endpointStats">
							<thead>
								<tr>
									<th>{{ t('openconnector', 'Property') }}</th>
									<th>{{ t('openconnector', 'Value') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td>{{ t('openconnector', 'Method') }}</td>
									<td>
										<span class="methodBadge" :class="`method-${(endpoint.method || 'unknown').toLowerCase()}`">
											{{ endpoint.method || t('openconnector', 'Unknown') }}
										</span>
									</td>
								</tr>
								<tr v-if="endpoint.endpoint">
									<td>{{ t('openconnector', 'Endpoint') }}</td>
									<td class="truncatedUrl">
										{{ endpoint.endpoint }}
									</td>
								</tr>
								<tr v-if="endpoint.version">
									<td>{{ t('openconnector', 'Version') }}</td>
									<td>{{ endpoint.version }}</td>
								</tr>
								<tr v-if="endpoint.targetType">
									<td>{{ t('openconnector', 'Target Type') }}</td>
									<td>{{ endpoint.targetType }}</td>
								</tr>
								<tr v-if="endpoint.targetId">
									<td>{{ t('openconnector', 'Target ID') }}</td>
									<td>{{ endpoint.targetId }}</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Rules') }}</td>
									<td>{{ endpoint.rules?.length || 0 }}</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Created') }}</td>
									<td>
										<NcDateTime v-if="endpoint.created" :timestamp="new Date(endpoint.created)" />
										<span v-else>-</span>
									</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Updated') }}</td>
									<td>
										<NcDateTime v-if="endpoint.updated" :timestamp="new Date(endpoint.updated)" />
										<span v-else>-</span>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</template>

			<!-- Table columns -->
			<template #column-name="{ row }">
				<div class="titleContent">
					<strong>{{ row.name }}</strong>
					<span v-if="row.description" class="textDescription textEllipsis">{{ row.description }}</span>
				</div>
			</template>

			<template #column-method="{ row }">
				<span class="methodBadge" :class="`method-${(row.method || 'unknown').toLowerCase()}`">
					{{ row.method || 'UNKNOWN' }}
				</span>
			</template>

			<template #column-endpoint="{ row }">
				<span v-if="row.endpoint" class="truncatedUrl">{{ row.endpoint }}</span>
				<span v-else>-</span>
			</template>

			<template #column-version="{ row }">
				{{ row.version || '-' }}
			</template>

			<template #column-rules="{ row }">
				{{ row.rules?.length || 0 }}
			</template>

			<template #column-created="{ row }">
				<NcDateTime v-if="row.created" :timestamp="new Date(row.created)" />
				<span v-else>-</span>
			</template>

			<template #column-updated="{ row }">
				<NcDateTime v-if="row.updated" :timestamp="new Date(row.updated)" />
				<span v-else>-</span>
			</template>

			<!-- Row actions (table view) -->
			<template #row-actions="{ row: endpoint }">
				<NcActions :primary="false">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton close-after-click @click="endpointStore.setEndpointItem(endpoint); navigationStore.setModal('viewEndpoint')">
						<template #icon>
							<Eye :size="20" />
						</template>
						{{ t('openconnector', 'View') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="endpointStore.setEndpointItem(endpoint); navigationStore.setModal('editEndpoint')">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('openconnector', 'Edit') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="endpointStore.exportEndpoint(endpoint.id)">
						<template #icon>
							<FileExportOutline :size="20" />
						</template>
						{{ t('openconnector', 'Export endpoint') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="endpointStore.setEndpointItem(endpoint); navigationStore.setModal('addEndpointRule')">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('openconnector', 'Add Rule') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="endpointStore.setEndpointItem(endpoint); navigationStore.setDialog('deleteEndpoint')">
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
import { NcAppContent, NcActions, NcActionButton, NcDateTime } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import Api from 'vue-material-design-icons/Api.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import FileImportOutline from 'vue-material-design-icons/FileImportOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import { getTheme } from '../../services/getTheme.js'

export default {
	name: 'EndpointsIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		NcActions,
		NcActionButton,
		NcDateTime,
		Api,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		FileExportOutline,
		FileImportOutline,
		Plus,
		Eye,
	},
	data() {
		return {
			selectedEndpoints: [],
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
		navigationStore() {
			return navigationStore
		},
		filteredEndpoints() {
			return endpointStore.endpointList || []
		},
		tableColumns() {
			return [
				{ key: 'name', label: t('openconnector', 'Name'), sortable: true },
				{ key: 'method', label: t('openconnector', 'Method') },
				{ key: 'endpoint', label: t('openconnector', 'Endpoint') },
				{ key: 'version', label: t('openconnector', 'Version') },
				{ key: 'rules', label: t('openconnector', 'Rules') },
				{ key: 'created', label: t('openconnector', 'Created'), sortable: true },
				{ key: 'updated', label: t('openconnector', 'Updated'), sortable: true },
			]
		},
		paginationData() {
			const page = this.pagination.page || 1
			const limit = this.pagination.limit || 20
			const total = this.filteredEndpoints.length
			const pages = Math.max(1, Math.ceil(total / limit))
			return { page, pages, total, limit }
		},
		emptyContentName() {
			if (endpointStore.error) return endpointStore.error
			if (!endpointStore.endpointList?.length) return t('openconnector', 'No endpoints found')
			return t('openconnector', 'Loading endpoints...')
		},
	},
	mounted() {
		endpointStore.refreshEndpointList()
	},
	methods: {
		addEndpoint() {
			endpointStore.setEndpointItem(null)
			navigationStore.setModal('editEndpoint')
		},
		openEndpoint(endpoint) {
			if (endpoint?.id == null) return
			this.$router.push('/endpoints/' + endpoint.id)
		},
		onPageChanged(page) {
			this.pagination.page = page
		},
		onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
		},
		onSelect(ids) {
			this.selectedEndpoints = ids
		},
		getEndpointColor(method) {
			const theme = getTheme()

			if (theme === 'dark') {
				switch (method) {
				case 'GET': return '#5c8d4a'
				case 'POST': return '#5d82c0'
				case 'PUT': return '#a46f96'
				case 'PATCH': return '#bc6d3d'
				case 'DELETE': return '#d25c53'
				default: return '#fff'
				}
			} else {
				switch (method) {
				case 'GET': return '#4e7f3d'
				case 'POST': return '#466eaa'
				case 'PUT': return '#87547a'
				case 'PATCH': return '#a95d2e'
				case 'DELETE': return '#b13f3a'
				default: return '#000'
				}
			}
		},
	},
}
</script>

<style scoped>
.methodBadge {
	display: inline-flex;
	padding: 2px 6px;
	border-radius: 8px;
	font-size: 0.7rem;
	font-weight: 600;
	color: white;
}
.methodBadge.method-get { background: var(--color-success); }
.methodBadge.method-post { background: var(--color-info); }
.methodBadge.method-put,
.methodBadge.method-patch { background: var(--color-warning); }
.methodBadge.method-delete { background: var(--color-error); }
.methodBadge.method-unknown { background: var(--color-text-maxcontrast); }

.truncatedUrl {
	max-width: 300px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	display: inline-block;
}
</style>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { mappingStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openconnector', 'Mappings')"
			:description="t('openconnector', 'Manage your data mappings')"
			:show-title="true"
			:objects="mappingStore.list"
			:exclude-columns="['mapping', 'cast', 'unset']"
			:pagination="paginationData"
			:loading="mappingStore.loading"
			:view-mode="mappingStore.viewMode"
			:selectable="true"
			:selected-ids="selectedMappings"
			:show-copy-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			show-view-toggle
			:add-label="t('openconnector', 'Add mapping')"
			row-key="id"
			:empty-text="emptyContentName"
			name-field="name"
			@add="onAdd"
			@delete="onDelete"
			@refresh="mappingStore.refreshList()"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="mappingStore.setViewMode($event)"
			@select="onSelect"
			@row-click="openMapping">
			<!-- Card view -->
			<template #card="{ object: mapping }">
				<div class="card">
					<div class="cardHeader">
						<h2 v-tooltip.bottom="mapping.description">
							<SitemapOutline :size="20" />
							{{ mapping.name }}
						</h2>
						<NcActions :primary="true" :menu-name="t('openconnector', 'Actions')">
							<template #icon>
								<DotsHorizontal :size="20" />
							</template>
							<NcActionButton close-after-click @click="openMapping(mapping)">
								<template #icon>
									<Eye :size="20" />
								</template>
								{{ t('openconnector', 'View') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="editMapping(mapping)">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('openconnector', 'Edit') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="$refs.indexPage.openDeleteDialog(mapping)">
								<template #icon>
									<TrashCanOutline :size="20" />
								</template>
								{{ t('openconnector', 'Delete') }}
							</NcActionButton>
						</NcActions>
					</div>
					<div class="mappingDetails">
						<p v-if="mapping.description" class="mappingDescription">
							{{ mapping.description }}
						</p>
						<table class="statisticsTable mappingStats">
							<thead>
								<tr>
									<th>{{ t('openconnector', 'Property') }}</th>
									<th>{{ t('openconnector', 'Value') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td>{{ t('openconnector', 'Mappings') }}</td>
									<td>{{ Object.keys(mapping.mapping || {}).length }}</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Casts') }}</td>
									<td>{{ Object.keys(mapping.cast || {}).length }}</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Unsets') }}</td>
									<td>{{ (mapping.unset || []).length }}</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Pass through') }}</td>
									<td>{{ mapping.passThrough ? t('openconnector', 'Yes') : t('openconnector', 'No') }}</td>
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

			<template #column-description="{ row }">
				<span v-if="row.description" class="textEllipsis">{{ row.description }}</span>
				<span v-else>-</span>
			</template>

			<template #column-passThrough="{ row }">
				{{ row.passThrough ? t('openconnector', 'Yes') : t('openconnector', 'No') }}
			</template>

			<template #column-reference="{ row }">
				{{ row.reference || '-' }}
			</template>

			<template #column-version="{ row }">
				{{ row.version || '-' }}
			</template>

			<!-- Row actions (table view) -->
			<template #row-actions="{ row: mapping }">
				<NcActions :primary="false">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton close-after-click @click="openMapping(mapping)">
						<template #icon>
							<Eye :size="20" />
						</template>
						{{ t('openconnector', 'View') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="editMapping(mapping)">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('openconnector', 'Edit') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="$refs.indexPage.openDeleteDialog(mapping)">
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
import { NcAppContent, NcActions, NcActionButton } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Eye from 'vue-material-design-icons/Eye.vue'

export default {
	name: 'MappingsIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		NcActions,
		NcActionButton,
		SitemapOutline,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Eye,
	},
	data() {
		return {
			selectedMappings: [],
			pagination: {
				page: 1,
				limit: 20,
			},
		}
	},
	computed: {
		paginationData() {
			const page = this.pagination.page || 1
			const limit = this.pagination.limit || 20
			const total = mappingStore.list?.length || 0
			const pages = Math.max(1, Math.ceil(total / limit))
			return { page, pages, total, limit }
		},
		emptyContentName() {
			if (mappingStore.error) return mappingStore.error
			if (!mappingStore.list?.length) return this.t('openconnector', 'No mappings found')
			return this.t('openconnector', 'Loading mappings...')
		},
	},
	mounted() {
		mappingStore.refreshList()
	},
	methods: {
		onAdd() {
			mappingStore.setItem(null)
			navigationStore.setModal('editMapping')
		},
		editMapping(mapping) {
			mappingStore.setItem(mapping)
			navigationStore.setModal('editMapping')
		},
		openMapping(mapping) {
			if (mapping?.id == null) return
			this.$router.push('/mappings/' + mapping.id)
		},
		async onDelete(id) {
			try {
				await mappingStore.deleteOne({ id })
				this.$refs.indexPage.setSingleDeleteResult({ success: true })
			} catch (e) {
				this.$refs.indexPage.setSingleDeleteResult({
					error: e.message || this.t('openconnector', 'An error occurred while deleting the mapping'),
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
			this.selectedMappings = ids
		},
	},
}
</script>

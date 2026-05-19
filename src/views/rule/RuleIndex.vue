<script setup>
import { translate as t } from '@nextcloud/l10n'
import { ruleStore, navigationStore } from '../../store/store.js'
import { ruleSchema, ruleTypeLabel } from './ruleSchema.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openconnector', 'Rules')"
			:description="t('openconnector', 'Manage your rules and their conditions')"
			:show-title="true"
			:objects="filteredRules"
			:schema="schema"
			:exclude-columns="['description', 'conditions', 'configuration', 'slug', 'version']"
			:pagination="paginationData"
			:loading="loading"
			:refreshing="loading"
			:view-mode="viewMode"
			:selectable="true"
			:selected-ids="selectedRules"
			:show-copy-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			show-view-toggle
			:add-label="t('openconnector', 'Add rule')"
			row-key="id"
			:empty-text="emptyContentName"
			name-field="name"
			@add="onAdd"
			@delete="onDelete"
			@refresh="refreshList"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="viewMode = $event"
			@select="onSelect"
			@row-click="openRule">
			<template #action-items>
				<NcActionButton close-after-click @click="navigationStore.setModal('importFile')">
					<template #icon>
						<FileImportOutline :size="20" />
					</template>
					{{ t('openconnector', 'Import') }}
				</NcActionButton>
			</template>

			<!-- Card view -->
			<template #card="{ object: rule }">
				<div class="card">
					<div class="cardHeader">
						<h2 v-tooltip.bottom="rule.description">
							<SitemapOutline :size="20" />
							{{ rule.name }}
						</h2>
						<NcActions :primary="true" :menu-name="t('openconnector', 'Actions')">
							<template #icon>
								<DotsHorizontal :size="20" />
							</template>
							<NcActionButton close-after-click @click="openRule(rule)">
								<template #icon>
									<Eye :size="20" />
								</template>
								{{ t('openconnector', 'View') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="editRule(rule)">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('openconnector', 'Edit') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="ruleStore.exportRule(rule.id)">
								<template #icon>
									<FileExportOutline :size="20" />
								</template>
								{{ t('openconnector', 'Export rule') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="$refs.indexPage.openDeleteDialog(rule)">
								<template #icon>
									<TrashCanOutline :size="20" />
								</template>
								{{ t('openconnector', 'Delete') }}
							</NcActionButton>
						</NcActions>
					</div>
					<div class="ruleDetails">
						<p v-if="rule.description" class="ruleDescription">
							{{ rule.description }}
						</p>
						<table class="statisticsTable ruleStats">
							<thead>
								<tr>
									<th>{{ t('openconnector', 'Property') }}</th>
									<th>{{ t('openconnector', 'Value') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td>{{ t('openconnector', 'Type') }}</td>
									<td>{{ formatType(rule.type) }}</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Action') }}</td>
									<td>{{ rule.action || '-' }}</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Timing') }}</td>
									<td>{{ rule.timing || '-' }}</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Order') }}</td>
									<td>{{ rule.order ?? 0 }}</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Created') }}</td>
									<td>
										<NcDateTime v-if="rule.created" :timestamp="new Date(rule.created)" />
										<span v-else>-</span>
									</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Updated') }}</td>
									<td>
										<NcDateTime v-if="rule.updated" :timestamp="new Date(rule.updated)" />
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

			<template #column-type="{ row }">
				{{ formatType(row.type) }}
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
			<template #row-actions="{ object: rule }">
				<NcActions :primary="false">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton close-after-click @click="openRule(rule)">
						<template #icon>
							<Eye :size="20" />
						</template>
						{{ t('openconnector', 'View') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="editRule(rule)">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('openconnector', 'Edit') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="ruleStore.exportRule(rule.id)">
						<template #icon>
							<FileExportOutline :size="20" />
						</template>
						{{ t('openconnector', 'Export rule') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="$refs.indexPage.openDeleteDialog(rule)">
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
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import FileImportOutline from 'vue-material-design-icons/FileImportOutline.vue'
import Eye from 'vue-material-design-icons/Eye.vue'

export default {
	name: 'RuleIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		NcActions,
		NcActionButton,
		NcDateTime,
		SitemapOutline,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		FileExportOutline,
		FileImportOutline,
		Eye,
	},
	data() {
		return {
			selectedRules: [],
			pagination: {
				page: 1,
				limit: 20,
			},
			loading: false,
			viewMode: 'cards',
		}
	},
	computed: {
		ruleStore() {
			return ruleStore
		},
		navigationStore() {
			return navigationStore
		},
		schema() {
			return ruleSchema()
		},
		filteredRules() {
			return ruleStore.list || []
		},
		paginationData() {
			const page = this.pagination.page || 1
			const limit = this.pagination.limit || 20
			const total = this.filteredRules.length
			const pages = Math.max(1, Math.ceil(total / limit))
			return { page, pages, total, limit }
		},
		emptyContentName() {
			if (!this.filteredRules.length) return t('openconnector', 'No rules found')
			return t('openconnector', 'Loading rules...')
		},
	},
	mounted() {
		this.refreshList()
	},
	methods: {
		async refreshList() {
			this.loading = true
			try {
				await ruleStore.refreshList()
			} finally {
				this.loading = false
			}
		},
		onAdd() {
			ruleStore.setItem(null)
			navigationStore.setModal('editRule')
		},
		editRule(rule) {
			ruleStore.setItem(rule)
			navigationStore.setModal('editRule')
		},
		openRule(rule) {
			if (rule?.id == null) return
			this.$router.push('/rules/' + rule.id)
		},
		async onDelete(id) {
			try {
				await ruleStore.deleteOne(id)
				this.$refs.indexPage.setSingleDeleteResult({ success: true })
			} catch (e) {
				this.$refs.indexPage.setSingleDeleteResult({
					error: e.message || t('openconnector', 'An error occurred while deleting the rule'),
				})
			}
		},
		formatType(type) {
			return type ? ruleTypeLabel(type) : '-'
		},
		onPageChanged(page) {
			this.pagination.page = page
		},
		onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
		},
		onSelect(ids) {
			this.selectedRules = ids
		},
	},
}
</script>

<style scoped>
.titleContent {
	display: flex;
	flex-direction: column;
}

.textDescription {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.textEllipsis {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	max-width: 400px;
}
</style>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { jobStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openconnector', 'Jobs')"
			:description="t('openconnector', 'Manage your background jobs and scheduled tasks')"
			:show-title="true"
			:objects="filteredJobs"
			:columns="tableColumns"
			:pagination="paginationData"
			:loading="loading"
			:refreshing="refreshing"
			:view-mode="jobStore.viewMode"
			:selectable="true"
			:selected-ids="selectedJobs"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-delete-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			show-view-toggle
			:add-label="t('openconnector', 'Add job')"
			row-key="id"
			:empty-text="emptyContentName"
			name-field="name"
			@add="onAdd"
			@refresh="onRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="jobStore.setViewMode($event)"
			@select="onSelect">
			<!-- Action bar extras: Import button -->
			<template #action-items>
				<NcActionButton
					close-after-click
					@click="navigationStore.setModal('importFile')">
					<template #icon>
						<FileImportOutline :size="20" />
					</template>
					{{ t('openconnector', 'Import') }}
				</NcActionButton>
			</template>

			<!-- Card view -->
			<template #card="{ object: job }">
				<div class="card">
					<div class="cardHeader">
						<h2 v-tooltip.bottom="job.description">
							<Update :size="20" />
							{{ job.name }}
						</h2>
						<NcActions :primary="true" :menu-name="t('openconnector', 'Actions')">
							<template #icon>
								<DotsHorizontal :size="20" />
							</template>
							<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setModal('viewJob')">
								<template #icon>
									<Eye :size="20" />
								</template>
								{{ t('openconnector', 'View details') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setModal('editJob')">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('openconnector', 'Edit') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setModal('testJob')">
								<template #icon>
									<Sync :size="20" />
								</template>
								{{ t('openconnector', 'Test') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setModal('runJob')">
								<template #icon>
									<Play :size="20" />
								</template>
								{{ t('openconnector', 'Run') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="viewJobLogs(job)">
								<template #icon>
									<TextBoxOutline :size="20" />
								</template>
								{{ t('openconnector', 'View logs') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="addJobArgument(job)">
								<template #icon>
									<Plus :size="20" />
								</template>
								{{ t('openconnector', 'Add argument') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="jobStore.exportJob(job.id)">
								<template #icon>
									<FileExportOutline :size="20" />
								</template>
								{{ t('openconnector', 'Export') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setDialog('deleteJob')">
								<template #icon>
									<TrashCanOutline :size="20" />
								</template>
								{{ t('openconnector', 'Delete') }}
							</NcActionButton>
						</NcActions>
					</div>
					<div class="jobDetails">
						<p v-if="job.description" class="jobDescription">
							{{ job.description }}
						</p>
						<table class="statisticsTable jobStats">
							<thead>
								<tr>
									<th>{{ t('openconnector', 'Property') }}</th>
									<th>{{ t('openconnector', 'Value') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td>{{ t('openconnector', 'Status') }}</td>
									<td>
										<span :class="job.isEnabled ? 'status-enabled' : 'status-disabled'">
											{{ job.isEnabled ? t('openconnector', 'Enabled') : t('openconnector', 'Disabled') }}
										</span>
									</td>
								</tr>
								<tr v-if="job.jobClass">
									<td>{{ t('openconnector', 'Job class') }}</td>
									<td class="truncatedText">
										{{ job.jobClass }}
									</td>
								</tr>
								<tr v-if="job.interval">
									<td>{{ t('openconnector', 'Interval') }}</td>
									<td>{{ job.interval }}</td>
								</tr>
								<tr v-if="job.executionTime">
									<td>{{ t('openconnector', 'Execution time') }}</td>
									<td>{{ job.executionTime }}</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Arguments') }}</td>
									<td>{{ getArgumentCount(job) }}</td>
								</tr>
								<tr v-if="job.nextRun">
									<td>{{ t('openconnector', 'Next run') }}</td>
									<td><NcDateTime :timestamp="new Date(job.nextRun)" /></td>
								</tr>
								<tr v-if="job.lastRun">
									<td>{{ t('openconnector', 'Last run') }}</td>
									<td><NcDateTime :timestamp="new Date(job.lastRun)" /></td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Version') }}</td>
									<td>{{ job.version || '-' }}</td>
								</tr>
							</tbody>
						</table>
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

			<template #column-status="{ row }">
				<span :class="row.isEnabled ? 'status-enabled' : 'status-disabled'">
					{{ row.isEnabled ? t('openconnector', 'Enabled') : t('openconnector', 'Disabled') }}
				</span>
			</template>

			<template #column-jobClass="{ row }">
				<span v-if="row.jobClass" class="truncatedText">{{ row.jobClass }}</span>
				<span v-else>-</span>
			</template>

			<template #column-interval="{ row }">
				{{ row.interval || '-' }}
			</template>

			<template #column-arguments="{ row }">
				{{ getArgumentCount(row) }}
			</template>

			<template #column-nextRun="{ row }">
				<NcDateTime v-if="row.nextRun" :timestamp="new Date(row.nextRun)" />
				<span v-else>-</span>
			</template>

			<template #column-lastRun="{ row }">
				<NcDateTime v-if="row.lastRun" :timestamp="new Date(row.lastRun)" />
				<span v-else>-</span>
			</template>

			<!-- Row actions (table view) -->
			<template #row-actions="{ row: job }">
				<NcActions :primary="false">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setModal('viewJob')">
						<template #icon>
							<Eye :size="20" />
						</template>
						{{ t('openconnector', 'View details') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setModal('editJob')">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('openconnector', 'Edit') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setModal('testJob')">
						<template #icon>
							<Sync :size="20" />
						</template>
						{{ t('openconnector', 'Test') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setModal('runJob')">
						<template #icon>
							<Play :size="20" />
						</template>
						{{ t('openconnector', 'Run') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="viewJobLogs(job)">
						<template #icon>
							<TextBoxOutline :size="20" />
						</template>
						{{ t('openconnector', 'View logs') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="addJobArgument(job)">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('openconnector', 'Add argument') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="jobStore.exportJob(job.id)">
						<template #icon>
							<FileExportOutline :size="20" />
						</template>
						{{ t('openconnector', 'Export') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setDialog('deleteJob')">
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
import Update from 'vue-material-design-icons/Update.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import Play from 'vue-material-design-icons/Play.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import FileImportOutline from 'vue-material-design-icons/FileImportOutline.vue'

export default {
	name: 'JobsIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		NcActions,
		NcActionButton,
		NcDateTime,
		Update,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Plus,
		Eye,
		Sync,
		Play,
		TextBoxOutline,
		FileExportOutline,
		FileImportOutline,
	},
	data() {
		return {
			selectedJobs: [],
			loading: false,
			refreshing: false,
			loadError: null,
			pagination: {
				page: 1,
				limit: 20,
			},
		}
	},
	computed: {
		filteredJobs() {
			return jobStore.jobList || []
		},
		tableColumns() {
			return [
				{ key: 'name', label: t('openconnector', 'Name'), sortable: true },
				{ key: 'status', label: t('openconnector', 'Status') },
				{ key: 'jobClass', label: t('openconnector', 'Job class') },
				{ key: 'interval', label: t('openconnector', 'Interval') },
				{ key: 'arguments', label: t('openconnector', 'Arguments') },
				{ key: 'nextRun', label: t('openconnector', 'Next run'), sortable: true },
				{ key: 'lastRun', label: t('openconnector', 'Last run'), sortable: true },
			]
		},
		paginationData() {
			const page = this.pagination.page || 1
			const limit = this.pagination.limit || 20
			const total = this.filteredJobs.length
			const pages = Math.max(1, Math.ceil(total / limit))
			return { page, pages, total, limit }
		},
		emptyContentName() {
			if (this.loadError) return this.loadError
			if (this.loading) return t('openconnector', 'Loading jobs...')
			if (!jobStore.jobList?.length) return t('openconnector', 'No jobs found')
			return ''
		},
	},
	async mounted() {
		this.loading = true
		try {
			await jobStore.refreshJobList()
		} catch (e) {
			this.loadError = e.message || t('openconnector', 'Failed to load jobs')
		} finally {
			this.loading = false
		}
	},
	methods: {
		onAdd() {
			jobStore.setJobItem({})
			navigationStore.setModal('editJob')
		},
		async onRefresh() {
			this.refreshing = true
			this.loadError = null
			try {
				await jobStore.refreshJobList()
			} catch (e) {
				this.loadError = e.message || t('openconnector', 'Failed to load jobs')
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
			this.selectedJobs = ids
		},
		getArgumentCount(job) {
			const args = job.arguments || {}
			return Object.keys(args).length
		},
		addJobArgument(job) {
			jobStore.setJobItem(job)
			jobStore.setJobArgumentKey(null)
			navigationStore.setModal('editJobArgument')
		},
		viewJobLogs(job) {
			jobStore.setJobItem(job)
			jobStore.refreshJobLogs(job.id)
			this.$router.push('/jobs/logs')
		},
	},
}
</script>

<style scoped>
.status-enabled {
	display: inline-flex;
	align-items: center;
	padding: 4px 12px;
	border-radius: 12px;
	font-size: 0.875rem;
	font-weight: 600;
	color: white;
	background: var(--color-success);
}

.status-disabled {
	display: inline-flex;
	align-items: center;
	padding: 4px 12px;
	border-radius: 12px;
	font-size: 0.875rem;
	font-weight: 600;
	color: white;
	background: var(--color-error);
}
</style>

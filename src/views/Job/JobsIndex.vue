<script setup>
import { jobStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ t('openconnector', 'Jobs') }}
				</h1>
				<p>{{ t('openconnector', 'Manage your background jobs and scheduled tasks') }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span class="viewTotalCount">
						{{ t('openconnector', 'Showing {showing} of {total} jobs', { showing: paginatedJobs.length, total: filteredJobs.length }) }}
					</span>
					<span v-if="selectedJobs.length > 0" class="viewIndicator">
						({{ t('openconnector', '{count} selected', { count: selectedJobs.length }) }})
					</span>
				</div>
				<div class="viewActions">
					<div class="viewModeSwitchContainer">
						<NcCheckboxRadioSwitch
							v-tooltip="'See jobs as cards'"
							:checked="currentViewMode === 'cards'"
							:button-variant="true"
							value="cards"
							name="view_mode_radio"
							type="radio"
							button-variant-grouped="horizontal"
							@update:checked="checked => checked && setViewMode('cards')">
							Cards
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-tooltip="'See jobs as a table'"
							:checked="currentViewMode === 'table'"
							:button-variant="true"
							value="table"
							name="view_mode_radio"
							type="radio"
							button-variant-grouped="horizontal"
							@update:checked="checked => checked && setViewMode('table')">
							Table
						</NcCheckboxRadioSwitch>
					</div>

					<NcActions
						:force-name="true"
						:inline="4"
						menu-name="Actions">
						<NcActionButton
							:primary="true"
							close-after-click
							@click="jobStore.setJobItem({}); navigationStore.setModal('editJob')">
							<template #icon>
								<Plus :size="20" />
							</template>
							Add Job
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="jobStore.refreshJobList()">
							<template #icon>
								<Refresh :size="20" />
							</template>
							Refresh
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="navigationStore.setModal('importFile')">
							<template #icon>
								<FileImportOutline :size="20" />
							</template>
							Import
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Loading, Error, and Empty States -->
			<NcEmptyContent v-if="jobStore.loading || jobStore.error || !filteredJobs.length"
				:name="emptyContentName"
				:description="emptyContentDescription">
				<template #icon>
					<NcLoadingIcon v-if="jobStore.loading" :size="64" />
					<Update v-else :size="64" />
				</template>
				<template v-if="!jobStore.loading && !jobStore.error && !jobStore.jobList.length" #action>
					<NcButton type="primary" @click="jobStore.setJobItem({}); navigationStore.setModal('editJob')">
						{{ t('openconnector', 'Add job') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<!-- Content -->
			<div v-else>
				<template v-if="currentViewMode === 'cards'">
					<div class="cardGrid">
						<div v-for="job in paginatedJobs" :key="job.id" class="card">
							<div class="cardHeader">
								<h2 v-tooltip.bottom="job.description">
									<Update :size="20" />
									{{ job.name }}
								</h2>
								<NcActions :primary="true" menu-name="Actions">
									<template #icon>
										<DotsHorizontal :size="20" />
									</template>
									<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setModal('viewJob')">
										<template #icon>
											<Eye :size="20" />
										</template>
										View Details
									</NcActionButton>
									<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setModal('editJob')">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
									<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setModal('testJob')">
										<template #icon>
											<Sync :size="20" />
										</template>
										Test
									</NcActionButton>
									<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setModal('runJob')">
										<template #icon>
											<Play :size="20" />
										</template>
										Run
									</NcActionButton>
									<NcActionButton close-after-click @click="viewJobLogs(job)">
										<template #icon>
											<TextBoxOutline :size="20" />
										</template>
										View Logs
									</NcActionButton>
									<NcActionButton close-after-click @click="addJobArgument(job)">
										<template #icon>
											<Plus :size="20" />
										</template>
										Add Argument
									</NcActionButton>
									<NcActionButton close-after-click @click="jobStore.exportJob(job.id)">
										<template #icon>
											<FileExportOutline :size="20" />
										</template>
										Export
									</NcActionButton>
									<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setDialog('deleteJob')">
										<template #icon>
											<TrashCanOutline :size="20" />
										</template>
										Delete
									</NcActionButton>
								</NcActions>
							</div>
							<!-- Job Details -->
							<div class="jobDetails">
								<p v-if="job.description" class="jobDescription">
									{{ job.description }}
								</p>
								<!-- Job Statistics Table -->
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
													{{ job.isEnabled ? 'Enabled' : 'Disabled' }}
												</span>
											</td>
										</tr>
										<tr v-if="job.jobClass">
											<td>{{ t('openconnector', 'Job Class') }}</td>
											<td class="truncatedText">
												{{ job.jobClass }}
											</td>
										</tr>
										<tr v-if="job.interval">
											<td>{{ t('openconnector', 'Interval') }}</td>
											<td>{{ job.interval }}</td>
										</tr>
										<tr v-if="job.executionTime">
											<td>{{ t('openconnector', 'Execution Time') }}</td>
											<td>{{ job.executionTime }}</td>
										</tr>
										<tr>
											<td>{{ t('openconnector', 'Arguments') }}</td>
											<td>{{ getArgumentCount(job) }}</td>
										</tr>
										<tr v-if="job.nextRun">
											<td>{{ t('openconnector', 'Next Run') }}</td>
											<td>{{ new Date(job.nextRun).toLocaleDateString() + ', ' + new Date(job.nextRun).toLocaleTimeString() }}</td>
										</tr>
										<tr v-if="job.lastRun">
											<td>{{ t('openconnector', 'Last Run') }}</td>
											<td>{{ new Date(job.lastRun).toLocaleDateString() + ', ' + new Date(job.lastRun).toLocaleTimeString() }}</td>
										</tr>
										<tr>
											<td>{{ t('openconnector', 'Version') }}</td>
											<td>{{ job.version || '-' }}</td>
										</tr>
									</tbody>
								</table>
							</div>
						</div>
					</div>
				</template>
				<template v-else>
					<div class="viewTableContainer">
						<table class="viewTable">
							<thead>
								<tr>
									<th class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="allSelected"
											:indeterminate="someSelected"
											@update:checked="toggleSelectAll" />
									</th>
									<th>{{ t('openconnector', 'Name') }}</th>
									<th>{{ t('openconnector', 'Status') }}</th>
									<th>{{ t('openconnector', 'Job Class') }}</th>
									<th>{{ t('openconnector', 'Interval') }}</th>
									<th>{{ t('openconnector', 'Arguments') }}</th>
									<th>{{ t('openconnector', 'Next Run') }}</th>
									<th>{{ t('openconnector', 'Last Run') }}</th>
									<th class="tableColumnActions">
										{{ t('openconnector', 'Actions') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="job in paginatedJobs"
									:key="job.id"
									class="viewTableRow"
									:class="{ viewTableRowSelected: selectedJobs.includes(job.id) }">
									<td class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="selectedJobs.includes(job.id)"
											@update:checked="(checked) => toggleJobSelection(job.id, checked)" />
									</td>
									<td class="tableColumnTitle">
										<div class="titleContent">
											<strong>{{ job.name }}</strong>
											<span v-if="job.description" class="textDescription textEllipsis">{{ job.description }}</span>
										</div>
									</td>
									<td>
										<span :class="job.isEnabled ? 'status-enabled' : 'status-disabled'">
											{{ job.isEnabled ? 'Enabled' : 'Disabled' }}
										</span>
									</td>
									<td class="tableColumnConstrained">
										<span v-if="job.jobClass" class="truncatedText">{{ job.jobClass }}</span>
										<span v-else>-</span>
									</td>
									<td>{{ job.interval || '-' }}</td>
									<td>{{ getArgumentCount(job) }}</td>
									<td>{{ job.nextRun ? new Date(job.nextRun).toLocaleDateString() + ', ' + new Date(job.nextRun).toLocaleTimeString() : '-' }}</td>
									<td>{{ job.lastRun ? new Date(job.lastRun).toLocaleDateString() + ', ' + new Date(job.lastRun).toLocaleTimeString() : '-' }}</td>
									<td class="tableColumnActions">
										<NcActions :primary="false">
											<template #icon>
												<DotsHorizontal :size="20" />
											</template>
											<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setModal('viewJob')">
												<template #icon>
													<Eye :size="20" />
												</template>
												View Details
											</NcActionButton>
											<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setModal('editJob')">
												<template #icon>
													<Pencil :size="20" />
												</template>
												Edit
											</NcActionButton>
											<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setModal('testJob')">
												<template #icon>
													<Sync :size="20" />
												</template>
												Test
											</NcActionButton>
											<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setModal('runJob')">
												<template #icon>
													<Play :size="20" />
												</template>
												Run
											</NcActionButton>
											<NcActionButton close-after-click @click="viewJobLogs(job)">
												<template #icon>
													<TextBoxOutline :size="20" />
												</template>
												View Logs
											</NcActionButton>
											<NcActionButton close-after-click @click="addJobArgument(job)">
												<template #icon>
													<Plus :size="20" />
												</template>
												Add Argument
											</NcActionButton>
											<NcActionButton close-after-click @click="jobStore.exportJob(job.id)">
												<template #icon>
													<FileExportOutline :size="20" />
												</template>
												Export
											</NcActionButton>
											<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setDialog('deleteJob')">
												<template #icon>
													<TrashCanOutline :size="20" />
												</template>
												Delete
											</NcActionButton>
										</NcActions>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</template>
			</div>

			<!-- Pagination -->
			<PaginationComponent
				v-if="filteredJobs.length > 0"
				:current-page="pagination.page || 1"
				:total-pages="Math.ceil(filteredJobs.length / (pagination.limit || 20))"
				:total-items="filteredJobs.length"
				:current-page-size="pagination.limit || 20"
				:min-items-to-show="0"
				@page-changed="onPageChanged"
				@page-size-changed="onPageSizeChanged" />
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcActions, NcActionButton, NcCheckboxRadioSwitch, NcButton } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import Update from 'vue-material-design-icons/Update.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import Play from 'vue-material-design-icons/Play.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import FileImportOutline from 'vue-material-design-icons/FileImportOutline.vue'

import PaginationComponent from '../../components/PaginationComponent.vue'

export default {
	name: 'JobsIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcCheckboxRadioSwitch,
		NcButton,
		Update,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Refresh,
		Plus,
		Eye,
		Sync,
		Play,
		TextBoxOutline,
		FileExportOutline,
		FileImportOutline,
		PaginationComponent,
	},
	data() {
		return {
			jobStore,
			navigationStore,
			selectedJobs: [],
			pagination: {
				page: 1,
				limit: 20,
			},
		}
	},
	computed: {
		currentViewMode() {
			return this.jobStore.viewMode
		},
		filteredJobs() {
			if (!this.jobStore.jobList) return []
			return this.jobStore.jobList
		},
		paginatedJobs() {
			const start = ((this.pagination.page || 1) - 1) * (this.pagination.limit || 20)
			const end = start + (this.pagination.limit || 20)
			return this.filteredJobs.slice(start, end)
		},
		allSelected() {
			return this.filteredJobs.length > 0 && this.filteredJobs.every(job => this.selectedJobs.includes(job.id))
		},
		someSelected() {
			return this.selectedJobs.length > 0 && !this.allSelected
		},
		emptyContentName() {
			if (this.jobStore.loading) {
				return t('openconnector', 'Loading jobs...')
			} else if (this.jobStore.error) {
				return this.jobStore.error
			} else if (!this.jobStore.jobList?.length) {
				return t('openconnector', 'No jobs found')
			}
			return ''
		},
		emptyContentDescription() {
			if (this.jobStore.loading) {
				return t('openconnector', 'Please wait while we fetch your jobs.')
			} else if (this.jobStore.error) {
				return t('openconnector', 'Please try again later.')
			} else if (!this.jobStore.jobList?.length) {
				return t('openconnector', 'No jobs are available.')
			}
			return ''
		},
	},
	mounted() {
		this.jobStore.refreshJobList()
	},
	methods: {
		setViewMode(mode) {
			if (mode === 'cards' || mode === 'table') {
				this.jobStore.setViewMode(mode)
			}
		},
		toggleSelectAll(checked) {
			if (checked) {
				this.selectedJobs = this.filteredJobs.map(job => job.id)
			} else {
				this.selectedJobs = []
			}
		},
		toggleJobSelection(jobId, checked) {
			if (checked) {
				this.selectedJobs.push(jobId)
			} else {
				this.selectedJobs = this.selectedJobs.filter(id => id !== jobId)
			}
		},
		onPageChanged(page) {
			this.pagination.page = page
		},
		onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
		},
		getArgumentCount(job) {
			const args = job.arguments || {}
			return Object.keys(args).length
		},
		addJobArgument(job) {
			this.jobStore.setJobItem(job)
			this.jobStore.setJobArgumentKey(null)
			this.navigationStore.setModal('editJobArgument')
		},
		/**
		 * Navigate to the job logs view for a specific job
		 * @param {object} job - The job to view logs for
		 */
		viewJobLogs(job) {
			// Set the selected job item to filter logs by this job
			this.jobStore.setJobItem(job)
			// Refresh the logs for this specific job
			this.jobStore.refreshJobLogs(job.id)
			// Navigate to the job logs view
			this.$router.push('/jobs/logs')
		},
	},
}
</script>

<style scoped>
/* Status badge styling */
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

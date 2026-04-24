<script setup>
import { translate as t } from '@nextcloud/l10n'
import { jobStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'viewJob'"
		ref="modalRef"
		:name="jobStore.jobItem?.name || t('openconnector', 'Job Details')"
		@close="navigationStore.setModal(false)">
		<div class="modal-content">
			<p v-if="jobStore.jobItem?.description" class="job-description">
				{{ jobStore.jobItem.description }}
			</p>

			<!-- Job Properties -->
			<div class="job-properties">
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
							<td>{{ jobStore.jobItem?.status || 'Unknown' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Enabled') }}</td>
							<td>{{ jobStore.jobItem?.isEnabled ? 'Enabled' : 'Disabled' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Version') }}</td>
							<td>{{ jobStore.jobItem?.version || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Job Class') }}</td>
							<td>{{ jobStore.jobItem?.jobClass || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Interval') }}</td>
							<td>{{ jobStore.jobItem?.interval || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Execution Time') }}</td>
							<td>{{ jobStore.jobItem?.executionTime || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Time Sensitive') }}</td>
							<td>{{ jobStore.jobItem?.timeSensitive || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Allow Parallel Runs') }}</td>
							<td>{{ jobStore.jobItem?.allowParallelRuns || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Single Run') }}</td>
							<td>{{ jobStore.jobItem?.singleRun || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Next Run') }}</td>
							<td>{{ getValidISOstring(jobStore.jobItem?.nextRun) ? new Date(jobStore.jobItem.nextRun).toLocaleString() : 'N/A' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Last Run') }}</td>
							<td>{{ getValidISOstring(jobStore.jobItem?.lastRun) ? new Date(jobStore.jobItem.lastRun).toLocaleString() : 'N/A' }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Tabs -->
			<div class="tabContainer">
				<BTabs content-class="mt-3" justified>
					<BTab title="Job Arguments">
						<div v-if="jobStore.jobItem?.arguments !== null && Object.keys(jobStore.jobItem?.arguments || {}).length" class="arguments-list">
							<NcListItem v-for="(value, key, i) in jobStore.jobItem?.arguments"
								:key="`${key}${i}`"
								:name="key"
								:bold="false"
								:force-display-actions="true"
								:active="jobStore.jobArgumentKey === key"
								@click="setActiveJobArgumentKey(key)">
								<template #icon>
									<SitemapOutline :class="jobStore.jobArgumentKey === key && 'selectedIcon'" :size="44" />
								</template>
								<template #subname>
									{{ value }}
								</template>
								<template #actions>
									<NcActionButton close-after-click @click="editJobArgument(key)">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
									<NcActionButton close-after-click @click="deleteJobArgument(key)">
										<template #icon>
											<Delete :size="20" />
										</template>
										Delete
									</NcActionButton>
								</template>
							</NcListItem>
						</div>
						<div v-if="!jobStore.jobItem?.arguments || !Object.keys(jobStore.jobItem?.arguments).length" class="tabPanel">
							<NcEmptyContent
								:name="t('openconnector', 'No arguments')"
								:description="t('openconnector', 'No arguments found for this job')">
								<template #icon>
									<SitemapOutline :size="64" />
								</template>
								<template #action>
									<NcButton @click="addJobArgument">
										{{ t('openconnector', 'Add Argument') }}
									</NcButton>
								</template>
							</NcEmptyContent>
						</div>
					</BTab>
				</BTabs>
			</div>

			<!-- Action buttons -->
			<div class="modal-actions">
				<NcButton @click="navigationStore.setModal('editJob')">
					<template #icon>
						<Pencil :size="20" />
					</template>
					Edit
				</NcButton>
				<NcButton @click="navigationStore.setModal('testJob')">
					<template #icon>
						<Update :size="20" />
					</template>
					Test
				</NcButton>
				<NcButton @click="navigationStore.setModal('runJob')">
					<template #icon>
						<Play :size="20" />
					</template>
					Run
				</NcButton>
				<NcButton @click="viewJobLogs()">
					<template #icon>
						<TimelineQuestionOutline :size="20" />
					</template>
					Logs
				</NcButton>
				<NcButton type="error" @click="navigationStore.setDialog('deleteJob')">
					<template #icon>
						<TrashCanOutline :size="20" />
					</template>
					Delete
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton, NcListItem, NcActionButton, NcEmptyContent } from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import TimelineQuestionOutline from 'vue-material-design-icons/TimelineQuestionOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Update from 'vue-material-design-icons/Update.vue'
import Play from 'vue-material-design-icons/Play.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

import getValidISOstring from '../../services/getValidISOstring.js'

export default {
	name: 'ViewJob',
	components: {
		NcModal,
		NcButton,
		NcListItem,
		NcActionButton,
		NcEmptyContent,
		BTabs,
		BTab,
		SitemapOutline,
		TimelineQuestionOutline,
		Pencil,
		Delete,
		Update,
		Play,
		TrashCanOutline,
	},
	mounted() {
		this.refreshJobLogs()
	},
	methods: {
		/**
		 * Delete job argument
		 * @param {string} key - The argument key to delete
		 */
		deleteJobArgument(key) {
			jobStore.setJobArgumentKey(key)
			navigationStore.setModal('deleteJobArgument')
		},
		/**
		 * Edit job argument
		 * @param {string} key - The argument key to edit
		 */
		editJobArgument(key) {
			jobStore.setJobArgumentKey(key)
			navigationStore.setModal('editJobArgument')
		},
		/**
		 * Add job argument
		 */
		addJobArgument() {
			jobStore.setJobArgumentKey(null)
			navigationStore.setModal('editJobArgument')
		},
		/**
		 * Set active job argument key
		 * @param {string} jobArgumentKey - The argument key to set as active
		 */
		setActiveJobArgumentKey(jobArgumentKey) {
			if (jobStore.jobArgumentKey === jobArgumentKey) {
				jobStore.setJobArgumentKey(false)
			} else {
				jobStore.setJobArgumentKey(jobArgumentKey)
			}
		},
		/**
		 * View job logs
		 */
		viewJobLogs() {
			jobStore.setJobItem(jobStore.jobItem)
			this.$router.push('/jobs/logs')
		},
		/**
		 * Refresh job logs
		 */
		refreshJobLogs() {
			if (jobStore.jobItem?.id) {
				jobStore.refreshJobLogs(jobStore.jobItem.id)
			}
		},
		/**
		 * Get valid ISO string
		 * @param {string} dateString - The date string to validate
		 * @return {boolean} True if valid ISO string
		 */
		getValidISOstring,
	},
}
</script>

<style scoped>
.modal-content {
	padding: 20px;
	max-width: 800px;
	max-height: 80vh;
	overflow-y: auto;
}

.job-description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 20px;
	font-style: italic;
}

.job-properties {
	margin-bottom: 20px;
}

.tabPanel {
	padding: 15px 0 15px 15px;
}

.selectedIcon {
	color: var(--color-primary);
}
</style>

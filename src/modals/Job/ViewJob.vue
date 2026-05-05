<script setup>
import { translate as t } from '@nextcloud/l10n'
import { jobStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'viewJob'"
		ref="modalRef"
		:name="jobStore.item?.name || t('openconnector', 'Job Details')"
		@close="navigationStore.setModal(false)">
		<div class="modal-content">
			<p v-if="jobStore.item?.description" class="job-description">
				{{ jobStore.item.description }}
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
							<td>{{ jobStore.item?.status || 'Unknown' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Enabled') }}</td>
							<td>{{ jobStore.item?.isEnabled ? 'Enabled' : 'Disabled' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Version') }}</td>
							<td>{{ jobStore.item?.version || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Job Class') }}</td>
							<td>{{ jobStore.item?.jobClass || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Interval') }}</td>
							<td>{{ jobStore.item?.interval || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Execution Time') }}</td>
							<td>{{ jobStore.item?.executionTime || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Time Sensitive') }}</td>
							<td>{{ jobStore.item?.timeSensitive || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Allow Parallel Runs') }}</td>
							<td>{{ jobStore.item?.allowParallelRuns || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Single Run') }}</td>
							<td>{{ jobStore.item?.singleRun || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Next Run') }}</td>
							<td>{{ getValidISOstring(jobStore.item?.nextRun) ? new Date(jobStore.item.nextRun).toLocaleString() : 'N/A' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Last Run') }}</td>
							<td>{{ getValidISOstring(jobStore.item?.lastRun) ? new Date(jobStore.item.lastRun).toLocaleString() : 'N/A' }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Tabs -->
			<div class="tabContainer">
				<BTabs content-class="mt-3" justified>
					<BTab title="Job Arguments">
						<div v-if="jobStore.item?.arguments !== null && Object.keys(jobStore.item?.arguments || {}).length" class="arguments-list">
							<NcListItem v-for="(value, key, i) in jobStore.item?.arguments"
								:key="`${key}${i}`"
								:name="key"
								:bold="false"
								:force-display-actions="true"
								:active="jobStore.argumentKey === key"
								@click="setActiveJobArgumentKey(key)">
								<template #icon>
									<SitemapOutline :class="jobStore.argumentKey === key && 'selectedIcon'" :size="44" />
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
						<div v-if="!jobStore.item?.arguments || !Object.keys(jobStore.item?.arguments).length" class="tabPanel">
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
			jobStore.setArgumentKey(key)
			navigationStore.setModal('deleteJobArgument')
		},
		/**
		 * Edit job argument
		 * @param {string} key - The argument key to edit
		 */
		editJobArgument(key) {
			jobStore.setArgumentKey(key)
			navigationStore.setModal('editJobArgument')
		},
		/**
		 * Add job argument
		 */
		addJobArgument() {
			jobStore.setArgumentKey(null)
			navigationStore.setModal('editJobArgument')
		},
		/**
		 * Set active job argument key
		 * @param {string} jobArgumentKey - The argument key to set as active
		 */
		setActiveJobArgumentKey(jobArgumentKey) {
			if (jobStore.argumentKey === jobArgumentKey) {
				jobStore.setArgumentKey(false)
			} else {
				jobStore.setArgumentKey(jobArgumentKey)
			}
		},
		/**
		 * View job logs
		 */
		viewJobLogs() {
			jobStore.setItem(jobStore.item)
			this.$router.push('/jobs/logs')
		},
		/**
		 * Refresh job logs
		 */
		refreshJobLogs() {
			if (jobStore.item?.id) {
				jobStore.refreshLogs()
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

.selectedIcon {
	color: var(--color-primary);
}
</style>

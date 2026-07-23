<script setup>
import { translate as t } from '@nextcloud/l10n'
import { jobStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'testJob'"
		ref="modalRef"
		label-id="testJob"
		@close="closeModal">
		<div class="modalContent">
			<h2>{{ t('openconnector', 'Test job') }}</h2>

			<div class="modal-actions">
				<NcButton v-if="!success"
					@click="closeModal">
					<template #icon>
						<CancelIcon size="20" />
					</template>
					{{ t('openconnector', 'Cancel') }}
				</NcButton>
				<NcButton
					:disabled="loading"
					type="primary"
					@click="testJob()">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<Sync v-if="!loading" :size="20" />
					</template>
					{{ t('openconnector', 'Test job') }}
				</NcButton>
			</div>
			<div v-if="jobStore.jobTest">
				<NcNoteCard v-if="jobStore.jobTest?.level === 'INFO'" type="success">
					<p>{{ t('openconnector', 'The job test was successful.') }} {{ jobStore.jobTest?.message }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="(jobStore.jobTest?.level !== 'INFO') || error" type="error">
					<p>{{ t('openconnector', 'An error occurred while testing the job:') }} {{ jobStore.jobTest ? jobStore.jobTest.message : error }}</p>
				</NcNoteCard>
			</div>

			<div v-if="jobStore.jobTest" class="jobTestTable">
				<table>
					<tr>
						<th>{{ t('openconnector', 'UUID') }}</th>
						<td>{{ jobStore.jobTest.uuid }}</td>
					</tr>
					<tr>
						<th>{{ t('openconnector', 'Level') }}</th>
						<td>{{ jobStore.jobTest.level }}</td>
					</tr>
					<tr>
						<th>{{ t('openconnector', 'Message') }}</th>
						<td>{{ jobStore.jobTest.message }}</td>
					</tr>
					<tr>
						<th>{{ t('openconnector', 'Job ID') }}</th>
						<td>{{ jobStore.jobTest.jobId }}</td>
					</tr>
					<tr>
						<th>{{ t('openconnector', 'Job List ID') }}</th>
						<td>{{ jobStore.jobTest.jobListId }}</td>
					</tr>
					<tr>
						<th>{{ t('openconnector', 'Job class') }}</th>
						<td>{{ jobStore.jobTest.jobClass || t('openconnector', 'N/A') }}</td>
					</tr>
					<tr>
						<th>{{ t('openconnector', 'Arguments') }}</th>
						<td>
							<ul>
								<li v-for="(value, key) in jobStore.jobTest.arguments" :key="key">
									{{ key }}: {{ value }}
								</li>
							</ul>
						</td>
					</tr>
					<tr>
						<th>{{ t('openconnector', 'Execution time') }}</th>
						<td>{{ jobStore.jobTest.executionTime }} ms</td>
					</tr>
					<tr>
						<th>{{ t('openconnector', 'User ID') }}</th>
						<td>{{ jobStore.jobTest.userId || t('openconnector', 'N/A') }}</td>
					</tr>
					<tr>
						<th>{{ t('openconnector', 'Session ID') }}</th>
						<td>{{ jobStore.jobTest.sessionId || t('openconnector', 'N/A') }}</td>
					</tr>
					<tr>
						<th>{{ t('openconnector', 'Stack Trace') }}</th>
						<td>
							<ol>
								<li v-for="(step, index) in jobStore.jobTest.stackTrace" :key="index">
									{{ step }}
								</li>
							</ol>
						</td>
					</tr>
				</table>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcModal,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'

export default {
	name: 'TestJob',
	components: {
		NcModal,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		CancelIcon,
	},
	data() {
		return {
			success: false,
			loading: false,
			error: false,
		}
	},
	methods: {
		/** @spec openspec/specs/endpoint-job-editor-ui/spec.md */
		closeModal() {
			navigationStore.setModal(false)
			this.success = false
			this.loading = false
			this.error = false
		},
		/** @spec openspec/specs/endpoint-job-editor-ui/spec.md */
		async testJob() {
			this.loading = true

			try {
				await jobStore.testJob(jobStore.jobItem.id)
				this.success = true
				this.loading = false
				this.error = false
			} catch (error) {
				this.loading = false
				this.success = false
				this.error = error.message || 'An error occurred while testing the job'
				jobStore.setJobTest(false)
			}
		},
	},
}
</script>
<style scoped>
.testJobDetailGrid {
	display: grid;
	grid-template-columns: 1fr;
	gap: 5px;
}

.jobTestTable th,
.jobTestTable td {
	padding: 4px;
}

.jobTestTable th {
	font-weight: bold
}

.jobTestTable ol {
	margin-left: 1rem;
}
</style>

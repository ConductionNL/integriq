<script setup>
import { jobStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcModal ref="modalRef"
		label-id="runJob"
		@close="closeModal">
		<div class="modalContent">
			<h2>Run job</h2>

			<div class="modal-actions">
				<NcButton v-if="!success"
					@click="closeModal">
					<template #icon>
						<CancelIcon :size="20" />
					</template>
					Cancel
				</NcButton>
				<NcButton
					:disabled="loading"
					type="primary"
					@click="runJob()">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<Sync v-if="!loading" :size="20" />
					</template>
					Run job
				</NcButton>
			</div>
			<div v-if="jobStore.jobRun">
				<NcNoteCard v-if="jobStore.jobRun?.level === 'INFO'" type="success">
					<p>The job run was successful. {{ jobStore.jobRun?.message }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="(jobStore.jobRun?.level !== 'INFO') || error" type="error">
					<p>An error occurred while running the job: {{ jobStore.jobRun ? jobStore.jobRun.message : error }}</p>
				</NcNoteCard>
			</div>

			<div v-if="jobStore.jobRun" class="jobRunTable">
				<table>
					<tr>
						<th>UUID</th>
						<td>{{ jobStore.jobRun.uuid }}</td>
					</tr>
					<tr>
						<th>Level</th>
						<td>{{ jobStore.jobRun.level }}</td>
					</tr>
					<tr>
						<th>Message</th>
						<td>{{ jobStore.jobRun.message }}</td>
					</tr>
					<tr>
						<th>Job ID</th>
						<td>{{ jobStore.jobRun.jobId }}</td>
					</tr>
					<tr>
						<th>Job List ID</th>
						<td>{{ jobStore.jobRun.jobListId }}</td>
					</tr>
					<tr>
						<th>Job Class</th>
						<td>{{ jobStore.jobRun.jobClass || 'N/A' }}</td>
					</tr>
					<tr>
						<th>Arguments</th>
						<td>
							<ul>
								<li v-for="(value, key) in jobStore.jobRun.arguments" :key="key">
									{{ key }}: {{ value }}
								</li>
							</ul>
						</td>
					</tr>
					<tr>
						<th>Execution Time</th>
						<td>{{ jobStore.jobRun.executionTime }} ms</td>
					</tr>
					<tr>
						<th>User ID</th>
						<td>{{ jobStore.jobRun.userId || 'N/A' }}</td>
					</tr>
					<tr>
						<th>Session ID</th>
						<td>{{ jobStore.jobRun.sessionId || 'N/A' }}</td>
					</tr>
					<tr>
						<th>Stack Trace</th>
						<td>
							<ol>
								<li v-for="(step, index) in jobStore.jobRun.stackTrace" :key="index">
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
	name: 'RunJob',
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
		closeModal() {
			navigationStore.setModal(false)
			this.success = false
			this.loading = false
			this.error = false
		},
		async runJob() {
			this.loading = true

			try {
				await jobStore.runJob(jobStore.jobItem.id)
				this.success = true
				this.loading = false
				this.error = false
			} catch (error) {
				this.loading = false
				this.success = false
				this.error = error.message || 'An error occurred while running the job'
				jobStore.setJobRun(false)
			}
		},
	},
}
</script>
<style scoped>
.runJobDetailGrid {
	display: grid;
	grid-template-columns: 1fr;
	gap: 5px;
}

.jobRunTable th,
.jobRunTable td {
	padding: 4px;
}

.jobRunTable th {
	font-weight: bold
}

.jobRunTable ol {
	margin-left: 1rem;
}
</style>

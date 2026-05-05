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
						<CancelIcon size="20" />
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
			<div v-if="jobStore.runResult">
				<NcNoteCard v-if="jobStore.runResult?.level === 'INFO'" type="success">
					<p>The job run was successful. {{ jobStore.runResult?.message }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="(jobStore.runResult?.level !== 'INFO') || error" type="error">
					<p>An error occurred while running the job: {{ jobStore.runResult ? jobStore.runResult.message : error }}</p>
				</NcNoteCard>
			</div>

			<div v-if="jobStore.runResult" class="jobRunTable">
				<table>
					<tr>
						<th>UUID</th>
						<td>{{ jobStore.runResult.uuid }}</td>
					</tr>
					<tr>
						<th>Level</th>
						<td>{{ jobStore.runResult.level }}</td>
					</tr>
					<tr>
						<th>Message</th>
						<td>{{ jobStore.runResult.message }}</td>
					</tr>
					<tr>
						<th>Job ID</th>
						<td>{{ jobStore.runResult.jobId }}</td>
					</tr>
					<tr>
						<th>Job List ID</th>
						<td>{{ jobStore.runResult.jobListId }}</td>
					</tr>
					<tr>
						<th>Job Class</th>
						<td>{{ jobStore.runResult.jobClass || 'N/A' }}</td>
					</tr>
					<tr>
						<th>Arguments</th>
						<td>
							<ul>
								<li v-for="(value, key) in jobStore.runResult.arguments" :key="key">
									{{ key }}: {{ value }}
								</li>
							</ul>
						</td>
					</tr>
					<tr>
						<th>Execution Time</th>
						<td>{{ jobStore.runResult.executionTime }} ms</td>
					</tr>
					<tr>
						<th>User ID</th>
						<td>{{ jobStore.runResult.userId || 'N/A' }}</td>
					</tr>
					<tr>
						<th>Session ID</th>
						<td>{{ jobStore.runResult.sessionId || 'N/A' }}</td>
					</tr>
					<tr>
						<th>Stack Trace</th>
						<td>
							<ol>
								<li v-for="(step, index) in jobStore.runResult.stackTrace" :key="index">
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
				await jobStore.runJob(jobStore.item.id)
				this.success = true
				this.loading = false
				this.error = false
			} catch (error) {
				this.loading = false
				this.success = false
				this.error = error.message || 'An error occurred while running the job'
				jobStore.setRunResult(false)
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

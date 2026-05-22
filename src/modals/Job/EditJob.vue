<script setup>
import { translate as t } from '@nextcloud/l10n'
import { jobStore, navigationStore } from '../../store/store.js'
import { Job } from '../../entities/index.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'editJob'"
		ref="modalRef"
		label-id="editJob"
		@close="closeModal">
		<div class="modalContent">
			<h2>{{ jobItem?.id ? t('openconnector', 'Edit job') : t('openconnector', 'Add job') }}</h2>
			<NcNoteCard v-if="success" type="success">
				<p>{{ t('openconnector', 'Successfully added job') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>

			<form v-if="!success" @submit.prevent="handleSubmit">
				<div class="form-group">
					<NcTextField
						:label="t('openconnector', 'Name')"
						maxlength="255"
						:value.sync="jobItem.name"
						required />

					<NcTextArea
						resize="vertical"
						:label="t('openconnector', 'Description')"
						:value.sync="jobItem.description" />

					<NcSelect v-bind="classOptions"
						v-model="classOptions.value"
						class="jobClassSelect"
						:input-label="t('openconnector', 'Job class')"
						:multiple="false"
						:clearable="false" />

					<NcInputField
						type="number"
						:label="t('openconnector', 'Interval')"
						:value.sync="jobItem.interval" />

					<NcInputField
						type="number"
						:label="t('openconnector', 'Execution time')"
						:value.sync="jobItem.executionTime" />

					<div class="jobCheckboxContainerGrid">
						<NcCheckboxRadioSwitch
							:disabled="loading"
							:checked.sync="jobItem.timeSensitive">
							{{ t('openconnector', 'Time sensitive') }}
						</NcCheckboxRadioSwitch>

						<NcCheckboxRadioSwitch
							:disabled="loading"
							:checked.sync="jobItem.allowParallelRuns">
							{{ t('openconnector', 'Allow parallel runs') }}
						</NcCheckboxRadioSwitch>

						<NcCheckboxRadioSwitch
							:disabled="loading"
							:checked.sync="jobItem.isEnabled">
							{{ t('openconnector', 'Enabled') }}
						</NcCheckboxRadioSwitch>

						<NcCheckboxRadioSwitch
							:disabled="loading"
							:checked.sync="jobItem.singleRun">
							{{ t('openconnector', 'Single run') }}
						</NcCheckboxRadioSwitch>
					</div>
					<div>
						<span>
							<p>{{ t('openconnector', 'Schedule After') }}</p>
							<NcDateTimePicker v-model="jobItem.scheduleAfter"
								:disabled="loading"
								:label="t('openconnector', 'Schedule After')" />
						</span>
					</div>
					<NcTextField
						:label="t('openconnector', 'User ID')"
						maxlength="255"
						:value.sync="jobItem.userId" />

					<NcInputField
						type="number"
						:label="t('openconnector', 'Log Retention')"
						:value.sync="jobItem.logRetention" />

					<NcInputField
						type="number"
						:label="t('openconnector', 'Error Retention')"
						:value.sync="jobItem.errorRetention" />
				</div>
			</form>

			<div class="modal-actions">
				<NcButton v-if="!success"
					@click="closeModal">
					<template #icon>
						<CancelIcon size="20" />
					</template>
					{{ t('openconnector', 'Cancel') }}
				</NcButton>
				<NcButton
					v-if="!success"
					:disabled="loading || !jobItem.name"
					type="primary"
					@click="editJob()">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<ContentSaveOutline v-if="!loading" :size="20" />
					</template>
					{{ t('openconnector', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcModal,
	NcSelect,
	NcTextField,
	NcTextArea,
	NcLoadingIcon,
	NcNoteCard,
	NcInputField,
	NcCheckboxRadioSwitch,
	NcDateTimePicker,
} from '@nextcloud/vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'

export default {
	name: 'EditJob',
	components: {
		NcModal,
		NcSelect,
		NcTextField,
		NcTextArea,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcInputField,
		NcCheckboxRadioSwitch,
		NcDateTimePicker,
		// Icons
		ContentSaveOutline,
		CancelIcon,
	},
	data() {
		return {
			jobItem: {
				name: '',
				description: '',
				jobClass: '',
				interval: '3600',
				executionTime: '3600',
				timeSensitive: false,
				allowParallelRuns: false,
				isEnabled: true,
				singleRun: false,
				scheduleAfter: '',
				userId: '',
				logRetention: '3600',
				errorRetention: '86400',
			},
			success: false,
			loading: false,
			error: false,
			classOptions: {
				options: [
					{ label: 'OCA\\OpenConnector\\Action\\SynchronizationAction' },
					{ label: 'OCA\\OpenConnector\\Action\\PingAction' },
				],
				value: { label: 'OCA\\OpenConnector\\Action\\SynchronizationAction' },
			},
			statusOptions: [
				{ label: 'Open', value: 'open' },
				{ label: 'In Progress', value: 'in_progress' },
				{ label: 'Completed', value: 'completed' },
			],
			hasUpdated: false,
			closeTimeoutFunc: null,
		}
	},
	mounted() {
		this.initializeJobItem()
	},
	updated() {
		if (navigationStore.modal === 'editJob' && !this.hasUpdated) {
			this.initializeJobItem()
			this.hasUpdated = true
		}
	},
	methods: {
		initializeJobItem() {
			if (jobStore.jobItem?.id) {
				const scheduleAfter = jobStore.jobItem.scheduleAfter ? new Date(jobStore.jobItem.scheduleAfter.date) : null

				const activeJobClass = this.classOptions.options.find(option => option.label === jobStore.jobItem.jobClass)
				activeJobClass && (this.classOptions.value = activeJobClass)

				this.jobItem = {
					...jobStore.jobItem,
					name: jobStore.jobItem.name || '',
					description: jobStore.jobItem.description || '',
					jobClass: jobStore.jobItem.jobClass || '',
					interval: jobStore.jobItem.interval || '3600',
					executionTime: jobStore.jobItem.executionTime || '3600',
					timeSensitive: typeof jobStore.jobItem.timeSensitive === 'boolean' ? jobStore.jobItem.timeSensitive : false,
					allowParallelRuns: typeof jobStore.jobItem.allowParallelRuns === 'boolean' ? jobStore.jobItem.allowParallelRuns : false,
					isEnabled: typeof jobStore.jobItem.isEnabled === 'boolean' ? jobStore.jobItem.isEnabled : true,
					singleRun: typeof jobStore.jobItem.singleRun === 'boolean' ? jobStore.jobItem.singleRun : false,
					scheduleAfter,
					logRetention: jobStore.jobItem.logRetention || '3600',
					errorRetention: jobStore.jobItem.errorRetention || '86400',
					userId: jobStore.jobItem.userId || '',
				}
			}
		},
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeTimeoutFunc)
			this.success = null
			this.loading = false
			this.error = false
			this.hasUpdated = false
			this.jobItem = {
				name: '',
				description: '',
				jobClass: '',
				interval: '3600',
				executionTime: '3600',
				timeSensitive: false,
				allowParallelRuns: false,
				isEnabled: true,
				singleRun: false,
				scheduleAfter: '',
				logRetention: '3600',
				errorRetention: '86400',
				userId: '',
			}
			this.classOptions.value = this.classOptions.options[0]
		},
		async editJob() {
			this.loading = true
			try {
				const jobItem = new Job({
					...this.jobItem,
					jobClass: this.classOptions.value.label,
				})

				await jobStore.saveJob(jobItem)
				// Close modal or show success message
				this.success = true
				this.loading = false
				this.closeTimeoutFunc = setTimeout(this.closeModal, 2000)

			} catch (error) {
				this.loading = false
				this.success = false
				this.error = error.message || 'An error occurred while saving the job'
			}
		},
	},
}
</script>
<style scoped>
.jobCheckboxContainerGrid {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 10px;
}

.jobClassSelect {
	width: 100%;
}
</style>

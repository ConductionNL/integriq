<script setup>
import { translate as t } from '@nextcloud/l10n'
import { jobStore, navigationStore } from '../../store/store.js'
import { Job } from '../../entities/index.js'
</script>

<template>
	<CnFormDialog
		v-if="navigationStore.modal === 'editJob'"
		ref="formDialog"
		:item="initialItem"
		:fields="formFields"
		:dialog-title="initialItem?.id ? t('openconnector', 'Edit job') : t('openconnector', 'Add job')"
		name-field="name"
		size="normal"
		@confirm="onConfirm"
		@close="closeModal">
		<!-- Job class: hardcoded options with label/value pairs (not a flat enum) -->
		<template #field-jobClass="{ value, updateField }">
			<div class="cn-form-dialog__select-wrapper">
				<label for="cn-form-jobClass" class="cn-form-dialog__label">
					{{ t('openconnector', 'Job class') }}
				</label>
				<NcSelect
					input-id="cn-form-jobClass"
					class="jobClassSelect"
					:options="classOptions"
					:value="resolvedClassOption(value)"
					:multiple="false"
					:clearable="false"
					@input="option => updateField('jobClass', option ? option.label : null)" />
			</div>
		</template>

		<!-- Synthetic field "_flags" positioned between executionTime and scheduleAfter,
		     rendered as the 2-col switch grid (state lives in component-local extraFlags). -->
		<template #field-_flags>
			<div class="jobCheckboxContainerGrid">
				<NcCheckboxRadioSwitch :checked.sync="extraFlags.timeSensitive">
					{{ t('openconnector', 'Time sensitive') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch :checked.sync="extraFlags.allowParallelRuns">
					{{ t('openconnector', 'Allow parallel runs') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch :checked.sync="extraFlags.isEnabled">
					{{ t('openconnector', 'Enabled') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch :checked.sync="extraFlags.singleRun">
					{{ t('openconnector', 'Single run') }}
				</NcCheckboxRadioSwitch>
			</div>
		</template>

		<!-- Schedule After: keep NcDateTimePicker rather than the default datetime input -->
		<template #field-scheduleAfter="{ value, updateField }">
			<div class="cn-form-dialog__select-wrapper">
				<label class="cn-form-dialog__label">
					{{ t('openconnector', 'Schedule after') }}
				</label>
				<NcDateTimePicker
					:value="value || null"
					@input="$event => updateField('scheduleAfter', $event)" />
			</div>
		</template>
	</CnFormDialog>
</template>

<script>
import { NcSelect, NcDateTimePicker, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { CnFormDialog } from '@conduction/nextcloud-vue'

const CLASS_OPTIONS = [
	{ label: 'OCA\\OpenConnector\\Action\\SynchronizationAction' },
	{ label: 'OCA\\OpenConnector\\Action\\PingAction' },
]

const DEFAULT_FLAGS = {
	timeSensitive: false,
	allowParallelRuns: false,
	isEnabled: true,
	singleRun: false,
}

export default {
	name: 'EditJob',
	components: {
		CnFormDialog,
		NcSelect,
		NcDateTimePicker,
		NcCheckboxRadioSwitch,
	},
	data() {
		return {
			classOptions: CLASS_OPTIONS,
			extraFlags: { ...DEFAULT_FLAGS },
		}
	},
	computed: {
		initialItem() {
			const item = jobStore.jobItem
			if (!item || !item.id) return null
			// Normalize scheduleAfter (backend returns { date: '...' }) into a Date for the picker
			const scheduleAfter = item.scheduleAfter?.date
				? new Date(item.scheduleAfter.date)
				: (item.scheduleAfter || null)
			return {
				...item,
				name: item.name || '',
				description: item.description || '',
				jobClass: item.jobClass || CLASS_OPTIONS[0].label,
				interval: item.interval ?? 3600,
				executionTime: item.executionTime ?? 3600,
				scheduleAfter,
				userId: item.userId || '',
				logRetention: item.logRetention ?? 3600,
				errorRetention: item.errorRetention ?? 86400,
			}
		},
		formFields() {
			return [
				{ key: 'name', label: t('openconnector', 'Name'), widget: 'text', required: true, validation: { maxLength: 255 } },
				{ key: 'description', label: t('openconnector', 'Description'), widget: 'textarea' },
				{ key: 'jobClass', label: t('openconnector', 'Job class'), widget: 'select', default: CLASS_OPTIONS[0].label },
				{ key: 'interval', label: t('openconnector', 'Interval'), widget: 'number', default: 3600 },
				{ key: 'executionTime', label: t('openconnector', 'Execution time'), widget: 'number', default: 3600 },
				// Synthetic field — rendered via #field-_flags slot to host the boolean switch grid
				// at the same position the original modal used (between executionTime and scheduleAfter).
				{ key: '_flags', label: '', widget: 'custom' },
				{ key: 'scheduleAfter', label: t('openconnector', 'Schedule after'), widget: 'datetime' },
				{ key: 'userId', label: t('openconnector', 'User ID'), widget: 'text', validation: { maxLength: 255 } },
				{ key: 'logRetention', label: t('openconnector', 'Log retention'), widget: 'number', default: 3600 },
				{ key: 'errorRetention', label: t('openconnector', 'Error retention'), widget: 'number', default: 86400 },
			]
		},
	},
	watch: {
		'navigationStore.modal': {
			immediate: true,
			handler(modal) {
				if (modal === 'editJob') {
					const item = jobStore.jobItem || {}
					this.extraFlags = {
						timeSensitive: !!item.timeSensitive,
						allowParallelRuns: !!item.allowParallelRuns,
						isEnabled: typeof item.isEnabled === 'boolean' ? item.isEnabled : true,
						singleRun: !!item.singleRun,
					}
				}
			},
		},
	},
	methods: {
		resolvedClassOption(value) {
			if (!value) return CLASS_OPTIONS[0]
			return CLASS_OPTIONS.find(opt => opt.label === value) || { label: value }
		},
		closeModal() {
			navigationStore.setModal(false)
			this.extraFlags = { ...DEFAULT_FLAGS }
		},
		async onConfirm(formData) {
			// Drop the synthetic flag-grid placeholder; real values live in `extraFlags`.
			const { _flags, ...payload } = formData
			try {
				const job = new Job({
					...payload,
					...this.extraFlags,
				})
				await jobStore.saveJob(job)
				this.$refs.formDialog.setResult({ success: true })
			} catch (error) {
				this.$refs.formDialog.setResult({
					error: error.message || t('openconnector', 'An error occurred while saving the job'),
				})
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
	margin-top: 10px;
}

.jobClassSelect {
	width: 100%;
}

.cn-form-dialog__select-wrapper {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.cn-form-dialog__label {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-main-text);
}
</style>

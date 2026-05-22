<script setup>
import { jobStore, navigationStore } from '../../store/store.js'
import { Job } from '../../entities/index.js'
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'editJobArgument'"
		ref="modalRef"
		label-id="editJobArgument"
		@close="closeModal">
		<div class="modalContent">
			<h2>{{ isEdit ? t('openconnector', 'Edit Job Argument') : t('openconnector', 'Add Job Argument') }}</h2>
			<NcNoteCard v-if="success" type="success">
				<p>{{ t('openconnector', 'Job argument saved successfully') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>

			<form v-if="!success" @submit.prevent="handleSubmit">
				<div class="form-group">
					<NcTextField
						id="key"
						:label="t('openconnector', 'Key*')"
						required
						:error="checkIfKeyIsUnique(argumentItem.key)"
						:helper-text="checkIfKeyIsUnique(argumentItem.key) ? t('openconnector', 'This key is already in use. Please choose a different key name.') : ''"
						:value.sync="argumentItem.key" />
					<NcTextField
						id="value"
						:label="t('openconnector', 'Value')"
						:value.sync="argumentItem.value" />
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
					:disabled="loading || !argumentItem.key || checkIfKeyIsUnique(argumentItem.key)"
					type="primary"
					@click="editJobArgument()">
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
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'

export default {
	name: 'EditJobArgument',
	components: {
		NcModal,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		// Icons
		ContentSaveOutline,
		CancelIcon,
	},
	data() {
		return {
			argumentItem: {
				key: '',
				value: '',
			},
			success: false,
			loading: false,
			error: false,
			hasUpdated: false,
			closeTimeoutFunc: null,
			oldKey: '',
			isEdit: false,
		}
	},
	mounted() {
		this.initializeJobArgument()
	},
	updated() {
		if (navigationStore.modal === 'editJobArgument' && !this.hasUpdated) {
			this.initializeJobArgument()
			this.hasUpdated = true
		}
	},
	methods: {
		initializeJobArgument() {
			if (!jobStore.jobArgumentKey) {
				return
			}
			const argumentItem = Object.entries(jobStore.jobItem.arguments).find(([key]) => key === jobStore.jobArgumentKey)
			if (argumentItem) {
				this.argumentItem = {
					key: argumentItem[0] || '',
					value: argumentItem[1] || '',
				}
				this.oldKey = argumentItem[0]
				this.isEdit = true
			}
		},
		checkIfKeyIsUnique(key) {
			if (!jobStore.jobItem.arguments) return false
			const keys = Object.keys(jobStore.jobItem.arguments)
			if (this.oldKey === key) return false
			if (keys.includes(key)) return true
			return false
		},
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeTimeoutFunc)
			this.success = false
			this.loading = false
			this.error = false
			this.hasUpdated = false
			this.isEdit = false
			this.oldKey = ''
			this.argumentItem = {
				key: '',
				value: '',
			}
		},
		async editJobArgument() {
			this.loading = true

			const jobItemClone = jobStore.jobItem.cloneRaw()

			const scheduleAfter = jobItemClone.scheduleAfter ? new Date(jobItemClone.scheduleAfter.date) : null

			const newJobItem = {
				...jobItemClone,
				scheduleAfter,
				arguments: {
					...jobItemClone.arguments,
					[this.argumentItem.key]: this.argumentItem.value,
				},
			}

			if (this.oldKey !== '' && this.oldKey !== this.argumentItem.key) {
				delete newJobItem.arguments[this.oldKey]
			}

			jobStore.saveJob(new Job(newJobItem))
				.then(({ response }) => {
					this.success = response.ok
					this.closeTimeoutFunc = setTimeout(this.closeModal, 2000)
				})
				.catch((error) => {
					this.success = false
					this.error = error.message || 'An error occurred while saving the job argument'
				})
				.finally(() => {
					this.loading = false
				})
		},
	},
}
</script>

<script setup>
import { synchronizationStore, navigationStore } from '../../store/store.js'
import { Synchronization } from '../../entities/index.js'
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<NcModal
		ref="modalRef"
		label-id="editSourceConfig"
		@close="closeModal">
		<div class="modalContent">
			<h2>{{ synchronizationStore.synchronizationSourceConfigKey ? t('openconnector', 'Edit') : t('openconnector', 'Add') }} {{ t('openconnector', 'Source Config') }}</h2>
			<NcNoteCard v-if="success" type="success">
				<p>{{ t('openconnector', 'Source config added successfully') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>

			<form v-if="!success" @submit.prevent="handleSubmit">
				<div class="form-group">
					<NcTextField
						id="sourceConfigKey"
						:label="t('openconnector', 'Source Config Key*')"
						required
						:error="isTaken(sourceConfig.key)"
						:helper-text="isTaken(sourceConfig.key) ? t('openconnector', 'This source config key is already in use. Please choose a different key name.') : ''"
						:value.sync="sourceConfig.key" />
					<NcTextField
						id="sourceConfigValue"
						:label="t('openconnector', 'Source Config Value')"
						:value.sync="sourceConfig.value" />
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
				<NcButton v-if="!success"
					:disabled="loading
						|| !sourceConfig.key
						/// checks if the key is unique, ignores if the key is not changed
						|| isTaken(sourceConfig.key)
						/// checks if the value is the same as the one in the source config, only works if the key is not changed
						|| (synchronizationStore.synchronizationItem?.sourceConfig && synchronizationStore.synchronizationItem.sourceConfig[sourceConfig.key] === sourceConfig.value)"
					type="primary"
					@click="editSourceConfig()">
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
	name: 'EditSynchronizationSourceConfig',
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
			sourceConfig: {
				key: '',
				value: '',
			},
			success: null,
			loading: false,
			error: false,
			closeTimeoutFunc: null,
		}
	},
	mounted() {
		this.initializeSourceConfig()
	},
	methods: {
		initializeSourceConfig() {
			if (synchronizationStore.synchronizationSourceConfigKey) {
				this.sourceConfig.key = synchronizationStore.synchronizationSourceConfigKey
				this.sourceConfig.value = synchronizationStore.synchronizationItem.sourceConfig[synchronizationStore.synchronizationSourceConfigKey]
			}
		},
		isTaken(key) {
			if (!synchronizationStore.synchronizationItem?.sourceConfig) return false

			// if the key is the same as the one we are editing, don't check for duplicates.
			// this is safe since you are not allowed to save the same key anyway (only for edit modal).
			if (synchronizationStore.synchronizationSourceConfigKey === key) return false

			const allKeys = Object.keys(synchronizationStore.synchronizationItem.sourceConfig)
			if (allKeys.includes(key)) return true

			return false
		},
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeTimeoutFunc)
			synchronizationStore.setSynchronizationSourceConfigKey(null)
		},
		editSourceConfig() {
			this.loading = true

			const isSourceConfigKeyPresent = !!synchronizationStore.synchronizationSourceConfigKey
			const keyChanged = synchronizationStore.synchronizationSourceConfigKey !== this.sourceConfig.key

			// copy the source config object
			const newSourceConfig = { ...synchronizationStore.synchronizationItem.sourceConfig }

			// if synchronizationSourceConfigKey is set remove that from the object
			if (isSourceConfigKeyPresent && keyChanged) {
				delete newSourceConfig[synchronizationStore.synchronizationSourceConfigKey]
			}

			// add the new key
			newSourceConfig[this.sourceConfig.key] = this.sourceConfig.value

			const newSynchronizationItem = new Synchronization({
				...synchronizationStore.synchronizationItem,
				sourceConfig: newSourceConfig,
			})

			synchronizationStore.saveSynchronization(newSynchronizationItem)
				.then(({ response }) => {
					this.success = response.ok
					this.closeTimeoutFunc = setTimeout(this.closeModal, 2000)
				})
				.catch((error) => {
					this.error = error.message || 'An error occurred while saving the source config'
				})
				.finally(() => {
					this.loading = false
				})
		},
	},
}
</script>

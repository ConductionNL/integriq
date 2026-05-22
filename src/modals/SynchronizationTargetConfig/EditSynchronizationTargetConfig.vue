<script setup>
import { synchronizationStore, navigationStore } from '../../store/store.js'
import { Synchronization } from '../../entities/index.js'
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<NcModal ref="modalRef"
		label-id="editTargetConfig"
		@close="closeModal">
		<div class="modalContent">
			<h2>{{ synchronizationStore.synchronizationTargetConfigKey ? t('openconnector', 'Edit') : t('openconnector', 'Add') }} {{ t('openconnector', 'Target Config') }}</h2>
			<NcNoteCard v-if="success" type="success">
				<p>{{ t('openconnector', 'Target config added successfully') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>

			<form v-if="!success" @submit.prevent="handleSubmit">
				<div class="form-group">
					<NcTextField
						id="targetConfigKey"
						:label="t('openconnector', 'Target Config Key*')"
						required
						:error="isTaken(targetConfig.key)"
						:helper-text="isTaken(targetConfig.key) ? t('openconnector', 'This target config key is already in use. Please choose a different key name.') : ''"
						:value.sync="targetConfig.key" />
					<NcTextField
						id="targetConfigValue"
						:label="t('openconnector', 'Target Config Value*')"
						required
						:value.sync="targetConfig.value" />
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
						|| !targetConfig.key
						|| !targetConfig.value
						/// checks if the key is unique, ignores if the key is not changed
						|| isTaken(targetConfig.key)
						/// checks if the value is the same as the one in the target config, only works if the key is not changed
						|| synchronizationStore.synchronizationItem.targetConfig[targetConfig.key] === targetConfig.value"
					type="primary"
					@click="editTargetConfig()">
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
	name: 'EditSynchronizationTargetConfig',
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
			targetConfig: {
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
		this.initializeTargetConfig()
	},
	methods: {
		initializeTargetConfig() {
			if (synchronizationStore.synchronizationTargetConfigKey) {
				this.targetConfig.key = synchronizationStore.synchronizationTargetConfigKey
				this.targetConfig.value = synchronizationStore.synchronizationItem.targetConfig[synchronizationStore.synchronizationTargetConfigKey]
			}
		},
		isTaken(key) {
			if (!synchronizationStore.synchronizationItem?.targetConfig) return false

			// if the key is the same as the one we are editing, don't check for duplicates.
			// this is safe since you are not allowed to save the same key anyway (only for edit modal).
			if (synchronizationStore.synchronizationTargetConfigKey === key) return false

			const allKeys = Object.keys(synchronizationStore.synchronizationItem.targetConfig)
			if (allKeys.includes(key)) return true

			return false
		},
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeTimeoutFunc)
			synchronizationStore.setSynchronizationTargetConfigKey(null)
		},
		editTargetConfig() {
			this.loading = true

			const isTargetConfigKeyPresent = !!synchronizationStore.synchronizationTargetConfigKey
			const keyChanged = synchronizationStore.synchronizationTargetConfigKey !== this.targetConfig.key

			// copy the target config object
			const newTargetConfig = { ...synchronizationStore.synchronizationItem.targetConfig }

			// if synchronizationTargetConfigKey is set remove that from the object
			if (isTargetConfigKeyPresent && keyChanged) {
				delete newTargetConfig[synchronizationStore.synchronizationTargetConfigKey]
			}

			// add the new key
			newTargetConfig[this.targetConfig.key] = this.targetConfig.value

			const newSynchronizationItem = new Synchronization({
				...synchronizationStore.synchronizationItem,
				targetConfig: newTargetConfig,
			})

			synchronizationStore.saveSynchronization(newSynchronizationItem)
				.then(({ response }) => {
					this.success = response.ok
					this.closeTimeoutFunc = setTimeout(this.closeModal, 2000)
				})
				.catch((error) => {
					this.error = error.message || 'An error occurred while saving the target config'
				})
				.finally(() => {
					this.loading = false
				})
		},
	},
}
</script>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { sourceStore, navigationStore } from '../../store/store.js'
import { Source } from '../../entities/index.js'
</script>

<template>
	<NcModal ref="modalRef"
		label-id="editSourceConfigurationAuthentication"
		@close="closeModal">
		<div class="modalContent">
			<h2>{{ isEdit ? t('openconnector', 'Edit Authentication') : t('openconnector', 'Add Authentication') }}</h2>
			<NcNoteCard v-if="success" type="success">
				<p>{{ t('openconnector', 'Authentication successfully added') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>

			<form v-if="!success" @submit.prevent="handleSubmit">
				<div class="form-group">
					<NcTextField
						id="key"
						:label="t('openconnector', 'Key') + '*'"
						required
						:error="checkIfKeyIsUnique(configurationItem.key)"
						:helper-text="checkIfKeyIsUnique(configurationItem.key) ? t('openconnector', 'This key is already in use. Please choose a different key name.') : ''"
						:value.sync="configurationItem.key" />
					<NcTextField
						id="value"
						:label="t('openconnector', 'Value')"
						:value.sync="configurationItem.value" />
				</div>
			</form>
			<div class="modal-actions">
				<NcButton
					v-if="!success"
					@click="closeModal">
					<template #icon>
						<CancelIcon size="20" />
					</template>
					{{ t('openconnector', 'Cancel') }}
				</NcButton>
				<NcButton
					v-if="!success"
					:disabled="loading || !configurationItem.key || checkIfKeyIsUnique(configurationItem.key)"
					type="primary"
					@click="editSourceConfiguration()">
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
	name: 'EditSourceConfigurationAuthentication',
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
			configurationItem: {
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
		this.initializeSourceConfiguration()
	},
	methods: {
		initializeSourceConfiguration() {
			if (!sourceStore.sourceConfigurationKey) {
				return
			}
			// Get authentication object or initialize empty object if it doesn't exist
			const authentication = sourceStore.item.configuration?.authentication || {}
			const key = sourceStore.sourceConfigurationKey

			if (key in authentication) {
				this.configurationItem = {
					key,
					value: authentication[key] || '',
				}
				this.oldKey = key
				this.isEdit = true
			}
		},
		checkIfKeyIsUnique(key) {
			if (!sourceStore.item.configuration?.authentication) return false
			if (this.oldKey === key) return false
			return key in sourceStore.item.configuration.authentication
		},
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeTimeoutFunc)
			sourceStore.setSourceConfigurationKey(null)
		},
		async editSourceConfiguration() {
			this.loading = true

			// Get current configuration and authentication or initialize empty objects
			const currentConfig = sourceStore.item.configuration || {}
			const currentAuth = currentConfig.authentication || {}

			// Create new authentication object with updated values
			const newAuth = {
				...currentAuth,
				[this.configurationItem.key]: this.configurationItem.value,
			}

			// Remove old key if it was renamed
			if (this.oldKey && this.oldKey !== this.configurationItem.key) {
				delete newAuth[this.oldKey]
			}

			const newSourceItem = new Source({
				...sourceStore.item,
				configuration: {
					...currentConfig,
					authentication: newAuth,
				},
			})

			try {
				await sourceStore.save(newSourceItem)
				// Close modal or show success message
				this.success = true
				this.loading = false
				this.closeTimeoutFunc = setTimeout(this.closeModal, 2000)
			} catch (error) {
				this.loading = false
				this.success = false
				this.error = error.message || t('openconnector', 'An error occurred while saving the authentication configuration')
			}
		},
	},
}
</script>

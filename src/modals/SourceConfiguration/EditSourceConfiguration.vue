<script setup>
import { translate as t } from '@nextcloud/l10n'
import { sourceStore, navigationStore } from '../../store/store.js'
import { Source } from '../../entities/index.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'editSourceConfiguration'"
		ref="modalRef"
		label-id="editSourceConfiguration"
		@close="closeModal">
		<div class="modalContent">
			<h2>{{ isEdit ? t('openconnector', 'Edit Configuration') : t('openconnector', 'Add Configuration') }}</h2>
			<NcNoteCard v-if="success" type="success">
				<p>{{ t('openconnector', 'Configuration successfully added') }}</p>
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
	name: 'EditSourceConfiguration',
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
	updated() {
		if (navigationStore.modal === 'editSourceConfiguration' && !this.hasUpdated) {
			this.initializeSourceConfiguration()
			this.hasUpdated = true
		}
	},
	methods: {
		initializeSourceConfiguration() {
			if (!sourceStore.sourceConfigurationKey) {
				return
			}
			const configurationItem = Object.entries(sourceStore.item.configuration).find(([key]) => key === sourceStore.sourceConfigurationKey)
			if (configurationItem) {
				this.configurationItem = {
					key: configurationItem[0] || '',
					value: configurationItem[1] || '',
				}
				this.oldKey = configurationItem[0]
				this.isEdit = true
			}
		},
		checkIfKeyIsUnique(key) {
			if (!sourceStore.item.configuration) return false
			const keys = Object.keys(sourceStore.item.configuration)
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
			this.configurationItem = {
				key: '',
				value: '',
			}
		},
		async editSourceConfiguration() {
			this.loading = true

			const newSourceItem = {
				...sourceStore.item,
				configuration: {
					...sourceStore.item.configuration,
					[this.configurationItem.key]: this.configurationItem.value,
				},
			}

			if (this.oldKey !== '' && this.oldKey !== this.configurationItem.key) {
				delete newSourceItem.configuration[this.oldKey]
			}

			try {
				const sourceItem = new Source(newSourceItem)

				await sourceStore.save(sourceItem)
				// Close modal or show success message
				this.success = true
				this.loading = false
				this.closeTimeoutFunc = setTimeout(this.closeModal, 2000)
			} catch (error) {
				this.loading = false
				this.success = false
				this.error = error.message || t('openconnector', 'An error occurred while saving the source configuration')
			}
		},
	},
}
</script>

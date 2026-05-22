<script setup>
import { logStore, navigationStore } from '../../store/store.js'
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'editLog'" ref="modalRef" @close="closeModal">
		<div class="modalContent">
			<h2>{{ logStore.logItem.id ? t('openconnector', 'Edit Log') : t('openconnector', 'Create Log') }}</h2>
			<NcNoteCard v-if="success" type="success">
				<p>{{ t('openconnector', 'Log saved successfully') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>

			<form v-if="!success" @submit.prevent="handleSubmit">
				<div class="form-group">
					<label for="title">{{ t('openconnector', 'Title') }}:</label>
					<input id="title" v-model="logStore.logItem.title" required>
				</div>
				<div class="form-group">
					<label for="description">{{ t('openconnector', 'Description') }}:</label>
					<textarea id="description" v-model="logStore.logItem.description" />
				</div>
				<div class="form-group">
					<label for="date">{{ t('openconnector', 'Date') }}:</label>
					<input id="date"
						v-model="logStore.logItem.date"
						type="date"
						required>
				</div>
			</form>

			<NcButton
				v-if="!success"
				:disabled="loading"
				type="primary"
				@click="editLog()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSaveOutline v-if="!loading" :size="20" />
				</template>
				{{ t('openconnector', 'Save') }}
			</NcButton>
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
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'

export default {
	name: 'EditLog',
	components: {
		NcModal,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		ContentSaveOutline,
	},
	data() {
		return {
			success: false,
			loading: false,
			error: false,
			closeTimeoutFunc: null,
		}
	},
	methods: {
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeTimeoutFunc)
			this.success = null
		},
		async editLog() {
			this.loading = true
			try {
				await logStore.saveLog()
				// Close modal or show success message
				this.success = true
				this.loading = false
				this.closeTimeoutFunc = setTimeout(this.closeModal, 2000)
			} catch (error) {
				this.loading = false
				this.success = false
				this.error = error.message || 'An error occurred while saving the log'
			}
		},
	},
}
</script>

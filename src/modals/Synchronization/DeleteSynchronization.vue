<script setup>
import { translate as t } from '@nextcloud/l10n'
import { synchronizationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'deleteSynchronization'"
		:name="t('openconnector', 'Delete synchronization')"
		size="normal"
		:can-close="false">
		<p v-if="!success">
			{{ t('openconnector', 'Do you want to delete') }} <b>{{ synchronizationStore.synchronizationItem.name }}</b>? {{ t('openconnector', 'This action cannot be undone.') }}
		</p>

		<NcNoteCard v-if="success" type="success">
			<p>{{ t('openconnector', 'Successfully deleted synchronization') }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton
				@click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? t('openconnector', 'Close') : t('openconnector', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!success"
				:disabled="loading"
				type="error"
				@click="deleteSynchronization()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<TrashCanOutline v-if="!loading" :size="20" />
				</template>
				{{ t('openconnector', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'DeleteSynchronization',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		TrashCanOutline,
		Cancel,
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
			navigationStore.setDialog(false)
			clearTimeout(this.closeTimeoutFunc)
			this.success = null
		},
		async deleteSynchronization() {
			this.loading = true
			try {
				await synchronizationStore.deleteSynchronization(synchronizationStore.synchronizationItem.id)
				// Close modal or show success message
				this.success = true
				this.loading = false
				this.error = false
				synchronizationStore.setSynchronizationItem(null)
				this.closeTimeoutFunc = setTimeout(this.closeModal, 2000)
			} catch (error) {
				this.loading = false
				this.success = false
				this.error = error.message || 'An error occurred while deleting the synchronization'
			}
		},
	},
}
</script>

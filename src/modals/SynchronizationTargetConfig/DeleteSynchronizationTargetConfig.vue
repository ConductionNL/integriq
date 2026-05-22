<script setup>
import { navigationStore, synchronizationStore } from '../../store/store.js'
import { Synchronization } from '../../entities/index.js'
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<NcDialog :name="t('openconnector', 'Delete Target Config')"
		:can-close="false">
		<div v-if="success !== null || error">
			<NcNoteCard v-if="success" type="success">
				<p>{{ t('openconnector', 'Target config deleted successfully') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="!success" type="error">
				<p>{{ t('openconnector', 'Something went wrong deleting the target config') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>
		</div>
		<p v-if="success === null">
			{{ t('openconnector', 'Do you want to delete') }} <b>{{ synchronizationStore.synchronizationTargetConfigKey }}</b>?
		</p>
		<template #actions>
			<NcButton :disabled="loading" @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success !== null ? t('openconnector', 'Close') : t('openconnector', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="success === null"
				:disabled="loading"
				icon="Delete"
				type="error"
				@click="deleteTargetConfig()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Delete v-if="!loading" :size="20" />
				</template>
				{{ t('openconnector', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'DeleteSynchronizationTargetConfig',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
		// Icons
		Cancel,
		Delete,
	},
	data() {
		return {
			loading: false,
			success: null,
			error: false,
			closeTimeoutFunc: null,
		}
	},
	methods: {
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeTimeoutFunc)
			synchronizationStore.setSynchronizationTargetConfigKey(null)
		},
		deleteTargetConfig() {
			this.loading = true

			const targetConfigClone = { ...synchronizationStore.synchronizationItem.targetConfig }

			if (synchronizationStore.synchronizationTargetConfigKey in targetConfigClone) {
				delete targetConfigClone[synchronizationStore.synchronizationTargetConfigKey]
			} else {
				this.error = 'Target config not found'
				this.loading = false
				return
			}

			const synchronizationItem = new Synchronization({
				...synchronizationStore.synchronizationItem,
				targetConfig: targetConfigClone,
			})

			synchronizationStore.saveSynchronization(synchronizationItem)
				.then(({ response }) => {
					this.success = response.ok

					// Wait for the user to read the feedback then close the model
					this.closeTimeoutFunc = setTimeout(this.closeModal, 2000)
				})
				.catch((err) => {
					this.error = err
				})
				.finally(() => {
					this.loading = false
				})
		},
	},
}
</script>

<style scoped>
.zaakDetailsContainer {
	margin-block-start: var(--OC-margin-20);
	margin-inline-start: var(--OC-margin-20);
	margin-inline-end: var(--OC-margin-20);
}

.success {
	color: green;
}
</style>

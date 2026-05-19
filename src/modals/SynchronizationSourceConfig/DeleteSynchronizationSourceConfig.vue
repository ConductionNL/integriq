<script setup>
import { navigationStore, synchronizationStore } from '../../store/store.js'
import { Synchronization } from '../../entities/index.js'
</script>

<template>
	<NcDialog name="Delete Source Config"
		:can-close="false">
		<div v-if="success !== null || error">
			<NcNoteCard v-if="success" type="success">
				<p>Successfully deleted source config</p>
			</NcNoteCard>
			<NcNoteCard v-if="!success" type="error">
				<p>Something went wrong deleting the source config</p>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>
		</div>
		<p v-if="success === null">
			Do you want to delete <b>{{ synchronizationStore.synchronizationSourceConfigKey }}</b>?
		</p>
		<template #actions>
			<NcButton :disabled="loading" @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success !== null ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton
				v-if="success === null"
				:disabled="loading"
				icon="Delete"
				type="error"
				@click="deleteSourceConfig()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Delete v-if="!loading" :size="20" />
				</template>
				Delete
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'DeleteSynchronizationSourceConfig',
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
			synchronizationStore.setSynchronizationSourceConfigKey(null)
		},
		deleteSourceConfig() {
			this.loading = true

			const synchronizationItemClone = synchronizationStore.synchronizationItem.cloneRaw()

			const sourceConfigClone = { ...synchronizationItemClone.sourceConfig }

			if (synchronizationStore.synchronizationSourceConfigKey in sourceConfigClone) {
				delete sourceConfigClone[synchronizationStore.synchronizationSourceConfigKey]
			} else {
				this.error = 'Source config not found'
				this.loading = false
				return
			}

			const synchronizationItem = new Synchronization({
				...synchronizationItemClone,
				sourceConfig: sourceConfigClone,
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

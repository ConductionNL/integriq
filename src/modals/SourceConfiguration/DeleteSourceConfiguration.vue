<script setup>
import { translate as t } from '@nextcloud/l10n'
import { navigationStore, sourceStore } from '../../store/store.js'
import { Source } from '../../entities/index.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.modal === 'deleteSourceConfiguration'"
		:name="t('openconnector', 'Delete Source Configuration')"
		:can-close="false">
		<div v-if="success !== null || error">
			<NcNoteCard v-if="success" type="success">
				<p>{{ t('openconnector', 'Successfully deleted source configuration') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="!success" type="error">
				<p>{{ t('openconnector', 'Something went wrong deleting the source configuration') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>
		</div>
		<p v-if="success === null">
			{{ t('openconnector', 'Do you want to delete {key}? This action cannot be undone.', { key: sourceStore.sourceConfigurationKey }) }}
		</p>
		<template #actions>
			<NcButton :disabled="loading" icon="" @click="closeModal">
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
				@click="deleteSourceConfiguration()">
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
	name: 'DeleteSourceConfiguration',
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
			this.success = null
		},
		deleteSourceConfiguration() {
			this.loading = true

			const sourceItemClone = sourceStore.item.cloneRaw()
			delete sourceItemClone?.configuration[sourceStore.sourceConfigurationKey]

			const sourceItem = new Source(sourceItemClone)

			sourceStore.save(sourceItem)
				.then(() => {
					this.loading = false
					this.success = true

					// Wait for the user to read the feedback then close the model
					this.closeTimeoutFunc = setTimeout(this.closeModal, 2000)
				})
				.catch((err) => {
					this.error = err
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

<script setup>
import { navigationStore, jobStore } from '../../store/store.js'
import { Job } from '../../entities/index.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.modal === 'deleteJobArgument'"
		name="Delete Job Argument"
		:can-close="false">
		<div v-if="success !== null || error">
			<NcNoteCard v-if="success" type="success">
				<p>Successfully deleted job argument</p>
			</NcNoteCard>
			<NcNoteCard v-if="!success" type="error">
				<p>Something went wrong deleting the job argument</p>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>
		</div>
		<p v-if="success === null">
			Do you want to delete <b>{{ jobStore.argumentKey }}</b>? This action cannot be undone.
		</p>
		<template #actions>
			<NcButton :disabled="loading" icon="" @click="closeModal">
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
				@click="deleteJobArgument()">
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
	name: 'DeleteJobArgument',
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
		deleteJobArgument() {
			this.loading = true

			const jobItemClone = jobStore.item.cloneRaw()
			delete jobItemClone?.arguments[jobStore.argumentKey]

			const scheduleAfter = jobItemClone.scheduleAfter ? new Date(jobItemClone.scheduleAfter.date) : null

			const jobItem = new Job({
				...jobItemClone,
				scheduleAfter,
			})

			jobStore.save(jobItem)
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

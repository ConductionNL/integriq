<script setup>
import { sourceStore, navigationStore } from '../../store/store.js'
import { Source } from '../../entities/index.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'editSource'"
		ref="modalRef"
		label-id="editSource"
		@close="closeModal">
		<div class="modalContent">
			<h2>{{ sourceItem?.id ? 'Edit' : 'Add' }} Source</h2>
			<NcNoteCard v-if="success" type="success">
				<p>Source successfully added</p>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>

			<form v-if="!success" @submit.prevent="handleSubmit">
				<div class="form-group">
					<NcTextField
						id="name"
						label="Name*"
						:value.sync="sourceItem.name" />

					<NcTextArea
						id="description"
						resize="vertical"
						label="Description"
						:value.sync="sourceItem.description" />

					<NcSelect
						id="type"
						v-bind="typeOptions"
						v-model="typeOptions.value" />

					<NcTextField
						id="location"
						label="location*"
						:value.sync="sourceItem.location"
						:helper-text="`The location of the source will never end on a /. If a / is added it will be removed on saving.`" />
				</div>
			</form>
			<div class="modal-actions">
				<NcButton
					v-if="!success"
					@click="closeModal">
					<template #icon>
						<CancelIcon size="20" />
					</template>
					Cancel
				</NcButton>
				<NcButton
					v-if="!success"
					:disabled="loading || !sourceItem.name || !sourceItem.location || !typeOptions.value"
					type="primary"
					@click="editSource()">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<ContentSaveOutline v-if="!loading" :size="20" />
					</template>
					Save
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcModal,
	NcSelect,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
	NcTextArea,
} from '@nextcloud/vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'

export default {
	name: 'EditSource',
	components: {
		NcModal,
		NcButton,
		NcSelect,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		NcTextArea,
		CancelIcon,
	},
	data() {
		return {
			sourceItem: {
				name: '',
				description: '',
				location: '',
			},
			success: false,
			loading: false,
			error: false,
			typeOptions: {
				inputLabel: 'Type*',
				options: [
					{ label: 'Database', id: 'database' },
					{ label: 'API', id: 'api' },
					{ label: 'File', id: 'file' },
					{ label: 'SOAP', id: 'soap' },
				],

			},
			hasUpdated: false,
			closeTimeoutFunc: null,
		}
	},
	mounted() {
		this.initializeSourceItem()
	},
	updated() {
		if (navigationStore.modal === 'editSource' && !this.hasUpdated) {
			this.initializeSourceItem()
			this.hasUpdated = true
		}
	},
	methods: {
		initializeSourceItem() {
			if (sourceStore.item?.id) {
				this.sourceItem = {
					...sourceStore.item.cloneRaw(),
					name: sourceStore.item.name || '',
					description: sourceStore.item.description || '',
					location: sourceStore.item.location || '',
				}

				const selectedType = this.typeOptions.options.find((option) => option.id === sourceStore.item.type)

				this.typeOptions = {
					inputLabel: 'Type*',
					options: [
						{ label: 'Database', id: 'database' },
						{ label: 'API', id: 'api' },
						{ label: 'File', id: 'file' },
						{ label: 'SOAP', id: 'soap' },
					],
					value: selectedType
						? {
							label: selectedType.label,
							id: selectedType.id,
						}
						: null,

				}
			}
		},
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeTimeoutFunc)
			this.success = false
			this.loading = false
			this.error = false
			this.hasUpdated = false
			this.sourceItem = {
				name: '',
				description: '',
				location: '',
			}
			this.typeOptions = {
				inputLabel: 'Type*',
				options: [
					{ label: 'Database', id: 'database' },
					{ label: 'API', id: 'api' },
					{ label: 'File', id: 'file' },
					{ label: 'SOAP', id: 'soap' },
				],

			}
		},
		async editSource() {
			this.loading = true
			try {
				const sourceItem = new Source({ ...this.sourceItem, type: this.typeOptions.value.id })

				await sourceStore.save(sourceItem)
				// Close modal or show success message
				this.success = true
				this.loading = false
				this.closeTimeoutFunc = setTimeout(this.closeModal, 2000)
			} catch (error) {
				this.loading = false
				this.success = false
				this.error = error.message || 'An error occurred while saving the source'
			}
		},
	},
}
</script>

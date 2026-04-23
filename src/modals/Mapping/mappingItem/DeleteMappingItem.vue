<script setup>
import { navigationStore, mappingStore } from '../../../store/store.js'
import { Mapping } from '../../../entities/index.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.dialog === 'deleteMappingItem'"
		:name="dialogTitle"
		:can-close="true"
		@close="closeDialog"
		@cancel="closeDialog"
		@update:open="val => { if (!val) closeDialog() }">
		<div v-if="success !== null || error">
			<NcNoteCard v-if="success" type="success">
				<p>Successfully deleted {{ modalLabel.toLowerCase() }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="!success" type="error">
				<p>Something went wrong deleting the {{ modalLabel.toLowerCase() }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>
		</div>
		<p v-if="success === null">
			Do you want to delete <b>{{ currentKey }}</b>? This action cannot be undone.
		</p>
		<template #actions>
			<NcButton :disabled="loading" icon="" @click="closeDialog">
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
				@click="performDelete()">
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
	name: 'DeleteMappingItem',
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
	computed: {
		itemType() {
			return mappingStore.editingMode
		},
		modalLabel() {
			if (this.itemType === 'cast') return 'Cast'
			if (this.itemType === 'mapping') return 'Mapping'
			if (this.itemType === 'unset') return 'Mapping Unset'
			return 'Mapping Item'
		},
		dialogTitle() {
			return `Delete ${this.modalLabel}`
		},
		currentKey() {
			if (this.itemType === 'cast') return mappingStore.mappingCastKey
			if (this.itemType === 'mapping') return mappingStore.mappingMappingKey
			if (this.itemType === 'unset') return mappingStore.mappingUnsetKey
			return ''
		},
	},
	methods: {
		closeDialog() {
			navigationStore.setDialog(false)
			clearTimeout(this.closeTimeoutFunc)
			this.success = null
			if (this.itemType === 'unset') {
				mappingStore.setMappingUnsetKey(null)
			} else if (this.itemType === 'cast') {
				mappingStore.setMappingCastKey(null)
			} else if (this.itemType === 'mapping') {
				mappingStore.setMappingMappingKey(null)
			}
			// clear editing context
			mappingStore.clearEditingContext && mappingStore.clearEditingContext()
		},
		performDelete() {
			this.loading = true

			try {
				const keyToDelete = this.currentKey
				if (!keyToDelete) {
					this.error = 'No key selected to delete'
					this.loading = false
					return
				}

				let newMappingRaw = { ...mappingStore.item }

				if (this.itemType === 'unset') {
					const cloneUnset = Array.isArray(mappingStore.item?.unset) ? [...mappingStore.item.unset] : []
					const index = cloneUnset.indexOf(keyToDelete)
					if (index > -1) {
						cloneUnset.splice(index, 1)
					} else {
						this.error = 'Mapping unset not found'
						this.loading = false
						return
					}
					newMappingRaw = {
						...newMappingRaw,
						unset: cloneUnset,
					}
				} else {
					const prop = this.itemType
					const clonedContainer = { ...(mappingStore.item?.[prop] || {}) }
					if (Object.prototype.hasOwnProperty.call(clonedContainer, keyToDelete)) {
						delete clonedContainer[keyToDelete]
					}
					newMappingRaw = {
						...newMappingRaw,
						[prop]: clonedContainer,
					}
				}

				const mappingItem = new Mapping(newMappingRaw)
				mappingStore.save(mappingItem)
					.then(({ response }) => {
						this.loading = false
						this.success = response?.ok ?? true
						// refresh mapping state
						const refreshId = mappingStore.editingMappingId || mappingStore.item?.id
						if (refreshId) {
							mappingStore.getOne(refreshId)
						}
						this.closeTimeoutFunc = setTimeout(this.closeDialog, 2000)
					})
					.catch((err) => {
						this.error = err?.message || err
						this.loading = false
					})
			} catch (e) {
				this.error = e?.message || 'Error while deleting mapping item'
				this.loading = false
			}
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

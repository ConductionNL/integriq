<script setup>
import { mappingStore, navigationStore } from '../../../store/store.js'
import { Mapping } from '../../../entities/index.js'
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<NcDialog
		v-if="navigationStore.dialog === 'editMappingItem'"
		ref="modalRef"
		label-id="editMappingItem"
		:can-close="true"
		:name="(isEdit ? t('openconnector', 'Edit') + ' ' : t('openconnector', 'Add') + ' ') + modalLabel"
		@close="closeDialog"
		@cancel="closeDialog"
		@update:open="val => { if (!val) closeDialog() }">
		<div class="modalContent">
			<NcNoteCard v-if="success" type="success">
				<p>{{ modalLabel }} {{ isEdit ? t('openconnector', 'updated successfully') : t('openconnector', 'added successfully') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>

			<form v-if="!success" @submit.prevent>
				<div class="form-group">
					<NcTextField
						id="key"
						:label="keyLabel"
						required
						:error="isKeyTaken(keyInput)"
						:helper-text="isKeyTaken(keyInput) ? t('openconnector', 'This key is already in use. Please choose a different key name.') : ''"
						:value.sync="keyInput" />
					<NcTextField
						v-if="showsValueField"
						id="value"
						:label="t('openconnector', 'Value')"
						:value.sync="valueInput" />
				</div>
			</form>

			<div class="modal-actions">
				<NcButton v-if="!success"
					@click="closeDialog">
					<template #icon>
						<CancelIcon :size="20" />
					</template>
					{{ t('openconnector', 'Cancel') }}
				</NcButton>
				<NcButton
					v-if="!success"
					:disabled="isSaveDisabled"
					type="primary"
					@click="save()">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<ContentSaveOutline v-if="!loading" :size="20" />
					</template>
					{{ t('openconnector', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'

export default {
	name: 'EditMappingItem',
	components: {
		NcDialog,
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
			keyInput: '',
			valueInput: '',
			success: false,
			loading: false,
			error: false,
			hasUpdated: false,
			oldKey: '',
			isEdit: false,
			closeTimeoutFunc: null,
			itemType: '', // 'cast' | 'mapping' | 'unset'
		}
	},
	computed: {
		showsValueField() {
			return this.itemType === 'cast' || this.itemType === 'mapping'
		},
		keyLabel() {
			return this.itemType === 'unset' ? 'Unset Key*' : 'Key*'
		},
		modalLabel() {
			if (this.itemType === 'cast') return 'Cast'
			if (this.itemType === 'mapping') return 'Mapping'
			if (this.itemType === 'unset') return 'Mapping Unset'
			return 'Mapping Item'
		},
		isSaveDisabled() {
			if (this.loading) return true
			if (!this.keyInput) return true
			if (this.isKeyTaken(this.keyInput)) return true
			if (this.itemType === 'unset' && mappingStore.mappingUnsetKey === this.keyInput) return true
			return false
		},
	},
	mounted() {
		this.initialize()
	},
	updated() {
		if (navigationStore.dialog === 'editMappingItem' && !this.hasUpdated) {
			this.initialize()
			this.hasUpdated = true
		}
	},
	methods: {
		getItemTypeFromModal() {
			return mappingStore.getEditingMode()
		},
		initialize() {
			this.itemType = this.getItemTypeFromModal()

			// reset defaults
			this.keyInput = ''
			this.valueInput = ''
			this.oldKey = ''
			this.isEdit = false

			if (!mappingStore.mappingItem) return

			if (this.itemType === 'cast') {
				if (!mappingStore.mappingCastKey) return
				const castEntry = Object.entries(mappingStore.mappingItem.cast || {}).find(([key]) => key === mappingStore.mappingCastKey)
				if (castEntry) {
					this.keyInput = castEntry[0] || ''
					this.valueInput = castEntry[1] || ''
					this.oldKey = castEntry[0]
					this.isEdit = true
				}
			} else if (this.itemType === 'mapping') {
				if (!mappingStore.mappingMappingKey) return
				const mappingEntry = Object.entries(mappingStore.mappingItem.mapping || {}).find(([key]) => key === mappingStore.mappingMappingKey)
				if (mappingEntry) {
					this.keyInput = mappingEntry[0] || ''
					this.valueInput = mappingEntry[1] || ''
					this.oldKey = mappingEntry[0]
					this.isEdit = true
				}
			} else if (this.itemType === 'unset') {
				if (mappingStore.mappingUnsetKey) {
					this.keyInput = mappingStore.mappingUnsetKey
					this.oldKey = mappingStore.mappingUnsetKey
					this.isEdit = true
				}
			}
		},
		isKeyTaken(key) {
			if (!key) return false
			if (!mappingStore.mappingItem) return false

			if (this.itemType === 'unset') {
				const allKeys = mappingStore.mappingItem?.unset || []
				if (this.oldKey === key) return false
				return allKeys.includes(key)
			}

			const container = (mappingStore.mappingItem && mappingStore.mappingItem[this.itemType]) || {}
			if (this.oldKey === key) return false
			return Object.prototype.hasOwnProperty.call(container, key)
		},
		closeDialog() {
			navigationStore.setDialog(false)
			clearTimeout(this.closeTimeoutFunc)
			this.success = false
			this.loading = false
			this.error = false
			this.hasUpdated = false
			this.isEdit = false
			this.oldKey = ''
			this.keyInput = ''
			this.valueInput = ''
			// clear any selected keys to avoid stale state
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
		async save() {
			this.loading = true

			try {
				// Ensure mapping has a valid name to avoid backend slug errors
				const active = mappingStore.mappingItem
				if (!active?.name || typeof active.name !== 'string' || !active.name.trim()) {
					throw new Error('Please set a valid mapping name before adding items')
				}

				let newMappingRaw = { ...mappingStore.mappingItem }

				if (this.itemType === 'unset') {
					const currentUnset = Array.isArray(mappingStore.mappingItem?.unset) ? [...mappingStore.mappingItem.unset] : []
					if (this.isEdit && this.oldKey) {
						const index = currentUnset.indexOf(this.oldKey)
						if (index > -1) {
							currentUnset.splice(index, 1)
						}
					}
					currentUnset.push(this.keyInput)
					const uniqueUnset = [...new Set(currentUnset)]
					newMappingRaw = {
						...newMappingRaw,
						unset: uniqueUnset,
					}
				} else {
					const prop = this.itemType
					const existingObj = (mappingStore.mappingItem && mappingStore.mappingItem[prop]) || {}
					const nextObj = {
						...existingObj,
						[(this.keyInput || '').trim()]: (this.valueInput || '').trim(),
					}
					if (this.oldKey && this.oldKey !== this.keyInput) {
						delete nextObj[this.oldKey]
					}
					newMappingRaw = {
						...newMappingRaw,
						[prop]: nextObj,
					}
				}

				const mappingEntity = new Mapping(newMappingRaw)
				await mappingStore.saveMapping(mappingEntity)

				this.success = true
				this.loading = false
				// refresh active mapping state immediately
				const refreshId = (mappingStore.getEditingMappingId && mappingStore.getEditingMappingId()) || (mappingStore.getMappingItem && mappingStore.getMappingItem()?.id)
				if (refreshId) {
					mappingStore.fetchMapping(refreshId)
				}
				this.closeTimeoutFunc = setTimeout(this.closeDialog, 2000)
			} catch (e) {
				this.loading = false
				this.success = false
				this.error = e?.message || 'An error occurred while saving the mapping'
			}
		},
	},
}
</script>

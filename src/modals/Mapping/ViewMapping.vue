<script setup>
import { translate as t } from '@nextcloud/l10n'
import { mappingStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<Fragment>
		<NcModal v-if="navigationStore.modal === 'viewMapping'"
			ref="modalRef"
			:name="mappingStore.item?.name || t('openconnector', 'Mapping Details')"
			@close="navigationStore.setModal(false)">
			<div class="modal-content">
				<p v-if="mappingStore.item?.description" class="mapping-description">
					{{ mappingStore.item.description }}
				</p>

				<!-- Mapping Properties -->
				<div class="mapping-properties">
					<table class="statisticsTable mappingStats">
						<thead>
							<tr>
								<th>{{ t('openconnector', 'Property') }}</th>
								<th>{{ t('openconnector', 'Value') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td>{{ t('openconnector', 'ID') }}</td>
								<td>{{ mappingStore.item?.id || '-' }}</td>
							</tr>
							<tr>
								<td>{{ t('openconnector', 'UUID') }}</td>
								<td>{{ mappingStore.item?.uuid || '-' }}</td>
							</tr>
							<tr>
								<td>{{ t('openconnector', 'Reference') }}</td>
								<td>{{ mappingStore.item?.reference || '-' }}</td>
							</tr>
							<tr>
								<td>{{ t('openconnector', 'Version') }}</td>
								<td>{{ mappingStore.item?.version || '-' }}</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Tabs -->
				<div class="tabContainer">
					<BTabs content-class="mt-3" justified>
						<BTab :title="t('openconnector', 'Mapping')">
							<div v-if="mappingStore.item?.mapping !== null && Object.keys(mappingStore.item?.mapping || {}).length" class="mapping-list">
								<NcListItem v-for="(value, key, i) in mappingStore.item?.mapping"
									:key="`${key}${i}`"
									:name="key"
									:bold="false"
									:force-display-actions="true"
									:active="mappingStore.mappingMappingKey === key"
									@click="setActiveMappingMappingKey(key)">
									<template #icon>
										<SitemapOutline :class="mappingStore.mappingMappingKey === key && 'selectedIcon'" :size="44" />
									</template>
									<template #subname>
										{{ value }}
									</template>
									<template #actions>
										<NcActionButton close-after-click @click="editMappingMapping(key)">
											<template #icon>
												<Pencil :size="20" />
											</template>
											{{ t('openconnector', 'Edit') }}
										</NcActionButton>
										<NcActionButton close-after-click @click="deleteMappingMapping(key)">
											<template #icon>
												<Delete :size="20" />
											</template>
											{{ t('openconnector', 'Delete') }}
										</NcActionButton>
									</template>
								</NcListItem>
							</div>
							<div v-if="!Object.keys(mappingStore.item?.mapping || {}).length" class="tabPanel">
								<NcEmptyContent
									:name="t('openconnector', 'No mapping')"
									:description="t('openconnector', 'No mapping found for this mapping')">
									<template #icon>
										<SitemapOutline :size="64" />
									</template>
									<template #action>
										<NcButton @click="addMappingMapping">
											{{ t('openconnector', 'Add Mapping') }}
										</NcButton>
									</template>
								</NcEmptyContent>
							</div>
						</BTab>
						<BTab :title="t('openconnector', 'Cast')">
							<div v-if="mappingStore.item?.cast !== null && Object.keys(mappingStore.item?.cast || {}).length" class="cast-list">
								<NcListItem v-for="(value, key, i) in mappingStore.item?.cast"
									:key="`${key}${i}`"
									:name="key"
									:bold="false"
									:force-display-actions="true"
									:active="mappingStore.mappingCastKey === key"
									@click="setActiveMappingCastKey(key)">
									<template #icon>
										<SwapHorizontal :class="mappingStore.mappingCastKey === key && 'selectedIcon'" :size="44" />
									</template>
									<template #subname>
										{{ value }}
									</template>
									<template #actions>
										<NcActionButton close-after-click @click="editMappingCast(key)">
											<template #icon>
												<Pencil :size="20" />
											</template>
											{{ t('openconnector', 'Edit') }}
										</NcActionButton>
										<NcActionButton close-after-click @click="deleteMappingCast(key)">
											<template #icon>
												<Delete :size="20" />
											</template>
											{{ t('openconnector', 'Delete') }}
										</NcActionButton>
									</template>
								</NcListItem>
							</div>
							<div v-if="!Object.keys(mappingStore.item?.cast || {}).length" class="tabPanel">
								<NcEmptyContent
									:name="t('openconnector', 'No cast')"
									:description="t('openconnector', 'No cast found for this mapping')">
									<template #icon>
										<SwapHorizontal :size="64" />
									</template>
									<template #action>
										<NcButton @click="addMappingCast">
											{{ t('openconnector', 'Add Cast') }}
										</NcButton>
									</template>
								</NcEmptyContent>
							</div>
						</BTab>
						<BTab :title="t('openconnector', 'Unset')">
							<div v-if="mappingStore.item?.unset?.length" class="unset-list">
								<NcListItem v-for="(value, i) in mappingStore.item?.unset"
									:key="`${value}${i}`"
									:name="value"
									:bold="false"
									:force-display-actions="true">
									<template #icon>
										<Eraser :class="mappingStore.mappingUnsetKey === value && 'selectedIcon'" :size="44" />
									</template>
									<template #actions>
										<NcActionButton close-after-click @click="editMappingUnset(value)">
											<template #icon>
												<Pencil :size="20" />
											</template>
											{{ t('openconnector', 'Edit') }}
										</NcActionButton>
										<NcActionButton close-after-click @click="deleteMappingUnset(value)">
											<template #icon>
												<Delete :size="20" />
											</template>
											{{ t('openconnector', 'Delete') }}
										</NcActionButton>
									</template>
								</NcListItem>
							</div>
							<div v-if="!mappingStore.item?.unset?.length" class="tabPanel">
								<NcEmptyContent
									:name="t('openconnector', 'No unset')"
									:description="t('openconnector', 'No unset found for this mapping')">
									<template #icon>
										<Eraser :size="64" />
									</template>
									<template #action>
										<NcButton @click="addMappingUnset">
											{{ t('openconnector', 'Add Unset') }}
										</NcButton>
									</template>
								</NcEmptyContent>
							</div>
						</BTab>
					</BTabs>
				</div>

				<!-- Action buttons -->
				<div class="modal-actions">
					<NcButton @click="navigationStore.setModal('editMapping')">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('openconnector', 'Edit') }}
					</NcButton>
					<NcButton @click="addMappingMapping()">
						<template #icon>
							<MapPlus :size="20" />
						</template>
						{{ t('openconnector', 'Add Mapping') }}
					</NcButton>
					<NcButton @click="addMappingCast()">
						<template #icon>
							<SwapHorizontal :size="20" />
						</template>
						{{ t('openconnector', 'Add Cast') }}
					</NcButton>
					<NcButton @click="addMappingUnset()">
						<template #icon>
							<Eraser :size="20" />
						</template>
						{{ t('openconnector', 'Add Unset') }}
					</NcButton>
					<NcButton @click="navigationStore.setModal('testMapping')">
						<template #icon>
							<TestTube :size="20" />
						</template>
						{{ t('openconnector', 'Test') }}
					</NcButton>
					<NcButton type="error" @click="showDeleteDialog = true">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						{{ t('openconnector', 'Delete') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<CnDeleteDialog
			v-if="showDeleteDialog && mappingStore.item"
			ref="deleteDialog"
			:item="mappingStore.item"
			name-field="name"
			:dialog-title="t('openconnector', 'Delete mapping')"
			:success-text="t('openconnector', 'Successfully deleted mapping')"
			@confirm="onDeleteConfirm"
			@close="showDeleteDialog = false" />
	</Fragment>
</template>

<script>
import { NcModal, NcButton, NcListItem, NcActionButton, NcEmptyContent } from '@nextcloud/vue'
import { CnDeleteDialog } from '@conduction/nextcloud-vue'
import { BTabs, BTab } from 'bootstrap-vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import MapPlus from 'vue-material-design-icons/MapPlus.vue'
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import TestTube from 'vue-material-design-icons/TestTube.vue'
import Eraser from 'vue-material-design-icons/Eraser.vue'

export default {
	name: 'ViewMapping',
	components: {
		NcModal,
		NcButton,
		NcListItem,
		NcActionButton,
		NcEmptyContent,
		CnDeleteDialog,
		BTabs,
		BTab,
		Pencil,
		MapPlus,
		SitemapOutline,
		SwapHorizontal,
		TrashCanOutline,
		Delete,
		TestTube,
		Eraser,
	},
	data() {
		return {
			showDeleteDialog: false,
		}
	},
	methods: {
		async onDeleteConfirm() {
			try {
				await mappingStore.deleteOne(mappingStore.item)
				this.$refs.deleteDialog.setResult({ success: true })
				this.showDeleteDialog = false
				navigationStore.setModal(false)
			} catch (e) {
				this.$refs.deleteDialog.setResult({
					error: e.message || t('openconnector', 'An error occurred while deleting the mapping'),
				})
			}
		},
		/**
		 * Delete mapping mapping
		 * @param {string} key - The mapping key to delete
		 */
		deleteMappingMapping(key) {
			mappingStore.setEditingMode('mapping')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingMappingKey(key)
			navigationStore.setDialog('deleteMappingItem')
		},
		/**
		 * Edit mapping mapping
		 * @param {string} key - The mapping key to edit
		 */
		editMappingMapping(key) {
			mappingStore.setEditingMode('mapping')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingMappingKey(key)
			navigationStore.setDialog('editMappingItem')
		},
		/**
		 * Add mapping mapping
		 */
		addMappingMapping() {
			mappingStore.setEditingMode('mapping')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingMappingKey(null)
			navigationStore.setDialog('editMappingItem')
		},
		/**
		 * Set active mapping mapping key
		 * @param {string} mappingMappingKey - The mapping key to set as active
		 */
		setActiveMappingMappingKey(mappingMappingKey) {
			if (mappingStore.mappingMappingKey === mappingMappingKey) {
				mappingStore.setMappingMappingKey(false)
			} else {
				mappingStore.setMappingMappingKey(mappingMappingKey)
			}
		},
		/**
		 * Delete mapping cast
		 * @param {string} key - The cast key to delete
		 */
		deleteMappingCast(key) {
			mappingStore.setEditingMode('cast')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingCastKey(key)
			navigationStore.setDialog('deleteMappingItem')
		},
		/**
		 * Edit mapping cast
		 * @param {string} key - The cast key to edit
		 */
		editMappingCast(key) {
			mappingStore.setEditingMode('cast')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingCastKey(key)
			navigationStore.setDialog('editMappingItem')
		},
		/**
		 * Add mapping cast
		 */
		addMappingCast() {
			mappingStore.setEditingMode('cast')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingCastKey(null)
			navigationStore.setDialog('editMappingItem')
		},
		/**
		 * Set active mapping cast key
		 * @param {string} mappingCastKey - The cast key to set as active
		 */
		setActiveMappingCastKey(mappingCastKey) {
			if (mappingStore.mappingCastKey === mappingCastKey) {
				mappingStore.setMappingCastKey(false)
			} else {
				mappingStore.setMappingCastKey(mappingCastKey)
			}
		},
		/**
		 * Edit mapping unset
		 * @param {string} value - The unset value to edit
		 */
		editMappingUnset(value) {
			mappingStore.setEditingMode('unset')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingUnsetKey(value)
			navigationStore.setDialog('editMappingItem')
		},
		/**
		 * Delete mapping unset
		 * @param {string} value - The unset value to delete
		 */
		deleteMappingUnset(value) {
			mappingStore.setEditingMode('unset')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingUnsetKey(value)
			navigationStore.setDialog('deleteMappingItem')
		},
		/**
		 * Add mapping unset
		 */
		addMappingUnset() {
			mappingStore.setEditingMode('unset')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingUnsetKey(null)
			navigationStore.setDialog('editMappingItem')
		},
	},
}
</script>

<style scoped>
.modal-content {
	padding: 20px;
	max-width: 800px;
	max-height: 80vh;
	overflow-y: auto;
}

.mapping-description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 20px;
	font-style: italic;
}

.mapping-properties {
	margin-bottom: 20px;
}

.tabPanel {
	padding: 15px 0;
}

.selectedIcon {
	color: var(--color-primary);
}
</style>

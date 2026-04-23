<script setup>
import { mappingStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<NcEmptyContent v-if="loading"
			class="detailContainer"
			name="Loading..."
			description="Fetching mapping details">
			<template #icon>
				<NcLoadingIcon />
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="loadError"
			class="detailContainer"
			name="Error"
			description="Failed to load mapping.">
			<template #icon>
				<SitemapOutline />
			</template>
			<template #action>
				<NcButton type="secondary" @click="backToList">
					Back
				</NcButton>
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="!mapping"
			class="detailContainer"
			name="Mapping not found">
			<template #icon>
				<SitemapOutline />
			</template>
			<template #action>
				<NcButton type="secondary" @click="backToList">
					Back
				</NcButton>
			</template>
		</NcEmptyContent>
		<div v-else class="detailContainer">
			<div class="detailHeader">
				<NcButton type="tertiary" aria-label="Back to mappings" @click="backToList">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
					Mappings
				</NcButton>
				<h1 class="h1">
					{{ mapping?.name || '-' }}
				</h1>
				<NcActions :primary="true" menu-name="Actions">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton close-after-click @click="editMapping">
						<template #icon>
							<Pencil :size="20" />
						</template>
						Edit
					</NcActionButton>
					<NcActionButton close-after-click @click="addMappingMapping">
						<template #icon>
							<MapPlus :size="20" />
						</template>
						Add Mapping
					</NcActionButton>
					<NcActionButton close-after-click @click="addMappingCast">
						<template #icon>
							<SwapHorizontal :size="20" />
						</template>
						Add Cast
					</NcActionButton>
					<NcActionButton close-after-click @click="addMappingUnset">
						<template #icon>
							<Eraser :size="20" />
						</template>
						Add Unset
					</NcActionButton>
					<NcActionButton close-after-click @click="navigationStore.setModal('testMapping')">
						<template #icon>
							<TestTube :size="20" />
						</template>
						Test
					</NcActionButton>
					<NcActionButton close-after-click @click="mappingStore.exportMapping(mapping.id)">
						<template #icon>
							<FileExportOutline :size="20" />
						</template>
						Export mapping
					</NcActionButton>
					<NcActionButton close-after-click @click="showDeleteDialog = true">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						Delete
					</NcActionButton>
				</NcActions>
			</div>

			<table class="statisticsTable mappingStats">
				<thead>
					<tr>
						<th>Property</th>
						<th>Value</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>ID</td>
						<td>{{ mapping?.id || '-' }}</td>
					</tr>
					<tr>
						<td>UUID</td>
						<td>{{ mapping?.uuid || '-' }}</td>
					</tr>
					<tr>
						<td>Name</td>
						<td>{{ mapping?.name || '-' }}</td>
					</tr>
					<tr>
						<td>Description</td>
						<td>{{ mapping?.description || '-' }}</td>
					</tr>
					<tr>
						<td>Reference</td>
						<td>{{ mapping?.reference || '-' }}</td>
					</tr>
					<tr>
						<td>Version</td>
						<td>{{ mapping?.version || '-' }}</td>
					</tr>
					<tr>
						<td>Pass through</td>
						<td>{{ mapping?.passThrough ? 'Yes' : 'No' }}</td>
					</tr>
				</tbody>
			</table>

			<div class="tabContainer">
				<BTabs content-class="mt-3" justified>
					<BTab title="Mapping">
						<div class="tabButtonsContainer">
							<NcButton type="primary"
								class="fullWidthButton"
								aria-label="Add Mapping"
								@click="addMappingMapping">
								<template #icon>
									<Plus :size="20" />
								</template>
								Add Mapping
							</NcButton>
						</div>
						<div v-if="mapping?.mapping !== null && Object.keys(mapping?.mapping || {}).length">
							<NcListItem v-for="(value, key, i) in mapping?.mapping"
								:key="`${key}${i}`"
								:name="key"
								:bold="false"
								:force-display-actions="true"
								:active="mappingStore.mappingMappingKey === key"
								@click="setActiveMappingMappingKey(key)">
								<template #icon>
									<SitemapOutline
										:class="mappingStore.mappingMappingKey === key && 'selectedZaakIcon'"
										disable-menu
										:size="44" />
								</template>
								<template #subname>
									{{ value }}
								</template>
								<template #actions>
									<NcActionButton close-after-click @click="editMappingMapping(key)">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
									<NcActionButton close-after-click @click="deleteMappingMapping(key)">
										<template #icon>
											<Delete :size="20" />
										</template>
										Delete
									</NcActionButton>
								</template>
							</NcListItem>
						</div>
						<div v-if="!Object.keys(mapping?.mapping || {}).length" class="tabPanel">
							No mapping found
						</div>
					</BTab>
					<BTab title="Cast">
						<div class="tabButtonsContainer">
							<NcButton type="primary"
								class="fullWidthButton"
								aria-label="Add Cast"
								@click="addMappingCast">
								<template #icon>
									<Plus :size="20" />
								</template>
								Add Cast
							</NcButton>
						</div>
						<div v-if="mapping?.cast !== null && Object.keys(mapping?.cast || {}).length">
							<NcListItem v-for="(value, key, i) in mapping?.cast"
								:key="`${key}${i}`"
								:name="key"
								:bold="false"
								:force-display-actions="true"
								:active="mappingStore.mappingCastKey === key"
								@click="setActiveMappingCastKey(key)">
								<template #icon>
									<SwapHorizontal
										:class="mappingStore.mappingCastKey === key && 'selectedZaakIcon'"
										disable-menu
										:size="44" />
								</template>
								<template #subname>
									{{ value }}
								</template>
								<template #actions>
									<NcActionButton close-after-click @click="editMappingCast(key)">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
									<NcActionButton close-after-click @click="deleteMappingCast(key)">
										<template #icon>
											<Delete :size="20" />
										</template>
										Delete
									</NcActionButton>
								</template>
							</NcListItem>
						</div>
						<div v-if="!Object.keys(mapping?.cast || {}).length" class="tabPanel">
							No cast found
						</div>
					</BTab>
					<BTab title="Unset">
						<div class="tabButtonsContainer">
							<NcButton type="primary"
								class="fullWidthButton"
								aria-label="Add Unset"
								@click="addMappingUnset">
								<template #icon>
									<Plus :size="20" />
								</template>
								Add Unset
							</NcButton>
						</div>
						<div v-if="mapping?.unset?.length">
							<NcListItem v-for="(value, i) in mapping?.unset"
								:key="`${value}${i}`"
								:name="value"
								:bold="false"
								:force-display-actions="true">
								<template #icon>
									<Eraser
										:class="mappingStore.mappingUnsetKey === value && 'selectedZaakIcon'"
										disable-menu
										:size="44" />
								</template>
								<template #actions>
									<NcActionButton close-after-click @click="editMappingUnset(value)">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
									<NcActionButton close-after-click @click="deleteMappingUnset(value)">
										<template #icon>
											<Delete :size="20" />
										</template>
										Delete
									</NcActionButton>
								</template>
							</NcListItem>
						</div>
						<div v-if="!mapping?.unset?.length" class="tabPanel">
							No unset found
						</div>
					</BTab>
				</BTabs>
			</div>
		</div>

		<CnDeleteDialog
			v-if="showDeleteDialog && mapping"
			ref="deleteDialog"
			:item="mapping"
			name-field="name"
			dialog-title="Delete mapping"
			success-text="Successfully deleted mapping"
			@confirm="onDeleteConfirm"
			@close="showDeleteDialog = false" />
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActions, NcActionButton, NcButton, NcEmptyContent, NcLoadingIcon, NcListItem } from '@nextcloud/vue'
import { CnDeleteDialog } from '@conduction/nextcloud-vue'
import { BTab, BTabs } from 'bootstrap-vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import MapPlus from 'vue-material-design-icons/MapPlus.vue'
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import TestTube from 'vue-material-design-icons/TestTube.vue'
import Eraser from 'vue-material-design-icons/Eraser.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'MappingDetails',
	components: {
		NcAppContent,
		NcActions,
		NcActionButton,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcListItem,
		CnDeleteDialog,
		BTab,
		BTabs,
		ArrowLeft,
		DotsHorizontal,
		Pencil,
		MapPlus,
		SitemapOutline,
		SwapHorizontal,
		TrashCanOutline,
		Delete,
		TestTube,
		Eraser,
		FileExportOutline,
		Plus,
	},
	data() {
		return {
			mapping: null,
			loading: false,
			loadError: false,
			activeLoadId: null,
			showDeleteDialog: false,
		}
	},
	watch: {
		'$route.params.id': {
			immediate: true,
			async handler(newId) {
				await this.loadByRoute(newId)
			},
		},
		'mappingStore.item': {
			handler(newItem) {
				if (newItem && String(newItem.id) === String(this.$route.params.id)) {
					this.mapping = newItem
				}
			},
		},
	},
	methods: {
		async loadByRoute(id) {
			const loadId = Symbol('RaceConditionGuard')
			this.activeLoadId = loadId
			this.loadError = false
			if (!id) {
				this.mapping = null
				mappingStore.setItem(null)
				this.loading = false
				return
			}

			this.loading = true
			try {
				await mappingStore.getOne(String(id))
				if (this.activeLoadId !== loadId) return
				this.mapping = mappingStore.item
			} catch (e) {
				if (this.activeLoadId !== loadId) return
				this.loadError = true
				this.mapping = null
			} finally {
				this.loading = false
			}
		},
		backToList() {
			mappingStore.setItem(null)
			this.loadError = false
			this.$router.push('/mappings')
		},
		editMapping() {
			mappingStore.setItem(this.mapping)
			navigationStore.setModal('editMapping')
		},
		async onDeleteConfirm() {
			try {
				await mappingStore.deleteOne(this.mapping)
				this.$refs.deleteDialog.setResult({ success: true })
				this.$router.push('/mappings')
			} catch (e) {
				this.$refs.deleteDialog.setResult({
					error: e.message || 'An error occurred while deleting the mapping',
				})
			}
		},
		// Mapping tab handlers
		addMappingMapping() {
			mappingStore.setEditingMode('mapping')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingMappingKey(null)
			navigationStore.setDialog('editMappingItem')
		},
		editMappingMapping(key) {
			mappingStore.setEditingMode('mapping')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingMappingKey(key)
			navigationStore.setDialog('editMappingItem')
		},
		deleteMappingMapping(key) {
			mappingStore.setEditingMode('mapping')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingMappingKey(key)
			navigationStore.setDialog('deleteMappingItem')
		},
		setActiveMappingMappingKey(key) {
			if (mappingStore.mappingMappingKey === key) {
				mappingStore.setMappingMappingKey(null)
			} else {
				mappingStore.setMappingMappingKey(key)
			}
		},
		// Cast tab handlers
		addMappingCast() {
			mappingStore.setEditingMode('cast')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingCastKey(null)
			navigationStore.setDialog('editMappingItem')
		},
		editMappingCast(key) {
			mappingStore.setEditingMode('cast')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingCastKey(key)
			navigationStore.setDialog('editMappingItem')
		},
		deleteMappingCast(key) {
			mappingStore.setEditingMode('cast')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingCastKey(key)
			navigationStore.setDialog('deleteMappingItem')
		},
		setActiveMappingCastKey(key) {
			if (mappingStore.mappingCastKey === key) {
				mappingStore.setMappingCastKey(null)
			} else {
				mappingStore.setMappingCastKey(key)
			}
		},
		// Unset tab handlers
		addMappingUnset() {
			mappingStore.setEditingMode('unset')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingUnsetKey(null)
			navigationStore.setDialog('editMappingItem')
		},
		editMappingUnset(value) {
			mappingStore.setEditingMode('unset')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingUnsetKey(value)
			navigationStore.setDialog('editMappingItem')
		},
		deleteMappingUnset(value) {
			mappingStore.setEditingMode('unset')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingUnsetKey(value)
			navigationStore.setDialog('deleteMappingItem')
		},
	},
}
</script>

<style scoped>
.detailContainer {
	padding: 20px;
}

.detailHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 10px;
	margin-bottom: 20px;
}

.detailHeader h1 {
	flex: 1;
	margin: 0;
}

.tabContainer {
	margin-top: 20px;
}

.tabButtonsContainer {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	margin-bottom: 1rem;
}

.fullWidthButton {
	width: 100%;
}

.tabPanel {
	padding: 20px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}
</style>

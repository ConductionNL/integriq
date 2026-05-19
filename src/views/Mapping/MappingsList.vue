<script setup>
import { mappingStore, navigationStore, searchStore } from '../../store/store.js'
</script>

<template>
	<NcAppContentList>
		<ul>
			<div class="listHeader">
				<NcTextField
					:value.sync="searchStore.search"
					:show-trailing-button="searchStore.search !== ''"
					label="Search"
					class="searchField"
					trailing-button-icon="close"
					@trailing-button-click="searchStore.clearSearch()">
					<Magnify :size="20" />
				</NcTextField>
				<NcActions class="listHeaderActions">
					<NcActionButton close-after-click @click="mappingStore.refreshMappingList()">
						<template #icon>
							<Refresh :size="20" />
						</template>
						Refresh
					</NcActionButton>
					<NcActionButton close-after-click @click="mappingStore.setMappingItem({}); navigationStore.setModal('editMapping')">
						<template #icon>
							<Plus :size="20" />
						</template>
						Add mapping
					</NcActionButton>
					<NcActionButton close-after-click @click="navigationStore.setModal('importFile')">
						<template #icon>
							<FileImportOutline :size="20" />
						</template>
						Import
					</NcActionButton>
				</NcActions>
			</div>
			<div v-if="mappingStore.mappingList && mappingStore.mappingList.length > 0">
				<NcListItem v-for="(mapping, i) in mappingStore.mappingList.filter(mapping => searchStore.search === '' || mapping.name.toLowerCase().includes(searchStore.search.toLowerCase()))"
					:key="`${mapping}${i}`"
					:name="mapping.name"
					:active="String($route.params.id || '') === String(mapping?.id || '')"
					:force-display-actions="true"
					@click="$router.push('/mappings/' + mapping.id)">
					<template #icon>
						<SitemapOutline :class="String($route.params.id || '') === String(mapping?.id || '') && 'selectedMappingIcon'"
							disable-menu
							:size="44" />
					</template>
					<template #subname>
						{{ mapping?.description }}
					</template>
					<template #actions>
						<NcActionButton close-after-click @click="mappingStore.setMappingItem(mapping); navigationStore.setModal('editMapping')">
							<template #icon>
								<Pencil />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton close-after-click @click="mappingStore.setMappingItem(mapping); navigationStore.setModal('testMapping')">
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
						<NcActionButton close-after-click @click="mappingStore.setMappingItem(mapping); navigationStore.setDialog('deleteMapping')">
							<template #icon>
								<TrashCanOutline />
							</template>
							Delete
						</NcActionButton>
					</template>
				</NcListItem>
			</div>
		</ul>

		<NcLoadingIcon v-if="!mappingStore.mappingList"
			class="loadingIcon"
			:size="64"
			appearance="dark"
			name="Loading mappings" />

		<div v-if="!mappingStore.mappingList.length" class="emptyListHeader">
			No mappings defined
		</div>
	</NcAppContentList>
</template>

<script>
import { NcListItem, NcActionButton, NcAppContentList, NcTextField, NcLoadingIcon, NcActions } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import TestTube from 'vue-material-design-icons/TestTube.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import FileImportOutline from 'vue-material-design-icons/FileImportOutline.vue'

export default {
	name: 'MappingsList',
	components: {
		NcListItem,
		NcActions,
		NcActionButton,
		NcAppContentList,
		NcTextField,
		NcLoadingIcon,
		Magnify,
		SitemapOutline,
		Refresh,
		Plus,
		Pencil,
		TrashCanOutline,
	},
	mounted() {
		searchStore.clearSearch()
		mappingStore.refreshMappingList()
	},
}
</script>

<style scoped>
/* Styles remain the same */
</style>

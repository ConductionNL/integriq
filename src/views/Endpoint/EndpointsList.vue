<script setup>
import { endpointStore, navigationStore, searchStore } from '../../store/store.js'
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
					<NcActionButton close-after-click @click="endpointStore.refreshEndpointList()">
						<template #icon>
							<Refresh :size="20" />
						</template>
						Refresh
					</NcActionButton>
					<NcActionButton close-after-click @click="endpointStore.setEndpointItem(null); navigationStore.setModal('editEndpoint')">
						<template #icon>
							<Plus :size="20" />
						</template>
						Add endpoint
					</NcActionButton>
					<NcActionButton close-after-click @click="navigationStore.setModal('importFile')">
						<template #icon>
							<FileImportOutline :size="20" />
						</template>
						Import
					</NcActionButton>
				</NcActions>
			</div>
			<div v-if="endpointStore.endpointList && endpointStore.endpointList.length > 0">
				<NcListItem v-for="(endpoint, i) in endpointStore.endpointList.filter(endpoint => searchStore.search === '' || endpoint.name.toLowerCase().includes(searchStore.search.toLowerCase()))"
					:key="`${endpoint}${i}`"
					:name="endpoint.name"
					:active="$route.params.id == endpoint.id"
					:force-display-actions="true"
					@click="$router.push('/endpoints/' + endpoint.id)">
					<template #icon>
						<Api :class="$route.params.id == endpoint.id && 'selectedEndpointIcon'"
							disable-menu
							:fill-color="getEndpointColor(endpoint.method)"
							:size="44" />
					</template>
					<template #subname>
						{{ endpoint?.description }}
					</template>
					<template #actions>
						<NcActionButton close-after-click @click="endpointStore.setEndpointItem(endpoint); navigationStore.setModal('editEndpoint')">
							<template #icon>
								<Pencil />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton close-after-click @click="endpointStore.exportEndpoint(endpoint.id)">
							<template #icon>
								<FileExportOutline :size="20" />
							</template>
							Export endpoint
						</NcActionButton>
						<NcActionButton close-after-click @click="endpointStore.setEndpointItem(endpoint); navigationStore.setDialog('deleteEndpoint')">
							<template #icon>
								<TrashCanOutline />
							</template>
							Delete
						</NcActionButton>
						<NcActionButton close-after-click @click="endpointStore.setEndpointItem(endpoint); navigationStore.setModal('addEndpointRule')">
							<template #icon>
								<Plus :size="20" />
							</template>
							Add Rule
						</NcActionButton>
					</template>
				</NcListItem>
			</div>
		</ul>

		<NcLoadingIcon v-if="!endpointStore.endpointList"
			class="loadingIcon"
			:size="64"
			appearance="dark"
			name="Loading endpoints" />

		<div v-if="!endpointStore.endpointList.length" class="emptyListHeader">
			No endpoints defined
		</div>
	</NcAppContentList>
</template>

<script>
import { NcListItem, NcActionButton, NcAppContentList, NcTextField, NcLoadingIcon, NcActions } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Api from 'vue-material-design-icons/Api.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import FileImportOutline from 'vue-material-design-icons/FileImportOutline.vue'
import { getTheme } from '../../services/getTheme.js'

export default {
	name: 'EndpointsList',
	components: {
		NcListItem,
		NcActions,
		NcActionButton,
		NcAppContentList,
		NcTextField,
		NcLoadingIcon,
		Magnify,
		// Icons
		Api,
		Refresh,
		Plus,
		Pencil,
		TrashCanOutline,
	},
	mounted() {
		searchStore.clearSearch()
		endpointStore.refreshEndpointList()
	},
	methods: {
		getEndpointColor(method) {
			const theme = getTheme()

			if (theme === 'dark') {
				switch (method) {
				case 'GET':
					return '#5c8d4a'
				case 'POST':
					return '#5d82c0'
				case 'PUT':
					return '#a46f96'
				case 'PATCH':
					return '#bc6d3d'
				case 'DELETE':
					return '#d25c53'
				default:
					return '#fff'
				}
			} else {
				switch (method) {
				case 'GET':
					return '#4e7f3d'
				case 'POST':
					return '#466eaa'
				case 'PUT':
					return '#87547a'
				case 'PATCH':
					return '#a95d2e'
				case 'DELETE':
					return '#b13f3a'
				default:
					return '#000'
				}
			}
		},
	},
}
</script>

<style scoped>
/* Styles remain the same */
</style>

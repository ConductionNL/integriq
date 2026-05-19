<script setup>
import { eventStore, navigationStore, searchStore } from '../../store/store.js'
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
					<NcActionButton close-after-click @click="eventStore.refreshEventList()">
						<template #icon>
							<Refresh :size="20" />
						</template>
						Refresh
					</NcActionButton>
					<NcActionButton close-after-click @click="eventStore.setEventItem(null); navigationStore.setModal('editEvent')">
						<template #icon>
							<Plus :size="20" />
						</template>
						Add event
					</NcActionButton>
				</NcActions>
			</div>
			<div v-if="eventStore.eventList && eventStore.eventList.length > 0">
				<NcListItem v-for="(event, i) in eventStore.eventList.filter(event => searchStore.search === '' || event.name.toLowerCase().includes(searchStore.search.toLowerCase()))"
					:key="`${event}${i}`"
					:name="event.name"
					:active="String($route.params.id || '') === String(event?.id || '')"
					:force-display-actions="true"
					@click="$router.push('/cloud-events/events/' + event.id)">
					<template #icon>
						<Update :class="String($route.params.id || '') === String(event?.id || '') && 'selectedEventIcon'"
							disable-menu
							:size="44" />
					</template>
					<template #subname>
						{{ event?.description }}
					</template>
					<template #actions>
						<NcActionButton close-after-click @click="eventStore.setEventItem(event); navigationStore.setModal('editEvent')">
							<template #icon>
								<Pencil />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton close-after-click @click="eventStore.setEventItem(event); navigationStore.setDialog('deleteEvent')">
							<template #icon>
								<TrashCanOutline />
							</template>
							Delete
						</NcActionButton>
					</template>
				</NcListItem>
			</div>
		</ul>

		<NcLoadingIcon v-if="!eventStore.eventList"
			class="loadingIcon"
			:size="64"
			appearance="dark"
			name="Loading events" />

		<div v-if="!eventStore.eventList.length" class="emptyListHeader">
			No events defined
		</div>
	</NcAppContentList>
</template>

<script>
import { NcListItem, NcActionButton, NcAppContentList, NcTextField, NcLoadingIcon, NcActions } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Update from 'vue-material-design-icons/Update.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'EventList',
	components: {
		NcListItem,
		NcActions,
		NcActionButton,
		NcAppContentList,
		NcTextField,
		NcLoadingIcon,
		Magnify,
		Update,
		Refresh,
		Plus,
		Pencil,
		TrashCanOutline,
	},
	mounted() {
		searchStore.clearSearch()
		eventStore.refreshEventList()
	},
}
</script>

<style scoped>
/* Styles remain the same */
</style>

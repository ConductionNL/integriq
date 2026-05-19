<script setup>
import { consumerStore, navigationStore, searchStore } from '../../store/store.js'
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
					<NcActionButton close-after-click @click="consumerStore.refreshConsumerList()">
						<template #icon>
							<Refresh :size="20" />
						</template>
						Refresh
					</NcActionButton>
					<NcActionButton close-after-click @click="consumerStore.setConsumerItem(null); navigationStore.setModal('editConsumer')">
						<template #icon>
							<Plus :size="20" />
						</template>
						Add consumer
					</NcActionButton>
				</NcActions>
			</div>
			<div v-if="consumerStore.consumerList && consumerStore.consumerList.length > 0">
				<NcListItem v-for="(consumer, i) in consumerStore.consumerList.filter(consumer => searchStore.search === '' || consumer.name.toLowerCase().includes(searchStore.search.toLowerCase()))"
					:key="`${consumer}${i}`"
					:name="consumer.name"
					:active="String($route.params.id) === String(consumer?.id)"
					:force-display-actions="true"
					@click="$router.push('/consumers/' + consumer.id)">
					<template #icon>
						<Api :class="String($route.params.id) === String(consumer.id) && 'selectedConsumerIcon'"
							disable-menu
							:size="44" />
					</template>
					<template #subname>
						{{ consumer?.description }}
					</template>
					<template #actions>
						<NcActionButton close-after-click @click="consumerStore.setConsumerItem(consumer); navigationStore.setModal('editConsumer')">
							<template #icon>
								<Pencil />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton close-after-click @click="consumerStore.setConsumerItem(consumer); navigationStore.setDialog('deleteConsumer')">
							<template #icon>
								<TrashCanOutline />
							</template>
							Delete
						</NcActionButton>
					</template>
				</NcListItem>
			</div>
		</ul>

		<NcLoadingIcon v-if="!consumerStore.consumerList"
			class="loadingIcon"
			:size="64"
			appearance="dark"
			name="Loading consumers" />

		<div v-if="!consumerStore.consumerList.length" class="emptyListHeader">
			No consumers defined
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

export default {
	name: 'ConsumersList',
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
		consumerStore.refreshConsumerList()
	},
}
</script>

<style scoped>
/* Styles remain the same */
</style>

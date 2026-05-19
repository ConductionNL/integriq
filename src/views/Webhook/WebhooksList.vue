<script setup>
import { webhookStore, navigationStore, searchStore } from '../../store/store.js'
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
					@trailing-button-click="webhookStore.refreshWebhookList()">
					<Magnify :size="20" />
				</NcTextField>
				<NcActions>
					<NcActionButton close-after-click @click="webhookStore.refreshWebhookList()">
						<template #icon>
							<Refresh :size="20" />
						</template>
						Refresh
					</NcActionButton>
					<NcActionButton close-after-click @click="webhookStore.setWebhookItem({}); navigationStore.setModal('editWebhook')">
						<template #icon>
							<Plus :size="20" />
						</template>
						Add webhook
					</NcActionButton>
				</NcActions>
			</div>
			<div v-if="webhookStore.webhookList && webhookStore.webhookList.length > 0">
				<NcListItem v-for="(webhook, i) in webhookStore.webhookList"
					:key="`${webhook}${i}`"
					:name="webhook.name"
					:active="webhookStore.webhookItem?.id === webhook?.id"
					:force-display-actions="true"
					@click="webhookStore.setWebhookItem(webhook)">
					<template #icon>
						<Webhook :class="webhookStore.webhookItem?.id === webhook.id && 'selectedWebhookIcon'"
							disable-menu
							:size="44" />
					</template>
					<template #subname>
						{{ webhook?.description }}
					</template>
					<template #actions>
						<NcActionButton close-after-click @click="webhookStore.setWebhookItem(webhook); navigationStore.setModal('editWebhook')">
							<template #icon>
								<Pencil />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton close-after-click @click="webhookStore.setWebhookItem(webhook); navigationStore.setDialog('deleteWebhook')">
							<template #icon>
								<TrashCanOutline />
							</template>
							Delete
						</NcActionButton>
					</template>
				</NcListItem>
			</div>
		</ul>

		<NcLoadingIcon v-if="!webhookStore.webhookList"
			class="loadingIcon"
			:size="64"
			appearance="dark"
			name="Loading webhooks" />

		<div v-if="!webhookStore.webhookList.length" class="emptyListHeader">
			No webhooks defined
		</div>
	</NcAppContentList>
</template>

<script>
import { NcListItem, NcActionButton, NcAppContentList, NcTextField, NcLoadingIcon, NcActions } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Webhook from 'vue-material-design-icons/Webhook.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'WebhooksList',
	components: {
		NcListItem,
		NcActions,
		NcActionButton,
		NcAppContentList,
		NcTextField,
		NcLoadingIcon,
		Magnify,
		Webhook,
		Refresh,
		Plus,
		Pencil,
		TrashCanOutline,
	},
	mounted() {
		webhookStore.refreshWebhookList()
	},
}
</script>

<style scoped>
/* Styles remain the same */
</style>

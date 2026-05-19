<script setup>
import { navigationStore } from '../../store/store.js'
</script>

<template>
	<div class="detailContainer">
		<div id="app-content">
			<div>
				<div class="detailHeader">
					<h1 class="h1">
						{{ consumer?.name }}
					</h1>

					<NcActions :primary="true" menu-name="Acties">
						<template #icon>
							<DotsHorizontal :size="20" />
						</template>
						<NcActionButton close-after-click @click="navigationStore.setModal('editConsumer')">
							<template #icon>
								<Pencil :size="20" />
							</template>
							Bewerken
						</NcActionButton>
						<NcActionButton close-after-click @click="navigationStore.setDialog('deleteConsumer')">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							Verwijderen
						</NcActionButton>
					</NcActions>
				</div>

				<span>{{ consumer?.description }}</span>

				<div class="detailGrid">
					<div class="gridContent">
						<b>Id:</b>
						<p>{{ consumer?.id || '-' }}</p>
					</div>
					<div class="gridContent">
						<b>Uuid:</b>
						<p>{{ consumer?.uuid || '-' }}</p>
					</div>
					<div class="gridContent" />

					<div class="gridContent">
						<b>Name:</b>
						<p>{{ consumer?.name || '-' }}</p>
					</div>
					<div class="gridContent">
						<b>Description:</b>
						<p>{{ consumer?.description || '-' }}</p>
					</div>
					<div class="gridContent">
						<b>Domains:</b>
						<p>{{ consumer?.domains?.join(', ') || '-' }}</p>
					</div>
					<div class="gridContent">
						<b>IP's:</b>
						<p>{{ consumer?.ips?.join(', ') || '-' }}</p>
					</div>
					<div class="gridContent">
						<b>Authorization Type:</b>
						<p>{{ consumer?.authorizationType || '-' }}</p>
					</div>
					<div class="gridContent">
						<b>Authorization Configuration:</b>
						<p>{{ consumer?.authorizationConfiguration || '-' }}</p>
					</div>

					<div class="gridContent">
						<b>Created:</b>
						<p>{{ consumer?.created ? new Date(consumer.created).toLocaleDateString() : '-' }}</p>
					</div>
					<div class="gridContent">
						<b>Updated:</b>
						<p>{{ consumer?.updated ? new Date(consumer.updated).toLocaleDateString() : '-' }}</p>
					</div>
				</div>
				<!-- Add more consumer-specific details here -->
			</div>
		</div>
	</div>
</template>

<script>
import { NcActions, NcActionButton } from '@nextcloud/vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'ConsumerDetails',
	components: {
		NcActions,
		NcActionButton,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
	},
	props: {
		consumer: {
			type: Object,
			required: false,
			default: null,
		},
	},
}
</script>

<style scoped>
/* Styles remain the same */
.gridContent {
	display: flex;
	gap: 10px;
}

.gridContent p {
	white-space: normal;
	overflow-wrap: break-word;
	word-wrap: break-word;
}
</style>

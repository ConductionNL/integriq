<script setup>
import { ruleStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<div class="detailContainer">
		<div id="app-content">
			<div>
				<div class="detailHeader">
					<h1 class="h1">
						{{ item?.name || '-' }}
					</h1>

					<NcActions :primary="true" menu-name="Actions">
						<template #icon>
							<DotsHorizontal :size="20" />
						</template>
						<NcActionButton close-after-click @click="navigationStore.setModal('editRule')">
							<template #icon>
								<Pencil :size="20" />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton close-after-click @click="ruleStore.exportRule(item?.id)">
							<template #icon>
								<FileExportOutline :size="20" />
							</template>
							Export rule
						</NcActionButton>
						<NcActionButton close-after-click @click="navigationStore.setDialog('deleteRule')">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							Delete
						</NcActionButton>
					</NcActions>
				</div>
				<span>{{ item?.description || '-' }}</span>

				<div class="detailGrid">
					<div class="gridContent gridFullWidth">
						<b>id:</b>
						<p>{{ item?.id || '-' }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>uuid:</b>
						<p>{{ item?.uuid || '-' }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Created:</b>
						<p>
							{{ item?.created
								? new Date(item?.created).toLocaleString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' })
								: 'N/A'
							}}
						</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Updated:</b>
						<p>
							{{ item?.updated
								? new Date(item?.updated).toLocaleString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' })
								: 'N/A'
							}}
						</p>
					</div>

					<div class="gridContent gridDoubleWidth">
						<h4>Rule Details</h4>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Type:</b>
						<p>{{ item?.type || 'N/A' }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Action:</b>
						<p>{{ item?.action || 'N/A' }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Order:</b>
						<p>{{ item?.order || 'N/A' }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Conditions:</b>
						<p>{{ item?.conditions ? JSON.stringify(item?.conditions, null, 2) : 'N/A' }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Action Config:</b>
						<p>{{ item?.actionConfig ? JSON.stringify(item?.actionConfig, null, 2) : 'N/A' }}</p>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcActions, NcActionButton } from '@nextcloud/vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'

export default {
	name: 'RuleDetails',
	components: {
		NcActions,
		NcActionButton,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		FileExportOutline,
	},
	props: {
		item: {
			type: Object,
			default: null,
		},
	},
}
</script>

<style scoped>
.gridDoubleWidth {
	grid-column: span 2;
}
</style>

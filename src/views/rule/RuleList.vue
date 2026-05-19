<script setup>
import { ruleStore, navigationStore, searchStore } from '../../store/store.js'
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
					<NcActionButton close-after-click @click="ruleStore.refreshRuleList()">
						<template #icon>
							<Refresh :size="20" />
						</template>
						Refresh
					</NcActionButton>
					<NcActionButton close-after-click @click="ruleStore.setRuleItem(null); navigationStore.setModal('editRule')">
						<template #icon>
							<Plus :size="20" />
						</template>
						Add rule
					</NcActionButton>
					<NcActionButton close-after-click @click="navigationStore.setModal('importFile')">
						<template #icon>
							<FileImportOutline :size="20" />
						</template>
						Import
					</NcActionButton>
				</NcActions>
			</div>
			<div v-if="ruleStore.ruleList && ruleStore.ruleList.length > 0">
				<NcListItem v-for="(rule, i) in ruleStore.ruleList.filter(rule => searchStore.search === '' || rule.name.toLowerCase().includes(searchStore.search.toLowerCase()))"
					:key="`${rule}${i}`"
					:name="rule.name"
					:active="String($route.params.id || '') === String(rule?.id || '')"
					:force-display-actions="true"
					@click="$router.push('/rules/' + rule.id)">
					<template #icon>
						<Update :class="String($route.params.id || '') === String(rule?.id || '') && 'selectedRuleIcon'"
							disable-menu
							:size="44" />
					</template>
					<template #subname>
						{{ rule?.description }}
					</template>
					<template #actions>
						<NcActionButton close-after-click @click="ruleStore.setRuleItem(rule); navigationStore.setModal('editRule')">
							<template #icon>
								<Pencil />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton close-after-click @click="ruleStore.exportRule(rule.id)">
							<template #icon>
								<FileExportOutline :size="20" />
							</template>
							Export rule
						</NcActionButton>
						<NcActionButton close-after-click @click="ruleStore.setRuleItem(rule); navigationStore.setDialog('deleteRule')">
							<template #icon>
								<TrashCanOutline />
							</template>
							Delete
						</NcActionButton>
					</template>
				</NcListItem>
			</div>
		</ul>

		<NcLoadingIcon v-if="!ruleStore.ruleList"
			class="loadingIcon"
			:size="64"
			appearance="dark"
			name="Loading rules" />

		<div v-if="!ruleStore.ruleList.length" class="emptyListHeader">
			No rules defined
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
import FileImportOutline from 'vue-material-design-icons/FileImportOutline.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'

export default {
	name: 'RuleList',
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
		FileImportOutline,
	},
	mounted() {
		searchStore.clearSearch()
		ruleStore.refreshRuleList()
	},
}
</script>

<style scoped>
/* Styles remain the same */
</style>

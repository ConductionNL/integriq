<script setup>
import { jobStore, navigationStore, searchStore } from '../../store/store.js'
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
				<NcActions>
					<NcActionButton close-after-click @click="jobStore.refreshJobList()">
						<template #icon>
							<Refresh :size="20" />
						</template>
						Refresh
					</NcActionButton>
					<NcActionButton close-after-click @click="jobStore.setJobItem(null); navigationStore.setModal('editJob')">
						<template #icon>
							<Plus :size="20" />
						</template>
						Add job
					</NcActionButton>
					<NcActionButton close-after-click @click="navigationStore.setModal('importFile')">
						<template #icon>
							<FileImportOutline :size="20" />
						</template>
						Import
					</NcActionButton>
				</NcActions>
			</div>
			<div v-if="jobStore.jobList && jobStore.jobList.length > 0">
				<NcListItem v-for="(job, i) in jobStore.jobList.filter(job => searchStore.search === '' || job.name.toLowerCase().includes(searchStore.search.toLowerCase()))"
					:key="`${job}${i}`"
					:name="job.name"
					:active="jobStore.jobItem?.id === job?.id"
					:force-display-actions="true"
					@click="jobStore.setJobItem(job); jobStore.refreshJobLogs(job.id)">
					<template #icon>
						<Update :class="jobStore.jobItem?.id === job.id && 'selectedJobIcon'"
							disable-menu
							:size="44" />
					</template>
					<template #subname>
						{{ job?.description }}
					</template>
					<template #actions>
						<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setModal('editJob')">
							<template #icon>
								<Pencil />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton close-after-click
							@click="() => {
								jobStore.setJobItem(job)
								jobStore.setJobArgumentKey(null)
								navigationStore.setModal('editJobArgument')
							}">
							<template #icon>
								<Plus :size="20" />
							</template>
							Add argument
						</NcActionButton>
						<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setModal('testJob')">
							<template #icon>
								<Update :size="20" />
							</template>
							Test
						</NcActionButton>
						<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setModal('runJob')">
							<template #icon>
								<Play :size="20" />
							</template>
							Run
						</NcActionButton>
						<NcActionButton close-after-click @click="jobStore.setJobItem(job); jobStore.refreshJobLogs(job.id)">
							<template #icon>
								<Sync :size="20" />
							</template>
							Refresh logs
						</NcActionButton>
						<NcActionButton close-after-click @click="jobStore.exportJob(job.id)">
							<template #icon>
								<FileExportOutline :size="20" />
							</template>
							Export job
						</NcActionButton>
						<NcActionButton close-after-click @click="jobStore.setJobItem(job); navigationStore.setDialog('deleteJob')">
							<template #icon>
								<TrashCanOutline />
							</template>
							Delete
						</NcActionButton>
					</template>
				</NcListItem>
			</div>
		</ul>

		<NcLoadingIcon v-if="!jobStore.jobList"
			class="loadingIcon"
			:size="64"
			appearance="dark"
			name="Loading jobs" />

		<div v-if="!jobStore.jobList.length" class="emptyListHeader">
			No jobs defined
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
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import FileImportOutline from 'vue-material-design-icons/FileImportOutline.vue'
import Play from 'vue-material-design-icons/Play.vue'
import Sync from 'vue-material-design-icons/Sync.vue'

export default {
	name: 'JobsList',
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
		jobStore.refreshJobList()
	},
}
</script>

<style scoped>
/* Styles remain the same */
</style>

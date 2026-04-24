<script setup>
import { synchronizationStore, navigationStore, logStore, ruleStore } from '../../store/store.js'
</script>

<template>
	<div class="detailContainer">
		<div id="app-content">
			<div>
				<div class="detailHeader">
					<h1 class="h1">
						{{ synchronizationStore.synchronizationItem.name }}
					</h1>

					<NcActions :primary="true" menu-name="Actions">
						<template #icon>
							<DotsHorizontal :size="20" />
						</template>
						<NcActionButton close-after-click @click="navigationStore.setModal('editSynchronization')">
							<template #icon>
								<Pencil :size="20" />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationSourceConfigKey(null); navigationStore.setModal('editSynchronizationSourceConfig')">
							<template #icon>
								<DatabaseSettingsOutline :size="20" />
							</template>
							Add Source Config
						</NcActionButton>
						<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationTargetConfigKey(null); navigationStore.setModal('editSynchronizationTargetConfig')">
							<template #icon>
								<CardBulletedSettingsOutline :size="20" />
							</template>
							Add Target Config
						</NcActionButton>
						<NcActionButton close-after-click @click="navigationStore.setModal('testSynchronization')">
							<template #icon>
								<Sync :size="20" />
							</template>
							Test
						</NcActionButton>
						<NcActionButton close-after-click @click="navigationStore.setModal('runSynchronization')">
							<template #icon>
								<Play :size="20" />
							</template>
							Run
						</NcActionButton>
						<NcActionButton close-after-click @click="synchronizationStore.exportSynchronization(synchronizationStore.synchronizationItem.id)">
							<template #icon>
								<FileExportOutline :size="20" />
							</template>
							Export synchronization
						</NcActionButton>
						<NcActionButton close-after-click @click="navigationStore.setDialog('deleteSynchronization')">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							Delete
						</NcActionButton>
					</NcActions>
				</div>
				<span>{{ synchronizationStore.synchronizationItem.description }}</span>

				<div class="detailGrid">
					<div class="gridContent gridFullWidth">
						<b>id:</b>
						<p>{{ synchronizationStore.synchronizationItem.id }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>uuid:</b>
						<p>{{ synchronizationStore.synchronizationItem.uuid }}</p>
					</div>
					<div class="gridContent">
						<b>Version:</b>
						<p>{{ synchronizationStore.synchronizationItem.version }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Created:</b>
						<p>
							{{ synchronizationStore.synchronizationItem.created
								? new Date(synchronizationStore.synchronizationItem.created).toLocaleString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' })
								: 'N/A'
							}}
						</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Updated:</b>
						<p>
							{{ synchronizationStore.synchronizationItem.updated
								? new Date(synchronizationStore.synchronizationItem.updated).toLocaleString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' })
								: 'N/A'
							}}
						</p>
					</div>

					<div class="gridContent gridDoubleWidth">
						<h4>Source</h4>
					</div>
					<div class="gridContent gridFullWidth">
						<b>SourceId:</b>
						<p>{{ synchronizationStore.synchronizationItem.sourceId || 'N/A' }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Source Type:</b>
						<p>{{ synchronizationStore.synchronizationItem.sourceType || 'N/A' }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Source Hash:</b>
						<p>{{ synchronizationStore.synchronizationItem.sourceHash || 'N/A' }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Source Hash mapping id:</b>
						<p>{{ synchronizationStore.synchronizationItem.sourceHashMapping || 'N/A' }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Source Last Changed:</b>
						<p>{{ getValidISOstring(synchronizationStore.synchronizationItem.sourceLastChanged) ? new Date(synchronizationStore.synchronizationItem.sourceLastChanged).toLocaleString() : 'N/A' }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Source Last Checked:</b>
						<p>{{ getValidISOstring(synchronizationStore.synchronizationItem.sourceLastChecked) ? new Date(synchronizationStore.synchronizationItem.sourceLastChecked).toLocaleString() : 'N/A' }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Source Last Synced:</b>
						<p>{{ getValidISOstring(synchronizationStore.synchronizationItem.sourceLastSynced) ? new Date(synchronizationStore.synchronizationItem.sourceLastSynced).toLocaleString() : 'N/A' }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Source Target Mapping:</b>
						<p>{{ synchronizationStore.synchronizationItem.sourceTargetMapping || 'N/A' }}</p>
					</div>

					<div class="gridContent gridDoubleWidth">
						<h4>Target</h4>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Target Id:</b>
						<p>{{ synchronizationStore.synchronizationItem.targetId || 'N/A' }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Target Type:</b>
						<p>{{ synchronizationStore.synchronizationItem.targetType || 'N/A' }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Target Last Changed:</b>
						<p>{{ getValidISOstring(synchronizationStore.synchronizationItem.targetLastChanged) ? new Date(synchronizationStore.synchronizationItem.targetLastChanged).toLocaleString() : 'N/A' }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Target Last Checked:</b>
						<p>{{ getValidISOstring(synchronizationStore.synchronizationItem.targetLastChecked) ? new Date(synchronizationStore.synchronizationItem.targetLastChecked).toLocaleString() : 'N/A' }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Target Last Synced:</b>
						<p>{{ getValidISOstring(synchronizationStore.synchronizationItem.targetLastSynced) ? new Date(synchronizationStore.synchronizationItem.targetLastSynced).toLocaleString() : 'N/A' }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Target Source Mapping:</b>
						<p>{{ synchronizationStore.synchronizationItem.targetSourceMapping || 'N/A' }}</p>
					</div>
				</div>
				<!-- Add more synchronization-specific details here -->

				<div class="tabContainer">
					<BTabs content-class="mt-3" justified>
						<BTab title="Contracts">
							<div v-if="synchronizationStore.synchronizationContracts?.length">
								<NcListItem v-for="(contract, i) in synchronizationStore.synchronizationContracts"
									:key="`${contract.id}${i}`"
									:name="contract.id.toString()"
									:bold="false"
									:force-display-actions="true"
									:active="false">
									<template #icon>
										<FileCertificateOutline
											disable-menu
											:size="44" />
									</template>
									<template #subname>
										{{ new Date(contract.created).toLocaleString() }}
									</template>
									<template #actions>
										<NcActionButton close-after-click @click="viewContract(contract)">
											<template #icon>
												<EyeOutline :size="20" />
											</template>
											View
										</NcActionButton>
									</template>
								</NcListItem>
							</div>
							<div v-if="!synchronizationStore.synchronizationContracts.length" class="tabPanel">
								No contracts found
							</div>
						</BTab>
						<BTab title="Source config">
							<div class="tabButtonsContainer">
								<NcButton type="primary"
									class="fullWidthButton"
									aria-label="Add Source Config"
									@click="synchronizationStore.setSynchronizationSourceConfigKey(null); navigationStore.setModal('editSynchronizationSourceConfig')">
									<template #icon>
										<Plus :size="20" />
									</template>
									Add Source Config
								</NcButton>
							</div>
							<div v-if="Object.keys(synchronizationStore.synchronizationItem.sourceConfig).length">
								<NcListItem v-for="(value, key, i) in synchronizationStore.synchronizationItem.sourceConfig"
									:key="`${key}${i}`"
									:name="key"
									:bold="false"
									:force-display-actions="true"
									:active="false">
									<template #icon>
										<DatabaseSettingsOutline
											disable-menu
											:size="44" />
									</template>
									<template #subname>
										{{ value }}
									</template>
									<template #actions>
										<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationSourceConfigKey(key); navigationStore.setModal('editSynchronizationSourceConfig')">
											<template #icon>
												<Pencil :size="20" />
											</template>
											Edit
										</NcActionButton>
										<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationSourceConfigKey(key); navigationStore.setModal('deleteSynchronizationSourceConfig')">
											<template #icon>
												<Delete :size="20" />
											</template>
											Delete
										</NcActionButton>
									</template>
								</NcListItem>
							</div>
							<div v-if="!Object.keys(synchronizationStore.synchronizationItem.sourceConfig).length" class="tabPanel">
								No source configs found
							</div>
						</BTab>
						<BTab title="Target config">
							<div class="tabButtonsContainer">
								<NcButton type="primary"
									class="fullWidthButton"
									aria-label="Add Target Config"
									@click="synchronizationStore.setSynchronizationTargetConfigKey(null); navigationStore.setModal('editSynchronizationTargetConfig')">
									<template #icon>
										<Plus :size="20" />
									</template>
									Add Target Config
								</NcButton>
							</div>
							<div v-if="Object.keys(synchronizationStore.synchronizationItem.targetConfig).length">
								<NcListItem v-for="(value, key, i) in synchronizationStore.synchronizationItem.targetConfig"
									:key="`${key}${i}`"
									:name="key"
									:bold="false"
									:force-display-actions="true"
									:active="false">
									<template #icon>
										<CardBulletedSettingsOutline
											disable-menu
											:size="44" />
									</template>
									<template #subname>
										{{ value }}
									</template>
									<template #actions>
										<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationTargetConfigKey(key); navigationStore.setModal('editSynchronizationTargetConfig')">
											<template #icon>
												<Pencil :size="20" />
											</template>
											Edit
										</NcActionButton>
										<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationTargetConfigKey(key); navigationStore.setModal('deleteSynchronizationTargetConfig')">
											<template #icon>
												<Delete :size="20" />
											</template>
											Delete
										</NcActionButton>
									</template>
								</NcListItem>
							</div>
							<div v-if="!Object.keys(synchronizationStore.synchronizationItem.targetConfig).length" class="tabPanel">
								No target configs found
							</div>
						</BTab>
						<BTab title="Rules">
							<div v-if="filteredRuleList.length">
								<NcListItem v-for="(rule, i) in filteredRuleList"
									:key="`${rule.id}${i}`"
									:name="rule.name"
									:bold="false"
									:force-display-actions="true"
									:details="rule.version"
									:active="false">
									<template #icon>
										<FileImportOutline
											disable-menu
											:size="44" />
									</template>
									<template #subname>
										{{ rule.description }}
									</template>
									<template #actions>
										<NcActionButton close-after-click @click="ruleStore.setRuleItem(rule); $router.push('/rules/' + rule.id)">
											<template #icon>
												<EyeOutline :size="20" />
											</template>
											View
										</NcActionButton>
										<NcActionButton close-after-click @click="ruleStore.setRuleItem(rule); navigationStore.setModal('editRule')">
											<template #icon>
												<Pencil :size="20" />
											</template>
											Edit
										</NcActionButton>
									</template>
								</NcListItem>
							</div>
							<div v-if="!filteredRuleList.length" class="tabPanel">
								No rules found
							</div>
						</BTab>
						<BTab title="Logs">
							<div v-if="synchronizationStore.synchronizationLogs?.length">
								<NcListItem v-for="(log, i) in [...synchronizationStore.synchronizationLogs].reverse()"
									:key="log.id + i"
									:name="log.message + (log.result?.objects?.found ? ` (found: ${log.result.objects.found})` : '')"
									:bold="false"
									:force-display-actions="true">
									<template #icon>
										<TimelineQuestionOutline disable-menu
											:size="44" />
									</template>
									<template #subname>
										{{ new Date(log.created).toLocaleString() }}
									</template>
									<template #actions>
										<NcActionButton close-after-click @click="viewLog(log)">
											<template #icon>
												<EyeOutline :size="20" />
											</template>
											View
										</NcActionButton>
									</template>
								</NcListItem>
							</div>
							<div v-if="!synchronizationStore.synchronizationLogs?.length" class="tabPanel">
								No logs found
							</div>
						</BTab>
					</BTabs>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcActions, NcActionButton, NcListItem, NcButton } from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import FileCertificateOutline from 'vue-material-design-icons/FileCertificateOutline.vue'
import TimelineQuestionOutline from 'vue-material-design-icons/TimelineQuestionOutline.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import DatabaseSettingsOutline from 'vue-material-design-icons/DatabaseSettingsOutline.vue'
import CardBulletedSettingsOutline from 'vue-material-design-icons/CardBulletedSettingsOutline.vue'
import Play from 'vue-material-design-icons/Play.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import FileImportOutline from 'vue-material-design-icons/FileImportOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

import getValidISOstring from '../../services/getValidISOstring.js'

export default {
	name: 'SynchronizationDetails',
	components: {
		NcActions,
		NcActionButton,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		NcButton,
		Plus,
	},
	data() {
		return {
			contracts: [],
		}
	},
	computed: {
		filteredRuleList() {
			return ruleStore.ruleList.filter((rule) => synchronizationStore.synchronizationItem?.actions?.includes(rule.id))
		},
		synchronizationId: () => synchronizationStore.synchronizationItem.id,
	},
	watch: {
		synchronizationId() {
			synchronizationStore.refreshSynchronizationLogs(synchronizationStore.synchronizationItem.id)
			synchronizationStore.refreshSynchronizationContracts(synchronizationStore.synchronizationItem.id)
		},
	},
	mounted() {
		synchronizationStore.refreshSynchronizationLogs(synchronizationStore.synchronizationItem.id)
		synchronizationStore.refreshSynchronizationContracts(synchronizationStore.synchronizationItem.id)
		ruleStore.refreshRuleList()
	},
	methods: {
		viewLog(log) {
			logStore.setViewLogItem(log)
			navigationStore.setModal('viewSynchronizationLog')
		},
		viewContract(contract) {
			logStore.setViewLogItem(contract)
			navigationStore.setModal('viewSynchronizationContract')
		},
	},
}
</script>

<style scoped>
.gridDoubleWidth {
	grid-column: span 2;
}
</style>

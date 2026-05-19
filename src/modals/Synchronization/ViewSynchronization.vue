<script setup>
import { translate as t } from '@nextcloud/l10n'
import { synchronizationStore, navigationStore, ruleStore } from '../../store/store.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'viewSynchronization'"
		ref="modalRef"
		:name="synchronizationStore.synchronizationItem?.name || t('openconnector', 'Synchronization Details')"
		@close="navigationStore.setModal(false)">
		<div class="modal-content">
			<p v-if="synchronizationStore.synchronizationItem?.description" class="synchronization-description">
				{{ synchronizationStore.synchronizationItem.description }}
			</p>

			<!-- Synchronization Properties -->
			<div class="synchronization-properties">
				<table class="statisticsTable synchronizationStats">
					<thead>
						<tr>
							<th>{{ t('openconnector', 'Property') }}</th>
							<th>{{ t('openconnector', 'Source') }}</th>
							<th>{{ t('openconnector', 'Target') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td>{{ t('openconnector', 'Type') }}</td>
							<td>{{ synchronizationStore.synchronizationItem?.sourceType || 'Unknown' }}</td>
							<td>{{ synchronizationStore.synchronizationItem?.targetType || 'Unknown' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'ID') }}</td>
							<td>{{ synchronizationStore.synchronizationItem?.sourceId || '-' }}</td>
							<td>{{ synchronizationStore.synchronizationItem?.targetId || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Hash') }}</td>
							<td>{{ synchronizationStore.synchronizationItem?.sourceHash || '-' }}</td>
							<td>{{ synchronizationStore.synchronizationItem?.targetHash || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Last Synced') }}</td>
							<td>{{ synchronizationStore.synchronizationItem?.sourceLastSynced ? new Date(synchronizationStore.synchronizationItem.sourceLastSynced).toLocaleDateString() + ', ' + new Date(synchronizationStore.synchronizationItem.sourceLastSynced).toLocaleTimeString() : '-' }}</td>
							<td>{{ synchronizationStore.synchronizationItem?.targetLastSynced ? new Date(synchronizationStore.synchronizationItem.targetLastSynced).toLocaleDateString() + ', ' + new Date(synchronizationStore.synchronizationItem.targetLastSynced).toLocaleTimeString() : '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Last Checked') }}</td>
							<td>{{ synchronizationStore.synchronizationItem?.sourceLastChecked ? new Date(synchronizationStore.synchronizationItem.sourceLastChecked).toLocaleDateString() + ', ' + new Date(synchronizationStore.synchronizationItem.sourceLastChecked).toLocaleTimeString() : '-' }}</td>
							<td>{{ synchronizationStore.synchronizationItem?.targetLastChecked ? new Date(synchronizationStore.synchronizationItem.targetLastChecked).toLocaleDateString() + ', ' + new Date(synchronizationStore.synchronizationItem.targetLastChecked).toLocaleTimeString() : '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Version') }}</td>
							<td colspan="2">
								{{ synchronizationStore.synchronizationItem?.version || '-' }}
							</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Created') }}</td>
							<td colspan="2">
								{{ synchronizationStore.synchronizationItem?.created ? new Date(synchronizationStore.synchronizationItem.created).toLocaleDateString() : '-' }}
							</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Updated') }}</td>
							<td colspan="2">
								{{ synchronizationStore.synchronizationItem?.updated ? new Date(synchronizationStore.synchronizationItem.updated).toLocaleDateString() : '-' }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Tabs -->
			<div class="tabContainer">
				<BTabs content-class="mt-3" justified>
					<BTab title="Source Config">
						<div v-if="Object.keys(synchronizationStore.synchronizationItem?.sourceConfig || {}).length" class="source-config-list">
							<NcListItem v-for="(value, key, i) in synchronizationStore.synchronizationItem.sourceConfig"
								:key="`${key}${i}`"
								:name="key"
								:bold="false"
								:force-display-actions="true">
								<template #icon>
									<DatabaseSettingsOutline :size="44" />
								</template>
								<template #subname>
									{{ typeof value === 'object' ? JSON.stringify(value) : value }}
								</template>
								<template #actions>
									<NcActionButton close-after-click @click="editSourceConfig(key)">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
									<NcActionButton close-after-click @click="deleteSourceConfig(key)">
										<template #icon>
											<Delete :size="20" />
										</template>
										Delete
									</NcActionButton>
								</template>
							</NcListItem>
						</div>
						<div v-if="!Object.keys(synchronizationStore.synchronizationItem?.sourceConfig || {}).length" class="tabPanel">
							<NcEmptyContent
								:name="t('openconnector', 'No source configs')"
								:description="t('openconnector', 'No source configurations found')">
								<template #icon>
									<DatabaseSettingsOutline :size="64" />
								</template>
								<template #action>
									<NcButton @click="addSourceConfig">
										{{ t('openconnector', 'Add Source Config') }}
									</NcButton>
								</template>
							</NcEmptyContent>
						</div>
					</BTab>
					<BTab title="Target Config">
						<div v-if="Object.keys(synchronizationStore.synchronizationItem?.targetConfig || {}).length" class="target-config-list">
							<NcListItem v-for="(value, key, i) in synchronizationStore.synchronizationItem.targetConfig"
								:key="`${key}${i}`"
								:name="key"
								:bold="false"
								:force-display-actions="true">
								<template #icon>
									<CardBulletedSettingsOutline :size="44" />
								</template>
								<template #subname>
									{{ typeof value === 'object' ? JSON.stringify(value) : value }}
								</template>
								<template #actions>
									<NcActionButton close-after-click @click="editTargetConfig(key)">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
									<NcActionButton close-after-click @click="deleteTargetConfig(key)">
										<template #icon>
											<Delete :size="20" />
										</template>
										Delete
									</NcActionButton>
								</template>
							</NcListItem>
						</div>
						<div v-if="!Object.keys(synchronizationStore.synchronizationItem?.targetConfig || {}).length" class="tabPanel">
							<NcEmptyContent
								:name="t('openconnector', 'No target configs')"
								:description="t('openconnector', 'No target configurations found')">
								<template #icon>
									<CardBulletedSettingsOutline :size="64" />
								</template>
								<template #action>
									<NcButton @click="addTargetConfig">
										{{ t('openconnector', 'Add Target Config') }}
									</NcButton>
								</template>
							</NcEmptyContent>
						</div>
					</BTab>
					<BTab title="Rules">
						<div v-if="filteredRuleList.length" class="rules-list">
							<NcListItem v-for="(rule, i) in filteredRuleList"
								:key="`${rule.id}${i}`"
								:name="rule.name"
								:bold="false"
								:force-display-actions="true"
								:details="rule.version">
								<template #icon>
									<FileImportOutline :size="44" />
								</template>
								<template #subname>
									{{ rule.description }}
								</template>
								<template #actions>
									<NcActionButton close-after-click @click="viewRule(rule)">
										<template #icon>
											<EyeOutline :size="20" />
										</template>
										View
									</NcActionButton>
									<NcActionButton close-after-click @click="editRule(rule)">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
								</template>
							</NcListItem>
						</div>
						<div v-if="!filteredRuleList.length" class="tabPanel">
							<NcEmptyContent
								:name="t('openconnector', 'No rules')"
								:description="t('openconnector', 'No rules found for this synchronization')">
								<template #icon>
									<FileImportOutline :size="64" />
								</template>
							</NcEmptyContent>
						</div>
					</BTab>
				</BTabs>
			</div>

			<!-- Action buttons -->
			<div class="modal-actions">
				<NcButton @click="navigationStore.setModal('editSynchronization')">
					<template #icon>
						<Pencil :size="20" />
					</template>
					Edit
				</NcButton>
				<NcButton @click="navigationStore.setModal('runSynchronization')">
					<template #icon>
						<Play :size="20" />
					</template>
					Run
				</NcButton>
				<NcButton @click="viewSynchronizationContracts()">
					<template #icon>
						<FileCertificateOutline :size="20" />
					</template>
					Contracts
				</NcButton>
				<NcButton @click="viewSynchronizationLogs()">
					<template #icon>
						<TimelineQuestionOutline :size="20" />
					</template>
					Logs
				</NcButton>
				<NcButton type="error" @click="navigationStore.setDialog('deleteSynchronization')">
					<template #icon>
						<TrashCanOutline :size="20" />
					</template>
					Delete
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton, NcListItem, NcActionButton, NcEmptyContent } from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import TimelineQuestionOutline from 'vue-material-design-icons/TimelineQuestionOutline.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import DatabaseSettingsOutline from 'vue-material-design-icons/DatabaseSettingsOutline.vue'
import CardBulletedSettingsOutline from 'vue-material-design-icons/CardBulletedSettingsOutline.vue'
import Play from 'vue-material-design-icons/Play.vue'
import FileImportOutline from 'vue-material-design-icons/FileImportOutline.vue'
import FileCertificateOutline from 'vue-material-design-icons/FileCertificateOutline.vue'

import getValidISOstring from '../../services/getValidISOstring.js'

export default {
	name: 'ViewSynchronization',
	components: {
		NcModal,
		NcButton,
		NcListItem,
		NcActionButton,
		NcEmptyContent,
		BTabs,
		BTab,
		Pencil,
		Delete,
		TrashCanOutline,
		TimelineQuestionOutline,
		EyeOutline,
		DatabaseSettingsOutline,
		CardBulletedSettingsOutline,
		Play,
		FileImportOutline,
		FileCertificateOutline,
	},
	computed: {
		/**
		 * Get filtered rule list for this synchronization
		 * @return {Array} Filtered rules
		 */
		filteredRuleList() {
			return ruleStore.ruleList.filter((rule) => synchronizationStore.synchronizationItem?.actions?.includes(rule.id))
		},
		/**
		 * Get synchronization ID for watching
		 * @return {string|number} Synchronization ID
		 */
		synchronizationId() {
			return synchronizationStore.synchronizationItem?.id
		},
	},
	watch: {
		synchronizationId() {
			this.refreshData()
		},
	},
	mounted() {
		ruleStore.refreshRuleList()
	},
	methods: {
		/**
		 * Refresh synchronization data
		 */
		refreshData() {
			// Only refresh rules, avoid problematic API calls
			ruleStore.refreshRuleList()
		},
		/**
		 * Edit source configuration
		 * @param {string} key - The configuration key
		 */
		editSourceConfig(key) {
			synchronizationStore.setSynchronizationSourceConfigKey(key)
			navigationStore.setModal('editSynchronizationSourceConfig')
		},
		/**
		 * Delete source configuration
		 * @param {string} key - The configuration key
		 */
		deleteSourceConfig(key) {
			synchronizationStore.setSynchronizationSourceConfigKey(key)
			navigationStore.setModal('deleteSynchronizationSourceConfig')
		},
		/**
		 * Add source configuration
		 */
		addSourceConfig() {
			synchronizationStore.setSynchronizationSourceConfigKey(null)
			navigationStore.setModal('editSynchronizationSourceConfig')
		},
		/**
		 * Edit target configuration
		 * @param {string} key - The configuration key
		 */
		editTargetConfig(key) {
			synchronizationStore.setSynchronizationTargetConfigKey(key)
			navigationStore.setModal('editSynchronizationTargetConfig')
		},
		/**
		 * Delete target configuration
		 * @param {string} key - The configuration key
		 */
		deleteTargetConfig(key) {
			synchronizationStore.setSynchronizationTargetConfigKey(key)
			navigationStore.setModal('deleteSynchronizationTargetConfig')
		},
		/**
		 * Add target configuration
		 */
		addTargetConfig() {
			synchronizationStore.setSynchronizationTargetConfigKey(null)
			navigationStore.setModal('editSynchronizationTargetConfig')
		},
		/**
		 * View rule details
		 * @param {object} rule - The rule object
		 */
		viewRule(rule) {
			ruleStore.setRuleItem(rule)
			this.$router.push('/rules/' + rule.id)
		},
		/**
		 * Edit rule
		 * @param {object} rule - The rule object
		 */
		editRule(rule) {
			ruleStore.setRuleItem(rule)
			navigationStore.setModal('editRule')
		},
		/**
		 * View synchronization logs
		 */
		viewSynchronizationLogs() {
			synchronizationStore.setSynchronizationItem(synchronizationStore.synchronizationItem)
			this.$router.push('/synchronizations/logs?synchronization=' + synchronizationStore.synchronizationItem.id)
		},
		/**
		 * View synchronization contracts
		 */
		viewSynchronizationContracts() {
			synchronizationStore.setSynchronizationItem(synchronizationStore.synchronizationItem)
			this.$router.push('/synchronizations/contracts?synchronization=' + synchronizationStore.synchronizationItem.id)
		},
		/**
		 * Get valid ISO string
		 * @param {string} dateString - The date string to validate
		 * @return {boolean} True if valid ISO string
		 */
		getValidISOstring,
	},
}
</script>

<style scoped>
.modal-content {
	padding: 20px;
	max-width: 800px;
	max-height: 80vh;
	overflow-y: auto;
}

.synchronization-description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 20px;
	font-style: italic;
}

.synchronization-properties {
	margin-bottom: 20px;
}

.selectedIcon {
	color: var(--color-primary);
}
</style>

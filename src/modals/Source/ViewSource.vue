<script setup>
import { translate as t } from '@nextcloud/l10n'
import { sourceStore, navigationStore, logStore, synchronizationStore } from '../../store/store.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'viewSource'"
		ref="modalRef"
		:name="sourceStore.sourceItem?.name || t('openconnector', 'Source details')"
		@close="navigationStore.setModal(false)">
		<div class="modal-content">
			<p v-if="sourceStore.sourceItem?.description" class="source-description">
				{{ sourceStore.sourceItem.description }}
			</p>

			<!-- Source Properties -->
			<div class="source-properties">
				<table class="statisticsTable sourceStats">
					<thead>
						<tr>
							<th>{{ t('openconnector', 'Property') }}</th>
							<th>{{ t('openconnector', 'Value') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td>{{ t('openconnector', 'Status') }}</td>
							<td>{{ sourceStore.sourceItem?.status || 'Unknown' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Enabled') }}</td>
							<td>{{ sourceStore.sourceItem?.isEnabled ? 'Enabled' : 'Disabled' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Type') }}</td>
							<td>{{ sourceStore.sourceItem?.type || 'Unknown' }}</td>
						</tr>
						<tr v-if="sourceStore.sourceItem?.location">
							<td>{{ t('openconnector', 'Location') }}</td>
							<td class="truncatedUrl">
								{{ sourceStore.sourceItem.location }}
							</td>
						</tr>
						<tr v-if="sourceStore.sourceItem?.version">
							<td>{{ t('openconnector', 'Version') }}</td>
							<td>{{ sourceStore.sourceItem.version }}</td>
						</tr>

						<tr v-if="sourceStore.sourceItem?.lastCall">
							<td>{{ t('openconnector', 'Last call') }}</td>
							<td>{{ new Date(sourceStore.sourceItem.lastCall).toLocaleDateString() + ', ' + new Date(sourceStore.sourceItem.lastCall).toLocaleTimeString() }}</td>
						</tr>
						<tr v-if="sourceStore.sourceItem?.lastSync">
							<td>{{ t('openconnector', 'Last sync') }}</td>
							<td>{{ new Date(sourceStore.sourceItem.lastSync).toLocaleDateString() + ', ' + new Date(sourceStore.sourceItem.lastSync).toLocaleTimeString() }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Created') }}</td>
							<td>{{ sourceStore.sourceItem?.dateCreated ? new Date(sourceStore.sourceItem.dateCreated).toLocaleDateString() : '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Updated') }}</td>
							<td>{{ sourceStore.sourceItem?.dateModified ? new Date(sourceStore.sourceItem.dateModified).toLocaleDateString() : '-' }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Tabs -->
			<div class="tabContainer">
				<BTabs content-class="mt-3" justified>
					<BTab title="Configurations">
						<div v-if="Object.keys(configuration)?.length" class="configurations-list">
							<NcListItem v-for="(value, key, i) in configuration"
								:key="`${key}${i}`"
								:name="key"
								:bold="false"
								:force-display-actions="true"
								:active="sourceStore.sourceConfigurationKey === key"
								@click="setActiveSourceConfigurationKey(key)">
								<template #icon>
									<FileCogOutline :class="sourceStore.sourceConfigurationKey === key && 'selectedIcon'" :size="44" />
								</template>
								<template #subname>
									{{ value }}
								</template>
								<template #actions>
									<NcActionButton close-after-click @click="editSourceConfiguration(key)">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
									<NcActionButton close-after-click @click="deleteSourceConfiguration(key)">
										<template #icon>
											<Delete :size="20" />
										</template>
										Delete
									</NcActionButton>
								</template>
							</NcListItem>
						</div>
						<div v-if="!Object.keys(configuration)?.length" class="tabPanel">
							<NcEmptyContent
								:name="t('openconnector', 'No configurations')"
								:description="t('openconnector', 'No configurations found for this source')">
								<template #icon>
									<FileCogOutline :size="64" />
								</template>
								<template #action>
									<NcButton @click="addSourceConfiguration">
										{{ t('openconnector', 'Add configuration') }}
									</NcButton>
								</template>
							</NcEmptyContent>
						</div>
					</BTab>
					<BTab title="Authentication">
						<div v-if="Object.keys(configurationAuthentication)?.length" class="authentication-list">
							<NcListItem v-for="(value, key, i) in configurationAuthentication"
								:key="`${key}${i}`"
								:name="key"
								:bold="false"
								:force-display-actions="true"
								:active="sourceStore.sourceConfigurationKey === key">
								<template #icon>
									<KeyOutline :class="sourceStore.sourceConfigurationKey === key && 'selectedIcon'" :size="44" />
								</template>
								<template #subname>
									{{ value }}
								</template>
								<template #actions>
									<NcActionButton close-after-click @click="sourceStore.setSourceConfigurationKey(key); navigationStore.setModal('editSourceConfigurationAuthentication')">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
									<NcActionButton close-after-click @click="sourceStore.setSourceConfigurationKey(key); navigationStore.setModal('deleteSourceConfigurationAuthentication')">
										<template #icon>
											<Delete :size="20" />
										</template>
										Delete
									</NcActionButton>
								</template>
							</NcListItem>
						</div>
						<div v-if="!Object.keys(configurationAuthentication)?.length" class="tabPanel">
							<NcEmptyContent
								:name="t('openconnector', 'No authentication')"
								:description="t('openconnector', 'No authentication configurations found for this source')">
								<template #icon>
									<KeyOutline :size="64" />
								</template>
								<template #action>
									<NcButton @click="addSourceAuthentication">
										{{ t('openconnector', 'Add authentication') }}
									</NcButton>
								</template>
							</NcEmptyContent>
						</div>
					</BTab>
					<BTab title="Synchronizations">
						<div v-if="linkedSynchronizations?.length" class="synchronizations-list">
							<NcListItem v-for="sync in linkedSynchronizations"
								:key="sync.id"
								:name="sync.name"
								:bold="false"
								:force-display-actions="true">
								<template #icon>
									<VectorPolylinePlus :size="44" />
								</template>
								<template #subname>
									{{ sync.description }}
								</template>
								<template #actions>
									<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationItem(sync); $router.push('/synchronizations/' + sync.id)">
										<template #icon>
											<EyeOutline :size="20" />
										</template>
										View
									</NcActionButton>
									<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationItem(sync); navigationStore.setModal('editSynchronization')">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
									<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationItem(sync); navigationStore.setDialog('deleteSynchronization')">
										<template #icon>
											<Delete :size="20" />
										</template>
										Delete
									</NcActionButton>
								</template>
							</NcListItem>
						</div>
						<div v-if="!linkedSynchronizations?.length" class="tabPanel">
							<NcEmptyContent
								:name="t('openconnector', 'No synchronizations')"
								:description="t('openconnector', 'No synchronizations found for this source')">
								<template #icon>
									<VectorPolylinePlus :size="64" />
								</template>
							</NcEmptyContent>
						</div>
					</BTab>
				</BTabs>
			</div>

			<!-- Action buttons -->
			<div class="modal-actions">
				<NcButton @click="navigationStore.setModal('editSource')">
					<template #icon>
						<Pencil :size="20" />
					</template>
					Edit
				</NcButton>
				<NcButton @click="navigationStore.setModal('testSource')">
					<template #icon>
						<Sync :size="20" />
					</template>
					Test
				</NcButton>
				<NcButton @click="viewSourceLogs()">
					<template #icon>
						<TimelineQuestionOutline :size="20" />
					</template>
					Logs
				</NcButton>
				<NcButton type="error" @click="navigationStore.setDialog('deleteSource')">
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
import FileCogOutline from 'vue-material-design-icons/FileCogOutline.vue'
import KeyOutline from 'vue-material-design-icons/KeyOutline.vue'
import VectorPolylinePlus from 'vue-material-design-icons/VectorPolylinePlus.vue'
import TimelineQuestionOutline from 'vue-material-design-icons/TimelineQuestionOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import Sync from 'vue-material-design-icons/Sync.vue'

import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'ViewSource',
	components: {
		NcModal,
		NcButton,
		NcListItem,
		NcActionButton,
		NcEmptyContent,
		BTabs,
		BTab,
		FileCogOutline,
		KeyOutline,
		VectorPolylinePlus,
		TimelineQuestionOutline,
		Pencil,
		Delete,
		EyeOutline,
		Sync,
		TrashCanOutline,
	},
	computed: {
		configuration() {
			const config = sourceStore.sourceItem?.configuration || {}
			const { authentication, ...configWithoutAuth } = config
			return configWithoutAuth
		},
		configurationAuthentication() {
			const source = sourceStore.sourceItem
			if (!source) return {}

			const authData = {}
			if (source.auth) authData['Auth Type'] = source.auth
			if (source.username) authData.Username = source.username
			if (source.apikey) authData['API Key'] = source.apikey
			if (source.jwt) authData.JWT = source.jwt
			if (source.secret) authData.Secret = source.secret
			if (source.authorizationHeader) authData['Authorization Header'] = source.authorizationHeader
			if (source.authenticationConfig && source.authenticationConfig.length > 0) {
				source.authenticationConfig.forEach((config, index) => {
					authData[`Auth Config ${index + 1}`] = typeof config === 'object' ? JSON.stringify(config) : config
				})
			}

			return authData
		},
		linkedSynchronizations() {
			return synchronizationStore.synchronizationList?.filter((item) =>
				item.sourceId.toString() === sourceStore.sourceItem?.id?.toString(),
			) || []
		},
	},
	mounted() {
		this.refreshSourceLogs()
		synchronizationStore.refreshSynchronizationList()
	},
	methods: {
		deleteSourceConfiguration(key) {
			sourceStore.setSourceConfigurationKey(key)
			navigationStore.setModal('deleteSourceConfiguration')
		},
		editSourceConfiguration(key) {
			sourceStore.setSourceConfigurationKey(key)
			navigationStore.setModal('editSourceConfiguration')
		},
		addSourceConfiguration() {
			sourceStore.setSourceConfigurationKey(null)
			navigationStore.setModal('editSourceConfiguration')
		},
		addSourceAuthentication() {
			sourceStore.setSourceConfigurationKey(null)
			navigationStore.setModal('editSourceConfigurationAuthentication')
		},
		viewLog(log) {
			logStore.setViewLogItem(log)
			navigationStore.setModal('viewSourceLog')
		},
		setActiveSourceConfigurationKey(sourceConfigurationKey) {
			if (sourceStore.sourceConfigurationKey === sourceConfigurationKey) {
				sourceStore.setSourceConfigurationKey(false)
			} else {
				sourceStore.setSourceConfigurationKey(sourceConfigurationKey)
			}
		},
		setActiveSourceLog(sourceLogId) {
			if (logStore.activeLogKey === `sourceLog-${sourceLogId}`) {
				logStore.setActiveLogKey(null)
			} else {
				logStore.setActiveLogKey(`sourceLog-${sourceLogId}`)
			}
		},
		refreshSourceLogs() {
			sourceStore.refreshSourceLogs()
		},
		checkIfStatusIsOk(statusCode) {
			if (statusCode > 199 && statusCode < 300) {
				return true
			}
			return false
		},
		/**
		 * View source logs
		 */
		viewSourceLogs() {
			sourceStore.setSourceItem(sourceStore.sourceItem)
			this.$router.push('/sources/logs')
		},
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

.source-description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 20px;
	font-style: italic;
}

.source-properties {
	margin-bottom: 20px;
}

.truncatedUrl {
	max-width: 300px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.selectedIcon {
	color: var(--color-primary);
}

:deep(.okStatus .counter-bubble__counter) {
	background-color: #69b090;
	color: white;
}

:deep(.errorStatus .counter-bubble__counter) {
	background-color: #dd3c49;
	color: white;
}
</style>

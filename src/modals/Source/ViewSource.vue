<script setup>
import { translate as t } from '@nextcloud/l10n'
import { sourceStore, navigationStore, logStore, synchronizationStore } from '../../store/store.js'
import { sourceSchema } from '../../views/Source/sourceSchema.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'viewSource'"
		ref="modalRef"
		:name="sourceStore.item?.name || t('openconnector', 'Source Details')"
		@close="navigationStore.setModal(false)">
		<div class="modal-content">
			<p v-if="sourceStore.item?.description" class="source-description">
				{{ sourceStore.item.description }}
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
							<td>{{ sourceStore.item?.status || t('openconnector', 'Unknown') }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Enabled') }}</td>
							<td>{{ sourceStore.item?.isEnabled ? t('openconnector', 'Enabled') : t('openconnector', 'Disabled') }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Type') }}</td>
							<td>{{ sourceStore.item?.type || t('openconnector', 'Unknown') }}</td>
						</tr>
						<tr v-if="sourceStore.item?.location">
							<td>{{ t('openconnector', 'Location') }}</td>
							<td class="truncatedUrl">
								{{ sourceStore.item.location }}
							</td>
						</tr>
						<tr v-if="sourceStore.item?.version">
							<td>{{ t('openconnector', 'Version') }}</td>
							<td>{{ sourceStore.item.version }}</td>
						</tr>

						<tr v-if="sourceStore.item?.lastCall">
							<td>{{ t('openconnector', 'Last Call') }}</td>
							<td>{{ new Date(sourceStore.item.lastCall).toLocaleDateString() + ', ' + new Date(sourceStore.item.lastCall).toLocaleTimeString() }}</td>
						</tr>
						<tr v-if="sourceStore.item?.lastSync">
							<td>{{ t('openconnector', 'Last Sync') }}</td>
							<td>{{ new Date(sourceStore.item.lastSync).toLocaleDateString() + ', ' + new Date(sourceStore.item.lastSync).toLocaleTimeString() }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Created') }}</td>
							<td>{{ sourceStore.item?.dateCreated ? new Date(sourceStore.item.dateCreated).toLocaleDateString() : '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Updated') }}</td>
							<td>{{ sourceStore.item?.dateModified ? new Date(sourceStore.item.dateModified).toLocaleDateString() : '-' }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Tabs -->
			<div class="tabContainer">
				<BTabs content-class="mt-3" justified>
					<BTab :title="t('openconnector', 'Configurations')">
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
										{{ t('openconnector', 'Edit') }}
									</NcActionButton>
									<NcActionButton close-after-click @click="deleteSourceConfiguration(key)">
										<template #icon>
											<Delete :size="20" />
										</template>
										{{ t('openconnector', 'Delete') }}
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
										{{ t('openconnector', 'Add Configuration') }}
									</NcButton>
								</template>
							</NcEmptyContent>
						</div>
					</BTab>
					<BTab :title="t('openconnector', 'Authentication')">
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
										{{ t('openconnector', 'Edit') }}
									</NcActionButton>
									<NcActionButton close-after-click @click="sourceStore.setSourceConfigurationKey(key); navigationStore.setModal('deleteSourceConfigurationAuthentication')">
										<template #icon>
											<Delete :size="20" />
										</template>
										{{ t('openconnector', 'Delete') }}
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
										{{ t('openconnector', 'Add Authentication') }}
									</NcButton>
								</template>
							</NcEmptyContent>
						</div>
					</BTab>
					<BTab :title="t('openconnector', 'Synchronizations')">
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
										{{ t('openconnector', 'View') }}
									</NcActionButton>
									<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationItem(sync); navigationStore.setModal('editSynchronization')">
										<template #icon>
											<Pencil :size="20" />
										</template>
										{{ t('openconnector', 'Edit') }}
									</NcActionButton>
									<NcActionButton close-after-click @click="synchronizationStore.setSynchronizationItem(sync); navigationStore.setDialog('deleteSynchronization')">
										<template #icon>
											<Delete :size="20" />
										</template>
										{{ t('openconnector', 'Delete') }}
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
				<NcButton @click="showEditDialog = true">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('openconnector', 'Edit') }}
				</NcButton>
				<NcButton @click="navigationStore.setModal('testSource')">
					<template #icon>
						<Sync :size="20" />
					</template>
					{{ t('openconnector', 'Test') }}
				</NcButton>
				<NcButton @click="viewSourceLogs()">
					<template #icon>
						<TimelineQuestionOutline :size="20" />
					</template>
					{{ t('openconnector', 'Logs') }}
				</NcButton>
				<NcButton type="error" @click="showDeleteDialog = true">
					<template #icon>
						<TrashCanOutline :size="20" />
					</template>
					{{ t('openconnector', 'Delete') }}
				</NcButton>
			</div>

			<CnFormDialog
				v-if="showEditDialog"
				ref="formDialog"
				:schema="schema"
				:item="sourceStore.item"
				:dialog-title="t('openconnector', 'Edit source')"
				name-field="name"
				@confirm="onFormConfirm"
				@close="showEditDialog = false" />

			<CnDeleteDialog
				v-if="showDeleteDialog && sourceStore.item"
				ref="deleteDialog"
				:item="sourceStore.item"
				name-field="name"
				:dialog-title="t('openconnector', 'Delete source')"
				:success-text="t('openconnector', 'Successfully deleted source')"
				@confirm="onDeleteConfirm"
				@close="showDeleteDialog = false" />
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton, NcListItem, NcActionButton, NcEmptyContent } from '@nextcloud/vue'
import { CnFormDialog, CnDeleteDialog } from '@conduction/nextcloud-vue'
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
		CnFormDialog,
		CnDeleteDialog,
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
	data() {
		return {
			showEditDialog: false,
			showDeleteDialog: false,
		}
	},
	computed: {
		schema() {
			return sourceSchema()
		},
		configuration() {
			const config = sourceStore.item?.configuration || {}
			const { authentication, ...configWithoutAuth } = config
			return configWithoutAuth
		},
		configurationAuthentication() {
			const source = sourceStore.item
			if (!source) return {}

			const authData = {}
			if (source.auth) authData[t('openconnector', 'Auth Type')] = source.auth
			if (source.username) authData[t('openconnector', 'Username')] = source.username
			if (source.apikey) authData[t('openconnector', 'API Key')] = source.apikey
			if (source.jwt) authData[t('openconnector', 'JWT')] = source.jwt
			if (source.secret) authData[t('openconnector', 'Secret')] = source.secret
			if (source.authorizationHeader) authData[t('openconnector', 'Authorization Header')] = source.authorizationHeader
			if (source.authenticationConfig && source.authenticationConfig.length > 0) {
				source.authenticationConfig.forEach((config, index) => {
					authData[t('openconnector', 'Auth config {index}', { index: index + 1 })] = typeof config === 'object' ? JSON.stringify(config) : config
				})
			}

			return authData
		},
		linkedSynchronizations() {
			return synchronizationStore.synchronizationList?.filter((item) =>
				item.sourceId.toString() === sourceStore.item?.id?.toString(),
			) || []
		},
	},
	mounted() {
		this.refreshSourceLogs()
		synchronizationStore.refreshSynchronizationList()
	},
	methods: {
		async onFormConfirm(formData) {
			try {
				const payload = { ...formData, location: (formData.location || '').replace(/\/+$/, '') }
				await sourceStore.save(payload)
				const id = sourceStore.item?.id
				if (id != null) await sourceStore.getOne(String(id))
				this.$refs.formDialog.setResult({ success: true })
			} catch (e) {
				this.$refs.formDialog.setResult({
					error: e.message || t('openconnector', 'An error occurred while saving the source'),
				})
			}
		},
		async onDeleteConfirm() {
			try {
				await sourceStore.deleteOne(sourceStore.item)
				this.$refs.deleteDialog.setResult({ success: true })
				navigationStore.setModal(false)
			} catch (e) {
				this.$refs.deleteDialog.setResult({
					error: e.message || t('openconnector', 'An error occurred while deleting the source'),
				})
			}
		},
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
			sourceStore.refreshLogs()
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
			sourceStore.setItem(sourceStore.item)
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

.tabPanel {
	padding: 15px 0;
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

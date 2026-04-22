<script setup>
import { consumerStore } from '../../store/store.js'
import { translate as t } from '@nextcloud/l10n'
import { consumerSchema } from './consumerSchema.js'
</script>

<template>
	<NcAppContent>
		<NcEmptyContent v-if="loading"
			class="detailContainer"
			:name="t('openconnector', 'Loading...')"
			:description="t('openconnector', 'Fetching consumer details')">
			<template #icon>
				<NcLoadingIcon />
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="loadError"
			class="detailContainer"
			:name="t('openconnector', 'Error')"
			:description="t('openconnector', 'Failed to load consumer.')">
			<template #icon>
				<Webhook />
			</template>
			<template #action>
				<NcButton type="secondary" @click="backToList">
					{{ t('openconnector', 'Back') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="!consumer"
			class="detailContainer"
			:name="t('openconnector', 'Consumer not found')">
			<template #icon>
				<Webhook />
			</template>
			<template #action>
				<NcButton type="secondary" @click="backToList">
					{{ t('openconnector', 'Back') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<div v-else class="detailContainer">
			<div class="detailHeader">
				<NcButton type="tertiary" :aria-label="t('openconnector', 'Back to consumers')" @click="backToList">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
					{{ t('openconnector', 'Consumers') }}
				</NcButton>
				<h1 class="h1">
					{{ consumer?.name }}
				</h1>
				<NcActions :primary="true" :menu-name="t('openconnector', 'Actions')">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton close-after-click @click="showEditDialog = true">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('openconnector', 'Edit') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="showDeleteDialog = true">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						{{ t('openconnector', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</div>

			<table class="statisticsTable consumerStats">
				<thead>
					<tr>
						<th>{{ t('openconnector', 'Property') }}</th>
						<th>{{ t('openconnector', 'Value') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>{{ t('openconnector', 'ID') }}</td>
						<td>{{ consumer?.id || '-' }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'UUID') }}</td>
						<td>{{ consumer?.uuid || '-' }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Name') }}</td>
						<td>{{ consumer?.name || '-' }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Description') }}</td>
						<td>{{ consumer?.description || '-' }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Domains') }}</td>
						<td>{{ consumer?.domains?.join(', ') || '-' }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'IPs') }}</td>
						<td>{{ consumer?.ips?.join(', ') || '-' }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Authorization type') }}</td>
						<td>{{ consumer?.authorizationType || '-' }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Authorization configuration') }}</td>
						<td>
							<pre v-if="consumer?.authorizationConfiguration" class="authConfig">{{ formattedAuthConfig }}</pre>
							<span v-else>-</span>
						</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Created') }}</td>
						<td>
							<NcDateTime v-if="consumer?.created" :timestamp="new Date(consumer.created)" />
							<span v-else>-</span>
						</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Updated') }}</td>
						<td>
							<NcDateTime v-if="consumer?.updated" :timestamp="new Date(consumer.updated)" />
							<span v-else>-</span>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<CnFormDialog
			v-if="showEditDialog"
			ref="formDialog"
			:schema="schema"
			:item="consumer"
			:dialog-title="t('openconnector', 'Edit consumer')"
			name-field="name"
			@confirm="onFormConfirm"
			@close="showEditDialog = false" />

		<CnDeleteDialog
			v-if="showDeleteDialog && consumer"
			ref="deleteDialog"
			:item="consumer"
			name-field="name"
			:dialog-title="t('openconnector', 'Delete consumer')"
			:success-text="t('openconnector', 'Successfully deleted consumer')"
			@confirm="onDeleteConfirm"
			@close="showDeleteDialog = false" />
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActions, NcActionButton, NcButton, NcEmptyContent, NcLoadingIcon, NcDateTime } from '@nextcloud/vue'
import { CnFormDialog, CnDeleteDialog } from '@conduction/nextcloud-vue'
import Webhook from 'vue-material-design-icons/Webhook.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'ConsumerDetails',
	components: {
		NcAppContent,
		NcActions,
		NcActionButton,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcDateTime,
		CnFormDialog,
		CnDeleteDialog,
		Webhook,
		ArrowLeft,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
	},
	data() {
		return {
			consumer: null,
			loading: false,
			loadError: false,
			activeLoadId: null,
			showEditDialog: false,
			showDeleteDialog: false,
		}
	},
	computed: {
		schema() {
			return consumerSchema()
		},
		formattedAuthConfig() {
			const cfg = this.consumer?.authorizationConfiguration
			if (!cfg) return '-'
			try {
				return JSON.stringify(cfg, null, 2)
			} catch (e) {
				return String(cfg)
			}
		},
	},
	watch: {
		'$route.params.id': {
			immediate: true,
			async handler(newId) {
				await this.loadByRoute(newId)
			},
		},
		'consumerStore.item': {
			handler(newItem) {
				if (newItem && String(newItem.id) === String(this.$route.params.id)) {
					this.consumer = newItem
				}
			},
		},
	},
	methods: {
		async loadByRoute(id) {
			const loadId = Symbol('RaceConditionGuard')
			this.activeLoadId = loadId
			this.loadError = false
			if (!id) {
				this.consumer = null
				consumerStore.setItem(null)
				this.loading = false
				return
			}

			this.loading = true
			try {
				const entity = await consumerStore.getOne(String(id))
				if (this.activeLoadId !== loadId) return
				this.consumer = entity
			} catch (e) {
				if (this.activeLoadId !== loadId) return
				this.loadError = true
				this.consumer = null
			} finally {
				this.loading = false
			}
		},
		backToList() {
			consumerStore.setItem(null)
			this.loadError = false
			this.$router.push('/consumers')
		},
		async onFormConfirm(formData) {
			try {
				await consumerStore.save(formData)
				this.$refs.formDialog.setResult({ success: true })
			} catch (e) {
				this.$refs.formDialog.setResult({
					error: e.message || t('openconnector', 'An error occurred while saving the consumer'),
				})
			}
		},
		async onDeleteConfirm() {
			try {
				await consumerStore.deleteOne(this.consumer)
				this.$refs.deleteDialog.setResult({ success: true })
				this.$router.push('/consumers')
			} catch (e) {
				this.$refs.deleteDialog.setResult({
					error: e.message || t('openconnector', 'An error occurred while deleting the consumer'),
				})
			}
		},
	},
}
</script>

<style scoped>
.detailContainer {
	padding: 20px;
}

.detailHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 10px;
	margin-bottom: 20px;
}

.detailHeader h1 {
	flex: 1;
	margin: 0;
}

.authConfig {
	margin: 0;
	white-space: pre-wrap;
	word-break: break-word;
	font-family: monospace;
}
</style>

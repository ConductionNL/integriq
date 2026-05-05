<script setup>
import { translate as t } from '@nextcloud/l10n'
import { consumerStore } from '../../store/store.js'
import { consumerSchema } from './consumerSchema.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openconnector', 'Consumers')"
			:description="t('openconnector', 'Manage your API consumers')"
			:show-title="true"
			:objects="filteredConsumers"
			:schema="schema"
			:exclude-columns="['authorizationConfiguration']"
			:pagination="paginationData"
			:loading="consumerStore.loading"
			:refreshing="consumerStore.loading"
			:view-mode="consumerStore.viewMode"
			:selectable="true"
			:selected-ids="selectedConsumers"
			:show-copy-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			show-view-toggle
			:add-label="t('openconnector', 'Add consumer')"
			row-key="id"
			:empty-text="emptyContentName"
			name-field="name"
			@add="onAdd"
			@create="onSave"
			@edit="onSave"
			@delete="onDelete"
			@refresh="consumerStore.refreshList()"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="consumerStore.setViewMode($event)"
			@select="onSelect"
			@row-click="openConsumer">
			<!-- Card view -->
			<template #card="{ object: consumer }">
				<div class="card">
					<div class="cardHeader">
						<h2 v-tooltip.bottom="consumer.description">
							<Webhook :size="20" />
							{{ consumer.name }}
						</h2>
						<NcActions :primary="true" :menu-name="t('openconnector', 'Actions')">
							<template #icon>
								<DotsHorizontal :size="20" />
							</template>
							<NcActionButton close-after-click @click="openConsumer(consumer)">
								<template #icon>
									<Eye :size="20" />
								</template>
								{{ t('openconnector', 'View') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="editConsumer(consumer)">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('openconnector', 'Edit') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="$refs.indexPage.openDeleteDialog(consumer)">
								<template #icon>
									<TrashCanOutline :size="20" />
								</template>
								{{ t('openconnector', 'Delete') }}
							</NcActionButton>
						</NcActions>
					</div>
					<div class="consumerDetails">
						<p v-if="consumer.description" class="consumerDescription">
							{{ consumer.description }}
						</p>
						<table class="statisticsTable consumerStats">
							<thead>
								<tr>
									<th>{{ t('openconnector', 'Property') }}</th>
									<th>{{ t('openconnector', 'Value') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td>{{ t('openconnector', 'Authorization type') }}</td>
									<td>{{ consumer.authorizationType || '-' }}</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Domains') }}</td>
									<td>{{ consumer.domains?.join(', ') || '-' }}</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'IPs') }}</td>
									<td>{{ consumer.ips?.join(', ') || '-' }}</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Created') }}</td>
									<td>
										<NcDateTime v-if="consumer.created" :timestamp="new Date(consumer.created)" />
										<span v-else>-</span>
									</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Updated') }}</td>
									<td>
										<NcDateTime v-if="consumer.updated" :timestamp="new Date(consumer.updated)" />
										<span v-else>-</span>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</template>

			<!-- Table columns -->
			<template #column-name="{ row }">
				<div class="titleContent">
					<strong>{{ row.name }}</strong>
					<span v-if="row.description" class="textDescription textEllipsis">{{ row.description }}</span>
				</div>
			</template>

			<template #column-description="{ row }">
				<span v-if="row.description" class="textEllipsis">{{ row.description }}</span>
				<span v-else>-</span>
			</template>

			<template #column-authorizationType="{ row }">
				{{ row.authorizationType || '-' }}
			</template>

			<template #column-domains="{ row }">
				{{ row.domains?.join(', ') || '-' }}
			</template>

			<template #column-ips="{ row }">
				{{ row.ips?.join(', ') || '-' }}
			</template>

			<!-- Row actions (table view) -->
			<template #row-actions="{ row: consumer }">
				<NcActions :primary="false">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton close-after-click @click="openConsumer(consumer)">
						<template #icon>
							<Eye :size="20" />
						</template>
						{{ t('openconnector', 'View') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="editConsumer(consumer)">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('openconnector', 'Edit') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="$refs.indexPage.openDeleteDialog(consumer)">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						{{ t('openconnector', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActions, NcActionButton, NcDateTime } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import Webhook from 'vue-material-design-icons/Webhook.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Eye from 'vue-material-design-icons/Eye.vue'

export default {
	name: 'ConsumersIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		NcActions,
		NcActionButton,
		NcDateTime,
		Webhook,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Eye,
	},
	data() {
		return {
			selectedConsumers: [],
			pagination: {
				page: 1,
				limit: 20,
			},
		}
	},
	computed: {
		consumerStore() {
			return consumerStore
		},
		schema() {
			return consumerSchema()
		},
		filteredConsumers() {
			return consumerStore.list || []
		},
		paginationData() {
			const page = this.pagination.page || 1
			const limit = this.pagination.limit || 20
			const total = this.filteredConsumers.length
			const pages = Math.max(1, Math.ceil(total / limit))
			return { page, pages, total, limit }
		},
		emptyContentName() {
			if (consumerStore.error) return consumerStore.error
			if (!consumerStore.list?.length) return t('openconnector', 'No consumers found')
			return t('openconnector', 'Loading consumers...')
		},
	},
	mounted() {
		consumerStore.refreshList()
	},
	methods: {
		onAdd() {
			this.$refs.indexPage.openFormDialog(null)
		},
		editConsumer(consumer) {
			this.$refs.indexPage.openFormDialog(consumer)
		},
		openConsumer(consumer) {
			if (consumer?.id == null) return
			this.$router.push('/consumers/' + consumer.id)
		},
		async onSave(formData) {
			try {
				await consumerStore.save(formData)
				this.$refs.indexPage.setFormResult({ success: true })
			} catch (e) {
				this.$refs.indexPage.setFormResult({
					error: e.message || t('openconnector', 'An error occurred while saving the consumer'),
				})
			}
		},
		async onDelete(id) {
			try {
				await consumerStore.deleteOne({ id })
				this.$refs.indexPage.setSingleDeleteResult({ success: true })
			} catch (e) {
				this.$refs.indexPage.setSingleDeleteResult({
					error: e.message || t('openconnector', 'An error occurred while deleting the consumer'),
				})
			}
		},
		onPageChanged(page) {
			this.pagination.page = page
		},
		onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
		},
		onSelect(ids) {
			this.selectedConsumers = ids
		},
	},
}
</script>

<style scoped>
.truncatedUrl {
	max-width: 300px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	display: inline-block;
}
</style>

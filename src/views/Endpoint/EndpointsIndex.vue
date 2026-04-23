<script setup>
import { translate as t } from '@nextcloud/l10n'
import { endpointStore, navigationStore, ruleStore } from '../../store/store.js'
import { Endpoint } from '../../entities/index.js'
import { endpointSchema } from './endpointSchema.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openconnector', 'Endpoints')"
			:description="t('openconnector', 'Manage your endpoints and their rules')"
			:show-title="true"
			:objects="filteredEndpoints"
			:schema="schema"
			:exclude-columns="['description', 'endpointArray', 'endpointRegex', 'configurations', 'slug', 'targetId', 'targetType', 'version']"
			:pagination="paginationData"
			:loading="endpointStore.loading"
			:view-mode="endpointStore.viewMode"
			:selectable="true"
			:selected-ids="selectedEndpoints"
			:show-copy-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			show-view-toggle
			:add-label="t('openconnector', 'Add endpoint')"
			row-key="id"
			:empty-text="emptyContentName"
			name-field="name"
			@add="onAdd"
			@delete="onDelete"
			@refresh="endpointStore.refreshList()"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="endpointStore.setViewMode($event)"
			@select="onSelect"
			@row-click="openEndpoint">
			<template #action-items>
				<NcActionButton close-after-click @click="navigationStore.setModal('importFile')">
					<template #icon>
						<FileImportOutline :size="20" />
					</template>
					{{ t('openconnector', 'Import') }}
				</NcActionButton>
			</template>

			<!-- Card view -->
			<template #card="{ object: endpoint }">
				<div class="card">
					<div class="cardHeader">
						<h2 v-tooltip.bottom="endpoint.description">
							<Api :size="20" :fill-color="getEndpointColor(endpoint.method)" />
							{{ endpoint.name }}
						</h2>
						<NcActions :primary="true" :menu-name="t('openconnector', 'Actions')">
							<template #icon>
								<DotsHorizontal :size="20" />
							</template>
							<NcActionButton close-after-click @click="openEndpoint(endpoint)">
								<template #icon>
									<Eye :size="20" />
								</template>
								{{ t('openconnector', 'View') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="editEndpoint(endpoint)">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('openconnector', 'Edit') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="endpointStore.exportEndpoint(endpoint.id)">
								<template #icon>
									<FileExportOutline :size="20" />
								</template>
								{{ t('openconnector', 'Export endpoint') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="openAddRuleDialog(endpoint)">
								<template #icon>
									<Plus :size="20" />
								</template>
								{{ t('openconnector', 'Add Rule') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="$refs.indexPage.openDeleteDialog(endpoint)">
								<template #icon>
									<TrashCanOutline :size="20" />
								</template>
								{{ t('openconnector', 'Delete') }}
							</NcActionButton>
						</NcActions>
					</div>
					<div class="endpointDetails">
						<p v-if="endpoint.description" class="endpointDescription">
							{{ endpoint.description }}
						</p>
						<table class="statisticsTable endpointStats">
							<thead>
								<tr>
									<th>{{ t('openconnector', 'Property') }}</th>
									<th>{{ t('openconnector', 'Value') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td>{{ t('openconnector', 'Method') }}</td>
									<td>
										<span class="methodBadge" :class="`method-${(endpoint.method || 'unknown').toLowerCase()}`">
											{{ endpoint.method || t('openconnector', 'Unknown') }}
										</span>
									</td>
								</tr>
								<tr v-if="endpoint.endpoint">
									<td>{{ t('openconnector', 'Endpoint') }}</td>
									<td class="truncatedUrl">
										{{ endpoint.endpoint }}
									</td>
								</tr>
								<tr v-if="endpoint.version">
									<td>{{ t('openconnector', 'Version') }}</td>
									<td>{{ endpoint.version }}</td>
								</tr>
								<tr v-if="endpoint.targetType">
									<td>{{ t('openconnector', 'Target Type') }}</td>
									<td>{{ endpoint.targetType }}</td>
								</tr>
								<tr v-if="endpoint.targetId">
									<td>{{ t('openconnector', 'Target ID') }}</td>
									<td>{{ endpoint.targetId }}</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Rules') }}</td>
									<td>{{ endpoint.rules?.length || 0 }}</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Created') }}</td>
									<td>
										<NcDateTime v-if="endpoint.created" :timestamp="new Date(endpoint.created)" />
										<span v-else>-</span>
									</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Updated') }}</td>
									<td>
										<NcDateTime v-if="endpoint.updated" :timestamp="new Date(endpoint.updated)" />
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

			<template #column-method="{ row }">
				<span class="methodBadge" :class="`method-${(row.method || 'unknown').toLowerCase()}`">
					{{ row.method || 'UNKNOWN' }}
				</span>
			</template>

			<template #column-endpoint="{ row }">
				<span v-if="row.endpoint" class="truncatedUrl">{{ row.endpoint }}</span>
				<span v-else>-</span>
			</template>

			<template #column-rules="{ row }">
				{{ row.rules?.length || 0 }}
			</template>

			<template #column-created="{ row }">
				<NcDateTime v-if="row.created" :timestamp="new Date(row.created)" />
				<span v-else>-</span>
			</template>

			<template #column-updated="{ row }">
				<NcDateTime v-if="row.updated" :timestamp="new Date(row.updated)" />
				<span v-else>-</span>
			</template>

			<!-- Override the built-in form-dialog so we can inject the targetId picker.
			     CnIndexPage does not forward per-field scoped slots to its inner CnFormDialog. -->
			<template #form-dialog="{ show, item: editItem, schema: dialogSchema, close }">
				<CnFormDialog
					v-if="show"
					ref="formDialog"
					:schema="dialogSchema"
					:item="editItem"
					:dialog-title="editItem?.id ? t('openconnector', 'Edit endpoint') : t('openconnector', 'Create endpoint')"
					name-field="name"
					@confirm="onFormConfirmInSlot(editItem, $event)"
					@close="close">
					<template #field-targetId="{ value, updateField }">
						<EndpointTargetIdField :model-value="value || ''" @update:model-value="updateField('targetId', $event)" />
					</template>
				</CnFormDialog>
			</template>

			<!-- Row actions (table view) -->
			<template #row-actions="{ object: endpoint }">
				<NcActions :primary="false">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton close-after-click @click="openEndpoint(endpoint)">
						<template #icon>
							<Eye :size="20" />
						</template>
						{{ t('openconnector', 'View') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="editEndpoint(endpoint)">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('openconnector', 'Edit') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="endpointStore.exportEndpoint(endpoint.id)">
						<template #icon>
							<FileExportOutline :size="20" />
						</template>
						{{ t('openconnector', 'Export endpoint') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="openAddRuleDialog(endpoint)">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('openconnector', 'Add Rule') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="$refs.indexPage.openDeleteDialog(endpoint)">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						{{ t('openconnector', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</template>
		</CnIndexPage>

		<!-- Standalone add-rule dialog, driven by a local flag so the list page
		     doesn't rely on navigationStore coordination -->
		<CnFormDialog
			v-if="showAddRuleDialog && addRuleTarget"
			ref="addRuleDialog"
			:schema="addRuleSchemaForTarget"
			:item="{}"
			:dialog-title="t('openconnector', 'Add rule to endpoint')"
			:confirm-label="t('openconnector', 'Add')"
			@confirm="onAddRuleConfirm"
			@close="closeAddRuleDialog" />
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActions, NcActionButton, NcDateTime } from '@nextcloud/vue'
import { CnIndexPage, CnFormDialog } from '@conduction/nextcloud-vue'
import Api from 'vue-material-design-icons/Api.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import FileImportOutline from 'vue-material-design-icons/FileImportOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import { getTheme } from '../../services/getTheme.js'
import EndpointTargetIdField from './EndpointTargetIdField.vue'

export default {
	name: 'EndpointsIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		CnFormDialog,
		NcActions,
		NcActionButton,
		NcDateTime,
		Api,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		FileExportOutline,
		FileImportOutline,
		Plus,
		Eye,
		EndpointTargetIdField,
	},
	data() {
		return {
			selectedEndpoints: [],
			pagination: {
				page: 1,
				limit: 20,
			},
			showAddRuleDialog: false,
			addRuleTarget: null,
		}
	},
	computed: {
		endpointStore() {
			return endpointStore
		},
		navigationStore() {
			return navigationStore
		},
		schema() {
			return endpointSchema()
		},
		filteredEndpoints() {
			return endpointStore.list || []
		},
		paginationData() {
			const page = this.pagination.page || 1
			const limit = this.pagination.limit || 20
			const total = this.filteredEndpoints.length
			const pages = Math.max(1, Math.ceil(total / limit))
			return { page, pages, total, limit }
		},
		emptyContentName() {
			if (endpointStore.error) return endpointStore.error
			if (!endpointStore.list?.length) return t('openconnector', 'No endpoints found')
			return t('openconnector', 'Loading endpoints...')
		},
		addRuleSchemaForTarget() {
			const existing = (this.addRuleTarget?.rules || []).map(id => String(id))
			return {
				title: t('openconnector', 'Add rule to endpoint'),
				required: ['rule'],
				properties: {
					rule: {
						type: 'string',
						title: t('openconnector', 'Select rule'),
						enum: async () => {
							await ruleStore.refreshRuleList()
							return (ruleStore.ruleList || [])
								.filter(r => !existing.includes(String(r.id)))
								.map(r => ({ id: String(r.id), label: r.name }))
						},
					},
				},
			}
		},
	},
	mounted() {
		endpointStore.refreshList()
	},
	methods: {
		onAdd() {
			this.$refs.indexPage.openFormDialog(null)
		},
		editEndpoint(endpoint) {
			this.$refs.indexPage.openFormDialog(endpoint)
		},
		openEndpoint(endpoint) {
			if (endpoint?.id == null) return
			this.$router.push('/endpoints/' + endpoint.id)
		},
		async onFormConfirmInSlot(editItem, formData) {
			try {
				const merged = editItem?.id ? { ...formData, id: editItem.id } : formData
				const payload = this.prepareSavePayload(merged)
				await endpointStore.save(payload)
				this.$refs.formDialog.setResult({ success: true })
			} catch (e) {
				this.$refs.formDialog.setResult({
					error: e.message || t('openconnector', 'An error occurred while saving the endpoint'),
				})
			}
		},
		async onDelete(id) {
			try {
				await endpointStore.deleteOne({ id })
				this.$refs.indexPage.setSingleDeleteResult({ success: true })
			} catch (e) {
				this.$refs.indexPage.setSingleDeleteResult({
					error: e.message || t('openconnector', 'An error occurred while deleting the endpoint'),
				})
			}
		},
		prepareSavePayload(formData) {
			return new Endpoint({
				...formData,
				endpointArray: Array.isArray(formData.endpointArray)
					? formData.endpointArray
					: (formData.endpointArray || '').split(/ *, */g).filter(Boolean),
				configurations: (formData.configurations || []).map(c => String(c?.id ?? c)),
				rules: (formData.rules || []).map(id => String(id)),
			})
		},
		openAddRuleDialog(endpoint) {
			this.addRuleTarget = endpoint
			this.showAddRuleDialog = true
		},
		closeAddRuleDialog() {
			this.showAddRuleDialog = false
			this.addRuleTarget = null
		},
		async onAddRuleConfirm(formData) {
			try {
				const ruleValue = formData.rule
				const ruleId = ruleValue?.id ?? ruleValue
				const base = this.addRuleTarget
				const updatedRules = [
					...(base.rules || []).map(id => String(id)),
					String(ruleId),
				]
				const payload = new Endpoint({
					...base,
					endpointArray: Array.isArray(base.endpointArray)
						? base.endpointArray
						: (base.endpointArray || '').split(/ *, */g).filter(Boolean),
					rules: updatedRules,
				})
				await endpointStore.save(payload)
				this.$refs.addRuleDialog.setResult({ success: true })
			} catch (e) {
				this.$refs.addRuleDialog.setResult({
					error: e.message || t('openconnector', 'An error occurred while adding the rule'),
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
			this.selectedEndpoints = ids
		},
		getEndpointColor(method) {
			const theme = getTheme()

			if (theme === 'dark') {
				switch (method) {
				case 'GET': return '#5c8d4a'
				case 'POST': return '#5d82c0'
				case 'PUT': return '#a46f96'
				case 'PATCH': return '#bc6d3d'
				case 'DELETE': return '#d25c53'
				default: return '#fff'
				}
			} else {
				switch (method) {
				case 'GET': return '#4e7f3d'
				case 'POST': return '#466eaa'
				case 'PUT': return '#87547a'
				case 'PATCH': return '#a95d2e'
				case 'DELETE': return '#b13f3a'
				default: return '#000'
				}
			}
		},
	},
}
</script>

<style scoped>
.methodBadge {
	display: inline-flex;
	padding: 2px 6px;
	border-radius: 8px;
	font-size: 0.7rem;
	font-weight: 600;
	color: white;
}
.methodBadge.method-get { background: var(--color-success); }
.methodBadge.method-post { background: var(--color-info); }
.methodBadge.method-put,
.methodBadge.method-patch { background: var(--color-warning); }
.methodBadge.method-delete { background: var(--color-error); }
.methodBadge.method-unknown { background: var(--color-text-maxcontrast); }

.truncatedUrl {
	max-width: 300px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	display: inline-block;
}
</style>

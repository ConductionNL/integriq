<script setup>
import { translate as t } from '@nextcloud/l10n'
import { endpointStore, navigationStore, ruleStore } from '../../store/store.js'
import { endpointSchema } from '../../views/Endpoint/endpointSchema.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'viewEndpoint'"
		ref="modalRef"
		:name="endpointStore.item?.name || t('openconnector', 'Endpoint Details')"
		@close="onModalClose">
		<div class="modal-content">
			<p v-if="endpointStore.item?.description" class="endpoint-description">
				{{ endpointStore.item.description }}
			</p>

			<!-- Endpoint Properties -->
			<div class="endpoint-properties">
				<table class="statisticsTable endpointStats">
					<thead>
						<tr>
							<th>{{ t('openconnector', 'Property') }}</th>
							<th>{{ t('openconnector', 'Value') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td>{{ t('openconnector', 'ID') }}</td>
							<td>{{ endpointStore.item?.id || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'UUID') }}</td>
							<td>{{ endpointStore.item?.uuid || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Version') }}</td>
							<td>{{ endpointStore.item?.version || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Endpoint') }}</td>
							<td>{{ endpointStore.item?.endpoint || '-' }}</td>
						</tr>
						<tr v-if="endpointStore.item?.endpointArray?.length">
							<td>{{ t('openconnector', 'Endpoint Array') }}</td>
							<td>{{ endpointStore.item.endpointArray.join(', ') || '-' }}</td>
						</tr>
						<tr v-if="endpointStore.item?.endpointRegex">
							<td>{{ t('openconnector', 'Endpoint Regex') }}</td>
							<td>{{ endpointStore.item.endpointRegex }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Method') }}</td>
							<td>{{ endpointStore.item?.method || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Target Type') }}</td>
							<td>{{ endpointStore.item?.targetType || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Target ID') }}</td>
							<td>{{ endpointStore.item?.targetId || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Created') }}</td>
							<td>{{ endpointStore.item?.created ? new Date(endpointStore.item.created).toLocaleDateString() : '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Updated') }}</td>
							<td>{{ endpointStore.item?.updated ? new Date(endpointStore.item.updated).toLocaleDateString() : '-' }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Tabs -->
			<div class="tabContainer">
				<BTabs content-class="mt-3" justified>
					<BTab :title="t('openconnector', 'Rules')">
						<div v-if="endpointStore.item?.rules?.length" class="rules-list">
							<NcListItem v-for="ruleId in endpointStore.item.rules"
								:key="ruleId"
								:name="getRuleName(ruleId)"
								:bold="false"
								:force-display-actions="true"
								@click="viewRule(ruleId)">
								<template #icon>
									<SitemapOutline :size="44" />
								</template>
								<template #subname>
									<span v-if="rulesLoaded">{{ getRuleType(ruleId) }}</span>
									<span v-else>{{ t('openconnector', 'Loading...') }}</span>
								</template>
								<template #actions>
									<NcActionButton close-after-click @click.stop="viewRule(ruleId)">
										<template #icon>
											<EyeOutline :size="20" />
										</template>
										{{ t('openconnector', 'View') }}
									</NcActionButton>
									<NcActionButton close-after-click @click.stop="removeRule(ruleId)">
										<template #icon>
											<LinkOff :size="20" />
										</template>
										{{ t('openconnector', 'Remove') }}
									</NcActionButton>
								</template>
							</NcListItem>
						</div>
						<div v-if="!endpointStore.item?.rules?.length" class="tabPanel">
							<NcEmptyContent
								:name="t('openconnector', 'No rules')"
								:description="t('openconnector', 'No rules found for this endpoint')">
								<template #icon>
									<SitemapOutline :size="64" />
								</template>
								<template #action>
									<NcButton @click="showAddRuleDialog = true">
										{{ t('openconnector', 'Add Rule') }}
									</NcButton>
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
				<NcButton @click="showAddRuleDialog = true">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('openconnector', 'Add Rule') }}
				</NcButton>
				<NcButton type="error" @click="showDeleteDialog = true">
					<template #icon>
						<TrashCanOutline :size="20" />
					</template>
					{{ t('openconnector', 'Delete') }}
				</NcButton>
			</div>

			<CnFormDialog
				v-if="showEditDialog && endpointStore.item"
				ref="formDialog"
				:schema="schema"
				:item="endpointStore.item"
				:dialog-title="t('openconnector', 'Edit endpoint')"
				name-field="name"
				@confirm="onFormConfirm"
				@close="showEditDialog = false">
				<template #field-targetId="{ value, updateField }">
					<EndpointTargetIdField :model-value="value || ''" @update:model-value="updateField('targetId', $event)" />
				</template>
			</CnFormDialog>

			<CnDeleteDialog
				v-if="showDeleteDialog && endpointStore.item"
				ref="deleteDialog"
				:item="endpointStore.item"
				name-field="name"
				:dialog-title="t('openconnector', 'Delete endpoint')"
				:success-text="t('openconnector', 'Successfully deleted endpoint')"
				@confirm="onDeleteConfirm"
				@close="showDeleteDialog = false" />

			<CnFormDialog
				v-if="showAddRuleDialog && endpointStore.item"
				ref="addRuleDialog"
				:schema="addRuleSchema"
				:item="{}"
				:dialog-title="t('openconnector', 'Add rule to endpoint')"
				:confirm-label="t('openconnector', 'Add')"
				@confirm="onAddRuleConfirm"
				@close="showAddRuleDialog = false" />
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton, NcListItem, NcActionButton, NcEmptyContent } from '@nextcloud/vue'
import { CnFormDialog, CnDeleteDialog } from '@conduction/nextcloud-vue'
import { BTabs, BTab } from 'bootstrap-vue'
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import LinkOff from 'vue-material-design-icons/LinkOff.vue'
import _ from 'lodash'

import { Endpoint } from '../../entities/index.js'
import EndpointTargetIdField from '../../views/Endpoint/EndpointTargetIdField.vue'

export default {
	name: 'ViewEndpoint',
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
		SitemapOutline,
		Pencil,
		TrashCanOutline,
		Plus,
		EyeOutline,
		LinkOff,
		EndpointTargetIdField,
	},
	data() {
		return {
			rulesList: [],
			rulesLoaded: false,
			showEditDialog: false,
			showDeleteDialog: false,
			showAddRuleDialog: false,
		}
	},
	computed: {
		schema() {
			return endpointSchema()
		},
		addRuleSchema() {
			const existing = (endpointStore.item?.rules || []).map(id => String(id))
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
		this.loadRules()
	},
	methods: {
		async loadRules() {
			try {
				await ruleStore.refreshRuleList()
				this.rulesList = ruleStore.ruleList
				this.rulesLoaded = true
			} catch (error) {
				console.error('Failed to load rules:', error)
			}
		},
		getRuleName(ruleId) {
			const rule = this.rulesList.find(rule => String(rule.id) === String(ruleId))
			return rule ? rule.name : t('openconnector', 'Rule {id}', { id: ruleId })
		},
		getRuleType(ruleId) {
			const rule = this.rulesList.find(rule => String(rule.id) === String(ruleId))
			if (!rule) return t('openconnector', 'Unknown type')

			switch (rule.type) {
			case 'error':
				return t('openconnector', 'Error Handler')
			case 'mapping':
				return t('openconnector', 'Data Mapping')
			case 'synchronization':
				return t('openconnector', 'Synchronization')
			case 'javascript':
				return t('openconnector', 'JavaScript')
			default:
				return rule.type || t('openconnector', 'Unknown type')
			}
		},
		viewRule(ruleId) {
			const rule = this.rulesList.find(rule => String(rule.id) === String(ruleId))
			if (rule) {
				ruleStore.setRuleItem(rule)
				this.$router.push(`/rules/${ruleId}`)
			}
		},
		async removeRule(ruleId) {
			try {
				const updatedEndpoint = _.cloneDeep(endpointStore.item)

				updatedEndpoint.rules = updatedEndpoint.rules.filter(id => String(id) !== String(ruleId))

				const newEndpointItem = new Endpoint({
					...updatedEndpoint,
					endpointArray: Array.isArray(updatedEndpoint.endpointArray)
						? updatedEndpoint.endpointArray
						: updatedEndpoint.endpointArray.split(/ *, */g),
					rules: updatedEndpoint.rules.map(id => String(id)),
				})

				await endpointStore.save(newEndpointItem)

				await this.loadRules()
			} catch (error) {
				console.error('Failed to remove rule:', error)
			}
		},
		onModalClose() {
			this.showEditDialog = false
			this.showDeleteDialog = false
			this.showAddRuleDialog = false
			navigationStore.setModal(false)
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
		async onFormConfirm(formData) {
			try {
				const payload = this.prepareSavePayload(formData)
				await endpointStore.save(payload)
				this.$refs.formDialog.setResult({ success: true })
				await endpointStore.getOne(String(endpointStore.item.id))
			} catch (e) {
				this.$refs.formDialog.setResult({
					error: e.message || t('openconnector', 'An error occurred while saving the endpoint'),
				})
			}
		},
		async onDeleteConfirm() {
			try {
				await endpointStore.deleteOne(endpointStore.item)
				this.$refs.deleteDialog.setResult({ success: true })
				navigationStore.setModal(false)
			} catch (e) {
				this.$refs.deleteDialog.setResult({
					error: e.message || t('openconnector', 'An error occurred while deleting the endpoint'),
				})
			}
		},
		async onAddRuleConfirm(formData) {
			try {
				const ruleValue = formData.rule
				const ruleId = ruleValue?.id ?? ruleValue
				const base = endpointStore.item
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
				await this.loadRules()
			} catch (e) {
				this.$refs.addRuleDialog.setResult({
					error: e.message || t('openconnector', 'An error occurred while adding the rule'),
				})
			}
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

.endpoint-description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 20px;
	font-style: italic;
}

.endpoint-properties {
	margin-bottom: 20px;
}

.selectedIcon {
	color: var(--color-primary);
}
</style>

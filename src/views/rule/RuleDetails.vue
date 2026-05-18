<script setup>
import { translate as t } from '@nextcloud/l10n'
import { ruleStore, navigationStore } from '../../store/store.js'
import { ruleTypeLabel, ruleActionLabel, ruleTimingLabel } from './ruleSchema.js'
</script>

<template>
	<NcAppContent>
		<NcEmptyContent v-if="loading"
			class="detailContainer"
			:name="t('openconnector', 'Loading...')"
			:description="t('openconnector', 'Fetching rule details')">
			<template #icon>
				<NcLoadingIcon />
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="loadError"
			class="detailContainer"
			:name="t('openconnector', 'Error')"
			:description="t('openconnector', 'Failed to load rule.')">
			<template #icon>
				<SitemapOutline />
			</template>
			<template #action>
				<NcButton type="secondary" @click="backToList">
					{{ t('openconnector', 'Back') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="!rule"
			class="detailContainer"
			:name="t('openconnector', 'Rule not found')">
			<template #icon>
				<SitemapOutline />
			</template>
			<template #action>
				<NcButton type="secondary" @click="backToList">
					{{ t('openconnector', 'Back') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<div v-else class="detailContainer">
			<div class="detailHeader">
				<NcButton type="tertiary" :aria-label="t('openconnector', 'Back to rules')" @click="backToList">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
					{{ t('openconnector', 'Rules') }}
				</NcButton>
				<h1 class="h1">
					{{ rule.name }}
				</h1>
				<NcActions :primary="true" :menu-name="t('openconnector', 'Actions')">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton close-after-click @click="editRule">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('openconnector', 'Edit') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="ruleStore.exportRule(rule.id)">
						<template #icon>
							<FileExportOutline :size="20" />
						</template>
						{{ t('openconnector', 'Export rule') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="showDeleteDialog = true">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						{{ t('openconnector', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</div>

			<p v-if="rule.description" class="description">
				{{ rule.description }}
			</p>

			<table class="statisticsTable ruleStats">
				<thead>
					<tr>
						<th>{{ t('openconnector', 'Property') }}</th>
						<th>{{ t('openconnector', 'Value') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>{{ t('openconnector', 'ID') }}</td>
						<td>{{ rule.id || '-' }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'UUID') }}</td>
						<td>{{ rule.uuid || '-' }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Type') }}</td>
						<td>{{ formatType(rule.type) }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Action') }}</td>
						<td>{{ formatAction(rule.action) }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Timing') }}</td>
						<td>{{ formatTiming(rule.timing) }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Order') }}</td>
						<td>{{ rule.order ?? 0 }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Created') }}</td>
						<td>
							<NcDateTime v-if="rule.created" :timestamp="new Date(rule.created)" />
							<span v-else>-</span>
						</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Updated') }}</td>
						<td>
							<NcDateTime v-if="rule.updated" :timestamp="new Date(rule.updated)" />
							<span v-else>-</span>
						</td>
					</tr>
				</tbody>
			</table>

			<section class="ruleSection">
				<h3>{{ t('openconnector', 'Conditions') }}</h3>
				<CnJsonViewer
					:value="formatJson(rule.conditions)"
					language="json"
					:read-only="true" />
			</section>

			<section class="ruleSection">
				<h3>{{ t('openconnector', 'Configuration') }}</h3>
				<CnJsonViewer
					:value="formatJson(rule.configuration)"
					language="json"
					:read-only="true" />
			</section>
		</div>

		<CnDeleteDialog
			v-if="showDeleteDialog && rule"
			ref="deleteDialog"
			:item="rule"
			name-field="name"
			:dialog-title="t('openconnector', 'Delete rule')"
			:success-text="t('openconnector', 'Successfully deleted rule')"
			@confirm="onDeleteConfirm"
			@close="showDeleteDialog = false" />
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActions, NcActionButton, NcButton, NcEmptyContent, NcLoadingIcon, NcDateTime } from '@nextcloud/vue'
import { CnJsonViewer, CnDeleteDialog } from '@conduction/nextcloud-vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'

export default {
	name: 'RuleDetails',
	components: {
		NcAppContent,
		NcActions,
		NcActionButton,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcDateTime,
		CnJsonViewer,
		CnDeleteDialog,
		ArrowLeft,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		FileExportOutline,
		SitemapOutline,
	},
	data() {
		return {
			rule: null,
			loading: false,
			loadError: false,
			activeLoadId: null,
			showDeleteDialog: false,
		}
	},
	watch: {
		'$route.params.id': {
			immediate: true,
			async handler(newId) {
				await this.loadByRoute(newId)
			},
		},
		'ruleStore.ruleItem': {
			handler(newItem) {
				if (newItem && String(newItem.id) === String(this.$route.params.id)) {
					this.rule = newItem
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
				this.rule = null
				ruleStore.setRuleItem(null)
				this.loading = false
				return
			}

			this.loading = true
			try {
				const { response, entity } = await ruleStore.fetchRule(String(id))
				if (this.activeLoadId !== loadId) return
				if (!response.ok) throw new Error('not found')
				this.rule = entity
			} catch (e) {
				if (this.activeLoadId !== loadId) return
				this.loadError = true
				this.rule = null
			} finally {
				if (this.activeLoadId === loadId) this.loading = false
			}
		},
		backToList() {
			ruleStore.setRuleItem(null)
			this.loadError = false
			this.$router.push('/rules')
		},
		editRule() {
			ruleStore.setRuleItem(this.rule)
			navigationStore.setModal('editRule')
		},
		async onDeleteConfirm() {
			try {
				await ruleStore.deleteRule(this.rule.id)
				this.$refs.deleteDialog.setResult({ success: true })
				this.$router.push('/rules')
			} catch (e) {
				this.$refs.deleteDialog.setResult({
					error: e.message || t('openconnector', 'An error occurred while deleting the rule'),
				})
			}
		},
		formatType(type) {
			return type ? ruleTypeLabel(type) : '-'
		},
		formatAction(action) {
			return action ? ruleActionLabel(action) : '-'
		},
		formatTiming(timing) {
			return timing ? ruleTimingLabel(timing) : '-'
		},
		formatJson(value) {
			if (value == null) return '{}'
			try {
				return JSON.stringify(value, null, 2)
			} catch {
				return String(value)
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

.description {
	margin: 0 0 20px 0;
	color: var(--color-text-maxcontrast);
}

.ruleSection {
	margin-top: 24px;
}

.ruleSection h3 {
	margin-bottom: 8px;
}
</style>

<script setup>
import { contractStore, synchronizationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		:name="t('openconnector', 'Contracts')"
		:subtitle="t('openconnector', 'Filter and manage contracts')"
		:subname="t('openconnector', 'Export, view, or delete contracts')"
		:open="navigationStore.sidebarState.contracts"
		@update:open="(e) => navigationStore.setSidebarState('contracts', e)">
		<NcAppSidebarTab id="filters-tab" :name="t('openconnector', 'Filters')" :order="1">
			<template #icon>
				<FilterOutline :size="20" />
			</template>

			<div class="sidebarSection">
				<h3 class="sidebarSection__title">
					{{ t('openconnector', 'Filter Contracts') }}
				</h3>
				<div class="sidebarSection__body">
					<NcSelect
						v-model="filters.synchronization"
						:options="synchronizationOptions"
						:placeholder="t('openconnector', 'All synchronizations')"
						:input-label="t('openconnector', 'Synchronization')"
						:clearable="true"
						@input="applyFilters" />
					<NcSelect
						v-model="filters.syncStatus"
						:options="syncStatusOptions"
						:placeholder="t('openconnector', 'All sync statuses')"
						:input-label="t('openconnector', 'Sync Status')"
						:clearable="true"
						@input="applyFilters" />
					<div class="sidebarSection__field">
						<span class="sidebarSection__fieldLabel">{{ t('openconnector', 'Last Synced') }}</span>
						<DateRangeInput
							:start="filters.dateFrom"
							:end="filters.dateTo"
							:max-start="new Date()"
							@update:start="(v) => { filters.dateFrom = v }"
							@update:end="(v) => { filters.dateTo = v }"
							@change="applyFilters" />
					</div>
					<NcButton v-if="hasActiveFilters" @click="clearFilters">
						<template #icon>
							<FilterOffOutline :size="20" />
						</template>
						{{ t('openconnector', 'Clear Filters') }}
					</NcButton>
				</div>
			</div>

			<div class="sidebarSection">
				<h3 class="sidebarSection__title">
					{{ t('openconnector', 'Export') }}
				</h3>
				<div class="sidebarSection__body">
					<NcButton @click="exportFiltered">
						<template #icon>
							<Download :size="20" />
						</template>
						{{ t('openconnector', 'Export Filtered Contracts') }}
					</NcButton>
				</div>
			</div>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="stats-tab" :name="t('openconnector', 'Statistics')" :order="2">
			<template #icon>
				<ChartLine :size="20" />
			</template>
			<CnStatsPanel
				class="contractsStatsPanel"
				:sections="statsSections"
				:loading="statisticsLoading" />
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import {
	NcAppSidebar,
	NcAppSidebarTab,
	NcSelect,
	NcButton,
} from '@nextcloud/vue'
import { CnStatsPanel } from '@conduction/nextcloud-vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import FilterOffOutline from 'vue-material-design-icons/FilterOffOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import DateRangeInput from '../../components/DateRangeInput.vue'
import { translate as t } from '@nextcloud/l10n'
import getValidISOstring from '@/services/getValidISOstring.js'

export default {
	name: 'ContractsSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcSelect,
		NcButton,
		CnStatsPanel,
		FilterOutline,
		ChartLine,
		FilterOffOutline,
		Download,
		DateRangeInput,
	},
	data() {
		return {
			activeTab: 'filters-tab',
			filters: {
				synchronization: null,
				syncStatus: null,
				dateFrom: null,
				dateTo: null,
			},
			statistics: {},
			statisticsLoading: false,
		}
	},
	computed: {
		syncStatusOptions() {
			return [
				{ id: 'synced', label: t('openconnector', 'Synced') },
				{ id: 'stale', label: t('openconnector', 'Stale') },
				{ id: 'unsynced', label: t('openconnector', 'Unsynced') },
				{ id: 'error', label: t('openconnector', 'Error') },
			]
		},
		synchronizationOptions() {
			return synchronizationStore.synchronizationList.map(sync => ({
				id: sync.id,
				label: sync.name || `Synchronization ${sync.id}`,
			}))
		},
		hasActiveFilters() {
			return Object.values(this.filters).some(value => value !== null && value !== '')
		},
		statsSections() {
			const stats = this.statistics || {}
			const toCount = (value) => Number(value) || 0
			const sections = [
				{
					id: 'totals',
					type: 'stats',
					title: t('openconnector', 'Statistics'),
					layout: 'grid',
					columns: 2,
					items: [
						{
							title: t('openconnector', 'Total Contracts'),
							count: toCount(stats.total),
							countLabel: t('openconnector', 'contracts'),
							icon: FileDocumentOutline,
							variant: 'primary',
						},
						{
							title: t('openconnector', 'Synced'),
							count: toCount(stats.synced),
							countLabel: t('openconnector', 'contracts'),
							icon: CheckCircle,
							variant: 'success',
						},
						{
							title: t('openconnector', 'Stale'),
							count: toCount(stats.stale),
							countLabel: t('openconnector', 'contracts'),
							icon: AlertCircle,
							variant: 'warning',
						},
						{
							title: t('openconnector', 'Errors'),
							count: toCount(stats.error),
							countLabel: t('openconnector', 'contracts'),
							icon: CloseCircle,
							variant: 'error',
						},
					],
				},
			]
			const distribution = stats.syncStatusDistribution
			if (distribution && Object.keys(distribution).length) {
				sections.push({
					id: 'syncStatusDistribution',
					type: 'progress',
					title: t('openconnector', 'Sync Status Distribution'),
					showPercentage: true,
					items: Object.entries(distribution).map(([status, count]) => ({
						key: status,
						label: this.getSyncStatusLabel(status),
						count,
						variant: this.variantForSyncStatus(status),
					})),
				})
			}
			return sections
		},
	},
	async mounted() {
		await this.loadStatistics()
		this.applyQueryParamsFromRoute()
	},
	methods: {
		t,
		async loadStatistics() {
			this.statisticsLoading = true
			try {
				await contractStore.fetchStatistics()
				this.statistics = contractStore.contractsStatistics
			} catch (error) {
				console.error('Error loading statistics:', error)
				this.statistics = {}
			} finally {
				this.statisticsLoading = false
			}
		},
		applyFilters() {
			const cleanFilters = {}
			Object.entries(this.filters).forEach(([key, value]) => {
				if (value !== null && value !== '') {
					cleanFilters[key] = value
				}
			})

			this.$root.$emit('contracts-filters-changed', cleanFilters)
			this.updateRouteQueryFromState()
		},
		buildQueryFromState() {
			const q = {}
			if (this.filters.synchronization) q.synchronization = String(this.filters.synchronization.id || this.filters.synchronization)
			if (this.filters.syncStatus) q.syncStatus = String(this.filters.syncStatus.id || this.filters.syncStatus)
			if (this.filters.dateFrom) q.dateFrom = getValidISOstring(this.filters.dateFrom)
			if (this.filters.dateTo) q.dateTo = getValidISOstring(this.filters.dateTo)
			return q
		},
		queriesEqual(a, b) {
			const aKeys = Object.keys(a)
			const bKeys = Object.keys(b)
			if (aKeys.length !== bKeys.length) return false
			return aKeys.every(k => String(a[k]) === String(b[k] || ''))
		},
		updateRouteQueryFromState() {
			if (this.$route.path !== '/synchronizations/contracts') return
			const next = this.buildQueryFromState()
			if (this.queriesEqual(next, this.$route.query || {})) return
			this.$router.replace({ path: this.$route.path, query: next })
		},
		applyQueryParamsFromRoute() {
			if (this.$route.path !== '/synchronizations/contracts') return
			const q = this.$route.query || {}
			this.filters.synchronization = q.synchronization
				? this.synchronizationOptions.find(opt => String(opt.id) === String(q.synchronization)) || null
				: null
			this.filters.syncStatus = q.syncStatus
				? this.syncStatusOptions.find(opt => opt.id === q.syncStatus) || null
				: null
			this.filters.dateFrom = q.dateFrom && new Date(q.dateFrom).getDate() ? new Date(q.dateFrom) : null
			this.filters.dateTo = q.dateTo && new Date(q.dateTo).getDate() ? new Date(q.dateTo) : null
			this.applyFilters()
		},
		clearFilters() {
			this.filters = {
				synchronization: null,
				syncStatus: null,
				dateFrom: null,
				dateTo: null,
			}
			this.applyFilters()
		},
		exportFiltered() {
			this.$root.$emit('contracts-export-filtered')
		},
		getSyncStatusLabel(status) {
			const opt = this.syncStatusOptions.find(o => o.id === status)
			return opt ? opt.label : status
		},
		variantForSyncStatus(status) {
			if (status === 'synced') return 'success'
			if (status === 'stale') return 'warning'
			if (status === 'error') return 'error'
			return 'default'
		},
	},
}
</script>

<style scoped>
.sidebarSection {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.sidebarSection:last-child {
	border-bottom: none;
}

.sidebarSection__title {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-weight: bold;
	padding: 0 16px;
	margin: 0 0 12px 0;
}

.sidebarSection__body {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 0 16px;
}

.sidebarSection__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.sidebarSection__fieldLabel {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.contractsStatsPanel :deep(.cn-kpi-grid),
.contractsStatsPanel :deep(.cn-stats-panel__list),
.contractsStatsPanel :deep(.cn-stats-panel__stack) {
	padding: 0 16px;
}

:deep(.v-select) {
	margin-bottom: 0;
}
</style>

<script setup>
import { logStore, contractStore, synchronizationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		:name="t('openconnector', 'Logs')"
		:subtitle="t('openconnector', 'Filter and manage logs')"
		:subname="t('openconnector', 'Export, view, or delete logs')"
		:open="navigationStore.sidebarState.logs"
		@update:open="(e) => navigationStore.setSidebarState('logs', e)">
		<NcAppSidebarTab id="filters-tab" :name="t('openconnector', 'Filters')" :order="1">
			<template #icon>
				<FilterOutline :size="20" />
			</template>

			<div class="sidebarSection">
				<h3 class="sidebarSection__title">
					{{ t('openconnector', 'Filter Logs') }}
				</h3>
				<div class="sidebarSection__body">
					<NcSelect
						v-model="filters.level"
						:options="levelOptions"
						:placeholder="t('openconnector', 'All levels')"
						:input-label="t('openconnector', 'Level')"
						:clearable="true"
						@input="applyFilters" />
					<NcSelect
						v-model="filters.contract"
						:options="contractOptions"
						:placeholder="t('openconnector', 'All contracts')"
						:input-label="t('openconnector', 'Contract')"
						:clearable="true"
						@input="applyFilters" />
					<NcSelect
						v-model="filters.synchronization"
						:options="synchronizationOptions"
						:placeholder="t('openconnector', 'All synchronizations')"
						:input-label="t('openconnector', 'Synchronization')"
						:clearable="true"
						@input="applyFilters" />
					<div class="sidebarSection__field">
						<span class="sidebarSection__fieldLabel">{{ t('openconnector', 'Date Range') }}</span>
						<DateRangeInput
							:start="filters.dateFrom"
							:end="filters.dateTo"
							:max-start="new Date()"
							@update:start="(v) => { filters.dateFrom = v }"
							@update:end="(v) => { filters.dateTo = v }"
							@change="applyFilters" />
					</div>
					<NcTextField
						v-model="filters.message"
						:label="t('openconnector', 'Message')"
						:placeholder="t('openconnector', 'Search in messages...')"
						@input="debouncedApplyFilters" />
					<NcButton v-if="hasActiveFilters" @click="clearFilters">
						<template #icon>
							<FilterOffOutline :size="20" />
						</template>
						{{ t('openconnector', 'Clear Filters') }}
					</NcButton>
				</div>
			</div>

			<div v-if="selectedCount > 0" class="sidebarSection">
				<h3 class="sidebarSection__title">
					{{ t('openconnector', 'Bulk Actions') }}
				</h3>
				<div class="sidebarSection__body">
					<p class="sidebarSection__fieldLabel">
						{{ t('openconnector', '{count} logs selected', { count: selectedCount }) }}
					</p>
					<NcButton type="error" @click="bulkDelete">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t('openconnector', 'Delete Selected') }}
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
						{{ t('openconnector', 'Export Filtered Logs') }}
					</NcButton>
				</div>
			</div>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="stats-tab" :name="t('openconnector', 'Statistics')" :order="2">
			<template #icon>
				<ChartLine :size="20" />
			</template>
			<CnStatsPanel
				class="logsStatsPanel"
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
	NcTextField,
	NcButton,
} from '@nextcloud/vue'
import { CnStatsPanel } from '@conduction/nextcloud-vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import FilterOffOutline from 'vue-material-design-icons/FilterOffOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Download from 'vue-material-design-icons/Download.vue'
import TimelineQuestionOutline from 'vue-material-design-icons/TimelineQuestionOutline.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import DateRangeInput from '../../components/DateRangeInput.vue'
import { translate as t } from '@nextcloud/l10n'
import getValidISOstring from '@/services/getValidISOstring.js'

export default {
	name: 'LogsSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcSelect,
		NcTextField,
		NcButton,
		CnStatsPanel,
		FilterOutline,
		ChartLine,
		FilterOffOutline,
		Delete,
		Download,
		DateRangeInput,
	},
	data() {
		return {
			levelOptions: [
				{ id: 'error', label: t('openconnector', 'Error') },
				{ id: 'warning', label: t('openconnector', 'Warning') },
				{ id: 'info', label: t('openconnector', 'Info') },
				{ id: 'success', label: t('openconnector', 'Success') },
				{ id: 'debug', label: t('openconnector', 'Debug') },
			],

			activeTab: 'filters-tab',
			filters: {
				level: null,
				contract: null,
				synchronization: null,
				dateFrom: null,
				dateTo: null,
				message: '',
			},
			selectedCount: 0,
			filteredCount: 0,
			statistics: {},
			statisticsLoading: false,
			debounceTimer: null,
		}
	},
	computed: {
		contractOptions() {
			return contractStore.contractsList.map(contract => ({
				id: contract.id,
				label: contract.name || `Contract ${contract.id}`,
			}))
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
			const sections = [
				{
					id: 'totals',
					type: 'stats',
					title: t('openconnector', 'Statistics'),
					layout: 'grid',
					columns: 2,
					items: [
						{
							title: t('openconnector', 'Total Logs'),
							count: this.filteredCount,
							countLabel: t('openconnector', 'logs'),
							icon: TimelineQuestionOutline,
							variant: 'primary',
						},
						{
							title: t('openconnector', 'Error Logs'),
							count: this.statistics.errorCount || 0,
							countLabel: t('openconnector', 'logs'),
							icon: CloseCircle,
							variant: 'error',
						},
						{
							title: t('openconnector', 'Warning Logs'),
							count: this.statistics.warningCount || 0,
							countLabel: t('openconnector', 'logs'),
							icon: AlertCircle,
							variant: 'warning',
						},
						{
							title: t('openconnector', 'Info Logs'),
							count: this.statistics.infoCount || 0,
							countLabel: t('openconnector', 'logs'),
							icon: InformationOutline,
							variant: 'success',
						},
					],
				},
			]
			const distribution = this.statistics.levelDistribution
			if (distribution && Object.keys(distribution).length) {
				sections.push({
					id: 'levelDistribution',
					type: 'progress',
					title: t('openconnector', 'Level Distribution'),
					showPercentage: true,
					items: Object.entries(distribution).map(([level, count]) => ({
						key: level,
						label: this.getLevelLabel(level),
						count,
						variant: this.variantForLevel(level),
					})),
				})
			}
			return sections
		},
	},
	async mounted() {
		await this.loadStatistics()

		this.$root.$on('logs-selection-count', this.updateSelectionCount)
		this.$root.$on('logs-filtered-count', this.updateFilteredCount)
		this.applyQueryParamsFromRoute()
	},
	beforeDestroy() {
		this.$root.$off('logs-selection-count', this.updateSelectionCount)
		this.$root.$off('logs-filtered-count', this.updateFilteredCount)

		if (this.debounceTimer) {
			clearTimeout(this.debounceTimer)
		}
	},
	methods: {
		t,
		async loadStatistics() {
			this.statisticsLoading = true
			try {
				await logStore.fetchStatistics()
				this.statistics = logStore.logsStatistics
			} catch (error) {
				console.error('Error loading statistics:', error)
				this.statistics = {}
			} finally {
				this.statisticsLoading = false
			}
		},
		debouncedApplyFilters() {
			if (this.debounceTimer) {
				clearTimeout(this.debounceTimer)
			}
			this.debounceTimer = setTimeout(() => {
				this.applyFilters()
			}, 500)
		},
		applyFilters() {
			const cleanFilters = {}
			Object.entries(this.filters).forEach(([key, value]) => {
				if (value !== null && value !== '') {
					cleanFilters[key] = value
				}
			})

			this.$root.$emit('logs-filters-changed', cleanFilters)
			this.updateRouteQueryFromState()
		},
		buildQueryFromState() {
			const q = {}
			if (this.filters.level) q.level = this.filters.level.id || this.filters.level
			if (this.filters.contract) q.contract = String(this.filters.contract.id || this.filters.contract)
			if (this.filters.synchronization) q.synchronization = String(this.filters.synchronization.id || this.filters.synchronization)
			if (this.filters.dateFrom) q.dateFrom = getValidISOstring(this.filters.dateFrom)
			if (this.filters.dateTo) q.dateTo = getValidISOstring(this.filters.dateTo)
			if (this.filters.message) q.message = this.filters.message
			return q
		},
		queriesEqual(a, b) {
			const aKeys = Object.keys(a)
			const bKeys = Object.keys(b)
			if (aKeys.length !== bKeys.length) return false
			return aKeys.every(k => String(a[k]) === String(b[k] || ''))
		},
		updateRouteQueryFromState() {
			if (this.$route.path !== '/synchronizations/logs') return
			const next = this.buildQueryFromState()
			if (this.queriesEqual(next, this.$route.query || {})) return
			this.$router.replace({ path: this.$route.path, query: next })
		},
		applyQueryParamsFromRoute() {
			if (this.$route.path !== '/synchronizations/logs') return
			const q = this.$route.query || {}
			this.filters.level = q.level ? this.levelOptions.find(opt => opt.id === q.level) || null : null
			this.filters.contract = q.contract
				? this.contractOptions.find(opt => String(opt.id) === String(q.contract)) || null
				: null
			this.filters.synchronization = q.synchronization
				? this.synchronizationOptions.find(opt => String(opt.id) === String(q.synchronization)) || null
				: null
			this.filters.dateFrom = q.dateFrom && !isNaN(new Date(q.dateFrom).getTime()) ? new Date(q.dateFrom) : null
			this.filters.dateTo = q.dateTo && !isNaN(new Date(q.dateTo).getTime()) ? new Date(q.dateTo) : null
			this.filters.message = q.message || ''
			this.applyFilters()
		},
		clearFilters() {
			this.filters = {
				level: null,
				contract: null,
				synchronization: null,
				dateFrom: null,
				dateTo: null,
				message: '',
			}
			this.applyFilters()
		},
		updateSelectionCount(count) {
			this.selectedCount = count
		},
		updateFilteredCount(count) {
			this.filteredCount = count
		},
		bulkDelete() {
			this.$root.$emit('logs-bulk-delete')
		},
		exportFiltered() {
			this.$root.$emit('logs-export-filtered')
		},
		getLevelLabel(level) {
			const levelOption = this.levelOptions.find(option => option.id === level)
			return levelOption ? levelOption.label : level
		},
		variantForLevel(level) {
			if (level === 'error') return 'error'
			if (level === 'warning') return 'warning'
			if (level === 'success' || level === 'info') return 'success'
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

.logsStatsPanel :deep(.cn-kpi-grid),
.logsStatsPanel :deep(.cn-stats-panel__list),
.logsStatsPanel :deep(.cn-stats-panel__stack) {
	padding: 0 16px;
}

:deep(.v-select) {
	margin-bottom: 0;
}
</style>

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

			<div class="filterSection">
				<h3>{{ t('openconnector', 'Filter Logs') }}</h3>
				<div class="filterGroup">
					<label>{{ t('openconnector', 'Level') }}</label>
					<NcSelect
						v-model="filters.level"
						:options="levelOptions"
						:placeholder="t('openconnector', 'All levels')"
						:input-label="t('openconnector', 'Level')"
						:clearable="true"
						@input="applyFilters" />
				</div>

				<div class="filterGroup">
					<label>{{ t('openconnector', 'Contract') }}</label>
					<NcSelect
						v-model="filters.contract"
						:options="contractOptions"
						:placeholder="t('openconnector', 'All contracts')"
						:input-label="t('openconnector', 'Contract')"
						:clearable="true"
						@input="applyFilters" />
				</div>

				<div class="filterGroup">
					<label>{{ t('openconnector', 'Synchronization') }}</label>
					<NcSelect
						v-model="filters.synchronization"
						:options="synchronizationOptions"
						:placeholder="t('openconnector', 'All synchronizations')"
						:input-label="t('openconnector', 'Synchronization')"
						:clearable="true"
						@input="applyFilters" />
				</div>

				<div class="filterGroup">
					<label>{{ t('openconnector', 'Date range') }}</label>
					<DateRangeInput
						:start="filters.dateFrom"
						:end="filters.dateTo"
						:max-start="new Date()"
						@update:start="(v) => { filters.dateFrom = v; }"
						@update:end="(v) => { filters.dateTo = v; }"
						@change="applyFilters" />
				</div>

				<div class="filterGroup">
					<label>{{ t('openconnector', 'Message') }}</label>
					<NcTextField
						v-model="filters.message"
						:placeholder="t('openconnector', 'Search in messages...')"
						@input="debouncedApplyFilters" />
				</div>
			</div>

			<div class="actionGroup">
				<NcButton v-if="hasActiveFilters" @click="clearFilters">
					<template #icon>
						<FilterOffOutline :size="20" />
					</template>
					{{ t('openconnector', 'Clear Filters') }}
				</NcButton>
			</div>

			<div v-if="selectedCount > 0" class="filterSection">
				<h3>{{ t('openconnector', 'Bulk Actions') }}</h3>
				<p class="selection-info">
					{{ t('openconnector', '{count} logs selected', { count: selectedCount }) }}
				</p>
				<div class="filterGroup">
					<NcButton type="error" @click="bulkDelete">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t('openconnector', 'Delete Selected') }}
					</NcButton>
				</div>
			</div>

			<div class="filterSection">
				<h3>{{ t('openconnector', 'Export') }}</h3>
				<div class="filterGroup">
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

			<div class="statsSection">
				<h3>{{ t('openconnector', 'Statistics') }}</h3>
				<div class="statCard">
					<div class="statNumber">
						{{ filteredCount }}
					</div>
					<div class="statLabel">
						{{ t('openconnector', 'Total Logs') }}
					</div>
				</div>

				<div class="statRow">
					<div class="statItem">
						<div class="statLabel">
							{{ t('openconnector', 'Error Logs') }}
						</div>
						<div class="statValue error">
							{{ statistics.errorCount || 0 }}
						</div>
					</div>
					<div class="statItem">
						<div class="statLabel">
							{{ t('openconnector', 'Warning Logs') }}
						</div>
						<div class="statValue warning">
							{{ statistics.warningCount || 0 }}
						</div>
					</div>
					<div class="statItem">
						<div class="statLabel">
							{{ t('openconnector', 'Info Logs') }}
						</div>
						<div class="statValue success">
							{{ statistics.infoCount || 0 }}
						</div>
					</div>
				</div>

				<div v-if="statisticsLoading" class="loading-small">
					<NcLoadingIcon :size="24" />
				</div>

				<div v-if="statistics.levelDistribution" class="chart-container">
					<h4 class="subTitle">
						{{ t('openconnector', 'Level Distribution') }}
					</h4>
					<div class="level-chart">
						<div v-for="(count, level) in statistics.levelDistribution"
							:key="level"
							class="level-bar"
							:class="'level-' + level">
							<div class="level-label">
								{{ getLevelLabel(level) }}
							</div>
							<div class="level-progress">
								<div class="level-fill" :style="{ width: getLevelPercentage(count) + '%' }" />
							</div>
							<div class="level-count">
								{{ count }}
							</div>
						</div>
					</div>
				</div>
			</div>
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
	NcLoadingIcon,
} from '@nextcloud/vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import FilterOffOutline from 'vue-material-design-icons/FilterOffOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Download from 'vue-material-design-icons/Download.vue'
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
		NcLoadingIcon,
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
		/**
		 * Get contract filter options
		 * @return {Array} Array of contract options
		 */
		contractOptions() {
			return contractStore.contractsList.map(contract => ({
				id: contract.id,
				label: contract.name || `Contract ${contract.id}`,
			}))
		},
		/**
		 * Get synchronization filter options
		 * @return {Array} Array of synchronization options
		 */
		synchronizationOptions() {
			return synchronizationStore.synchronizationList.map(sync => ({
				id: sync.id,
				label: sync.name || `Synchronization ${sync.id}`,
			}))
		},
		/**
		 * Check if any filters are active
		 * @return {boolean} Whether any filters are active
		 */
		hasActiveFilters() {
			return Object.values(this.filters).some(value => value !== null && value !== '')
		},
	},
	async mounted() {
		// Load initial statistics
		await this.loadStatistics()

		// Listen for events from main view
		this.$root.$on('logs-selection-count', this.updateSelectionCount)
		this.$root.$on('logs-filtered-count', this.updateFilteredCount)
		// Initialize filters from URL
		this.applyQueryParamsFromRoute()
	},
	beforeDestroy() {
		this.$root.$off('logs-selection-count')
		this.$root.$off('logs-filtered-count')

		if (this.debounceTimer) {
			clearTimeout(this.debounceTimer)
		}
	},
	methods: {
		t,
		/**
		 * Load statistics data
		 * @return {Promise<void>}
		 */
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
		/**
		 * Apply filters with debouncing for number inputs
		 * @return {void}
		 */
		debouncedApplyFilters() {
			if (this.debounceTimer) {
				clearTimeout(this.debounceTimer)
			}
			this.debounceTimer = setTimeout(() => {
				this.applyFilters()
			}, 500)
		},
		/**
		 * Apply current filters
		 * @return {void}
		 */
		applyFilters() {
			// Clean up empty values
			const cleanFilters = {}
			Object.entries(this.filters).forEach(([key, value]) => {
				if (value !== null && value !== '') {
					cleanFilters[key] = value
				}
			})

			// Emit filters to main view
			this.$root.$emit('logs-filters-changed', cleanFilters)
			// Write filters to URL
			this.updateRouteQueryFromState()
		},
		buildQueryFromState() {
			const q = {}
			if (this.filters.level) q.level = this.filters.level
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
			this.filters.level = q.level || null
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
		/**
		 * Clear all filters
		 * @return {void}
		 */
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
		/**
		 * Update selection count from main view
		 * @param {number} count - Number of selected items
		 * @return {void}
		 */
		updateSelectionCount(count) {
			this.selectedCount = count
		},
		/**
		 * Update filtered count from main view
		 * @param {number} count - Number of filtered items
		 * @return {void}
		 */
		updateFilteredCount(count) {
			this.filteredCount = count
		},
		/**
		 * Trigger bulk delete action
		 * @return {void}
		 */
		bulkDelete() {
			this.$root.$emit('logs-bulk-delete')
		},
		/**
		 * Trigger export filtered action
		 * @return {void}
		 */
		exportFiltered() {
			this.$root.$emit('logs-export-filtered')
		},
		/**
		 * Get level label for display
		 * @param {string} level - Log level
		 * @return {string} Level label
		 */
		getLevelLabel(level) {
			const levelOption = this.levelOptions.find(option => option.id === level)
			return levelOption ? levelOption.label : level
		},
		/**
		 * Get percentage for level distribution
		 * @param {number} count - Count for this level
		 * @return {number} Percentage
		 */
		getLevelPercentage(count) {
			const total = Object.values(this.statistics.levelDistribution || {}).reduce((sum, c) => sum + c, 0)
			return total > 0 ? (count / total) * 100 : 0
		},
	},
}
</script>

<style scoped>
.filterSection,
.statsSection {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.filterSection:last-child,
.statsSection:last-child {
	border-bottom: none;
}

.filterSection h3,
.statsSection h3 {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-weight: bold;
	padding: 0 16px;
	margin: 0 0 12px 0;
}

.subTitle {
	margin: 0 16px 12px;
	font-size: 1rem;
	font-weight: 500;
}

.filterGroup {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 0 16px;
	margin-bottom: 16px;
}

.filterGroup label {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.actionGroup {
	padding: 12px;
	margin-bottom: 12px;
}

.selection-info {
	color: var(--color-text-maxcontrast);
	padding: 0 16px 8px;
	margin: 0;
}

/* Stats */
.statCard {
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 16px;
	margin: 12px 16px 16px;
	text-align: center;
}

.statNumber {
	font-size: 2rem;
	font-weight: bold;
	color: var(--color-primary);
	margin-bottom: 4px;
}

.statLabel {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.statRow {
	display: grid;
	grid-template-columns: 1fr 1fr 1fr;
	gap: 12px;
	padding: 0 16px 8px;
}

.statItem {
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 12px;
	text-align: center;
}

.statValue {
	font-weight: 600;
	font-size: 1.1rem;
}

.statValue.error { color: var(--color-error); }

.statValue.warning { color: var(--color-warning); }

.statValue.success { color: var(--color-success); }

/* Chart */
.chart-container { margin: 12px 16px 16px; }

.level-chart { display: flex; flex-direction: column; gap: 8px; }

.level-bar { display: flex; align-items: center; gap: 10px; font-size: 0.85rem; }

.level-label { min-width: 60px; font-weight: 500; }

.level-progress { flex: 1; height: 8px; background: var(--color-background-dark); border-radius: 4px; overflow: hidden; }

.level-fill { height: 100%; transition: width 0.3s ease; }

.level-bar.level-error .level-fill { background: var(--color-error); }

.level-bar.level-warning .level-fill { background: var(--color-warning); }

.level-bar.level-info .level-fill,
.level-bar.level-success .level-fill { background: var(--color-success); }

.level-bar.level-debug .level-fill { background: var(--color-background-dark); }

.level-count { min-width: 30px; text-align: right; font-weight: 500; }

/* Inputs spacing */
:deep(.v-select) { margin-bottom: 8px; }
</style>

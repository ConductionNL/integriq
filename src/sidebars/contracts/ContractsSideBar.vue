<script setup>
import { synchronizationStore, navigationStore } from '../../store/store.js'
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

			<!-- Filter Section -->
			<div class="filterSection">
				<h3>{{ t('openconnector', 'Filter contracts') }}</h3>
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
					<label>{{ t('openconnector', 'Sync status') }}</label>
					<NcSelect
						v-model="filters.syncStatus"
						:options="syncStatusOptions"
						:placeholder="t('openconnector', 'All sync statuses')"
						:input-label="t('openconnector', 'Sync status')"
						:clearable="true"
						@input="applyFilters" />
				</div>

				<div class="filterGroup">
					<label>{{ t('openconnector', 'Last synced') }}</label>
					<DateRangeInput
						:start="filters.dateFrom"
						:end="filters.dateTo"
						:max-start="new Date()"
						@update:start="(v) => { filters.dateFrom = v }"
						@update:end="(v) => { filters.dateTo = v }"
						@change="applyFilters" />
				</div>
			</div>

			<div class="actionGroup">
				<NcButton v-if="hasActiveFilters" @click="clearFilters">
					{{ t('openconnector', 'Clear filters') }}
				</NcButton>
			</div>

			<!-- Bulk Actions Section -->
			<div v-if="selectedCount > 0" class="filterSection">
				<h3>{{ t('openconnector', 'Bulk actions') }}</h3>
				<p class="selection-info">
					{{ t('openconnector', '{count} contracts selected', { count: selectedCount }) }}
				</p>
				<div class="filterGroup">
					<NcButton type="error" @click="bulkDelete">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t('openconnector', 'Delete selected') }}
					</NcButton>
				</div>
			</div>

			<!-- Export Section -->
			<div class="filterSection">
				<h3>{{ t('openconnector', 'Export') }}</h3>
				<div class="filterGroup">
					<NcButton @click="exportFiltered">
						<template #icon>
							<Download :size="20" />
						</template>
						{{ t('openconnector', 'Export filtered contracts') }}
					</NcButton>
				</div>
			</div>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="stats-tab" :name="t('openconnector', 'Statistics')" :order="2">
			<template #icon>
				<ChartLine :size="20" />
			</template>

			<div class="statsSection">
				<h3>{{ t('openconnector', 'Contracts statistics') }}</h3>
				<div class="statCard">
					<div class="statNumber">
						{{ filteredCount }}
					</div>
					<div class="statLabel">
						{{ t('openconnector', 'Total contracts') }}
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
	NcButton,
} from '@nextcloud/vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
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
		FilterOutline,
		ChartLine,
		Download,
		Delete,
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
			selectedCount: 0,
			filteredCount: 0,
			statistics: {},
			statisticsLoading: false,
			debounceTimer: null,
		}
	},
	computed: {
		syncStatusOptions() {
			return [
				{ id: 'synced', label: this.t('openconnector', 'Synced') },
				{ id: 'stale', label: this.t('openconnector', 'Stale') },
				{ id: 'unsynced', label: this.t('openconnector', 'Unsynced') },
			]
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
		this.$root.$on('contracts-selection-count', this.updateSelectionCount)
		this.$root.$on('contracts-filtered-count', this.updateFilteredCount)
		// Initialize SPOT from URL
		this.applyQueryParamsFromRoute()
	},
	beforeDestroy() {
		this.$root.$off('contracts-selection-count')
		this.$root.$off('contracts-filtered-count')

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
				// For contracts, we only need basic count statistics
				// The filteredCount will be updated from the main view
				this.statistics = {}
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
			this.$root.$emit('contracts-filters-changed', cleanFilters)
			// Write URL (SPOT)
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
		/**
		 * Clear all filters
		 * @return {void}
		 */
		clearFilters() {
			this.filters = {
				synchronization: null,
				syncStatus: null,
				dateFrom: null,
				dateTo: null,
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
			this.$root.$emit('contracts-bulk-delete')
		},
		/**
		 * Trigger export filtered action
		 * @return {void}
		 */
		exportFiltered() {
			this.$root.$emit('contracts-export-filtered')
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

/* Add some spacing between select inputs */
:deep(.v-select) {
	margin-bottom: 8px;
}
</style>

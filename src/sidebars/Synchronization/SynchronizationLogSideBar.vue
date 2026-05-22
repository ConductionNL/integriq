<script setup>
import { navigationStore, synchronizationStore, contractStore } from '../../store/store.js'
</script>
<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		:name="t('openconnector', 'Synchronization log management')"
		:subtitle="t('openconnector', 'Filter and manage synchronization logs')"
		:subname="t('openconnector', 'Export, view, or delete logs')"
		:open="navigationStore.sidebarState.logs"
		@update:open="(e) => navigationStore.setSidebarState('logs', e)">
		<NcAppSidebarTab id="filters-tab" :name="t('openconnector', 'Filters')" :order="1">
			<template #icon>
				<FilterOutline :size="20" />
			</template>

			<!-- Filter Section -->
			<div class="filterSection">
				<h3>{{ t('openconnector', 'Filter logs') }}</h3>
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
						@update:start="(v) => { filters.dateFrom = v }"
						@update:end="(v) => { filters.dateTo = v }"
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
					{{ t('openconnector', 'Clear filters') }}
				</NcButton>
			</div>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="stats-tab" :name="t('openconnector', 'Statistics')" :order="2">
			<template #icon>
				<ChartLine :size="20" />
			</template>
			<div class="statsSection">
				<h3>{{ t('openconnector', 'Log statistics') }}</h3>
				<div class="statCard">
					<div class="statNumber">
						{{ filteredCount }}
					</div>
					<div class="statLabel">
						{{ t('openconnector', 'Total logs') }}
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
} from '@nextcloud/vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import FilterOffOutline from 'vue-material-design-icons/FilterOffOutline.vue'
import DateRangeInput from '../../components/DateRangeInput.vue'
import { translate as t } from '@nextcloud/l10n'
import getValidISOstring from '@/services/getValidISOstring.js'

export default {
	name: 'SynchronizationLogSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcSelect,
		NcTextField,
		NcButton,
		FilterOutline,
		ChartLine,
		FilterOffOutline,
		DateRangeInput,
	},
	data() {
		return {
			activeTab: 'filters-tab',
			filters: {
				level: null,
				contract: null,
				synchronization: null,
				dateFrom: null,
				dateTo: null,
				message: '',
			},
			filteredCount: 0,
			debounceTimer: null,
		}
	},
	computed: {
		levelOptions() {
			return [
				{ id: 'error', label: this.t('openconnector', 'Error') },
				{ id: 'warning', label: this.t('openconnector', 'Warning') },
				{ id: 'info', label: this.t('openconnector', 'Info') },
				{ id: 'success', label: this.t('openconnector', 'Success') },
				{ id: 'debug', label: this.t('openconnector', 'Debug') },
			]
		},
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
	},
	mounted() {
		this.$root.$on('synchronization-logs-filtered-count', this.updateFilteredCount)
		// Initialize SPOT from URL on first load
		this.applyQueryParamsFromRoute()
	},
	beforeDestroy() {
		this.$root.$off('synchronization-logs-filtered-count')
		if (this.debounceTimer) {
			clearTimeout(this.debounceTimer)
		}
	},
	methods: {
		t,
		applyFilters() {
			const cleanFilters = {}
			Object.entries(this.filters).forEach(([key, value]) => {
				if (value !== null && value !== '') {
					cleanFilters[key] = value
				}
			})
			this.$root.$emit('synchronization-logs-filters-changed', cleanFilters)
			// Write URL (SPOT)
			this.updateRouteQueryFromState()
		},
		debouncedApplyFilters() {
			if (this.debounceTimer) clearTimeout(this.debounceTimer)
			this.debounceTimer = setTimeout(() => this.applyFilters(), 500)
		},
		clearFilters() {
			this.filters = { level: null, contract: null, synchronization: null, dateFrom: null, dateTo: null, message: '' }
			this.applyFilters()
		},
		updateFilteredCount(count) {
			this.filteredCount = count
		},
		buildQueryFromState() {
			const q = {}
			if (this.filters.level) q.level = String(this.filters.level.id || this.filters.level)
			if (this.filters.contract) q.contract = String(this.filters.contract.id || this.filters.contract)
			if (this.filters.synchronization) q.synchronization = String(this.filters.synchronization.id || this.filters.synchronization)
			if (this.filters.dateFrom) q.dateFrom = getValidISOstring(this.filters.dateFrom)
			if (this.filters.dateTo) q.dateTo = getValidISOstring(this.filters.dateTo)
			if (this.filters.message) q.message = this.filters.message
			return q
		},
		queriesEqual(a, b) {
			const aKeys = Object.keys(a)
			const bKeys = Object.keys(b || {})
			if (aKeys.length !== bKeys.length) return false
			return aKeys.every(k => String(a[k]) === String((b || {})[k] || ''))
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
			this.filters.contract = q.contract ? this.contractOptions.find(opt => opt.id === String(q.contract)) || null : null
			this.filters.synchronization = q.synchronization ? this.synchronizationOptions.find(opt => opt.id === String(q.synchronization)) || null : null
			this.filters.dateFrom = q.dateFrom && !isNaN(new Date(q.dateFrom).getTime()) ? new Date(q.dateFrom) : null
			this.filters.dateTo = q.dateTo && !isNaN(new Date(q.dateTo).getTime()) ? new Date(q.dateTo) : null
			this.filters.message = q.message || ''
			this.applyFilters()
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

/* Add some spacing between select inputs */
:deep(.v-select) {
	margin-bottom: 8px;
}

.statsSection .statCard {
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 16px;
	margin: 12px 16px 0;
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
</style>

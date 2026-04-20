<script setup>
import { logStore, navigationStore, sourceStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		:name="t('openconnector', 'Source Log Management')"
		:subtitle="t('openconnector', 'Filter and manage source call logs')"
		:subname="t('openconnector', 'Export, view, or delete call logs')"
		:open="navigationStore.sidebarState.sourceLogs"
		@update:open="(e) => navigationStore.setSidebarState('sourceLogs', e)">
		<NcAppSidebarTab id="filters-tab" :name="t('openconnector', 'Filters')" :order="1">
			<template #icon>
				<FilterOutline :size="20" />
			</template>

			<!-- Filter Section -->
			<div class="sidebarSection">
				<h3 class="sidebarSection__title">
					{{ t('openconnector', 'Filter Call Logs') }}
				</h3>
				<div class="sidebarSection__body">
					<NcSelect
						v-model="selectedSource"
						:options="sourceOptions"
						:placeholder="t('openconnector', 'All sources')"
						:input-label="t('openconnector', 'Source')"
						:clearable="true"
						@input="handleSourceChange" />
					<NcSelect
						v-model="selectedStatusCodes"
						:options="statusCodeOptions"
						:placeholder="t('openconnector', 'All status codes')"
						:input-label="t('openconnector', 'Status codes')"
						:multiple="true"
						:clearable="true"
						@input="applyFilters" />
					<NcSelect
						v-model="selectedMethods"
						:options="methodOptions"
						:placeholder="t('openconnector', 'All methods')"
						:input-label="t('openconnector', 'HTTP methods')"
						:multiple="true"
						:clearable="true"
						@input="applyFilters" />
					<div class="sidebarSection__field">
						<span class="sidebarSection__fieldLabel">{{ t('openconnector', 'Date range') }}</span>
						<DateRangeInput
							:start="dateFrom"
							:end="dateTo"
							:max-start="new Date()"
							@update:start="(val) => { dateFrom = val }"
							@update:end="(val) => { dateTo = val }"
							@change="applyFilters" />
					</div>
					<NcTextField
						:value="endpointFilter"
						:label="t('openconnector', 'Endpoint')"
						:placeholder="t('openconnector', 'Enter endpoint URL')"
						@input="handleEndpointFilterChange" />
					<NcCheckboxRadioSwitch
						:checked="showOnlyErrors"
						@update:checked="(v) => { showOnlyErrors = v; applyFilters() }">
						{{ t('openconnector', 'Show only errors (4xx, 5xx)') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:checked="showSlowRequests"
						@update:checked="(v) => { showSlowRequests = v; applyFilters() }">
						{{ t('openconnector', 'Show slow requests (>5s)') }}
					</NcCheckboxRadioSwitch>
					<NcButton @click="clearFilters">
						<template #icon>
							<FilterOffOutline :size="20" />
						</template>
						{{ t('openconnector', 'Clear filters') }}
					</NcButton>
				</div>
			</div>

			<NcNoteCard type="info" class="sidebarSection__hint">
				{{ t('openconnector', 'Use filters to narrow down call logs by source, status code, HTTP method, date range, or endpoint.') }}
			</NcNoteCard>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="stats-tab" :name="t('openconnector', 'Statistics')" :order="2">
			<template #icon>
				<ChartLine :size="20" />
			</template>

			<!-- Statistics Section -->
			<div class="sidebarSection sidebarSection--stats">
				<h3 class="sidebarSection__title">
					{{ t('openconnector', 'Call Log Statistics') }}
				</h3>
				<div class="sidebarSection__body">
					<CnStatsBlock
						:title="t('openconnector', 'Total call logs')"
						:count="totalLogs"
						:count-label="t('openconnector', 'calls')"
						:icon="TimelineQuestionOutline" />
					<CnStatsBlock
						:title="t('openconnector', 'Successful calls (2xx)')"
						:count="successCount"
						:count-label="t('openconnector', 'calls')"
						:icon="CheckCircle"
						variant="success" />
					<CnStatsBlock
						:title="t('openconnector', 'Failed calls (4xx, 5xx)')"
						:count="errorCount"
						:count-label="t('openconnector', 'calls')"
						:icon="AlertCircle"
						variant="error" />
					<CnStatsBlock
						:title="t('openconnector', 'Average response time')"
						:count="averageResponseTime"
						:count-label="t('openconnector', 'seconds')"
						:icon="ChartLine" />
				</div>
			</div>

			<!-- Status Code Distribution -->
			<div class="statusDistribution">
				<h4>{{ t('openconnector', 'Status Code Distribution') }}</h4>
				<NcListItem v-for="(status, index) in statusDistribution"
					:key="index"
					:name="`${status.code} - ${status.message}`"
					:bold="false">
					<template #icon>
						<CheckCircle v-if="status.code >= 200 && status.code < 300" :size="32" />
						<AlertCircle v-else-if="status.code >= 400 && status.code < 500" :size="32" />
						<CloseCircle v-else-if="status.code >= 500" :size="32" />
						<InformationOutline v-else :size="32" />
					</template>
					<template #subname>
						{{ t('openconnector', '{count} calls', { count: status.count }) }}
					</template>
				</NcListItem>
			</div>

			<!-- Top Sources -->
			<div class="topSources">
				<h4>{{ t('openconnector', 'Most Active Sources') }}</h4>
				<NcListItem v-for="(source, index) in topSources"
					:key="index"
					:name="source.name"
					:bold="false">
					<template #icon>
						<DatabaseArrowLeftOutline :size="32" />
					</template>
					<template #subname>
						{{ t('openconnector', '{count} calls', { count: source.count }) }}
					</template>
				</NcListItem>
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import {
	NcAppSidebar,
	NcAppSidebarTab,
	NcSelect,
	NcNoteCard,
	NcButton,
	NcListItem,
	NcTextField,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import DatabaseArrowLeftOutline from 'vue-material-design-icons/DatabaseArrowLeftOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import FilterOffOutline from 'vue-material-design-icons/FilterOffOutline.vue'
import TimelineQuestionOutline from 'vue-material-design-icons/TimelineQuestionOutline.vue'
import { translate as t } from '@nextcloud/l10n'
import DateRangeInput from '../../components/DateRangeInput.vue'

export default {
	name: 'SourceLogSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcSelect,
		NcNoteCard,
		NcButton,
		NcListItem,
		NcTextField,
		NcCheckboxRadioSwitch,
		CnStatsBlock,
		FilterOutline,
		ChartLine,
		DatabaseArrowLeftOutline,
		CheckCircle,
		AlertCircle,
		CloseCircle,
		InformationOutline,
		FilterOffOutline,
		DateRangeInput,
	},
	data() {
		return {
			statusCodeOptions: [
				{ label: '200 - OK', value: '200' },
				{ label: '201 - Created', value: '201' },
				{ label: '400 - Bad Request', value: '400' },
				{ label: '401 - Unauthorized', value: '401' },
				{ label: '403 - Forbidden', value: '403' },
				{ label: '404 - Not Found', value: '404' },
				{ label: '500 - Internal Server Error', value: '500' },
				{ label: '502 - Bad Gateway', value: '502' },
				{ label: '503 - Service Unavailable', value: '503' },
			],
			methodOptions: [
				{ label: 'GET', value: 'GET' },
				{ label: 'POST', value: 'POST' },
				{ label: 'PUT', value: 'PUT' },
				{ label: 'PATCH', value: 'PATCH' },
				{ label: 'DELETE', value: 'DELETE' },
			],

			activeTab: 'filters-tab',
			selectedSource: null,
			selectedStatusCodes: [],
			selectedMethods: [],
			// uses DateRangeInput component, value is NOT date object, but instead string (e.g. 2025-10-01T00:00)
			dateFrom: null,
			dateTo: null,
			endpointFilter: '',
			showOnlyErrors: false,
			showSlowRequests: false,
			filteredCount: 0,
			totalLogs: 0,
			successCount: 0,
			errorCount: 0,
			averageResponseTime: 0,
			statusDistribution: [],
			topSources: [],
			filterTimeout: null,
		}
	},
	computed: {
		sourceOptions() {
			return sourceStore.list?.map(source => ({
				value: source,
				label: source.name,
				title: source.name,
			})) || []
		},
		selectedSourceValue() {
			if (!sourceStore.item) return null
			return sourceStore.list?.find(s => s.id === sourceStore.item.id) || null
		},
	},
	watch: {
		'sourceStore.item'() {
			this.selectedSource = this.selectedSourceValue
			this.applyFilters()
		},
	},
	async mounted() {
		// Load required data
		if (!sourceStore.list?.length) {
			await sourceStore.refreshList()
		}

		// Load initial log data
		this.loadLogData()
		this.loadStatistics()
		this.loadStatusDistribution()
		this.loadTopSources()

		// Listen for filtered count updates
		this.$root.$on('source-log-filtered-count', (count) => {
			this.filteredCount = count
		})

		// Watch store changes and update count
		this.updateFilteredCount()

		this.selectedSource = this.selectedSourceValue
		// Initialize SPOT from URL
		this.applyQueryParamsFromRoute()
	},
	beforeDestroy() {
		this.$root.$off('source-log-filtered-count')
	},
	methods: {
		/**
		 * Load call log data and update filtered count
		 */
		async loadLogData() {
			try {
				await sourceStore.refreshSourceLogs()
				this.updateFilteredCount()
			} catch (error) {
				console.error('Error loading log data:', error)
			}
		},
		/**
		 * Clear all filters
		 */
		clearAllFilters() {
			// Clear component state
			this.selectedStatusCodes = []
			this.selectedMethods = []
			this.dateFrom = null
			this.dateTo = null
			this.endpointFilter = ''
			this.showOnlyErrors = false
			this.showSlowRequests = false

			// Clear global stores
			sourceStore.setItem(null)

			// Clear store filters
			logStore.setLogFilters({})

			// Refresh without applying filters
			sourceStore.refreshSourceLogs()

			// Write SPOT to URL
			this.updateRouteQueryFromState()
		},
		/**
		 * Clear filters (alias for clearAllFilters for template compatibility)
		 */
		clearFilters() {
			this.clearAllFilters()
		},
		/**
		 * Handle endpoint filter change with debouncing
		 * @param {string} value - The endpoint filter value
		 */
		handleEndpointFilterChange(value) {
			const nextValue = typeof value === 'string' ? value : (value && value.target ? value.target.value : '')
			this.endpointFilter = nextValue
			this.debouncedApplyFilters()
		},
		/**
		 * Apply filters and emit to parent components
		 */
		applyFilters() {
			const filters = {}

			// Build status code filter
			if (Array.isArray(this.selectedStatusCodes) && this.selectedStatusCodes.length > 0) {
				const statusCodes = this.selectedStatusCodes.filter(s => s && s.value).map(s => s.value)
				if (statusCodes.length > 0) {
					filters.statusCode = statusCodes.join(',')
				}
			}

			// Build method filter
			if (Array.isArray(this.selectedMethods) && this.selectedMethods.length > 0) {
				const methods = this.selectedMethods.filter(m => m && m.value).map(m => m.value)
				if (methods.length > 0) {
					filters.method = methods.join(',')
				}
			}

			// Build source filter
			if (this.selectedSource && this.selectedSource.value) {
				filters.source_id = this.selectedSource.value.id.toString()
			}

			// Date filters
			if (this.dateFrom) {
				filters.dateFrom = this.dateFrom
			}
			if (this.dateTo) {
				filters.dateTo = this.dateTo
			}

			// Endpoint filter
			if (this.endpointFilter) {
				filters.endpoint = this.endpointFilter
			}

			// Error filter
			if (this.showOnlyErrors) {
				filters.onlyErrors = true
			}

			// Slow requests filter
			if (this.showSlowRequests) {
				filters.slowRequests = true
			}

			// Set filters in store and refresh data
			logStore.setLogFilters(filters)
			sourceStore.refreshSourceLogs(filters)

			// Also emit for legacy compatibility
			this.$root.$emit('source-log-filters-changed', filters)
			// Write URL (SPOT)
			this.updateRouteQueryFromState()
		},
		/**
		 * Debounced version of applyFilters for text input
		 */
		debouncedApplyFilters() {
			clearTimeout(this.filterTimeout)
			this.filterTimeout = setTimeout(() => {
				this.applyFilters()
			}, 500)
		},
		buildQueryFromState() {
			const query = {}
			if (Array.isArray(this.selectedStatusCodes) && this.selectedStatusCodes.length > 0) {
				const statusCodes = this.selectedStatusCodes.filter(s => s && (s.value || typeof s === 'string')).map(s => (typeof s === 'string' ? s : s.value))
				if (statusCodes.length > 0) query.statusCode = statusCodes.join(',')
			}
			if (Array.isArray(this.selectedMethods) && this.selectedMethods.length > 0) {
				const methods = this.selectedMethods.filter(m => m && (m.value || typeof m === 'string')).map(m => (typeof m === 'string' ? m : m.value))
				if (methods.length > 0) query.method = methods.join(',')
			}
			if (this.selectedSource && this.selectedSource.value && this.selectedSource.value.id) query.source_id = String(this.selectedSource.value.id)
			if (this.dateFrom) query.dateFrom = this.dateFrom
			if (this.dateTo) query.dateTo = this.dateTo
			if (this.endpointFilter) query.endpoint = this.endpointFilter
			if (this.showOnlyErrors) query.onlyErrors = 'true'
			if (this.showSlowRequests) query.slowRequests = 'true'
			return query
		},
		queriesEqual(a, b) {
			const aKeys = Object.keys(a)
			const bKeys = Object.keys(b)
			if (aKeys.length !== bKeys.length) return false
			return aKeys.every(k => {
				const aVal = a[k] === undefined ? '' : String(a[k])
				const bVal = b[k] === undefined ? '' : String(b[k])
				return aVal === bVal
			})
		},
		updateRouteQueryFromState() {
			if (this.$route.path !== '/sources/logs') return
			const next = this.buildQueryFromState()
			if (this.queriesEqual(next, this.$route.query || {})) return
			this.$router.replace({ path: this.$route.path, query: next })
		},
		applyQueryParamsFromRoute() {
			if (this.$route.path !== '/sources/logs') return
			const q = this.$route.query || {}
			// Status codes
			if (typeof q.statusCode === 'string') {
				const parts = q.statusCode.split(',').map(s => s.trim()).filter(Boolean)
				this.selectedStatusCodes = parts
					.map(code => this.statusCodeOptions.find(opt => opt.value === code))
					.filter(Boolean)
			}
			// Methods
			if (typeof q.method === 'string') {
				const parts = q.method.split(',').map(s => s.trim()).filter(Boolean)
				this.selectedMethods = parts
					.map(method => this.methodOptions.find(opt => opt.value === method))
					.filter(Boolean)
			}
			// Source
			if (q.source_id) {
				const id = Number(q.source_id)
				const found = sourceStore.list?.find(s => s.id === id)
				this.selectedSource = found ? { value: found, label: found.name, title: found.name } : null
			}
			// Dates
			this.dateFrom = q.dateFrom || null
			this.dateTo = q.dateTo || null
			// Endpoint
			this.endpointFilter = q.endpoint || ''
			// Flags
			this.showOnlyErrors = String(q.onlyErrors) === 'true'
			this.showSlowRequests = String(q.slowRequests) === 'true'
			// Apply
			this.applyFilters()
		},
		/**
		 * Update filtered count from store
		 */
		updateFilteredCount() {
			const logs = (sourceStore.sourceLogs && Array.isArray(sourceStore.sourceLogs.results)) ? sourceStore.sourceLogs.results : []
			this.filteredCount = logs.length
			this.totalLogs = logs.length
		},
		/**
		 * Load statistics
		 */
		async loadStatistics() {
			try {
				const logs = (sourceStore.sourceLogs && Array.isArray(sourceStore.sourceLogs.results)) ? sourceStore.sourceLogs.results : []
				this.totalLogs = logs.length
				this.successCount = logs.filter(log => log.statusCode >= 200 && log.statusCode < 300).length
				this.errorCount = logs.filter(log => log.statusCode >= 400).length
				const responseTimes = logs.filter(log => log.response?.responseTime).map(log => log.response.responseTime / 1000)
				this.averageResponseTime = responseTimes.length > 0
					? Math.round((responseTimes.reduce((sum, time) => sum + time, 0) / responseTimes.length) * 1000) / 1000
					: 0
			} catch (error) {
				console.error('Error loading statistics:', error)
			}
		},
		/**
		 * Load status code distribution for stats
		 */
		async loadStatusDistribution() {
			try {
				const logs = (sourceStore.sourceLogs && Array.isArray(sourceStore.sourceLogs.results)) ? sourceStore.sourceLogs.results : []
				const statusMap = {}
				logs.forEach(log => {
					const code = log.statusCode
					if (!statusMap[code]) {
						statusMap[code] = {
							code,
							message: log.statusMessage || 'Unknown',
							count: 0,
						}
					}
					statusMap[code].count++
				})
				this.statusDistribution = Object.values(statusMap).sort((a, b) => b.count - a.count).slice(0, 10)
			} catch (error) {
				console.error('Error loading status distribution:', error)
			}
		},
		/**
		 * Load top sources for stats
		 */
		async loadTopSources() {
			try {
				const logs = (sourceStore.sourceLogs && Array.isArray(sourceStore.sourceLogs.results)) ? sourceStore.sourceLogs.results : []
				const sourceMap = {}
				logs.forEach(log => {
					const sourceId = log.sourceId
					if (!sourceMap[sourceId]) {
						const source = sourceStore.list?.find(s => s.id === sourceId)
						sourceMap[sourceId] = {
							name: source?.name || `Source ${sourceId}`,
							count: 0,
						}
					}
					sourceMap[sourceId].count++
				})
				this.topSources = Object.values(sourceMap).sort((a, b) => b.count - a.count).slice(0, 10)
			} catch (error) {
				console.error('Error loading top sources:', error)
			}
		},
		/**
		 * Handle source change
		 * @param {object} sourceOption - The selected source option object
		 */
		handleSourceChange(sourceOption) {
			const source = sourceOption && sourceOption.value ? sourceOption.value : null
			sourceStore.setItem(source)
			this.applyFilters()
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
}

.sidebarSection__hint {
	margin: 8px 16px;
}

.statusDistribution,
.topSources {
	margin-top: 20px;
}

.statusDistribution h4,
.topSources h4 {
	margin: 0 0 12px 0;
	font-size: 1rem;
	font-weight: 500;
	color: var(--color-main-text);
}
</style>

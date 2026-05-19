<template>
	<NcAppContent>
		<CnDashboardPage
			:title="t('openconnector', 'Dashboard')"
			:widgets="widgetDefs"
			:layout="dashboardLayout"
			:loading="isLoading && !hasData"
			@layout-change="onLayoutChange">
			<template #widget-stat-sources>
				<CnStatsBlock
					title="Sources"
					:count="stats.sources || 0"
					:count-label="n('openconnector', 'source', 'sources', stats.sources || 0)"
					:icon="DatabaseOutline"
					:loading="isLoading"
					variant="primary"
					:route="{ path: '/sources' }" />
			</template>
			<template #widget-stat-mappings>
				<CnStatsBlock
					title="Mappings"
					:count="stats.mappings || 0"
					:count-label="n('openconnector', 'mapping', 'mappings', stats.mappings || 0)"
					:icon="SwapHorizontal"
					:loading="isLoading"
					variant="primary"
					:route="{ path: '/mappings' }" />
			</template>
			<template #widget-stat-synchronizations>
				<CnStatsBlock
					title="Synchronizations"
					:count="stats.synchronizations || 0"
					:count-label="n('openconnector', 'sync', 'syncs', stats.synchronizations || 0)"
					:icon="SyncIcon"
					:loading="isLoading"
					variant="primary"
					:route="{ path: '/synchronizations' }" />
			</template>
			<template #widget-stat-contracts>
				<CnStatsBlock
					title="Contracts"
					:count="stats.synchronizationContracts || 0"
					:count-label="n('openconnector', 'contract', 'contracts', stats.synchronizationContracts || 0)"
					:icon="FileDocumentOutline"
					:loading="isLoading"
					variant="primary"
					:route="{ path: '/synchronizations/contracts' }" />
			</template>
			<template #widget-stat-jobs>
				<CnStatsBlock
					title="Jobs"
					:count="stats.jobs || 0"
					:count-label="n('openconnector', 'job', 'jobs', stats.jobs || 0)"
					:icon="CogOutline"
					:loading="isLoading"
					variant="primary"
					:route="{ path: '/jobs' }" />
			</template>
			<template #widget-stat-endpoints>
				<CnStatsBlock
					title="Endpoints"
					:count="stats.endpoints || 0"
					:count-label="n('openconnector', 'endpoint', 'endpoints', stats.endpoints || 0)"
					:icon="ConnectionIcon"
					:loading="isLoading"
					variant="primary"
					:route="{ path: '/endpoints' }" />
			</template>

			<template #widget-date-range>
				<div class="date-range-selector">
					<div class="date-picker">
						<label>{{ t('openconnector', 'From') }}:</label>
						<NcDateTimePicker
							v-model="dateRange.from"
							:max-date="dateRange.to"
							:show-time="true"
							:placeholder="t('openconnector', 'Select start date')"
							@change="handleDateChange" />
					</div>
					<div class="date-picker">
						<label>{{ t('openconnector', 'To') }}:</label>
						<NcDateTimePicker
							v-model="dateRange.to"
							:min-date="dateRange.from"
							:max-date="now"
							:show-time="true"
							:placeholder="t('openconnector', 'Select end date')"
							@change="handleDateChange" />
					</div>
				</div>
			</template>

			<template #widget-calls-daily>
				<CnChartWidget
					type="area"
					:series="sourcesCallsSeries"
					:colors="['#28a745', '#dc3545']"
					:options="datetimeAreaOptions" />
			</template>
			<template #widget-calls-hourly>
				<CnChartWidget
					type="area"
					:series="incomingCallsSeries"
					:categories="hourCategories"
					:colors="['#28a745', '#dc3545']"
					:options="stackedAreaOptions" />
			</template>

			<template #widget-jobs-daily>
				<CnChartWidget
					type="area"
					:series="jobCallsSeries"
					:colors="['#28a745', '#ffc107', '#dc3545', '#17a2b8']"
					:options="datetimeAreaOptions" />
			</template>
			<template #widget-jobs-hourly>
				<CnChartWidget
					type="area"
					:series="jobCallsByHourSeries"
					:categories="hourCategories"
					:colors="['#28a745', '#ffc107', '#dc3545', '#17a2b8']"
					:options="stackedAreaOptions" />
			</template>

			<template #widget-syncs-daily>
				<CnChartWidget
					type="area"
					:series="syncCallsSeries"
					:colors="['#28a745']"
					:options="datetimeAreaOptions" />
			</template>
			<template #widget-syncs-hourly>
				<CnChartWidget
					type="area"
					:series="syncCallsByHourSeries"
					:categories="hourCategories"
					:colors="['#28a745']"
					:options="stackedAreaOptions" />
			</template>
		</CnDashboardPage>
	</NcAppContent>
</template>

<script>

import { NcAppContent, NcDateTimePicker } from '@nextcloud/vue'
import { CnDashboardPage, CnStatsBlock, CnChartWidget } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import SyncIcon from 'vue-material-design-icons/Sync.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import ConnectionIcon from 'vue-material-design-icons/Connection.vue'

const HOUR_CATEGORIES = Array.from({ length: 24 }, (_, i) => i.toString().padStart(2, '0') + ':00')

const DEFAULT_LAYOUT = [
	{ id: 1, widgetId: 'stat-sources', gridX: 0, gridY: 0, gridWidth: 2, gridHeight: 2, showTitle: false },
	{ id: 2, widgetId: 'stat-mappings', gridX: 2, gridY: 0, gridWidth: 2, gridHeight: 2, showTitle: false },
	{ id: 3, widgetId: 'stat-synchronizations', gridX: 4, gridY: 0, gridWidth: 2, gridHeight: 2, showTitle: false },
	{ id: 4, widgetId: 'stat-contracts', gridX: 6, gridY: 0, gridWidth: 2, gridHeight: 2, showTitle: false },
	{ id: 5, widgetId: 'stat-jobs', gridX: 8, gridY: 0, gridWidth: 2, gridHeight: 2, showTitle: false },
	{ id: 6, widgetId: 'stat-endpoints', gridX: 10, gridY: 0, gridWidth: 2, gridHeight: 2, showTitle: false },
	{ id: 7, widgetId: 'date-range', gridX: 0, gridY: 2, gridWidth: 12, gridHeight: 2, showTitle: false },
	{ id: 8, widgetId: 'calls-daily', gridX: 0, gridY: 4, gridWidth: 6, gridHeight: 4 },
	{ id: 9, widgetId: 'calls-hourly', gridX: 6, gridY: 4, gridWidth: 6, gridHeight: 4 },
	{ id: 10, widgetId: 'jobs-daily', gridX: 0, gridY: 8, gridWidth: 6, gridHeight: 4 },
	{ id: 11, widgetId: 'jobs-hourly', gridX: 6, gridY: 8, gridWidth: 6, gridHeight: 4 },
	{ id: 12, widgetId: 'syncs-daily', gridX: 0, gridY: 12, gridWidth: 6, gridHeight: 4 },
	{ id: 13, widgetId: 'syncs-hourly', gridX: 6, gridY: 12, gridWidth: 6, gridHeight: 4 },
]

/**
 * Dashboard component showing statistics and graphs for the OpenConnector app
 */
export default {
	name: 'DashboardIndex',
	components: {
		NcAppContent,
		NcDateTimePicker,
		CnDashboardPage,
		CnStatsBlock,
		CnChartWidget,
	},
	data() {
		const to = new Date()
		const from = new Date()
		from.setDate(from.getDate() - 7)

		return {
			isLoading: true,
			isMounted: false,
			activeRequests: [],

			dashboardLayout: [...DEFAULT_LAYOUT],

			stats: {
				sources: 0,
				mappings: 0,
				synchronizations: 0,
				synchronizationContracts: 0,
				jobs: 0,
				endpoints: 0,
			},
			dateRange: { from, to },

			DatabaseOutline,
			SwapHorizontal,
			SyncIcon,
			FileDocumentOutline,
			CogOutline,
			ConnectionIcon,

			sourcesCallsSeries: [
				{ name: 'Successful Calls', data: [] },
				{ name: 'Failed Calls', data: [] },
			],
			incomingCallsSeries: [
				{ name: 'Successful Calls', data: Array(24).fill(0) },
				{ name: 'Failed Calls', data: Array(24).fill(0) },
			],
			jobCallsSeries: [
				{ name: 'Info', data: [] },
				{ name: 'Warning', data: [] },
				{ name: 'Error', data: [] },
				{ name: 'Debug', data: [] },
			],
			jobCallsByHourSeries: [
				{ name: 'Info', data: Array(24).fill(0) },
				{ name: 'Warning', data: Array(24).fill(0) },
				{ name: 'Error', data: Array(24).fill(0) },
				{ name: 'Debug', data: Array(24).fill(0) },
			],
			syncCallsSeries: [{ name: 'Executions', data: [] }],
			syncCallsByHourSeries: [{ name: 'Executions', data: Array(24).fill(0) }],
		}
	},
	computed: {
		widgetDefs() {
			return [
				{ id: 'stat-sources', title: 'Sources', type: 'custom' },
				{ id: 'stat-mappings', title: 'Mappings', type: 'custom' },
				{ id: 'stat-synchronizations', title: 'Synchronizations', type: 'custom' },
				{ id: 'stat-contracts', title: 'Contracts', type: 'custom' },
				{ id: 'stat-jobs', title: 'Jobs', type: 'custom' },
				{ id: 'stat-endpoints', title: 'Endpoints', type: 'custom' },
				{ id: 'date-range', title: this.t('openconnector', 'Date range'), type: 'custom' },
				{ id: 'calls-daily', title: this.t('openconnector', 'Outgoing calls (daily)'), type: 'custom' },
				{ id: 'calls-hourly', title: this.t('openconnector', 'Outgoing calls (hourly)'), type: 'custom' },
				{ id: 'jobs-daily', title: this.t('openconnector', 'Job executions (daily)'), type: 'custom' },
				{ id: 'jobs-hourly', title: this.t('openconnector', 'Job executions (hourly)'), type: 'custom' },
				{ id: 'syncs-daily', title: this.t('openconnector', 'Synchronization executions (daily)'), type: 'custom' },
				{ id: 'syncs-hourly', title: this.t('openconnector', 'Synchronization executions (hourly)'), type: 'custom' },
			]
		},
		hasData() {
			return (this.stats.sources || 0) > 0
				|| (this.stats.mappings || 0) > 0
				|| (this.stats.synchronizations || 0) > 0
		},
		now() {
			return new Date()
		},
		hourCategories() {
			return HOUR_CATEGORIES
		},
		datetimeAreaOptions() {
			return {
				chart: { stacked: true },
				stroke: { curve: 'smooth' },
				dataLabels: { enabled: false },
				xaxis: {
					type: 'datetime',
					labels: {
						datetimeFormatter: {
							year: 'yyyy',
							month: 'MMM \'yy',
							day: 'dd MMM',
						},
					},
				},
				tooltip: { x: { format: 'dd MMM yyyy' } },
			}
		},
		stackedAreaOptions() {
			return {
				chart: { stacked: true },
				stroke: { curve: 'smooth' },
				dataLabels: { enabled: false },
			}
		},
	},
	mounted() {
		this.isMounted = true
		this.$nextTick(() => {
			if (window.requestIdleCallback) {
				window.requestIdleCallback(() => {
					if (this.isMounted) {
						this.fetchAllStats()
					}
				}, { timeout: 100 })
			} else {
				setTimeout(() => {
					if (this.isMounted) {
						this.fetchAllStats()
					}
				}, 0)
			}
		})
	},
	beforeDestroy() {
		this.isMounted = false
		this.activeRequests.forEach(request => {
			if (request && typeof request.cancel === 'function') {
				request.cancel()
			}
		})
		this.activeRequests = []
	},
	methods: {
		/**
		 * Fetches statistics from the backend
		 * @return {Promise<void>}
		 */
		async fetchStats() {
			if (this.stats.sources === 0 && this.stats.mappings === 0) {
				this.isLoading = true
			}
			try {
				const response = await axios.get(generateUrl('/apps/openconnector/api/dashboard'))
				if (this.isMounted) {
					this.stats = response.data
				}
			} catch (error) {
				if (this.isMounted) {
					console.error('Error fetching stats:', error)
				}
			} finally {
				if (this.isMounted) {
					this.isLoading = false
				}
			}
		},

		/**
		 * Fetches call statistics from the backend
		 * @return {Promise<void>}
		 */
		async fetchCallStats() {
			try {
				const params = this.getDateRangeParams()
				const response = await axios.get(
					generateUrl('/apps/openconnector/api/dashboard/callstats'),
					{ params },
				)
				if (!this.isMounted) return

				const { daily, hourly } = response.data

				this.sourcesCallsSeries = [
					{
						name: 'Successful Calls',
						data: Object.entries(daily).map(([date, s]) => ({
							x: new Date(date).getTime(),
							y: s.success,
						})).sort((a, b) => a.x - b.x),
					},
					{
						name: 'Failed Calls',
						data: Object.entries(daily).map(([date, s]) => ({
							x: new Date(date).getTime(),
							y: s.error,
						})).sort((a, b) => a.x - b.x),
					},
				]

				const successData = Array(24).fill(0)
				const errorData = Array(24).fill(0)
				Object.entries(hourly).forEach(([hour, s]) => {
					successData[parseInt(hour)] = s.success
					errorData[parseInt(hour)] = s.error
				})

				this.incomingCallsSeries = [
					{ name: 'Successful Calls', data: successData },
					{ name: 'Failed Calls', data: errorData },
				]
			} catch (error) {
				if (this.isMounted) {
					console.error('Error fetching call stats:', error)
				}
			}
		},

		/**
		 * Fetches job statistics from the backend
		 * @return {Promise<void>}
		 */
		async fetchJobStats() {
			try {
				const params = this.getDateRangeParams()
				const response = await axios.get(
					generateUrl('/apps/openconnector/api/dashboard/jobstats'),
					{ params },
				)
				if (!this.isMounted) return

				const { daily, hourly } = response.data

				this.jobCallsSeries = [
					{
						name: 'Info',
						data: Object.entries(daily).map(([date, s]) => ({ x: new Date(date).getTime(), y: s.info })),
					},
					{
						name: 'Warning',
						data: Object.entries(daily).map(([date, s]) => ({ x: new Date(date).getTime(), y: s.warning })),
					},
					{
						name: 'Error',
						data: Object.entries(daily).map(([date, s]) => ({ x: new Date(date).getTime(), y: s.error })),
					},
					{
						name: 'Debug',
						data: Object.entries(daily).map(([date, s]) => ({ x: new Date(date).getTime(), y: s.debug })),
					},
				]

				const infoData = Array(24).fill(0)
				const warningData = Array(24).fill(0)
				const errorData = Array(24).fill(0)
				const debugData = Array(24).fill(0)

				Object.entries(hourly).forEach(([hour, s]) => {
					infoData[parseInt(hour)] = s.info
					warningData[parseInt(hour)] = s.warning
					errorData[parseInt(hour)] = s.error
					debugData[parseInt(hour)] = s.debug
				})

				this.jobCallsByHourSeries = [
					{ name: 'Info', data: infoData },
					{ name: 'Warning', data: warningData },
					{ name: 'Error', data: errorData },
					{ name: 'Debug', data: debugData },
				]
			} catch (error) {
				if (this.isMounted) {
					console.error('Error fetching job stats:', error)
				}
			}
		},

		/**
		 * Fetches synchronization statistics from the backend
		 * @return {Promise<void>}
		 */
		async fetchSyncStats() {
			try {
				const params = this.getDateRangeParams()
				const response = await axios.get(
					generateUrl('/apps/openconnector/api/dashboard/syncstats'),
					{ params },
				)
				if (!this.isMounted) return

				const { daily, hourly } = response.data

				this.syncCallsSeries = [
					{
						name: 'Executions',
						data: Object.entries(daily).map(([date, count]) => ({
							x: new Date(date).getTime(),
							y: count,
						})),
					},
				]

				const executionData = Array(24).fill(0)
				Object.entries(hourly).forEach(([hour, count]) => {
					executionData[parseInt(hour)] = count
				})

				this.syncCallsByHourSeries = [{ name: 'Executions', data: executionData }]
			} catch (error) {
				if (this.isMounted) {
					console.error('Error fetching sync stats:', error)
				}
			}
		},

		/**
		 * Handle date change events from either date picker
		 */
		async handleDateChange() {
			this.isLoading = true
			try {
				await this.fetchGraphStats()
			} finally {
				this.isLoading = false
			}
		},

		/**
		 * Fetch all graph-related statistics
		 */
		async fetchGraphStats() {
			await Promise.all([
				this.fetchCallStats(),
				this.fetchJobStats(),
				this.fetchSyncStats(),
			])
		},

		/**
		 * Fetch all statistics
		 * @return {Promise<void>}
		 */
		async fetchAllStats() {
			Promise.all([
				this.fetchStats(),
				this.fetchCallStats(),
				this.fetchJobStats(),
				this.fetchSyncStats(),
			]).catch(error => {
				console.error('Error fetching dashboard stats:', error)
			})
		},

		/**
		 * Get date range parameters for API calls
		 * @return {object} Object containing from and to dates in ISO format
		 */
		getDateRangeParams() {
			return {
				from: this.dateRange.from.toISOString(),
				to: this.dateRange.to.toISOString(),
			}
		},

		/**
		 * Persist layout changes in component state
		 * @param {Array} newLayout - New layout array from CnDashboardPage
		 */
		onLayoutChange(newLayout) {
			this.dashboardLayout = newLayout
		},
	},
}
</script>

<style scoped>
.date-range-selector {
	display: flex;
	gap: 2rem;
	padding: 1rem;
	width: 100%;
	height: 100%;
	justify-content: center;
	align-items: center;
}

.date-picker {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.date-picker label {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

:deep(.mx-input) {
	height: 34px;
	padding: 6px 12px;
	border-radius: 4px;
	border: 1px solid var(--color-border);
	background-color: var(--color-main-background);
	color: var(--color-text-maxcontrast);
}

:deep(.mx-input:hover),
:deep(.mx-input:focus) {
	border-color: var(--color-primary);
}
</style>

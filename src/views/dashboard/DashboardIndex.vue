<template>
	<NcAppContent>
		<h2 class="pageHeader">
			Dashboard
		</h2>

		<div class="dashboard-content">
			<div class="stats">
				<div
					v-for="(stat, key) in statsConfig"
					:key="key"
					class="clickable"
					@click="navigateTo(key)">
					<h5>{{ stat.label }}</h5>
					<div class="content">
						<NcLoadingIcon v-if="isLoading" :size="44" />
						<template v-else>
							{{ stats[key] || 0 }}
						</template>
					</div>
				</div>
			</div>

			<div class="date-range-selector">
				<div class="date-picker">
					<label for="fromDate">From:</label>
					<NcDateTimePicker
						v-model="dateRange.from"
						:max-date="dateRange.to"
						:show-time="true"
						:placeholder="'Select start date'"
						@change="handleDateChange" />
				</div>
				<div class="date-picker">
					<label for="toDate">To:</label>
					<NcDateTimePicker
						v-model="dateRange.to"
						:min-date="dateRange.from"
						:max-date="new Date()"
						:show-time="true"
						:placeholder="'Select end date'"
						@change="handleDateChange" />
				</div>
			</div>

			<div class="graph-section">
				<h3>Calls</h3>
				<div class="graphs">
					<div>
						<h5>Outgoing Calls (Last 7 Days)</h5>
						<div class="content">
							<apexchart
								width="500"
								:options="sourcesCalls.options"
								:series="sourcesCalls.series" />
						</div>
					</div>
					<div>
						<h5>Outgoing Calls by Hour</h5>
						<div class="content">
							<apexchart
								width="500"
								:options="incomingCalls.options"
								:series="incomingCalls.series" />
						</div>
					</div>
				</div>
			</div>

			<div class="graph-section">
				<h3>Jobs</h3>
				<div class="graphs">
					<div>
						<h5>Job Executions (Last 7 Days)</h5>
						<div class="content">
							<apexchart
								width="500"
								:options="jobCalls.options"
								:series="jobCalls.series" />
						</div>
					</div>
					<div>
						<h5>Job Executions by Hour</h5>
						<div class="content">
							<apexchart
								width="500"
								:options="jobCallsByHour.options"
								:series="jobCallsByHour.series" />
						</div>
					</div>
				</div>
			</div>

			<div class="graph-section">
				<h3>Synchronizations</h3>
				<div class="graphs">
					<div>
						<h5>Synchronization Executions (Last 7 Days)</h5>
						<div class="content">
							<apexchart
								width="500"
								:options="syncCalls.options"
								:series="syncCalls.series" />
						</div>
					</div>
					<div>
						<h5>Synchronization Executions by Hour</h5>
						<div class="content">
							<apexchart
								width="500"
								:options="syncCallsByHour.options"
								:series="syncCallsByHour.series" />
						</div>
					</div>
				</div>
			</div>
		</div>
	</NcAppContent>
</template>

<script>

import { NcAppContent, NcLoadingIcon, NcDateTimePicker } from '@nextcloud/vue'
import VueApexCharts from 'vue-apexcharts'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

import { getTheme } from '../../services/getTheme.js'

/**
 * Dashboard component showing statistics and graphs for the OpenConnector app
 */
export default {
	name: 'DashboardIndex',
	components: {
		NcAppContent,
		NcLoadingIcon,
		NcDateTimePicker,
		apexchart: VueApexCharts,
	},
	data() {
		const to = new Date()
		const from = new Date()
		from.setDate(from.getDate() - 7)

		return {
			isLoading: true,
			// Track if component is mounted to prevent updates after unmount
			isMounted: false,
			// Track active requests to cancel them if component unmounts
			activeRequests: [],

			stats: {
				sources: 0,
				mappings: 0,
				synchronizations: 0,
				synchronizationContracts: 0,
				jobs: 0,
				endpoints: 0,
			},
			statsConfig: {
				sources: { label: 'Sources' },
				mappings: { label: 'Mappings' },
				synchronizations: { label: 'Synchronizations' },
				synchronizationContracts: { label: 'Contracts' },
				jobs: { label: 'Jobs' },
				endpoints: { label: 'Endpoints' },
			},
			dateRange: {
				from,
				to,
			},
			// Chart data - initialized lazily in created() to avoid blocking
			sourcesCalls: {
				options: {
					theme: {
						mode: getTheme(),
					},
					chart: {
						id: 'source-calls',
						type: 'area',
						stacked: true,
						foreColor: getTheme() === 'light' ? '#000000' : '#ffffff',
					},
					dataLabels: {
						enabled: false,
					},
					stroke: {
						curve: 'smooth',
					},
					xaxis: {
						type: 'datetime',
						labels: {
							datetimeFormatter: {
								year: 'yyyy',
								month: 'MMM \'yy',
								day: 'dd MMM',
							},
							format: 'dd MMM',
							style: {
								colors: getTheme() === 'light' ? '#000000' : '#ffffff',
							},
						},
					},
					tooltip: {
						x: {
							format: 'dd MMM yyyy',
						},
					},
					colors: ['#28a745', '#dc3545'], // Green for success, red for errors
					title: {
						text: 'Daily Outgoing Calls',
						align: 'left',
					},
					yaxis: {
						title: {
							text: 'Number of Calls',
							style: {
								color: getTheme() === 'light' ? '#000000' : '#ffffff',
							},
						},
						labels: {
							style: {
								colors: getTheme() === 'light' ? '#000000' : '#ffffff',
							},
						},
					},
				},
				series: [
					{
						name: 'Successful Calls',
						data: [],
					},
					{
						name: 'Failed Calls',
						data: [],
					},
				],
			},
			incomingCalls: {
				options: {
					theme: {
						mode: getTheme(),
					},
					chart: {
						id: 'calls-per-hour',
						type: 'area',
						stacked: true,
						foreColor: getTheme() === 'light' ? '#000000' : '#ffffff',
					},
					dataLabels: {
						enabled: false,
					},
					stroke: {
						curve: 'smooth',
					},
					xaxis: {
						categories: Array.from({ length: 24 }, (_, i) => i.toString().padStart(2, '0') + ':00'),
						labels: {
							style: {
								colors: getTheme() === 'light' ? '#000000' : '#ffffff',
							},
						},
					},
					colors: ['#28a745', '#dc3545'],
					title: {
						text: 'Hourly Outgoing Calls',
						align: 'left',
					},
					yaxis: {
						title: {
							text: 'Average Number of Calls',
							style: {
								color: getTheme() === 'light' ? '#000000' : '#ffffff',
							},
						},
						labels: {
							style: {
								colors: getTheme() === 'light' ? '#000000' : '#ffffff',
							},
						},
					},
					legend: {
						labels: {
							colors: getTheme() === 'light' ? '#000000' : '#ffffff',
						},
					},
				},
				series: [
					{
						name: 'Successful Calls',
						data: Array(24).fill(0),
					},
					{
						name: 'Failed Calls',
						data: Array(24).fill(0),
					},
				],
			},
			jobCalls: {
				options: {
					theme: {
						mode: getTheme(),
					},
					chart: {
						id: 'job-calls',
						type: 'area',
						stacked: true,
						foreColor: getTheme() === 'light' ? '#000000' : '#ffffff',
					},
					dataLabels: {
						enabled: false,
					},
					stroke: {
						curve: 'smooth',
					},
					xaxis: {
						type: 'datetime',
						labels: {
							style: {
								colors: getTheme() === 'light' ? '#000000' : '#ffffff',
							},
						},
					},
					colors: ['#28a745', '#ffc107', '#dc3545', '#17a2b8'], // green, yellow, red, blue for info, warning, error, debug
					title: {
						text: 'Daily Job Executions by Level',
						align: 'left',
					},
					yaxis: {
						title: {
							text: 'Number of Logs',
							style: {
								color: getTheme() === 'light' ? '#000000' : '#ffffff',
							},
						},
						labels: {
							style: {
								colors: getTheme() === 'light' ? '#000000' : '#ffffff',
							},
						},
					},
				},
				series: [
					{
						name: 'Info',
						data: [],
					},
					{
						name: 'Warning',
						data: [],
					},
					{
						name: 'Error',
						data: [],
					},
					{
						name: 'Debug',
						data: [],
					},
				],
			},
			jobCallsByHour: {
				options: {
					theme: {
						mode: getTheme(),
					},
					chart: {
						id: 'job-calls-per-hour',
						type: 'area',
						stacked: true,
						foreColor: getTheme() === 'light' ? '#000000' : '#ffffff',
					},
					dataLabels: {
						enabled: false,
					},
					stroke: {
						curve: 'smooth',
					},
					xaxis: {
						categories: Array.from({ length: 24 }, (_, i) => i.toString().padStart(2, '0') + ':00'),
						labels: {
							style: {
								colors: getTheme() === 'light' ? '#000000' : '#ffffff',
							},
						},
					},
					colors: ['#28a745', '#ffc107', '#dc3545', '#17a2b8'],
					title: {
						text: 'Hourly Job Executions by Level',
						align: 'left',
					},
					yaxis: {
						title: {
							text: 'Average Number of Logs',
							style: {
								color: getTheme() === 'light' ? '#000000' : '#ffffff',
							},
						},
						labels: {
							style: {
								colors: getTheme() === 'light' ? '#000000' : '#ffffff',
							},
						},
					},
					legend: {
						labels: {
							colors: getTheme() === 'light' ? '#000000' : '#ffffff',
						},
					},
				},
				series: [
					{
						name: 'Info',
						data: Array(24).fill(0),
					},
					{
						name: 'Warning',
						data: Array(24).fill(0),
					},
					{
						name: 'Error',
						data: Array(24).fill(0),
					},
					{
						name: 'Debug',
						data: Array(24).fill(0),
					},
				],
			},
			syncCalls: {
				options: {
					theme: {
						mode: getTheme(),
					},
					chart: {
						id: 'sync-calls',
						type: 'area',
						stacked: true,
						foreColor: getTheme() === 'light' ? '#000000' : '#ffffff',
					},
					dataLabels: {
						enabled: false,
					},
					stroke: {
						curve: 'smooth',
					},
					xaxis: {
						type: 'datetime',
						labels: {
							style: {
								colors: getTheme() === 'light' ? '#000000' : '#ffffff',
							},
						},
					},
					colors: ['#28a745'], // Only green since we're only showing executions
					title: {
						text: 'Daily Synchronization Executions',
						align: 'left',
					},
					yaxis: {
						title: {
							text: 'Number of Executions',
							style: {
								color: getTheme() === 'light' ? '#000000' : '#ffffff',
							},
						},
						labels: {
							style: {
								colors: getTheme() === 'light' ? '#000000' : '#ffffff',
							},
						},
					},
				},
				series: [
					{
						name: 'Executions',
						data: [],
					},
				],
			},
			syncCallsByHour: {
				options: {
					theme: {
						mode: getTheme(),
					},
					chart: {
						id: 'sync-calls-per-hour',
						type: 'area',
						stacked: true,
						foreColor: getTheme() === 'light' ? '#000000' : '#ffffff',
					},
					dataLabels: {
						enabled: false,
					},
					stroke: {
						curve: 'smooth',
					},
					xaxis: {
						categories: Array.from({ length: 24 }, (_, i) => i.toString().padStart(2, '0') + ':00'),
						labels: {
							style: {
								colors: getTheme() === 'light' ? '#000000' : '#ffffff',
							},
						},
					},
					colors: ['#28a745'],
					title: {
						text: 'Hourly Synchronization Executions',
						align: 'left',
					},
					yaxis: {
						title: {
							text: 'Average Number of Executions',
							style: {
								color: getTheme() === 'light' ? '#000000' : '#ffffff',
							},
						},
						labels: {
							style: {
								colors: getTheme() === 'light' ? '#000000' : '#ffffff',
							},
						},
					},
					legend: {
						labels: {
							colors: getTheme() === 'light' ? '#000000' : '#ffffff',
						},
					},
				},
				series: [
					{
						name: 'Executions',
						data: Array(24).fill(0),
					},
				],
			},
		}
	},
	/**
	 * Fetch stats when component is mounted
	 * Use requestIdleCallback or setTimeout to avoid blocking initial render
	 */
	mounted() {
		this.isMounted = true
		// Use nextTick to ensure DOM is rendered before starting heavy operations
		this.$nextTick(() => {
			// Start API calls without blocking - use requestIdleCallback if available
			if (window.requestIdleCallback) {
				window.requestIdleCallback(() => {
					if (this.isMounted) {
						this.fetchAllStats()
					}
				}, { timeout: 100 })
			} else {
				// Fallback for browsers without requestIdleCallback
				setTimeout(() => {
					if (this.isMounted) {
						this.fetchAllStats()
					}
				}, 0)
			}
		})
	},
	/**
	 * Cleanup when component is destroyed
	 * Cancel any pending requests and prevent memory leaks
	 */
	beforeDestroy() {
		this.isMounted = false
		// Cancel any active requests
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
		 * @throws {Error} When the API call fails
		 */
		async fetchStats() {
			// Only set loading if stats haven't been loaded yet
			if (this.stats.sources === 0 && this.stats.mappings === 0) {
				this.isLoading = true
			}
			try {
				const response = await axios.get(generateUrl('/apps/openconnector/api/dashboard'))
				// Only update if component is still mounted
				if (this.isMounted) {
					this.stats = response.data
				}
			} catch (error) {
				// Only log if component is still mounted
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
				// Only update if component is still mounted
				if (!this.isMounted) return

				const { daily, hourly } = response.data

				// Ensure dates are properly formatted for the chart
				this.sourcesCalls.series = [
					{
						name: 'Successful Calls',
						data: Object.entries(daily).map(([date, stats]) => ({
							x: new Date(date).getTime(), // Convert to timestamp
							y: stats.success,
						})).sort((a, b) => a.x - b.x),
					},
					{
						name: 'Failed Calls',
						data: Object.entries(daily).map(([date, stats]) => ({
							x: new Date(date).getTime(), // Convert to timestamp
							y: stats.error,
						})).sort((a, b) => a.x - b.x),
					},
				]

				// Update hourly stats
				const successData = Array(24).fill(0)
				const errorData = Array(24).fill(0)
				Object.entries(hourly).forEach(([hour, stats]) => {
					successData[parseInt(hour)] = stats.success
					errorData[parseInt(hour)] = stats.error
				})

				this.incomingCalls.series = [
					{
						name: 'Successful Calls',
						data: successData,
					},
					{
						name: 'Failed Calls',
						data: errorData,
					},
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
				// Only update if component is still mounted
				if (!this.isMounted) return

				const { daily, hourly } = response.data

				// Update daily stats
				this.jobCalls.series = [
					{
						name: 'Info',
						data: Object.entries(daily).map(([date, stats]) => ({
							x: new Date(date).getTime(),
							y: stats.info,
						})),
					},
					{
						name: 'Warning',
						data: Object.entries(daily).map(([date, stats]) => ({
							x: new Date(date).getTime(),
							y: stats.warning,
						})),
					},
					{
						name: 'Error',
						data: Object.entries(daily).map(([date, stats]) => ({
							x: new Date(date).getTime(),
							y: stats.error,
						})),
					},
					{
						name: 'Debug',
						data: Object.entries(daily).map(([date, stats]) => ({
							x: new Date(date).getTime(),
							y: stats.debug,
						})),
					},
				]

				// Update hourly stats
				const infoData = Array(24).fill(0)
				const warningData = Array(24).fill(0)
				const errorData = Array(24).fill(0)
				const debugData = Array(24).fill(0)

				Object.entries(hourly).forEach(([hour, stats]) => {
					infoData[parseInt(hour)] = stats.info
					warningData[parseInt(hour)] = stats.warning
					errorData[parseInt(hour)] = stats.error
					debugData[parseInt(hour)] = stats.debug
				})

				this.jobCallsByHour.series = [
					{
						name: 'Info',
						data: infoData,
					},
					{
						name: 'Warning',
						data: warningData,
					},
					{
						name: 'Error',
						data: errorData,
					},
					{
						name: 'Debug',
						data: debugData,
					},
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
				// Only update if component is still mounted
				if (!this.isMounted) return

				const { daily, hourly } = response.data

				// Update daily stats
				this.syncCalls.series = [
					{
						name: 'Executions',
						data: Object.entries(daily).map(([date, count]) => ({
							x: new Date(date).getTime(),
							y: count,
						})),
					},
				]

				// Update hourly stats
				const executionData = Array(24).fill(0)
				Object.entries(hourly).forEach(([hour, count]) => {
					executionData[parseInt(hour)] = count
				})

				this.syncCallsByHour.series = [
					{
						name: 'Executions',
						data: executionData,
					},
				]
			} catch (error) {
				if (this.isMounted) {
					console.error('Error fetching sync stats:', error)
				}
			}
		},

		/**
		 * Navigate to the selected section
		 * @param {string} section - The section to navigate to
		 */
		/**
		 * Navigate to the selected section based on the routes defined in router/index.js
		 * @param {string} section - The section to navigate to ('sources', 'mappings', 'synchronizations', etc)
		 */
		navigateTo(section) {
			// Map section names to their corresponding routes
			const routeMap = {
				sources: '/sources',
				mappings: '/mappings',
				synchronizations: '/synchronizations',
				synchronizationContracts: '/synchronizations/contracts',
				jobs: '/jobs',
				endpoints: '/endpoints',
				consumers: '/consumers',
				webhooks: '/webhooks',
				rules: '/rules',
				cloudEvents: '/cloud-events',
				import: '/import',
			}

			// Get the route path for the section
			const route = routeMap[section]

			// Navigate to the route if it exists
			if (route) {
				this.$router.push(route)
			} else {
				console.warn(`Unknown section: ${section}`)
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
		 * Runs API calls in parallel but doesn't block initial render
		 * @return {Promise<void>}
		 */
		async fetchAllStats() {
			// Don't await - let calls run in parallel without blocking
			Promise.all([
				// loading is being exclusively handled by the fetchStats() method
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
	},
}
</script>

<style scoped>
.apexcharts-svg {
	background-color: transparent !important;
}

.dashboard-content {
	margin-inline: auto;
	max-width: 1000px;
	display: flex;
	flex-direction: column;
	justify-content: center;
	align-items: center;
}

.dashboard-content > * {
	margin-block-end: 4rem;
}

/* default theme */
@media (prefers-color-scheme: light) {
	:root {
		--dashboard-item-background-color: rgba(0, 0, 0, 0.07);
	}
}
@media (prefers-color-scheme: dark) {
	:root {
		--dashboard-item-background-color: rgba(255, 255, 255, 0.1);
	}
}
/* do theme checks, light mode | dark mode */
:root:has(body[data-theme-light]) {
	--dashboard-item-background-color: rgba(0, 0, 0, 0.07);
}

:root:has(body[data-theme-dark]) {
	--dashboard-item-background-color: rgba(255, 255, 255, 0.1);
}

/* most searched terms */
.dashboard-content > .stats {
	display: grid;
	grid-template-columns: 1fr;
	gap: 1rem;
}
@media screen and (min-width: 880px) {
	.dashboard-content > .stats {
		grid-template-columns: 1fr 1fr;
	}
}
@media screen and (min-width: 1024px) {
	.dashboard-content > .stats {
		grid-template-columns: repeat(2, 1fr);
	}
}
@media screen and (min-width: 1220px) {
	.dashboard-content > .stats {
		grid-template-columns: repeat(3, 1fr);
	}
}
@media screen and (min-width: 1590px) {
	.dashboard-content > .stats {
		grid-template-columns: repeat(3, 1fr);
	}
}

.dashboard-content > .stats > div {
	padding: 1rem;
	height: 150px;
	width: 250px;
	border-radius: 8px;
	cursor: pointer;
	transition: transform 0.2s ease-in-out;
}

.dashboard-content > .stats > div:hover {
	transform: scale(1.02);
}

/* default theme */
@media (prefers-color-scheme: light) {
	.dashboard-content > .stats > div {
		background-color: rgba(0, 0, 0, 0.07);
	}
}
@media (prefers-color-scheme: dark) {
	.dashboard-content > .stats > div {
		background-color: rgba(255, 255, 255, 0.1);
	}
}
/* do theme checks, light mode | dark mode */
body[data-theme-light] .dashboard-content > .stats > div {
	background-color: rgba(0, 0, 0, 0.07);
}

body[data-theme-dark] .dashboard-content > .stats > div {
	background-color: rgba(255, 255, 255, 0.1);
}

.dashboard-content > .stats > div > h5 {
	margin: 0;
	font-weight: normal;
}

.dashboard-content > .stats > div > .content {
	display: flex;
	justify-content: center;
	align-items: center;
	height: calc(100% - 40px);

	font-size: 3.5rem;
}

/* Update the graph section styling */
.graph-section {
	width: 100%;
	margin-bottom: 4rem;
}

.graph-section > h3 {
	margin-bottom: 1rem;
	text-align: center;
}

/* Update the graphs container styling */
.dashboard-content > .graph-section > .graphs {
	display: flex;
	flex-wrap: wrap;
	gap: 2rem;
	width: 100%;
	justify-content: center;
}

.dashboard-content > .graph-section > .graphs > div {
	flex: 1;
	min-width: 300px; /* Minimum width for readable graphs */
	max-width: calc(50% - 1rem); /* Maximum width of 50% minus half the gap */
}

/* On smaller screens (mobile) */
@media screen and (max-width: 768px) {
	.dashboard-content > .graph-section > .graphs {
		flex-direction: column;
		align-items: center;
	}

	.dashboard-content > .graph-section > .graphs > div {
		width: 100%;
		max-width: 100%;
	}
}

/* Remove the old .graphs styles that were causing the issue */
.dashboard-content > .graphs {
	display: none;
}

/* Adjust the loading icon size and color to match the theme */
.dashboard-content > .stats .icon-loading {
	width: 44px;
	height: 44px;
}

.clickable {
	cursor: pointer;
}

.date-range-selector {
	display: flex;
	gap: 2rem;
	margin: 2rem 0;
	padding: 1rem;
	width: 100%;
	justify-content: center;
	background-color: var(--dashboard-item-background-color);
	border-radius: 8px;
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

/* Make date picker more visible */
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

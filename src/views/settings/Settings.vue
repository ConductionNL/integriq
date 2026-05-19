<template>
	<div>
		<NcSettingsSection
			name="Open Connector"
			description="A central place for managing your Open Connector"
			doc-url="https://docs.openconnector.nl" />

		<!-- Version Information -->
		<CnVersionInfoCard
			app-name="OpenConnector"
			:app-version="versionInfo.appVersion || 'Unknown'"
			:loading="loading"
			:additional-items="[
				{ label: 'License', value: 'EUPL-1.2' },
				{ label: 'Author', value: 'Conduction B.V.' },
				{ label: 'Website', value: 'https://github.com/ConductionNL/OpenConnector' },
			]">
			<template #footer>
				<div class="cn-support-info">
					<h4>Support</h4>
					<p>
						For support, contact us at
						<a href="mailto:support@conduction.nl">support@conduction.nl</a>
					</p>
					<p>
						For a Service Level Agreement (SLA), contact
						<a href="mailto:sales@conduction.nl">sales@conduction.nl</a>
					</p>
				</div>
			</template>
		</CnVersionInfoCard>

		<NcSettingsSection name="System Statistics"
			description="Overview of your Open Connector data and potential issues">
			<div v-if="!loadingStats" class="stats-section">
				<!-- Refresh Button -->
				<div class="section-header-inline">
					<span />
					<div class="button-group">
						<NcButton
							type="secondary"
							:disabled="loading || saving || rebasing || loadingStats"
							@click="loadStats">
							<template #icon>
								<NcLoadingIcon v-if="loadingStats" :size="20" />
								<Refresh v-else :size="20" />
							</template>
							Refresh
						</NcButton>
					</div>
				</div>

				<div class="stats-content">
					<div class="stats-grid">
						<!-- Warning Stats -->
						<div class="stats-card warning-stats">
							<h4>⚠️ Items Requiring Attention</h4>
							<div class="stats-table-container">
								<table class="stats-table">
									<thead>
										<tr>
											<th class="stats-table-header">
												Issue
											</th>
											<th class="stats-table-header">
												Count
											</th>
											<th class="stats-table-header">
												Size
											</th>
										</tr>
									</thead>
									<tbody>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Call logs without expiry
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.warnings.callLogsWithoutExpiry || 0) > 0 }">
												{{ stats.warnings.callLogsWithoutExpiry || 0 }}
											</td>
											<td class="stats-table-value">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Event messages without expiry
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.warnings.eventMessagesWithoutExpiry || 0) > 0 }">
												{{ stats.warnings.eventMessagesWithoutExpiry || 0 }}
											</td>
											<td class="stats-table-value">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Job logs without expiry
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.warnings.jobLogsWithoutExpiry || 0) > 0 }">
												{{ stats.warnings.jobLogsWithoutExpiry || 0 }}
											</td>
											<td class="stats-table-value">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Sync contract logs without expiry
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.warnings.syncContractLogsWithoutExpiry || 0) > 0 }">
												{{ stats.warnings.syncContractLogsWithoutExpiry || 0 }}
											</td>
											<td class="stats-table-value">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Sync logs without expiry
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.warnings.syncLogsWithoutExpiry || 0) > 0 }">
												{{ stats.warnings.syncLogsWithoutExpiry || 0 }}
											</td>
											<td class="stats-table-value">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Expired call logs
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.warnings.expiredCallLogs || 0) > 0 }">
												{{ stats.warnings.expiredCallLogs || 0 }}
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.sizes.expiredCallLogsSize || 0) > 0 }">
												{{ formatBytes(stats.sizes.expiredCallLogsSize || 0) }}
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Expired event messages
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.warnings.expiredEventMessages || 0) > 0 }">
												{{ stats.warnings.expiredEventMessages || 0 }}
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.sizes.expiredEventMessagesSize || 0) > 0 }">
												{{ formatBytes(stats.sizes.expiredEventMessagesSize || 0) }}
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Expired job logs
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.warnings.expiredJobLogs || 0) > 0 }">
												{{ stats.warnings.expiredJobLogs || 0 }}
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.sizes.expiredJobLogsSize || 0) > 0 }">
												{{ formatBytes(stats.sizes.expiredJobLogsSize || 0) }}
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Expired sync contract logs
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.warnings.expiredSyncContractLogs || 0) > 0 }">
												{{ stats.warnings.expiredSyncContractLogs || 0 }}
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.sizes.expiredSyncContractLogsSize || 0) > 0 }">
												{{ formatBytes(stats.sizes.expiredSyncContractLogsSize || 0) }}
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Expired sync logs
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.warnings.expiredSyncLogs || 0) > 0 }">
												{{ stats.warnings.expiredSyncLogs || 0 }}
											</td>
											<td class="stats-table-value" :class="{ 'danger': (stats.sizes.expiredSyncLogsSize || 0) > 0 }">
												{{ formatBytes(stats.sizes.expiredSyncLogsSize || 0) }}
											</td>
										</tr>
									</tbody>
								</table>
							</div>
						</div>

						<!-- Total Stats -->
						<div class="stats-card total-stats">
							<h4>📊 System Totals</h4>
							<div class="stats-table-container">
								<table class="stats-table">
									<thead>
										<tr>
											<th class="stats-table-header">
												Category
											</th>
											<th class="stats-table-header">
												Total
											</th>
											<th class="stats-table-header">
												Size
											</th>
										</tr>
									</thead>
									<tbody>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Call Logs
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalCallLogs.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												{{ formatBytes(stats.sizes.totalCallLogsSize) }}
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Event Messages
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalEventMessages.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												{{ formatBytes(stats.sizes.totalEventMessagesSize) }}
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Job Logs
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalJobLogs.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												{{ formatBytes(stats.sizes.totalJobLogsSize) }}
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Sync Contract Logs
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalSynchronizationContractLogs.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												{{ formatBytes(stats.sizes.totalSyncContractLogsSize) }}
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Sync Logs
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalSynchronizationLogs.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												{{ formatBytes(stats.sizes.totalSyncLogsSize) }}
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Consumers
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalConsumers.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Endpoints
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalEndpoints.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Event Subscriptions
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalEventSubscriptions.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Events
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalEvents.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Jobs
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalJobs.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Mappings
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalMappings.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Rules
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalRules.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Sources
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalSources.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Sync Contracts
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalSynchronizationContracts.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												-
											</td>
										</tr>
										<tr class="stats-table-row">
											<td class="stats-table-label">
												Synchronizations
											</td>
											<td class="stats-table-value total">
												{{ stats.totals.totalSynchronizations.toLocaleString() }}
											</td>
											<td class="stats-table-value total">
												-
											</td>
										</tr>
									</tbody>
								</table>
							</div>
						</div>
					</div>

					<div class="stats-footer">
						<p class="stats-updated">
							Last updated: {{ formatDate(stats.lastUpdated) }}
						</p>
					</div>
				</div>
			</div>

			<!-- Loading State -->
			<NcLoadingIcon v-else
				class="loading-icon"
				:size="64"
				appearance="dark" />
		</NcSettingsSection>

		<NcSettingsSection name="Log Retention">
			<template #description>
				Configure data and log retention policies
			</template>

			<div v-if="!loading" class="retention-options">
				<!-- Save and Rebase Buttons -->
				<div class="section-header-inline">
					<span />
					<div class="button-group">
						<NcButton
							type="error"
							:disabled="loading || saving || rebasing"
							@click="showRebaseDialog">
							<template #icon>
								<NcLoadingIcon v-if="rebasing" :size="20" />
								<Refresh v-else :size="20" />
							</template>
							Rebase
						</NcButton>
						<NcButton
							type="primary"
							:disabled="loading || saving || rebasing"
							@click="saveSettings">
							<template #icon>
								<NcLoadingIcon v-if="saving" :size="20" />
								<Save v-else :size="20" />
							</template>
							Save
						</NcButton>
					</div>
				</div>

				<!-- Section Description -->
				<div class="section-description-full">
					<p class="main-description">
						Configure retention policies for OpenConnector logs. Log retention manages how long different types of logs are kept for compliance and debugging.
						<strong>Note:</strong> Setting retention to 0 means data is kept forever (not advisable for production).
					</p>
					<p class="impact-description warning-box">
						<strong>⚠️ Important:</strong> Changes to retention policies only apply to logs that are created or modified after the retention policy was changed.
						Existing logs will retain their previous retention schedules until the rebase operation is performed.
					</p>
				</div>

				<!-- Log Retention Settings -->
				<div class="option-section">
					<h4>Log Retention Policies</h4>
					<p class="option-description">
						Configure retention periods for different types of logs (in milliseconds). These settings control how long logs are stored before automatic cleanup.
					</p>

					<div class="retention-table">
						<div class="retention-row">
							<div class="retention-label">
								<strong>Call Log Retention</strong>
								<p class="retention-description">
									Retention period for logs with success status and request/response data
								</p>
							</div>
							<div class="retention-input">
								<div class="retention-input-wrapper">
									<input
										v-model.number="retentionOptions.successLogRetention"
										type="number"
										:disabled="loading || saving"
										placeholder="3600000"
										class="retention-input-field">
									<span class="retention-unit">ms</span>
								</div>
							</div>
							<div class="retention-display">
								{{ formatRetentionPeriod(retentionOptions.successLogRetention) }}
							</div>
						</div>
						<div class="retention-row">
							<div class="retention-label">
								<strong>Call Log Retention</strong>
								<p class="retention-description">
									Retention period for API call logs and request/response data
								</p>
							</div>
							<div class="retention-input">
								<div class="retention-input-wrapper">
									<input
										v-model.number="retentionOptions.callLogRetention"
										type="number"
										:disabled="loading || saving"
										placeholder="2592000000"
										class="retention-input-field">
									<span class="retention-unit">ms</span>
								</div>
							</div>
							<div class="retention-display">
								{{ formatRetentionPeriod(retentionOptions.callLogRetention) }}
							</div>
						</div>

						<div class="retention-row">
							<div class="retention-label">
								<strong>Event Message Retention</strong>
								<p class="retention-description">
									Retention period for event messages and webhook deliveries
								</p>
							</div>
							<div class="retention-input">
								<div class="retention-input-wrapper">
									<input
										v-model.number="retentionOptions.eventMessageRetention"
										type="number"
										:disabled="loading || saving"
										placeholder="604800000"
										class="retention-input-field">
									<span class="retention-unit">ms</span>
								</div>
							</div>
							<div class="retention-display">
								{{ formatRetentionPeriod(retentionOptions.eventMessageRetention) }}
							</div>
						</div>

						<div class="retention-row">
							<div class="retention-label">
								<strong>Job Log Retention</strong>
								<p class="retention-description">
									Retention period for job execution logs and results
								</p>
							</div>
							<div class="retention-input">
								<div class="retention-input-wrapper">
									<input
										v-model.number="retentionOptions.jobLogRetention"
										type="number"
										:disabled="loading || saving"
										placeholder="2592000000"
										class="retention-input-field">
									<span class="retention-unit">ms</span>
								</div>
							</div>
							<div class="retention-display">
								{{ formatRetentionPeriod(retentionOptions.jobLogRetention) }}
							</div>
						</div>

						<div class="retention-row">
							<div class="retention-label">
								<strong>Sync Contract Log Retention</strong>
								<p class="retention-description">
									Retention period for synchronization contract logs
								</p>
							</div>
							<div class="retention-input">
								<div class="retention-input-wrapper">
									<input
										v-model.number="retentionOptions.syncContractLogRetention"
										type="number"
										:disabled="loading || saving"
										placeholder="7776000000"
										class="retention-input-field">
									<span class="retention-unit">ms</span>
								</div>
							</div>
							<div class="retention-display">
								{{ formatRetentionPeriod(retentionOptions.syncContractLogRetention) }}
							</div>
						</div>

						<div class="retention-row">
							<div class="retention-label">
								<strong>Sync Log Retention</strong>
								<p class="retention-description">
									Retention period for synchronization execution logs
								</p>
							</div>
							<div class="retention-input">
								<div class="retention-input-wrapper">
									<input
										v-model.number="retentionOptions.syncLogRetention"
										type="number"
										:disabled="loading || saving"
										placeholder="2592000000"
										class="retention-input-field">
									<span class="retention-unit">ms</span>
								</div>
							</div>
							<div class="retention-display">
								{{ formatRetentionPeriod(retentionOptions.syncLogRetention) }}
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Loading State -->
			<NcLoadingIcon v-else
				class="loading-icon"
				:size="64"
				appearance="dark" />
		</NcSettingsSection>

		<!-- Rebase Confirmation Dialog -->
		<NcDialog
			v-if="showRebaseConfirmation"
			name="Confirm Rebase"
			:can-close="!rebasing"
			@closing="hideRebaseDialog">
			<div class="rebase-dialog">
				<div class="rebase-warning">
					<h3>⚠️ Rebase All Logs</h3>
					<p class="warning-text">
						This action will recalculate expiry times for all logs based on your current retention settings.
						This operation uses database-optimized queries for maximum performance.
					</p>
					<p class="impact-text">
						<strong>This operation:</strong><br>
						• Will update expiry timestamps for all existing logs<br>
						• Cannot be undone once started<br>
						• May take some time to complete depending on data volume<br>
						• Uses database operations for optimal performance
					</p>
				</div>
				<div class="dialog-actions">
					<NcButton
						:disabled="rebasing"
						@click="hideRebaseDialog">
						Cancel
					</NcButton>
					<NcButton
						type="error"
						:disabled="rebasing"
						@click="performRebase">
						<template #icon>
							<NcLoadingIcon v-if="rebasing" :size="20" />
							<Refresh v-else :size="20" />
						</template>
						{{ rebasing ? 'Rebasing...' : 'Confirm Rebase' }}
					</NcButton>
				</div>
			</div>
		</NcDialog>
	</div>
</template>

<script>
import { defineComponent } from 'vue'
import { CnVersionInfoCard } from '@conduction/nextcloud-vue'
import {
	NcSettingsSection,
	NcButton,
	NcLoadingIcon,
	NcDialog,
} from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Save from 'vue-material-design-icons/ContentSave.vue'

/**
 * @class Settings
 * @module Components
 * @package
 * @author Claude AI
 * @copyright 2024 Conduction
 * @license EUPL-1.2
 * @version 1.0.0
 * @see https://github.com/ConductionNL/OpenConnector
 *
 * Settings component for the Open Connector that allows users to configure
 * version information and view system statistics.
 */
export default defineComponent({
	name: 'Settings',
	components: {
		CnVersionInfoCard,
		NcSettingsSection,
		NcButton,
		NcLoadingIcon,
		NcDialog,
		Refresh,
		Save,
	},

	/**
	 * Component data
	 *
	 * @return {object} Component data
	 */
	data() {
		return {
			loading: true,
			saving: false,
			rebasing: false,
			loadingStats: true,
			showRebaseConfirmation: false,
			stats: {
				warnings: {
					callLogsWithoutExpiry: 0,
					eventMessagesWithoutExpiry: 0,
					jobLogsWithoutExpiry: 0,
					syncContractLogsWithoutExpiry: 0,
					syncLogsWithoutExpiry: 0,
					expiredCallLogs: 0,
					expiredEventMessages: 0,
					expiredJobLogs: 0,
					expiredSyncContractLogs: 0,
					expiredSyncLogs: 0,
				},
				totals: {
					totalCallLogs: 0,
					totalConsumers: 0,
					totalEndpoints: 0,
					totalEventMessages: 0,
					totalEventSubscriptions: 0,
					totalEvents: 0,
					totalJobLogs: 0,
					totalJobs: 0,
					totalMappings: 0,
					totalRules: 0,
					totalSources: 0,
					totalSynchronizationContractLogs: 0,
					totalSynchronizationContracts: 0,
					totalSynchronizationLogs: 0,
					totalSynchronizations: 0,
				},
				sizes: {
					totalCallLogsSize: 0,
					totalEventMessagesSize: 0,
					totalJobLogsSize: 0,
					totalSyncContractLogsSize: 0,
					totalSyncLogsSize: 0,
					expiredCallLogsSize: 0,
					expiredEventMessagesSize: 0,
					expiredJobLogsSize: 0,
					expiredSyncContractLogsSize: 0,
					expiredSyncLogsSize: 0,
				},
				lastUpdated: new Date(),
			},
			versionInfo: {
				appName: 'Open Connector',
				appVersion: '0.2.0',
			},
			retentionOptions: {
				successLogRetention: 3600000, // 1 hour default
				callLogRetention: 2592000000, // 1 month default
				eventMessageRetention: 604800000, // 1 week default
				jobLogRetention: 2592000000, // 1 month default
				syncContractLogRetention: 7776000000, // 3 months default
				syncLogRetention: 2592000000, // 1 month default
			},
		}
	},

	/**
	 * Lifecycle hook that loads settings when component is created
	 */
	async created() {
		await this.loadSettings()
		await this.loadStats()
	},

	methods: {
		/**
		 * Load statistics from the backend
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async loadStats() {
			this.loadingStats = true

			try {
				const response = await fetch('/index.php/apps/openconnector/api/settings/stats')
				const data = await response.json()

				if (data.error) {
					console.error('Failed to load stats:', data.error)
					return
				}

				this.stats = {
					warnings: data.warnings || {},
					totals: data.totals || {},
					sizes: data.sizes || {},
					lastUpdated: new Date(data.lastUpdated || Date.now()),
				}

			} catch (error) {
				console.error('Failed to load stats:', error)
			} finally {
				this.loadingStats = false
			}
		},

		/**
		 * Format a date for display
		 *
		 * @param {Date|string} date Date to format
		 * @return {string} Formatted date string
		 */
		formatDate(date) {
			if (!date) return 'Unknown'

			try {
				// Handle both string dates and date objects with nested date property
				let dateValue = date
				if (typeof date === 'object' && date.date) {
					dateValue = date.date
				}

				const dateObj = dateValue instanceof Date ? dateValue : new Date(dateValue)
				if (isNaN(dateObj.getTime())) {
					return 'Invalid Date'
				}
				return dateObj.toLocaleString()
			} catch (error) {
				console.error('Error formatting date:', error, date)
				return 'Invalid Date'
			}
		},

		/**
		 * Load settings from the backend
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async loadSettings() {
			this.loading = true

			try {
				const response = await fetch('/index.php/apps/openconnector/api/settings')
				const data = await response.json()

				if (data.error) {
					console.error('Failed to load settings:', data.error)
					return
				}

				// Unwrap config from dependency-check response envelope
				const config = data.config || data

				// Version information
				this.versionInfo = config.version

				// Retention settings
				if (config.retention) {
					this.retentionOptions = {
						successLogRetention: config.retention.successLogRetention || 2592000000,
						callLogRetention: config.retention.callLogRetention || 2592000000,
						eventMessageRetention: config.retention.eventMessageRetention || 604800000,
						jobLogRetention: config.retention.jobLogRetention || 2592000000,
						syncContractLogRetention: config.retention.syncContractLogRetention || 7776000000,
						syncLogRetention: config.retention.syncLogRetention || 2592000000,
					}
				}

			} catch (error) {
				console.error('Failed to load settings:', error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Save settings to the backend
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async saveSettings() {
			this.saving = true

			try {
				const settingsData = {
					retention: {
						successLogRetention: this.retentionOptions.successLogRetention,
						callLogRetention: this.retentionOptions.callLogRetention,
						eventMessageRetention: this.retentionOptions.eventMessageRetention,
						jobLogRetention: this.retentionOptions.jobLogRetention,
						syncContractLogRetention: this.retentionOptions.syncContractLogRetention,
						syncLogRetention: this.retentionOptions.syncLogRetention,
					},
				}

				const response = await fetch('/index.php/apps/openconnector/api/settings', {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(settingsData),
				})

				const result = await response.json()

				if (result.error) {
					console.error('Failed to save settings:', result.error)
					return
				}

				// Update local state with server response
				if (result.retention) {
					this.retentionOptions = {
						successLogRetention: result.retention.successLogRetention,
						callLogRetention: result.retention.callLogRetention,
						eventMessageRetention: result.retention.eventMessageRetention,
						jobLogRetention: result.retention.jobLogRetention,
						syncContractLogRetention: result.retention.syncContractLogRetention,
						syncLogRetention: result.retention.syncLogRetention,
					}
				}

			} catch (error) {
				console.error('Failed to save settings:', error)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Show the rebase confirmation dialog
		 *
		 * @return {void}
		 */
		showRebaseDialog() {
			this.showRebaseConfirmation = true
		},

		/**
		 * Hide the rebase confirmation dialog
		 *
		 * @return {void}
		 */
		hideRebaseDialog() {
			this.showRebaseConfirmation = false
		},

		/**
		 * Perform the rebase operation
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async performRebase() {
			this.rebasing = true

			try {
				const response = await fetch('/index.php/apps/openconnector/api/settings/rebase', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				const result = await response.json()

				if (result.error) {
					console.error('Failed to rebase:', result.error)
					return
				}

				// Hide the dialog and show success
				this.hideRebaseDialog()

			} catch (error) {
				console.error('Failed to rebase:', error)
			} finally {
				this.rebasing = false
			}
		},

		/**
		 * Format retention period in milliseconds to human-readable format
		 *
		 * @param {number} ms Milliseconds
		 * @return {string} Formatted time period
		 */
		formatRetentionPeriod(ms) {
			if (!ms || ms === 0) {
				return 'Forever (not advisable)'
			}

			const units = [
				{ name: 'year', ms: 365 * 24 * 60 * 60 * 1000, plural: 'years' },
				{ name: 'month', ms: 30 * 24 * 60 * 60 * 1000, plural: 'months' },
				{ name: 'week', ms: 7 * 24 * 60 * 60 * 1000, plural: 'weeks' },
				{ name: 'day', ms: 24 * 60 * 60 * 1000, plural: 'days' },
				{ name: 'hour', ms: 60 * 60 * 1000, plural: 'hours' },
				{ name: 'minute', ms: 60 * 1000, plural: 'minutes' },
			]

			const parts = []
			let remaining = ms

			for (const unit of units) {
				const count = Math.floor(remaining / unit.ms)
				if (count > 0) {
					parts.push(`${count} ${count === 1 ? unit.name : unit.plural}`)
					remaining -= count * unit.ms
				}
			}

			if (parts.length === 0) {
				return 'Less than 1 minute'
			}

			// Show up to 2 most significant units
			return parts.slice(0, 2).join(', ')
		},

		/**
		 * Format bytes to human readable format
		 *
		 * @param {number} bytes Number of bytes
		 * @return {string} Formatted byte string
		 */
		formatBytes(bytes) {
			if (!bytes || bytes === 0) return '0 Bytes'

			const k = 1024
			const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))

			return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
		},
	},
})
</script>

<style scoped>

.section-header-inline {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 1rem;
	position: relative;
	top: -45px;
	margin-bottom: -40px;
	z-index: 10;
}

.button-group {
	display: flex;
	gap: 0.5rem;
	align-items: center;
}

.stats-section {
	max-width: none;
}

.stats-content {
	margin-top: 1rem;
}

.stats-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 1.5rem;
	margin-bottom: 1.5rem;
}

.stats-card {
	padding: 1.5rem;
	border-radius: var(--border-radius-large);
	border: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
}

.stats-card h4 {
	margin: 0 0 1rem 0;
	font-size: 1rem;
	font-weight: bold;
	color: var(--color-text-dark);
}

.stats-footer {
	text-align: center;
	padding-top: 1rem;
	border-top: 1px solid var(--color-border);
}

.stats-updated {
	margin: 0;
	color: var(--color-text-lighter);
	font-size: 0.8rem;
}

/* Stats Table Styles */
.stats-table-container {
	margin-top: 1rem;
	overflow-x: auto;
}

.stats-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9rem;
}

.stats-table-header {
	padding: 0.75rem 1rem;
	text-align: left;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	background-color: var(--color-background-dark);
	border-bottom: 1px solid var(--color-border);
}

.stats-table-header:first-child {
	border-top-left-radius: var(--border-radius);
}

.stats-table-header:last-child {
	border-top-right-radius: var(--border-radius);
}

.stats-table-row {
	border-bottom: 1px solid var(--color-border-dark);
}

.stats-table-row:hover {
	background-color: var(--color-background-hover);
}

.stats-table-label {
	padding: 0.75rem 1rem;
	font-weight: 500;
	color: var(--color-text-light);
}

.stats-table-value {
	padding: 0.75rem 1rem;
	text-align: right;
	font-weight: 600;
}

.stats-table-value.total {
	color: var(--color-primary-element);
}

.stats-table-value.danger {
	color: var(--color-error) !important;
	font-weight: 600;
}

@media (max-width: 768px) {
	.stats-grid {
		grid-template-columns: 1fr;
		gap: 1rem;
	}
}

/* Retention Settings Styles */
.retention-options {
	max-width: none;
}

.option-section {
	margin-bottom: 1.5rem;
	padding: 1rem 0;
	border-bottom: 1px solid var(--color-border);
}

.option-section:last-child {
	border-bottom: none;
}

.option-description {
	margin-top: 0.5rem;
	color: var(--color-text-lighter);
	font-size: 0.9rem;
	line-height: 1.4;
}

.section-description-full {
	margin-bottom: 1.5rem;
}

.main-description {
	margin-bottom: 1rem;
	line-height: 1.5;
	color: var(--color-text-light);
}

.warning-box {
	background-color: var(--color-warning-light, #fff3cd);
	border: 1px solid var(--color-warning, #ffc107);
	border-radius: var(--border-radius);
	padding: 0.75rem;
	margin-bottom: 1rem;
	color: var(--color-warning-dark, #856404);
}

.impact-description {
	margin-bottom: 0;
	padding: 0.75rem;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
	line-height: 1.4;
	font-size: 0.9rem;
}

.retention-table {
	margin-top: 1rem;
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.retention-row {
	display: grid;
	grid-template-columns: 1fr auto 1fr;
	gap: 1.5rem;
	align-items: center;
	padding: 1rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.retention-label {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.retention-label strong {
	font-size: 1rem;
	color: var(--color-text-dark);
}

.retention-description {
	margin: 0;
	color: var(--color-text-light);
	font-size: 0.9rem;
	line-height: 1.4;
}

.retention-input-wrapper {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.retention-input-field {
	width: 140px;
	padding: 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	text-align: right;
	background-color: var(--color-main-background);
}

.retention-input-field:focus {
	outline: none;
	border-color: var(--color-primary);
	box-shadow: 0 0 0 2px var(--color-primary-light);
}

.retention-unit {
	color: var(--color-text-lighter);
	font-size: 0.9rem;
	font-weight: bold;
	min-width: 20px;
}

.retention-display {
	font-size: 0.8rem;
	color: var(--color-text-lighter);
	font-style: italic;
	padding: 0.25rem 0.5rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-small);
	border-left: 3px solid var(--color-primary);
}

.rebase-dialog {
	padding: 1.5rem;
	max-width: 700px;
	width: 100%;
}

.rebase-warning {
	margin-bottom: 1.5rem;
}

.rebase-warning h3 {
	color: var(--color-error);
	margin-bottom: 1rem;
	font-size: 1.1rem;
}

.warning-text {
	margin-bottom: 1rem;
	line-height: 1.5;
	color: var(--color-text-light);
}

.impact-text {
	padding: 1rem;
	background-color: var(--color-warning-light, #fff3cd);
	border: 1px solid var(--color-warning, #ffc107);
	border-radius: var(--border-radius);
	margin-bottom: 1rem;
	color: var(--color-warning-dark, #856404);
	line-height: 1.4;
	font-size: 0.9rem;
}

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.5rem;
	margin-top: 1rem;
}
</style>

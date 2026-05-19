<template>
	<NcDashboardWidget :items="items"
		:loading="loading"
		:item-menu="itemMenu"
		@show="onShow">
		<template #empty-content>
			<NcEmptyContent :title="t('openconnector', 'Geen recente calls gevonden')">
				<template #icon>
					<PhoneIcon />
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
import { NcDashboardWidget, NcEmptyContent } from '@nextcloud/vue'
import PhoneIcon from 'vue-material-design-icons/Phone.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'RecentCallsWidget',
	components: {
		NcDashboardWidget,
		NcEmptyContent,
		PhoneIcon,
	},
	props: {
		title: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loading: false,
			callLogs: [],
			itemMenu: {
				show: {
					text: t('openconnector', 'Bekijk logs'),
					icon: 'icon-link',
				},
			},
		}
	},
	computed: {
		items() {
			return this.callLogs.map((log) => {
				const statusCode = log.statusCode || log.status_code || 0
				const method = (log.method || 'GET').toUpperCase()
				const endpoint = log.url || log.endpoint || t('openconnector', 'Onbekend endpoint')
				const isSuccess = statusCode >= 200 && statusCode < 300
				const statusLabel = isSuccess ? '✓' : '✗'
				const dateText = log.dateCreated || log.created || ''
				const formattedDate = dateText ? this.formatDate(dateText) : ''

				return {
					id: log.id,
					mainText: method + ' ' + this.truncateUrl(endpoint),
					subText: statusLabel + ' ' + statusCode + (formattedDate ? ' - ' + formattedDate : ''),
					avatarUrl: '',
				}
			})
		},
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		/**
		 * Navigate to call logs
		 * @param {object} item - The log item
		 */
		onShow(item) {
			window.location.href = '/index.php/apps/openconnector/sources/logs'
		},
		/**
		 * Truncate a URL for display
		 * @param {string} url - The URL to truncate
		 * @return {string} The truncated URL
		 */
		truncateUrl(url) {
			if (url.length > 50) {
				return url.substring(0, 47) + '...'
			}
			return url
		},
		/**
		 * Format a date string to a human-readable format
		 * @param {string} dateString - The date string to format
		 * @return {string} The formatted date string
		 */
		formatDate(dateString) {
			try {
				const date = new Date(dateString)
				return date.toLocaleString()
			} catch {
				return dateString
			}
		},
		/**
		 * Fetch call logs from the API
		 * @return {Promise<void>}
		 */
		async fetchData() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openconnector/api/sources/logs'),
					{ params: { _limit: 7, _order: { dateCreated: 'desc' } } },
				)
				this.callLogs = response.data?.results || response.data || []
			} catch (error) {
				console.error('Error fetching call logs for widget:', error)
				this.callLogs = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

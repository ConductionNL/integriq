<template>
	<NcDashboardWidget :items="items"
		:loading="loading"
		:item-menu="itemMenu"
		@show="onShow">
		<template #empty-content>
			<NcEmptyContent :title="t('openconnector', 'Geen bronnen gevonden')">
				<template #icon>
					<DatabaseIcon />
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
import { NcDashboardWidget, NcEmptyContent } from '@nextcloud/vue'
import DatabaseIcon from 'vue-material-design-icons/Database.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'SourceSyncWidget',
	components: {
		NcDashboardWidget,
		NcEmptyContent,
		DatabaseIcon,
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
			sources: [],
			itemMenu: {
				show: {
					text: t('openconnector', 'Bekijk bron'),
					icon: 'icon-link',
				},
			},
		}
	},
	computed: {
		items() {
			return this.sources.map((source) => {
				const lastSync = source.lastSync || source.dateModified || null
				const lastSyncText = lastSync
					? t('openconnector', 'Laatst gesynchroniseerd: {date}', { date: this.formatDate(lastSync) })
					: t('openconnector', 'Nog niet gesynchroniseerd')
				const status = source.status || 'unknown'
				const statusText = this.getStatusText(status)

				return {
					id: source.id,
					mainText: source.name || t('openconnector', 'Naamloze bron'),
					subText: lastSyncText + ' - ' + statusText,
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
		 * Navigate to the source detail page
		 * @param {object} item - The source item to show
		 */
		onShow(item) {
			window.location.href = '/index.php/apps/openconnector/sources'
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
		 * Get a human-readable status text
		 * @param {string} status - The status value
		 * @return {string} The status text
		 */
		getStatusText(status) {
			const statusMap = {
				active: t('openconnector', 'Actief'),
				inactive: t('openconnector', 'Inactief'),
				error: t('openconnector', 'Fout'),
				unknown: t('openconnector', 'Onbekend'),
			}
			return statusMap[status] || status
		},
		/**
		 * Fetch the source data from the API
		 * @return {Promise<void>}
		 */
		async fetchData() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openconnector/api/sources'),
					{ params: { _limit: 7 } },
				)
				this.sources = response.data?.results || response.data || []
			} catch (error) {
				console.error('Error fetching sources for widget:', error)
				this.sources = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

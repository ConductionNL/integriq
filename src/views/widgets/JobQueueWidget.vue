<template>
	<NcDashboardWidget :items="items"
		:loading="loading"
		:item-menu="itemMenu"
		@show="onShow">
		<template #empty-content>
			<NcEmptyContent :title="t('openconnector', 'Geen taken gevonden')">
				<template #icon>
					<ClockIcon />
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
import { NcDashboardWidget, NcEmptyContent } from '@nextcloud/vue'
import ClockIcon from 'vue-material-design-icons/Clock.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'JobQueueWidget',
	components: {
		NcDashboardWidget,
		NcEmptyContent,
		ClockIcon,
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
			jobs: [],
			itemMenu: {
				show: {
					text: t('openconnector', 'Bekijk taken'),
					icon: 'icon-link',
				},
			},
		}
	},
	computed: {
		items() {
			return this.jobs.map((job) => {
				const isEnabled = job.isEnabled !== false && job.enabled !== false
				const statusText = isEnabled
					? t('openconnector', 'Ingeschakeld')
					: t('openconnector', 'Uitgeschakeld')
				const lastRun = job.lastRun || job.dateModified || null
				const lastRunText = lastRun
					? t('openconnector', 'Laatst uitgevoerd: {date}', { date: this.formatDate(lastRun) })
					: t('openconnector', 'Nog niet uitgevoerd')

				return {
					id: job.id,
					mainText: job.name || t('openconnector', 'Naamloze taak'),
					subText: statusText + ' - ' + lastRunText,
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
		 * Navigate to the jobs page
		 * @param {object} item - The job item
		 */
		onShow(item) {
			window.location.href = '/index.php/apps/openconnector/jobs'
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
		 * Fetch jobs from the API
		 * @return {Promise<void>}
		 */
		async fetchData() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openconnector/api/jobs'),
					{ params: { _limit: 7 } },
				)
				this.jobs = response.data?.results || response.data || []
			} catch (error) {
				console.error('Error fetching jobs for widget:', error)
				this.jobs = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

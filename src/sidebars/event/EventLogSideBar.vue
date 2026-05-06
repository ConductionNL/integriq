<script setup>
import { eventStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		:name="t('openconnector', 'Event Log Management')"
		:subtitle="t('openconnector', 'Filter and analyze event execution logs')"
		:subname="t('openconnector', 'View, filter, or refresh event logs')"
		:open="navigationStore.sidebarState.eventLogs"
		@update:open="(e) => navigationStore.setSidebarState('eventLogs', e)">
		<!-- Filter Section -->
		<div class="sidebarSection">
			<h3 class="sidebarSection__title">
				{{ t('openconnector', 'Filter Event Logs') }}
			</h3>
			<div class="sidebarSection__body">
				<NcSelect
					v-model="filters.eventId"
					:options="eventOptions"
					:input-label="t('openconnector', 'Event')"
					:placeholder="t('openconnector', 'Select event')"
					:clearable="true"
					@input="handleFilterChange" />
				<NcSelect
					v-model="filters.level"
					:options="logLevelOptions"
					:input-label="t('openconnector', 'Log Level')"
					:placeholder="t('openconnector', 'Select level')"
					:clearable="true"
					@input="handleFilterChange" />
				<div class="sidebarSection__field">
					<span class="sidebarSection__fieldLabel">{{ t('openconnector', 'Date range') }}</span>
					<DateRangeInput
						:start="filters.startDate"
						:end="filters.endDate"
						:max-start="new Date()"
						@update:start="(v) => { filters.startDate = v }"
						@update:end="(v) => { filters.endDate = v }"
						@change="handleFilterChange" />
				</div>
				<NcTextField
					:value="filters.message"
					:label="t('openconnector', 'Message')"
					:placeholder="t('openconnector', 'Search in messages')"
					@input="handleMessageFilterChange" />
				<NcCheckboxRadioSwitch
					:checked="filters.showOnlyErrors"
					@update:checked="(v) => { filters.showOnlyErrors = v; handleFilterChange() }">
					{{ t('openconnector', 'Show only errors') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="filters.showOnlySlow"
					@update:checked="(v) => { filters.showOnlySlow = v; handleFilterChange() }">
					{{ t('openconnector', 'Show only slow executions') }}
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
			{{ t('openconnector', 'Use filters to narrow down event logs by event, level, date range, or message content.') }}
		</NcNoteCard>

		<!-- Statistics Panel: stats grid + level distribution + top events -->
		<CnStatsPanel class="eventStatsPanel" :sections="statsSections" />
	</NcAppSidebar>
</template>

<script>
import {
	NcAppSidebar,
	NcSelect,
	NcTextField,
	NcButton,
	NcCheckboxRadioSwitch,
	NcNoteCard,
} from '@nextcloud/vue'
import { CnStatsPanel } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import FilterOffOutline from 'vue-material-design-icons/FilterOffOutline.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import TimelineQuestionOutline from 'vue-material-design-icons/TimelineQuestionOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import Update from 'vue-material-design-icons/Update.vue'
import DateRangeInput from '../../components/DateRangeInput.vue'
import getValidISOstring from '@/services/getValidISOstring.js'

export default {
	name: 'EventLogSideBar',
	components: {
		NcAppSidebar,
		NcSelect,
		NcTextField,
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		CnStatsPanel,
		FilterOffOutline,
		DateRangeInput,
	},
	data() {
		return {
			logLevelOptions: [
				{ label: t('openconnector', 'Success'), value: 'SUCCESS' },
				{ label: t('openconnector', 'Warning'), value: 'WARNING' },
				{ label: t('openconnector', 'Error'), value: 'ERROR' },
				{ label: t('openconnector', 'Critical'), value: 'CRITICAL' },
				{ label: t('openconnector', 'Alert'), value: 'ALERT' },
				{ label: t('openconnector', 'Emergency'), value: 'EMERGENCY' },
				{ label: t('openconnector', 'Info'), value: 'INFO' },
			],
			filters: {
				eventId: null,
				level: null,
				startDate: null,
				endDate: null,
				message: '',
				showOnlyErrors: false,
				showOnlySlow: false,
			},
			filterTimeout: null,
		}
	},
	computed: {
		eventOptions() {
			if (!eventStore.list) return []
			return eventStore.list.map(event => ({
				label: event.name,
				value: event.id,
			}))
		},
		logsArray() {
			return (eventStore.logs && Array.isArray(eventStore.logs.results)) ? eventStore.logs.results : []
		},
		totalLogs() {
			return this.logsArray.length
		},
		successfulLogs() {
			return this.logsArray.filter(log => log.level === 'SUCCESS').length
		},
		failedLogs() {
			return this.logsArray.filter(log => ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'].includes(log.level)).length
		},
		averageExecutionTimeSeconds() {
			const times = this.logsArray.filter(log => log.executionTime).map(log => log.executionTime)
			if (!times.length) return 0
			const avgMs = times.reduce((sum, time) => sum + time, 0) / times.length
			return Math.round((avgMs / 1000) * 1000) / 1000
		},
		levelDistribution() {
			const map = {}
			this.logsArray.forEach(log => {
				const level = log.level || 'UNKNOWN'
				map[level] = (map[level] || 0) + 1
			})
			return Object.entries(map)
				.map(([level, count]) => ({ level, count }))
				.sort((a, b) => b.count - a.count)
				.slice(0, 10)
		},
		topEvents() {
			const map = {}
			this.logsArray.forEach(log => {
				const id = log.eventId
				if (!id) return
				if (!map[id]) {
					const entity = eventStore.list?.find(e => String(e.id) === String(id))
					map[id] = { id, name: entity?.name || `Event ${id}`, count: 0 }
				}
				map[id].count++
			})
			return Object.values(map)
				.sort((a, b) => b.count - a.count)
				.slice(0, 10)
		},
		hasActiveFilters() {
			return Object.values(this.filters).some(value => {
				if (typeof value === 'boolean') return value
				return value !== null && value !== ''
			})
		},
		statsSections() {
			const sections = [
				{
					id: 'stats',
					type: 'stats',
					title: t('openconnector', 'Event Log Statistics'),
					layout: 'grid',
					columns: 2,
					items: [
						{
							title: t('openconnector', 'Total event logs'),
							count: this.totalLogs,
							countLabel: t('openconnector', 'events'),
							icon: TimelineQuestionOutline,
						},
						{
							title: t('openconnector', 'Successful events'),
							count: this.successfulLogs,
							countLabel: t('openconnector', 'events'),
							icon: CheckCircle,
							variant: 'success',
						},
						{
							title: t('openconnector', 'Failed events'),
							count: this.failedLogs,
							countLabel: t('openconnector', 'events'),
							icon: AlertCircle,
							variant: 'error',
						},
						{
							title: t('openconnector', 'Average execution time'),
							count: this.averageExecutionTimeSeconds,
							countLabel: t('openconnector', 'seconds'),
							icon: ChartLine,
						},
					],
				},
			]
			if (this.levelDistribution.length) {
				sections.push({
					id: 'levelDistribution',
					type: 'list',
					title: t('openconnector', 'Log Level Distribution'),
					items: this.levelDistribution.map(entry => ({
						key: entry.level,
						name: entry.level,
						subname: t('openconnector', '{count} events', { count: entry.count }),
						icon: this.iconForLevel(entry.level),
					})),
				})
			}
			if (this.topEvents.length) {
				sections.push({
					id: 'topEvents',
					type: 'list',
					title: t('openconnector', 'Most Active Events'),
					items: this.topEvents.map(entry => ({
						key: String(entry.id),
						name: entry.name,
						subname: t('openconnector', '{count} events', { count: entry.count }),
						icon: Update,
					})),
				})
			}
			return sections
		},
	},
	mounted() {
		eventStore.refreshList()
		this.applyQueryParamsFromRoute()
	},
	methods: {
		iconForLevel(level) {
			if (level === 'SUCCESS') return CheckCircle
			if (level === 'WARNING') return AlertCircle
			if (['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'].includes(level)) return CloseCircle
			return InformationOutline
		},
		handleFilterChange() {
			this.$root.$emit('event-log-filters-changed', this.filters)
			this.updateRouteQueryFromState()
		},
		handleMessageFilterChange(value) {
			const nextValue = typeof value === 'string' ? value : (value && value.target ? value.target.value : '')
			this.filters.message = nextValue
			this.debouncedHandleFilterChange()
		},
		debouncedHandleFilterChange() {
			clearTimeout(this.filterTimeout)
			this.filterTimeout = setTimeout(() => {
				this.handleFilterChange()
			}, 500)
		},
		clearFilters() {
			this.filters = {
				eventId: null,
				level: null,
				startDate: null,
				endDate: null,
				message: '',
				showOnlyErrors: false,
				showOnlySlow: false,
			}
			this.handleFilterChange()
		},
		buildQueryFromState() {
			const q = {}
			if (this.filters.eventId) q.eventId = String(this.filters.eventId)
			if (this.filters.level) q.level = String(this.filters.level)
			if (this.filters.startDate) q.startDate = getValidISOstring(this.filters.startDate)
			if (this.filters.endDate) q.endDate = getValidISOstring(this.filters.endDate)
			if (this.filters.message) q.message = this.filters.message
			if (this.filters.showOnlyErrors) q.onlyErrors = 'true'
			if (this.filters.showOnlySlow) q.slowExecutions = 'true'
			return q
		},
		queriesEqual(a, b) {
			const aKeys = Object.keys(a)
			const bKeys = Object.keys(b)
			if (aKeys.length !== bKeys.length) return false
			return aKeys.every(k => String(a[k]) === String(b[k] || ''))
		},
		updateRouteQueryFromState() {
			if (this.$route.path !== '/cloud-events/logs') return
			const next = this.buildQueryFromState()
			if (this.queriesEqual(next, this.$route.query || {})) return
			this.$router.replace({ path: this.$route.path, query: next })
		},
		applyQueryParamsFromRoute() {
			if (this.$route.path !== '/cloud-events/logs') return
			const q = this.$route.query || {}
			this.filters.eventId = q.eventId || null
			this.filters.level = q.level || null
			this.filters.startDate = q.startDate && new Date(q.startDate).getDate() ? new Date(q.startDate) : null
			this.filters.endDate = q.endDate && new Date(q.endDate).getDate() ? new Date(q.endDate) : null
			this.filters.message = q.message || ''
			this.filters.showOnlyErrors = String(q.onlyErrors) === 'true'
			this.filters.showOnlySlow = String(q.slowExecutions) === 'true'
			this.handleFilterChange()
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

/* Pad CnStatsPanel section content to match the sidebar's 16px gutter; titles already have their own padding. */
.eventStatsPanel :deep(.cn-kpi-grid),
.eventStatsPanel :deep(.cn-stats-panel__list),
.eventStatsPanel :deep(.cn-stats-panel__stack) {
	padding: 0 16px;
}
</style>

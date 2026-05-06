<script setup>
import { translate as t } from '@nextcloud/l10n'
import { eventStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openconnector', 'Events')"
			:description="t('openconnector', 'Manage your cloud events and event listeners')"
			:show-title="true"
			:objects="filteredEvents"
			:columns="tableColumns"
			:pagination="paginationData"
			:loading="loading"
			:refreshing="refreshing"
			:inline-action-count="2"
			:selectable="true"
			:selected-ids="selectedEvents"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-delete-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			show-view-toggle
			:add-label="t('openconnector', 'Add event')"
			row-key="id"
			:empty-text="emptyContentName"
			name-field="name"
			@add="onAdd"
			@refresh="onRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@row-click="openEvent"
			@select="onSelect">
			<!-- Card view -->
			<template #card="{ object: event }">
				<div class="card">
					<div class="cardHeader">
						<h2 v-tooltip.bottom="event.description">
							<Update :size="20" />
							{{ event.name }}
						</h2>
						<NcActions :primary="true" :menu-name="t('openconnector', 'Actions')">
							<template #icon>
								<DotsHorizontal :size="20" />
							</template>
							<NcActionButton close-after-click @click="openEvent(event)">
								<template #icon>
									<Eye :size="20" />
								</template>
								{{ t('openconnector', 'View details') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="eventStore.setItem(event); navigationStore.setModal('editEvent')">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('openconnector', 'Edit') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="eventStore.setItem(event); navigationStore.setModal('testEvent')">
								<template #icon>
									<Sync :size="20" />
								</template>
								{{ t('openconnector', 'Test') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="eventStore.setItem(event); navigationStore.setModal('runEvent')">
								<template #icon>
									<Play :size="20" />
								</template>
								{{ t('openconnector', 'Run') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="addEventArgument(event)">
								<template #icon>
									<Plus :size="20" />
								</template>
								{{ t('openconnector', 'Add Argument') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="viewEventLogs(event)">
								<template #icon>
									<TextBoxOutline :size="20" />
								</template>
								{{ t('openconnector', 'View logs') }}
							</NcActionButton>
							<NcActionButton close-after-click @click="eventStore.setItem(event); navigationStore.setDialog('deleteEvent')">
								<template #icon>
									<TrashCanOutline :size="20" />
								</template>
								{{ t('openconnector', 'Delete') }}
							</NcActionButton>
						</NcActions>
					</div>
					<div class="eventDetails">
						<p v-if="event.description" class="eventDescription">
							{{ event.description }}
						</p>
						<table class="statisticsTable eventStats">
							<thead>
								<tr>
									<th>{{ t('openconnector', 'Property') }}</th>
									<th>{{ t('openconnector', 'Value') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td>{{ t('openconnector', 'Status') }}</td>
									<td>
										<span :class="event.isEnabled ? 'status-enabled' : 'status-disabled'">
											{{ event.isEnabled ? t('openconnector', 'Enabled') : t('openconnector', 'Disabled') }}
										</span>
									</td>
								</tr>
								<tr v-if="event.eventClass">
									<td>{{ t('openconnector', 'Event class') }}</td>
									<td class="truncatedText">
										{{ event.eventClass }}
									</td>
								</tr>
								<tr v-if="event.interval">
									<td>{{ t('openconnector', 'Interval') }}</td>
									<td>{{ event.interval }}</td>
								</tr>
								<tr>
									<td>{{ t('openconnector', 'Arguments') }}</td>
									<td>{{ getArgumentCount(event) }}</td>
								</tr>
								<tr v-if="event.nextRun">
									<td>{{ t('openconnector', 'Next run') }}</td>
									<td><NcDateTime :timestamp="new Date(event.nextRun)" /></td>
								</tr>
								<tr v-if="event.lastRun">
									<td>{{ t('openconnector', 'Last run') }}</td>
									<td><NcDateTime :timestamp="new Date(event.lastRun)" /></td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</template>

			<!-- Table column slots -->
			<template #column-name="{ row }">
				<div class="titleContent">
					<strong>{{ row.name }}</strong>
					<span v-if="row.description" class="textDescription textEllipsis">{{ row.description }}</span>
				</div>
			</template>

			<template #column-status="{ row }">
				<span :class="row.isEnabled ? 'status-enabled' : 'status-disabled'">
					{{ row.isEnabled ? t('openconnector', 'Enabled') : t('openconnector', 'Disabled') }}
				</span>
			</template>

			<template #column-eventClass="{ row }">
				<span v-if="row.eventClass" class="truncatedText">{{ row.eventClass }}</span>
				<span v-else>-</span>
			</template>

			<template #column-interval="{ row }">
				{{ row.interval || '-' }}
			</template>

			<template #column-arguments="{ row }">
				{{ getArgumentCount(row) }}
			</template>

			<template #column-nextRun="{ row }">
				<NcDateTime v-if="row.nextRun" :timestamp="new Date(row.nextRun)" />
				<span v-else>-</span>
			</template>

			<template #column-lastRun="{ row }">
				<NcDateTime v-if="row.lastRun" :timestamp="new Date(row.lastRun)" />
				<span v-else>-</span>
			</template>

			<!-- Row actions (table view) -->
			<template #row-actions="{ row: event }">
				<NcActions :primary="false">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton close-after-click @click="openEvent(event)">
						<template #icon>
							<Eye :size="20" />
						</template>
						{{ t('openconnector', 'View details') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="eventStore.setItem(event); navigationStore.setModal('editEvent')">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('openconnector', 'Edit') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="eventStore.setItem(event); navigationStore.setModal('testEvent')">
						<template #icon>
							<Sync :size="20" />
						</template>
						{{ t('openconnector', 'Test') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="eventStore.setItem(event); navigationStore.setModal('runEvent')">
						<template #icon>
							<Play :size="20" />
						</template>
						{{ t('openconnector', 'Run') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="addEventArgument(event)">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('openconnector', 'Add Argument') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="viewEventLogs(event)">
						<template #icon>
							<TextBoxOutline :size="20" />
						</template>
						{{ t('openconnector', 'View logs') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="eventStore.setItem(event); navigationStore.setDialog('deleteEvent')">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						{{ t('openconnector', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActions, NcActionButton, NcDateTime } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import Update from 'vue-material-design-icons/Update.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import Play from 'vue-material-design-icons/Play.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'

export default {
	name: 'EventIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		NcActions,
		NcActionButton,
		NcDateTime,
		Update,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Plus,
		Eye,
		Sync,
		Play,
		TextBoxOutline,
	},
	data() {
		return {
			selectedEvents: [],
			loading: false,
			refreshing: false,
			loadError: null,
			pagination: {
				page: 1,
				limit: 20,
			},
		}
	},
	computed: {
		filteredEvents() {
			return eventStore.list || []
		},
		tableColumns() {
			return [
				{ key: 'name', label: t('openconnector', 'Name'), sortable: true },
				{ key: 'status', label: t('openconnector', 'Status') },
				{ key: 'eventClass', label: t('openconnector', 'Event class') },
				{ key: 'interval', label: t('openconnector', 'Interval') },
				{ key: 'arguments', label: t('openconnector', 'Arguments') },
				{ key: 'nextRun', label: t('openconnector', 'Next run'), sortable: true },
				{ key: 'lastRun', label: t('openconnector', 'Last run'), sortable: true },
			]
		},
		paginationData() {
			const page = this.pagination.page || 1
			const limit = this.pagination.limit || 20
			const total = this.filteredEvents.length
			const pages = Math.max(1, Math.ceil(total / limit))
			return { page, pages, total, limit }
		},
		emptyContentName() {
			if (this.loadError) return this.loadError
			if (this.loading) return t('openconnector', 'Loading events...')
			if (!eventStore.list?.length) return t('openconnector', 'No events found')
			return ''
		},
	},
	async mounted() {
		this.loading = true
		try {
			await eventStore.refreshList()
		} catch (e) {
			this.loadError = e.message || t('openconnector', 'Failed to load events')
		} finally {
			this.loading = false
		}
	},
	methods: {
		onAdd() {
			eventStore.setItem(null)
			navigationStore.setModal('editEvent')
		},
		async onRefresh() {
			this.refreshing = true
			this.loadError = null
			try {
				await eventStore.refreshList()
			} catch (e) {
				this.loadError = e.message || t('openconnector', 'Failed to load events')
			} finally {
				this.refreshing = false
			}
		},
		onPageChanged(page) {
			this.pagination.page = page
		},
		onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
		},
		onSelect(ids) {
			this.selectedEvents = ids
		},
		openEvent(event) {
			this.$router.push('/cloud-events/events/' + event.id)
		},
		getArgumentCount(event) {
			const args = event.arguments || {}
			return Object.keys(args).length
		},
		addEventArgument(event) {
			eventStore.setItem(event)
			eventStore.setArgumentKey(null)
			navigationStore.setModal('editEventArgument')
		},
		viewEventLogs(event) {
			eventStore.setItem(event)
			eventStore.refreshLogs({ event_id: event.id })
			this.$router.push('/cloud-events/logs')
		},
	},
}
</script>

<style scoped>
.status-enabled {
	display: inline-flex;
	align-items: center;
	padding: 4px 12px;
	border-radius: 12px;
	font-size: 0.875rem;
	font-weight: 600;
	color: white;
	background: var(--color-success);
}

.status-disabled {
	display: inline-flex;
	align-items: center;
	padding: 4px 12px;
	border-radius: 12px;
	font-size: 0.875rem;
	font-weight: 600;
	color: white;
	background: var(--color-error);
}
</style>

<script setup>
import { eventStore, navigationStore, logStore } from '../../store/store.js'
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<NcAppContent>
		<NcEmptyContent v-if="loading"
			class="detailContainer"
			:name="t('openconnector', 'Loading event...')"
			:description="t('openconnector', 'Fetching event details')">
			<template #icon>
				<NcLoadingIcon />
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="loadError"
			class="detailContainer"
			:name="t('openconnector', 'Error')"
			:description="t('openconnector', 'Failed to load event.')">
			<template #icon>
				<Update />
			</template>
			<template #action>
				<NcButton type="secondary" @click="backToList">
					{{ t('openconnector', 'Back') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="!event"
			class="detailContainer"
			:name="t('openconnector', 'Event not found')">
			<template #icon>
				<Update />
			</template>
			<template #action>
				<NcButton type="secondary" @click="backToList">
					{{ t('openconnector', 'Back') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<div v-else class="detailContainer">
			<div class="detailHeader">
				<NcButton type="tertiary" :aria-label="t('openconnector', 'Back to events')" @click="backToList">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
					{{ t('openconnector', 'Events') }}
				</NcButton>
				<h1 class="h1">
					{{ event?.name }}
				</h1>
				<NcActions :primary="true" :menu-name="t('openconnector', 'Actions')">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton close-after-click @click="navigationStore.setModal('editEvent')">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('openconnector', 'Edit') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="addEventArgument()">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('openconnector', 'Add Argument') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="navigationStore.setModal('testEvent')">
						<template #icon>
							<Update :size="20" />
						</template>
						{{ t('openconnector', 'Test') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="navigationStore.setModal('runEvent')">
						<template #icon>
							<Play :size="20" />
						</template>
						{{ t('openconnector', 'Run') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="refreshEventLogs()">
						<template #icon>
							<Sync :size="20" />
						</template>
						{{ t('openconnector', 'Refresh Logs') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="navigationStore.setDialog('deleteEvent')">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						{{ t('openconnector', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</div>

			<table class="statisticsTable eventStats">
				<thead>
					<tr>
						<th>{{ t('openconnector', 'Property') }}</th>
						<th>{{ t('openconnector', 'Value') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>{{ t('openconnector', 'ID') }}</td>
						<td>{{ event?.id || '-' }}</td>
					</tr>
					<tr v-if="event?.description">
						<td>{{ t('openconnector', 'Description') }}</td>
						<td>{{ event.description }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Status') }}</td>
						<td>
							<span :class="event?.isEnabled ? 'status-enabled' : 'status-disabled'">
								{{ event?.isEnabled ? t('openconnector', 'Enabled') : t('openconnector', 'Disabled') }}
							</span>
						</td>
					</tr>
					<tr v-if="event?.eventClass">
						<td>{{ t('openconnector', 'Event class') }}</td>
						<td class="truncatedText">
							{{ event.eventClass }}
						</td>
					</tr>
					<tr v-if="event?.interval">
						<td>{{ t('openconnector', 'Interval') }}</td>
						<td>{{ event.interval }}</td>
					</tr>
					<tr v-if="event?.executionTime">
						<td>{{ t('openconnector', 'Execution time') }}</td>
						<td>{{ event.executionTime }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Time sensitive') }}</td>
						<td>{{ event?.timeSensitive }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Allow parallel runs') }}</td>
						<td>{{ event?.allowParallelRuns }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Single run') }}</td>
						<td>{{ event?.singleRun }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Next run') }}</td>
						<td>
							<NcDateTime v-if="event?.nextRun" :timestamp="new Date(event.nextRun)" />
							<span v-else>-</span>
						</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Last run') }}</td>
						<td>
							<NcDateTime v-if="event?.lastRun" :timestamp="new Date(event.lastRun)" />
							<span v-else>-</span>
						</td>
					</tr>
				</tbody>
			</table>

			<div class="tabContainer">
				<BTabs content-class="mt-3" justified>
					<BTab :title="t('openconnector', 'Event Arguments')">
						<div v-if="event?.arguments !== null && Object.keys(event?.arguments || {}).length > 0">
							<NcListItem v-for="(value, key, i) in event?.arguments"
								:key="`${key}${i}`"
								:name="key"
								:bold="false"
								:force-display-actions="true"
								:active="eventStore.argumentKey === key"
								@click="setActiveEventArgumentKey(key)">
								<template #icon>
									<SitemapOutline
										:class="eventStore.argumentKey === key && 'selectedZaakIcon'"
										disable-menu
										:size="44" />
								</template>
								<template #subname>
									{{ value }}
								</template>
								<template #actions>
									<NcActionButton close-after-click @click="editEventArgument(key)">
										<template #icon>
											<Pencil :size="20" />
										</template>
										{{ t('openconnector', 'Edit') }}
									</NcActionButton>
									<NcActionButton close-after-click @click="deleteEventArgument(key)">
										<template #icon>
											<Delete :size="20" />
										</template>
										{{ t('openconnector', 'Delete') }}
									</NcActionButton>
								</template>
							</NcListItem>
						</div>
						<NcEmptyContent v-if="event?.arguments === null || !Object.keys(event?.arguments || {}).length"
							:name="t('openconnector', 'No arguments found')">
							<template #icon>
								<SitemapOutline />
							</template>
						</NcEmptyContent>
					</BTab>
					<BTab :title="t('openconnector', 'Logs')">
						<div v-if="eventStore.logs?.length">
							<NcListItem v-for="(log, i) in eventStore.logs"
								:key="log.id + i"
								:class="getLevelColor(log.level)"
								:name="log.message"
								:bold="false"
								:counter-number="log.level"
								:force-display-actions="true"
								:active="logStore.activeLogKey === `eventLog-${log.id}`"
								@click="setActiveEventLog(log.id)">
								<template #icon>
									<TimelineQuestionOutline disable-menu
										:size="44" />
								</template>
								<template #subname>
									{{ new Date(log.created).toLocaleString() }}
								</template>
								<template #actions>
									<NcActionButton close-after-click @click="viewLog(log)">
										<template #icon>
											<EyeOutline :size="20" />
										</template>
										{{ t('openconnector', 'View') }}
									</NcActionButton>
								</template>
							</NcListItem>
						</div>
						<NcEmptyContent v-if="!eventStore.logs?.length"
							:name="t('openconnector', 'No logs found')">
							<template #icon>
								<TimelineQuestionOutline />
							</template>
						</NcEmptyContent>
					</BTab>
				</BTabs>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActions, NcActionButton, NcButton, NcEmptyContent, NcLoadingIcon, NcListItem, NcDateTime } from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import TimelineQuestionOutline from 'vue-material-design-icons/TimelineQuestionOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import Update from 'vue-material-design-icons/Update.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import Play from 'vue-material-design-icons/Play.vue'

export default {
	name: 'EventDetails',
	components: {
		NcAppContent,
		NcActions,
		NcActionButton,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcListItem,
		NcDateTime,
		BTabs,
		BTab,
		ArrowLeft,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		TimelineQuestionOutline,
		Plus,
		Delete,
		SitemapOutline,
		Update,
		Sync,
		EyeOutline,
		Play,
	},
	data() {
		return {
			event: null,
			loading: false,
			loadError: false,
			activeLoadId: null,
		}
	},
	watch: {
		'$route.params.id': {
			immediate: true,
			async handler(newId) {
				await this.loadByRoute(newId)
			},
		},
		'eventStore.item': {
			handler(newItem) {
				if (newItem && String(newItem.id) === String(this.$route.params.id)) {
					this.event = newItem
				}
			},
		},
	},
	mounted() {
		eventStore.refreshLogs()
	},
	methods: {
		async loadByRoute(id) {
			const loadId = Symbol('RaceConditionGuard')
			this.activeLoadId = loadId
			this.loadError = false
			if (!id) {
				this.event = null
				eventStore.setItem(null)
				this.loading = false
				return
			}

			this.loading = true
			try {
				const entity = await eventStore.getOne(String(id))
				if (this.activeLoadId !== loadId) return
				this.event = entity
				eventStore.refreshLogs({ event_id: String(id) })
			} catch (e) {
				if (this.activeLoadId !== loadId) return
				this.loadError = true
				this.event = null
			} finally {
				this.loading = false
			}
		},
		backToList() {
			eventStore.setItem(null)
			this.loadError = false
			this.$router.push('/cloud-events/events')
		},
		deleteEventArgument(key) {
			eventStore.setArgumentKey(key)
			navigationStore.setModal('deleteEventArgument')
		},
		editEventArgument(key) {
			eventStore.setArgumentKey(key)
			navigationStore.setModal('editEventArgument')
		},
		addEventArgument() {
			eventStore.setArgumentKey(null)
			navigationStore.setModal('editEventArgument')
		},
		setActiveEventArgumentKey(eventArgumentKey) {
			if (eventStore.argumentKey === eventArgumentKey) {
				eventStore.setArgumentKey(false)
			} else { eventStore.setArgumentKey(eventArgumentKey) }
		},
		setActiveEventLog(eventLogId) {
			if (logStore.activeLogKey === `eventLog-${eventLogId}`) {
				logStore.setActiveLogKey(null)
			} else {
				logStore.setActiveLogKey(`eventLog-${eventLogId}`)
			}
		},
		viewLog(log) {
			logStore.setViewLogItem(log)
			navigationStore.setModal('viewEventLog')
		},
		refreshEventLogs() {
			eventStore.refreshLogs()
		},
		getLevelColor(level) {
			switch (level) {
			case 'SUCCESS':
				return 'successLevel'
			case 'INFO':
				return 'infoLevel'
			case 'NOTICE':
				return 'noticeLevel'
			case 'WARNING':
				return 'warningLevel'
			case 'ERROR':
				return 'errorLevel'
			case 'CRITICAL':
				return 'criticalLevel'
			case 'ALERT':
				return 'alertLevel'
			case 'EMERGENCY':
				return 'emergencyLevel'
			case 'DEBUG':
				return 'debugLevel'
			default:
				return 'debugLevel'
			}
		},
	},
}
</script>

<style scoped>
.detailContainer {
	padding: 20px;
}

.detailHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 10px;
	margin-bottom: 20px;
}

.detailHeader h1 {
	flex: 1;
	margin: 0;
}

.tabContainer {
	margin-top: 20px;
}

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

.successLevel * .counter-bubble__counter {
	background-color: var(--OC-color-status-background-success);
	color: var(--OC-color-status-success);
}

.errorLevel * .counter-bubble__counter {
	background-color: var(--OC-color-status-background-error);
	color: var(--OC-color-status-error);
}

.noticeLevel * .counter-bubble__counter {
	background-color: var(--OC-color-status-background-notice);
	color: var(--OC-color-status-notice);
}

.warningLevel * .counter-bubble__counter {
	background-color: var(--OC-color-status-background-warning);
	color: var(--OC-color-status-warning);
}

.infoLevel * .counter-bubble__counter {
	background-color: var(--OC-color-status-background-info);
	color: var(--OC-color-status-info);
}

.criticalLevel * .counter-bubble__counter {
	background-color: var(--OC-color-status-background-critical);
	color: var(--OC-color-status-critical);
}

.alertLevel * .counter-bubble__counter {
	background-color: var(--OC-color-status-background-alert);
	color: var(--OC-color-status-alert);
}

.emergencyLevel * .counter-bubble__counter {
	background-color: var(--OC-color-status-background-emergency);
	color: var(--OC-color-status-emergency);
}

.debugLevel * .counter-bubble__counter {
	background-color: var(--OC-color-status-background-debug);
	color: var(--OC-color-status-debug);
}
</style>

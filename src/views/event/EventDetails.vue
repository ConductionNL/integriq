<script setup>
import { eventStore, navigationStore, logStore } from '../../store/store.js'
</script>

<template>
	<div class="detailContainer">
		<div id="app-content">
			<div>
				<div class="detailHeader">
					<h1 class="h1">
						{{ item?.name || '-' }}
					</h1>

					<NcActions :primary="true" menu-name="Actions">
						<template #icon>
							<DotsHorizontal :size="20" />
						</template>
						<NcActionButton close-after-click @click="navigationStore.setModal('editEvent')">
							<template #icon>
								<Pencil :size="20" />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton close-after-click @click="addEventArgument()">
							<template #icon>
								<Plus :size="20" />
							</template>
							Add Argument
						</NcActionButton>
						<NcActionButton close-after-click @click="navigationStore.setModal('testEvent')">
							<template #icon>
								<Update :size="20" />
							</template>
							Test
						</NcActionButton>
						<NcActionButton close-after-click @click="navigationStore.setModal('runEvent')">
							<template #icon>
								<Play :size="20" />
							</template>
							Run
						</NcActionButton>

						<NcActionButton close-after-click @click="refreshEventLogs()">
							<template #icon>
								<Sync :size="20" />
							</template>
							Refresh Logs
						</NcActionButton>
						<NcActionButton close-after-click @click="navigationStore.setDialog('deleteEvent')">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							Delete
						</NcActionButton>
					</NcActions>
				</div>
				<span>{{ item?.description || '-' }}</span>

				<div class="detailGrid">
					<div class="gridContent gridFullWidth">
						<b>id:</b>
						<p>{{ item?.id || '-' }}</p>
					</div>
					<div class="gridContent">
						<b>Status:</b>
						<p>{{ item?.status || '-' }}</p>
					</div>
					<div class="gridContent">
						<b>Enabled:</b>
						<p>{{ item?.isEnabled }}</p>
					</div>
					<div class="gridContent">
						<b>Event Class:</b>
						<p>{{ item?.eventClass }}</p>
					</div>
					<div class="gridContent">
						<b>Interval:</b>
						<p>{{ item?.interval }}</p>
					</div>
					<div class="gridContent">
						<b>Execution Time:</b>
						<p>{{ item?.executionTime }}</p>
					</div>
					<div class="gridContent">
						<b>Time Sensitive:</b>
						<p>{{ item?.timeSensitive }}</p>
					</div>
					<div class="gridContent">
						<b>Allow Parallel Runs:</b>
						<p>{{ item?.allowParallelRuns }}</p>
					</div>
					<div class="gridContent">
						<b>Single Run:</b>
						<p>{{ item?.singleRun }}</p>
					</div>
					<div class="gridContent">
						<b>Next Run:</b>
						<p>
							{{ getValidISOstring(item?.nextRun) ? new Date(item?.nextRun).toLocaleString() : 'N/A' }}
						</p>
					</div>
					<div class="gridContent">
						<b>Last Run:</b>
						<p>
							{{ getValidISOstring(item?.lastRun) ? new Date(item?.lastRun).toLocaleString() : 'N/A' }}
						</p>
					</div>
				</div>

				<div class="tabContainer">
					<BTabs content-class="mt-3" justified>
						<BTab title="Event Arguments">
							<div v-if="item?.arguments !== null && Object.keys(item?.arguments || {}).length > 0">
								<NcListItem v-for="(value, key, i) in item?.arguments"
									:key="`${key}${i}`"
									:name="key"
									:bold="false"
									:force-display-actions="true"
									:active="eventStore.eventArgumentKey === key"
									@click="setActiveEventArgumentKey(key)">
									<template #icon>
										<SitemapOutline
											:class="eventStore.eventArgumentKey === key && 'selectedZaakIcon'"
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
											Edit
										</NcActionButton>
										<NcActionButton close-after-click @click="deleteEventArgument(key)">
											<template #icon>
												<Delete :size="20" />
											</template>
											Delete
										</NcActionButton>
									</template>
								</NcListItem>
							</div>
							<div v-if="item?.arguments === null || !Object.keys(item?.arguments || {}).length" class="tabPanel">
								No arguments found
							</div>
						</BTab>
						<BTab title="Logs">
							<div v-if="eventStore.eventLogs?.length">
								<NcListItem v-for="(log, i) in eventStore.eventLogs"
									:key="log.id + i"
									:class="getLevelColor(log.level)"
									:name="log.message"
									:bold="false"
									:counter-number="log.level"
									:force-display-actions="true"
									:active="logStore.activeLogKey === `eventLog-${log.id}`"
									@click="setActiveEventLog(log.id)">
									>
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
											View
										</NcActionButton>
									</template>
								</NcListItem>
							</div>
							<div v-if="!eventStore.eventLogs?.length" class="tabPanel">
								No logs found
							</div>
						</BTab>
					</BTabs>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcActions, NcActionButton, NcListItem } from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'
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

import getValidISOstring from '../../services/getValidISOstring.js'

export default {
	name: 'EventDetails',
	components: {
		NcActions,
		NcActionButton,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Update,
		BTabs,
		BTab,
		NcListItem,
	},
	props: {
		item: {
			type: Object,
			default: null,
		},
		loading: {
			type: Boolean,
			default: false,
		},
	},
	mounted() {
		eventStore.refreshEventLogs()
	},
	methods: {
		deleteEventArgument(key) {
			eventStore.setEventArgumentKey(key)
			navigationStore.setModal('deleteEventArgument')
		},
		editEventArgument(key) {
			eventStore.setEventArgumentKey(key)
			navigationStore.setModal('editEventArgument')
		},
		addEventArgument() {
			eventStore.setEventArgumentKey(null)
			navigationStore.setModal('editEventArgument')
		},
		setActiveEventArgumentKey(eventArgumentKey) {
			if (eventStore.eventArgumentKey === eventArgumentKey) {
				eventStore.setEventArgumentKey(false)
			} else { eventStore.setEventArgumentKey(eventArgumentKey) }
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
			eventStore.refreshEventLogs()
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

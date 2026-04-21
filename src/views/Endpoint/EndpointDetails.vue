<script setup>
import { endpointStore, navigationStore, ruleStore } from '../../store/store.js'
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<NcAppContent>
		<NcEmptyContent v-if="loading"
			class="detailContainer"
			:name="t('openconnector', 'Loading...')"
			:description="t('openconnector', 'Fetching endpoint details')">
			<template #icon>
				<NcLoadingIcon />
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="loadError"
			class="detailContainer"
			:name="t('openconnector', 'Error')"
			:description="t('openconnector', 'Failed to load endpoint.')">
			<template #icon>
				<Api />
			</template>
			<template #action>
				<NcButton type="secondary" @click="backToList">
					{{ t('openconnector', 'Back') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="!endpoint"
			class="detailContainer"
			:name="t('openconnector', 'Endpoint not found')">
			<template #icon>
				<Api />
			</template>
			<template #action>
				<NcButton type="secondary" @click="backToList">
					{{ t('openconnector', 'Back') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<div v-else class="detailContainer">
			<div class="detailHeader">
				<NcButton type="tertiary" :aria-label="t('openconnector', 'Back to endpoints')" @click="backToList">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
					{{ t('openconnector', 'Endpoints') }}
				</NcButton>
				<h1 class="h1">
					{{ endpoint?.name }}
				</h1>
				<NcActions :primary="true" :menu-name="t('openconnector', 'Actions')">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton close-after-click @click="navigationStore.setModal('editEndpoint')">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('openconnector', 'Edit') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="endpointStore.exportEndpoint(endpointStore.endpointItem.id)">
						<template #icon>
							<FileExportOutline :size="20" />
						</template>
						{{ t('openconnector', 'Export endpoint') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="navigationStore.setDialog('deleteEndpoint')">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						{{ t('openconnector', 'Delete') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="navigationStore.setModal('addEndpointRule')">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('openconnector', 'Add Rule') }}
					</NcActionButton>
				</NcActions>
			</div>

			<table class="statisticsTable endpointStats">
				<thead>
					<tr>
						<th>{{ t('openconnector', 'Property') }}</th>
						<th>{{ t('openconnector', 'Value') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>{{ t('openconnector', 'ID') }}</td>
						<td>{{ endpoint?.id || '-' }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'UUID') }}</td>
						<td>{{ endpoint?.uuid || '-' }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Name') }}</td>
						<td>{{ endpoint?.name || '-' }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Description') }}</td>
						<td>{{ endpoint?.description || '-' }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Version') }}</td>
						<td>{{ endpoint?.version || '-' }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Endpoint') }}</td>
						<td>{{ endpoint?.endpoint || '-' }}</td>
					</tr>
					<tr v-if="endpoint?.endpointArray?.length">
						<td>{{ t('openconnector', 'Endpoint Array') }}</td>
						<td>{{ endpoint.endpointArray.join(', ') }}</td>
					</tr>
					<tr v-if="endpoint?.endpointRegex">
						<td>{{ t('openconnector', 'Endpoint Regex') }}</td>
						<td>{{ endpoint.endpointRegex }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Method') }}</td>
						<td>{{ endpoint?.method || '-' }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Target Type') }}</td>
						<td>{{ endpoint?.targetType || '-' }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Target ID') }}</td>
						<td>{{ endpoint?.targetId || '-' }}</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Created') }}</td>
						<td>
							<NcDateTime v-if="endpoint?.created" :timestamp="new Date(endpoint.created)" />
							<span v-else>-</span>
						</td>
					</tr>
					<tr>
						<td>{{ t('openconnector', 'Updated') }}</td>
						<td>
							<NcDateTime v-if="endpoint?.updated" :timestamp="new Date(endpoint.updated)" />
							<span v-else>-</span>
						</td>
					</tr>
				</tbody>
			</table>

			<div class="tabContainer">
				<BTabs content-class="mt-3" justified>
					<!-- Rules Tab -->
					<BTab :title="t('openconnector', 'Rules')">
						<div class="tabButtonsContainer">
							<NcButton type="primary"
								class="fullWidthButton"
								:aria-label="t('openconnector', 'Add Rule')"
								@click="navigationStore.setModal('addEndpointRule')">
								<template #icon>
									<Plus :size="20" />
								</template>
								{{ t('openconnector', 'Add Rule') }}
							</NcButton>
						</div>
						<div v-if="endpoint?.rules?.length">
							<NcListItem v-for="ruleId in endpoint.rules"
								:key="ruleId"
								:name="getRuleName(ruleId)"
								:bold="false"
								:force-display-actions="true"
								@click="viewRule(ruleId)">
								<template #icon>
									<SitemapOutline disable-menu :size="44" />
								</template>
								<template #subname>
									<span v-if="rulesLoaded">{{ getRuleType(ruleId) }}</span>
									<span v-else>{{ t('openconnector', 'Loading...') }}</span>
								</template>
								<template #actions>
									<NcActionButton close-after-click @click.stop="viewRule(ruleId)">
										<template #icon>
											<EyeOutline :size="20" />
										</template>
										{{ t('openconnector', 'View') }}
									</NcActionButton>
									<NcActionButton close-after-click @click.stop="removeRule(ruleId)">
										<template #icon>
											<LinkOff :size="20" />
										</template>
										{{ t('openconnector', 'Remove') }}
									</NcActionButton>
								</template>
							</NcListItem>
						</div>
						<NcEmptyContent v-else :name="t('openconnector', 'No rules found')">
							<template #icon>
								<SitemapOutline />
							</template>
						</NcEmptyContent>
					</BTab>

					<!-- Logs Tab -->
					<BTab :title="t('openconnector', 'Logs')">
						<NcEmptyContent :name="t('openconnector', 'No logs found')">
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
import { NcAppContent, NcActions, NcActionButton, NcListItem, NcButton, NcEmptyContent, NcLoadingIcon, NcDateTime } from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'
import Api from 'vue-material-design-icons/Api.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import TimelineQuestionOutline from 'vue-material-design-icons/TimelineQuestionOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import LinkOff from 'vue-material-design-icons/LinkOff.vue'
import _ from 'lodash'

import { Endpoint } from '../../entities/index.js'

export default {
	name: 'EndpointDetails',
	components: {
		NcAppContent,
		NcActions,
		NcActionButton,
		NcListItem,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcDateTime,
		BTabs,
		BTab,
		Api,
		ArrowLeft,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		FileExportOutline,
		SitemapOutline,
		TimelineQuestionOutline,
		Plus,
		EyeOutline,
		LinkOff,
	},
	data() {
		return {
			endpoint: null,
			loading: false,
			loadError: false,
			activeLoadId: null,
			rulesList: [],
			rulesLoaded: false,
		}
	},
	watch: {
		'$route.params.id': {
			immediate: true,
			async handler(newId) {
				await this.loadByRoute(newId)
			},
		},
		'endpointStore.endpointItem': {
			handler(newItem) {
				if (newItem && String(newItem.id) === String(this.$route.params.id)) {
					this.endpoint = newItem
				}
			},
		},
	},
	mounted() {
		this.loadRules()
	},
	methods: {
		async loadByRoute(id) {
			const loadId = Symbol('RaceConditionGuard')
			this.activeLoadId = loadId
			this.loadError = false
			if (!id) {
				this.endpoint = null
				endpointStore.setEndpointItem(null)
				this.loading = false
				return
			}

			this.loading = true
			try {
				const { entity, response } = await endpointStore.fetchEndpoint(String(id))
				if (this.activeLoadId !== loadId) return
				if (!response.ok) throw new Error(response.statusText)
				this.endpoint = entity
			} catch (e) {
				if (this.activeLoadId !== loadId) return
				this.loadError = true
				this.endpoint = null
			} finally {
				this.loading = false
			}
		},
		backToList() {
			endpointStore.setEndpointItem(null)
			this.loadError = false
			this.$router.push('/endpoints')
		},
		async loadRules() {
			try {
				await ruleStore.refreshRuleList()
				this.rulesList = ruleStore.ruleList
				this.rulesLoaded = true
			} catch (error) {
				console.error('Failed to load rules:', error)
			}
		},
		getRuleName(ruleId) {
			const rule = this.rulesList.find(rule => String(rule.id) === String(ruleId))
			return rule ? rule.name : t('openconnector', 'Rule {id}', { id: ruleId })
		},
		getRuleType(ruleId) {
			const rule = this.rulesList.find(rule => String(rule.id) === String(ruleId))
			if (!rule) return t('openconnector', 'Unknown type')
			switch (rule.type) {
			case 'error': return t('openconnector', 'Error Handler')
			case 'mapping': return t('openconnector', 'Data Mapping')
			case 'synchronization': return t('openconnector', 'Synchronization')
			case 'javascript': return t('openconnector', 'JavaScript')
			default: return rule.type || t('openconnector', 'Unknown type')
			}
		},
		viewRule(ruleId) {
			const rule = this.rulesList.find(rule => String(rule.id) === String(ruleId))
			if (rule) {
				ruleStore.setRuleItem(rule)
				this.$router.push('/rules/' + rule.id)
			}
		},
		async removeRule(ruleId) {
			try {
				const updatedEndpoint = _.cloneDeep(endpointStore.endpointItem)
				updatedEndpoint.rules = updatedEndpoint.rules.filter(id => String(id) !== String(ruleId))

				const newEndpointItem = new Endpoint({
					...updatedEndpoint,
					endpointArray: Array.isArray(updatedEndpoint.endpointArray)
						? updatedEndpoint.endpointArray
						: updatedEndpoint.endpointArray.split(/ *, */g),
					rules: updatedEndpoint.rules.map(id => String(id)),
				})

				await endpointStore.saveEndpoint(newEndpointItem)
				await this.loadRules()
				this.endpoint = endpointStore.getEndpointItem()
			} catch (error) {
				console.error('Failed to remove rule:', error)
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

.tabButtonsContainer {
	margin-bottom: 10px;
}
</style>

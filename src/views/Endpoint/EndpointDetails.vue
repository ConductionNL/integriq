<script setup>
import { translate as t } from '@nextcloud/l10n'
import { endpointStore, navigationStore, ruleStore } from '../../store/store.js'
</script>

<template>
	<div class="detailContainer">
		<div id="app-content">
			<div>
				<div class="detailHeader">
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
							{{ t('openconnector', 'Add rule') }}
						</NcActionButton>
					</NcActions>
				</div>

				<div class="detailGrid">
					<div class="gridContent">
						<b>{{ t('openconnector', 'ID') }}:</b>
						<p>{{ endpoint?.id || '-' }}</p>
					</div>
					<div class="gridContent">
						<b>{{ t('openconnector', 'UUID') }}:</b>
						<p>{{ endpoint?.uuid || '-' }}</p>
					</div>
					<div class="gridContent" />

					<div class="gridContent">
						<b>{{ t('openconnector', 'Name') }}:</b>
						<p>{{ endpoint?.name || '-' }}</p>
					</div>
					<div class="gridContent">
						<b>{{ t('openconnector', 'Description') }}:</b>
						<p>{{ endpoint?.description || '-' }}</p>
					</div>

					<div class="gridContent">
						<b>{{ t('openconnector', 'Version') }}:</b>
						<p>{{ endpoint?.version || '-' }}</p>
					</div>
					<div class="gridContent">
						<b>{{ t('openconnector', 'Endpoint') }}:</b>
						<p>{{ endpoint?.endpoint || '-' }}</p>
					</div>
					<div class="gridContent">
						<b>{{ t('openconnector', 'Endpoint array') }}:</b>
						<p>{{ endpoint?.endpointArray?.join(', ') || '-' }}</p>
					</div>
					<div class="gridContent">
						<b>{{ t('openconnector', 'Endpoint regex') }}:</b>
						<p>{{ endpoint?.endpointRegex || '-' }}</p>
					</div>
					<div class="gridContent">
						<b>{{ t('openconnector', 'Method') }}:</b>
						<p>{{ endpoint?.method || '-' }}</p>
					</div>
					<div class="gridContent">
						<b>{{ t('openconnector', 'Target type') }}:</b>
						<p>{{ endpoint?.targetType || '-' }}</p>
					</div>
					<div class="gridContent">
						<b>{{ t('openconnector', 'Target ID') }}:</b>
						<p>{{ endpoint?.targetId || '-' }}</p>
					</div>

					<div class="gridContent">
						<b>{{ t('openconnector', 'Created') }}:</b>
						<p>{{ endpoint?.created ? new Date(endpoint.created).toLocaleDateString() : '-' }}</p>
					</div>
					<div class="gridContent">
						<b>{{ t('openconnector', 'Updated') }}:</b>
						<p>{{ endpoint?.updated ? new Date(endpoint.updated).toLocaleDateString() : '-' }}</p>
					</div>
				</div>

				<div class="tabContainer">
					<BTabs content-class="mt-3" justified>
						<!-- Rules Tab -->
						<BTab :title="t('openconnector', 'Rules')">
							<div class="tabButtonsContainer">
								<NcButton type="primary"
									class="fullWidthButton"
									:aria-label="t('openconnector', 'Add rule')"
									@click="navigationStore.setModal('addEndpointRule')">
									<template #icon>
										<Plus :size="20" />
									</template>
									{{ t('openconnector', 'Add rule') }}
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
										<SitemapOutline
											disable-menu
											:size="44" />
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
							<div v-else class="tabPanel">
								{{ t('openconnector', 'No rules found for this endpoint') }}
							</div>
						</BTab>

						<!-- Logs Tab -->
						<BTab :title="t('openconnector', 'Logs')">
							<div class="tabPanel">
								{{ t('openconnector', 'No logs found') }}
							</div>
						</BTab>
					</BTabs>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcActions, NcActionButton, NcListItem, NcButton } from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import LinkOff from 'vue-material-design-icons/LinkOff.vue'
import _ from 'lodash'

import { Endpoint } from '../../entities/index.js'

export default {
	name: 'EndpointDetails',
	components: {
		NcActions,
		NcActionButton,
		NcListItem,
		BTabs,
		BTab,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		FileExportOutline,
		SitemapOutline,
		Plus,
		EyeOutline,
		LinkOff,
		NcButton,
	},
	props: {
		endpoint: {
			type: Object,
			required: false,
			default: null,
		},
	},
	data() {
		return {
			rulesList: [],
			rulesLoaded: false,
		}
	},
	mounted() {
		this.loadRules()
	},
	methods: {

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
			return rule ? rule.name : `Rule ${ruleId}`
		},
		getRuleType(ruleId) {
			const rule = this.rulesList.find(rule => String(rule.id) === String(ruleId))
			if (!rule) return 'Unknown type'

			// Convert type to more readable format
			switch (rule.type) {
			case 'error':
				return 'Error Handler'
			case 'mapping':
				return 'Data Mapping'
			case 'synchronization':
				return 'Synchronization'
			case 'javascript':
				return 'JavaScript'
			default:
				return rule.type || 'Unknown type'
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

				// Remove the rule ID from the rules array
				updatedEndpoint.rules = updatedEndpoint.rules.filter(id => String(id) !== String(ruleId))

				const newEndpointItem = new Endpoint({
					...updatedEndpoint,
					endpointArray: Array.isArray(updatedEndpoint.endpointArray)
						? updatedEndpoint.endpointArray
						: updatedEndpoint.endpointArray.split(/ *, */g),
					rules: updatedEndpoint.rules.map(id => String(id)),
				})

				// Save the updated endpoint
				await endpointStore.saveEndpoint(newEndpointItem)

				// Refresh the rules list
				await this.loadRules()
				this.$emit('endpoint-updated')
			} catch (error) {
				console.error('Failed to remove rule:', error)
			}
		},
	},
}
</script>

<style scoped>
.detailHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}
</style>

<style scoped>
.detailContainer {
	padding: 20px;
}

.detailGrid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 20px;
	margin: 20px 0;
}

.gridContent {
	display: flex;
	gap: 10px;
}

.gridFullWidth {
	grid-column: 1 / -1;
}

.tabContainer {
	margin-top: 20px;
}

.tabPanel {
	text-align: center;
	color: var(--color-text-maxcontrast);
}
</style>

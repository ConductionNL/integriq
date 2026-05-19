<script setup>
import { translate as t } from '@nextcloud/l10n'
import { endpointStore, navigationStore, ruleStore } from '../../store/store.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'viewEndpoint'"
		ref="modalRef"
		:name="endpointStore.endpointItem?.name || t('openconnector', 'Endpoint details')"
		@close="navigationStore.setModal(false)">
		<div class="modal-content">
			<p v-if="endpointStore.endpointItem?.description" class="endpoint-description">
				{{ endpointStore.endpointItem.description }}
			</p>

			<!-- Endpoint Properties -->
			<div class="endpoint-properties">
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
							<td>{{ endpointStore.endpointItem?.id || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'UUID') }}</td>
							<td>{{ endpointStore.endpointItem?.uuid || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Version') }}</td>
							<td>{{ endpointStore.endpointItem?.version || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Endpoint') }}</td>
							<td>{{ endpointStore.endpointItem?.endpoint || '-' }}</td>
						</tr>
						<tr v-if="endpointStore.endpointItem?.endpointArray?.length">
							<td>{{ t('openconnector', 'Endpoint array') }}</td>
							<td>{{ endpointStore.endpointItem.endpointArray.join(', ') || '-' }}</td>
						</tr>
						<tr v-if="endpointStore.endpointItem?.endpointRegex">
							<td>{{ t('openconnector', 'Endpoint regex') }}</td>
							<td>{{ endpointStore.endpointItem.endpointRegex }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Method') }}</td>
							<td>{{ endpointStore.endpointItem?.method || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Target type') }}</td>
							<td>{{ endpointStore.endpointItem?.targetType || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Target ID') }}</td>
							<td>{{ endpointStore.endpointItem?.targetId || '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Created') }}</td>
							<td>{{ endpointStore.endpointItem?.created ? new Date(endpointStore.endpointItem.created).toLocaleDateString() : '-' }}</td>
						</tr>
						<tr>
							<td>{{ t('openconnector', 'Updated') }}</td>
							<td>{{ endpointStore.endpointItem?.updated ? new Date(endpointStore.endpointItem.updated).toLocaleDateString() : '-' }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Tabs -->
			<div class="tabContainer">
				<BTabs content-class="mt-3" justified>
					<BTab title="Rules">
						<div v-if="endpointStore.endpointItem?.rules?.length" class="rules-list">
							<NcListItem v-for="ruleId in endpointStore.endpointItem.rules"
								:key="ruleId"
								:name="getRuleName(ruleId)"
								:bold="false"
								:force-display-actions="true"
								@click="viewRule(ruleId)">
								<template #icon>
									<SitemapOutline :size="44" />
								</template>
								<template #subname>
									<span v-if="rulesLoaded">{{ getRuleType(ruleId) }}</span>
									<span v-else>Loading...</span>
								</template>
								<template #actions>
									<NcActionButton close-after-click @click.stop="viewRule(ruleId)">
										<template #icon>
											<EyeOutline :size="20" />
										</template>
										View
									</NcActionButton>
									<NcActionButton close-after-click @click.stop="removeRule(ruleId)">
										<template #icon>
											<LinkOff :size="20" />
										</template>
										Remove
									</NcActionButton>
								</template>
							</NcListItem>
						</div>
						<div v-if="!endpointStore.endpointItem?.rules?.length" class="tabPanel">
							<NcEmptyContent
								:name="t('openconnector', 'No rules')"
								:description="t('openconnector', 'No rules found for this endpoint')">
								<template #icon>
									<SitemapOutline :size="64" />
								</template>
								<template #action>
									<NcButton @click="addRule">
										{{ t('openconnector', 'Add rule') }}
									</NcButton>
								</template>
							</NcEmptyContent>
						</div>
					</BTab>
				</BTabs>
			</div>

			<!-- Action buttons -->
			<div class="modal-actions">
				<NcButton @click="navigationStore.setModal('editEndpoint')">
					<template #icon>
						<Pencil :size="20" />
					</template>
					Edit
				</NcButton>
				<NcButton @click="addRule()">
					<template #icon>
						<Plus :size="20" />
					</template>
					Add Rule
				</NcButton>
				<NcButton type="error" @click="navigationStore.setDialog('deleteEndpoint')">
					<template #icon>
						<TrashCanOutline :size="20" />
					</template>
					Delete
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton, NcListItem, NcActionButton, NcEmptyContent } from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import LinkOff from 'vue-material-design-icons/LinkOff.vue'
import _ from 'lodash'

import { Endpoint } from '../../entities/index.js'

export default {
	name: 'ViewEndpoint',
	components: {
		NcModal,
		NcButton,
		NcListItem,
		NcActionButton,
		NcEmptyContent,
		BTabs,
		BTab,
		SitemapOutline,
		Pencil,
		TrashCanOutline,
		Plus,
		EyeOutline,
		LinkOff,
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
		/**
		 * Load rules from the store
		 */
		async loadRules() {
			try {
				await ruleStore.refreshRuleList()
				this.rulesList = ruleStore.ruleList
				this.rulesLoaded = true
			} catch (error) {
				console.error('Failed to load rules:', error)
			}
		},
		/**
		 * Get rule name by ID
		 * @param {string|number} ruleId - The rule ID
		 * @return {string} Rule name
		 */
		getRuleName(ruleId) {
			const rule = this.rulesList.find(rule => String(rule.id) === String(ruleId))
			return rule ? rule.name : `Rule ${ruleId}`
		},
		/**
		 * Get rule type by ID
		 * @param {string|number} ruleId - The rule ID
		 * @return {string} Rule type
		 */
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
		/**
		 * View rule details
		 * @param {string|number} ruleId - The rule ID
		 */
		viewRule(ruleId) {
			const rule = this.rulesList.find(rule => String(rule.id) === String(ruleId))
			if (rule) {
				ruleStore.setRuleItem(rule)
				this.$router.push(`/rules/${ruleId}`)
			}
		},
		/**
		 * Remove rule from endpoint
		 * @param {string|number} ruleId - The rule ID to remove
		 */
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
			} catch (error) {
				console.error('Failed to remove rule:', error)
			}
		},
		/**
		 * Add rule to endpoint
		 */
		addRule() {
			navigationStore.setModal('addEndpointRule')
		},
	},
}
</script>

<style scoped>
.modal-content {
	padding: 20px;
	max-width: 800px;
	max-height: 80vh;
	overflow-y: auto;
}

.endpoint-description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 20px;
	font-style: italic;
}

.endpoint-properties {
	margin-bottom: 20px;
}

.selectedIcon {
	color: var(--color-primary);
}
</style>

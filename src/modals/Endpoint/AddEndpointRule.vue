<script setup>
import { endpointStore, navigationStore, ruleStore } from '../../store/store.js'
import { Endpoint } from '../../entities/index.js'
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<NcModal ref="modalRef"
		label-id="addEndpointRule"
		@close="closeModal">
		<div class="modalContent">
			<h2>{{ t('openconnector', 'Add Rule to Endpoint') }}</h2>

			<div v-if="success || error">
				<NcNoteCard v-if="success" type="success">
					<p>{{ t('openconnector', 'Rule successfully added to endpoint') }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="error" type="error">
					<p>{{ error }}</p>
				</NcNoteCard>
			</div>

			<form v-if="!success" @submit.prevent="handleSubmit">
				<NcSelect
					v-bind="ruleOptions"
					v-model="ruleOptions.value"
					:loading="loading"
					:input-label="t('openconnector', 'Select Rule')"
					:multiple="false"
					:clearable="false" />
			</form>

			<div class="modal-actions">
				<NcButton v-if="!success"
					@click="closeModal">
					<template #icon>
						<CancelIcon size="20" />
					</template>
					{{ t('openconnector', 'Cancel') }}
				</NcButton>
				<NcButton v-if="!success"
					:disabled="loading || !ruleOptions.value"
					type="primary"
					@click="addRule">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<ContentSaveOutline v-if="!loading" :size="20" />
					</template>
					{{ t('openconnector', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcModal,
	NcSelect,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'

export default {
	name: 'AddEndpointRule',
	components: {
		NcModal,
		NcButton,
		NcSelect,
		NcLoadingIcon,
		NcNoteCard,
		CancelIcon,
	},
	data() {
		return {
			success: null,
			loading: false,
			error: false,
			ruleOptions: {
				options: [],
				value: null,
			},
			closeTimeoutFunc: null,
		}
	},
	mounted() {
		this.loadAvailableRules()
	},
	methods: {
		async loadAvailableRules() {
			this.loading = true

			try {
				await ruleStore.refreshRuleList()

				// Filter out rules that are already added to the endpoint
				const availableRules = ruleStore.ruleList.filter(rule =>
					!endpointStore.endpointItem.rules?.includes(rule.id),
				)

				this.ruleOptions.options = availableRules.map(rule => ({
					label: rule.name,
					value: rule.id,
				}))
			} catch (error) {
				console.error('Failed to load rules:', error)
				this.error = 'Failed to load available rules'
			} finally {
				this.loading = false
			}
		},

		async addRule() {
			if (!this.ruleOptions.value) return

			this.loading = true

			try {
				// Create a copy of the current endpoint
				const updatedEndpoint = endpointStore.endpointItem.cloneRaw()

				// Initialize rules array if it doesn't exist
				if (!updatedEndpoint.rules) {
					updatedEndpoint.rules = []
				} else if (!Array.isArray(updatedEndpoint.rules)) {
					updatedEndpoint.rules = []
				}

				// Convert existing rules to strings and add the new rule ID as string
				const updatedRules = [
					...updatedEndpoint.rules.map(id => String(id)),
					String(this.ruleOptions.value.value),
				]

				// Prepare endpoint data for saving
				const endpointToSave = new Endpoint({
					...updatedEndpoint,
					endpointArray: Array.isArray(updatedEndpoint.endpointArray)
						? updatedEndpoint.endpointArray
						: updatedEndpoint.endpointArray.split(/ *, */g),
					rules: updatedRules, // Use the array of string IDs
				})

				const { response } = await endpointStore.saveEndpoint(endpointToSave)

				if (response.ok) {
					this.success = true
					this.error = false
					this.closeTimeoutFunc = setTimeout(this.closeModal, 2000)
				} else {
					this.success = false
					this.error = 'Failed to add rule to endpoint'
				}
			} catch (error) {
				console.error('Error adding rule:', error)
				this.success = false
				this.error = error.message || 'An error occurred while adding the rule'
			} finally {
				this.loading = false
			}
		},

		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeTimeoutFunc)
		},
	},
}
</script>

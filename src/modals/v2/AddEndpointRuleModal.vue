<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Add Rule to Endpoint modal — manifest-driven replacement for the legacy
  src/modals/Endpoint/AddEndpointRule.vue (172 LoC). Renders an NcSelect
  of available rules (those not already on the endpoint) and PATCHes the
  endpoint via OR.

  Closes #836.

  Backend contract: the endpoint is an OR object (register=openconnector,
  schema=endpoint). The modal lists rules from
    GET /api/objects/openconnector/rule
  and updates the endpoint via
    PATCH /api/objects/openconnector/endpoint/{id}
  with `{ rules: [...string ids...] }`. Multi-select is supported so the
  user can attach several rules in one trip.
-->
<template>
	<NcModal v-if="open"
		label-id="addEndpointRuleModal"
		size="normal"
		@close="onClose">
		<div class="cn-add-endpoint-rule-modal">
			<h2>{{ t('openconnector', 'Add rule to endpoint') }}</h2>

			<NcNoteCard v-if="endpointName" type="info">
				<p>{{ t('openconnector', 'Endpoint: {name}', { name: endpointName }) }}</p>
			</NcNoteCard>

			<NcNoteCard v-if="success" type="success">
				<p>{{ t('openconnector', 'Rule(s) added to endpoint.') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>

			<form v-if="!success" @submit.prevent="onSave">
				<label for="cn-add-endpoint-rule-select">
					{{ t('openconnector', 'Select rules to add') }}
				</label>
				<NcSelect
					id="cn-add-endpoint-rule-select"
					:aria-label-combobox="t('openconnector', 'Select rules to add')"
					v-model="selectedRules"
					:options="availableRules"
					:loading="loadingRules"
					:multiple="true"
					:clearable="true"
					:placeholder="t('openconnector', 'Pick one or more rules')"
					input-id="cn-add-endpoint-rule-select" />
			</form>

			<div class="cn-add-endpoint-rule-modal__actions">
				<NcButton v-if="!success" @click="onClose">
					<template #icon>
						<CancelIcon :size="20" />
					</template>
					{{ t('openconnector', 'Cancel') }}
				</NcButton>
				<NcButton v-if="!success"
					type="primary"
					:disabled="!canSave || saving"
					@click="onSave">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
						<ContentSaveOutline v-else :size="20" />
					</template>
					{{ t('openconnector', 'Save') }}
				</NcButton>
				<NcButton v-if="success" @click="onClose">
					{{ t('openconnector', 'Close') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcModal,
	NcButton,
	NcSelect,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'AddEndpointRuleModal',

	components: {
		NcModal,
		NcButton,
		NcSelect,
		NcLoadingIcon,
		NcNoteCard,
		CancelIcon,
		ContentSaveOutline,
	},

	props: {
		/** Whether the modal is mounted/visible. */
		open: { type: Boolean, default: false },
		/** Endpoint row from the row-action context. */
		endpoint: { type: Object, default: null },
	},

	data() {
		return {
			selectedRules: [],
			availableRules: [],
			loadingRules: false,
			saving: false,
			success: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		endpointName() {
			return this.endpoint?.name || this.endpoint?.title || ''
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		canSave() {
			return this.selectedRules.length > 0
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		endpointId() {
			return this.endpoint?.id || this.endpoint?.uuid
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		existingRuleIds() {
			const raw = this.endpoint?.rules
			if (!Array.isArray(raw)) return []
			return raw
				.map((r) => (typeof r === 'object' && r !== null ? (r.id || r.uuid) : r))
				.filter((id) => id !== undefined && id !== null)
				.map((id) => String(id))
		},
	},

	watch: {
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		open(value) {
			if (value) {
				this.resetState()
				this.fetchRules()
			}
		},
	},

	methods: {
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		onClose() {
			this.$emit('close')
		},

		/** @spec openspec/specs/rule-editor-ui/spec.md */
		resetState() {
			this.selectedRules = []
			this.saving = false
			this.success = false
			this.error = ''
		},

		/** @spec openspec/specs/rule-editor-ui/spec.md */
		async fetchRules() {
			this.loadingRules = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/objects/openconnector/rule'),
					// `_limit`, not `limit` — an unprefixed param is a PROPERTY
					// FILTER in OpenRegister and silently returns `total: 0`
					// under HTTP 200. See FlowDetailPage.fetchPickerOptions().
					{ params: { _limit: 500 } },
				)
				const data = response.data
				const list = Array.isArray(data?.results)
					? data.results
					: Array.isArray(data)
						? data
						: []
				const existing = new Set(this.existingRuleIds)
				this.availableRules = list
					.filter((rule) => {
						const id = String(rule.id || rule.uuid)
						return !existing.has(id)
					})
					.map((rule) => ({
						id: String(rule.id || rule.uuid),
						label: rule.name || rule.title || rule.id,
					}))
			} catch (err) {
				// eslint-disable-next-line no-console
				console.warn('[AddEndpointRuleModal] rules fetch failed', err)
				this.error = t('openconnector', 'Failed to load available rules.')
			} finally {
				this.loadingRules = false
			}
		},

		/** @spec openspec/specs/rule-editor-ui/spec.md */
		async onSave() {
			if (!this.canSave || !this.endpointId) return
			this.saving = true
			this.error = ''
			try {
				const newIds = this.selectedRules.map((rule) => String(rule.id))
				const merged = Array.from(new Set([
					...this.existingRuleIds,
					...newIds,
				]))
				await axios.patch(
					generateUrl(`/apps/openregister/api/objects/openconnector/endpoint/${this.endpointId}`),
					{ rules: merged },
				)
				this.success = true
				showSuccess(t('openconnector', 'Rule(s) added to endpoint.'))
			} catch (err) {
				const detail = err?.response?.data?.message || err?.message || ''
				this.error = t('openconnector', 'Failed to add rule to endpoint')
					+ (detail ? `: ${detail}` : '')
				showError(this.error)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.cn-add-endpoint-rule-modal {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 20px;
	min-width: 480px;
	max-width: 720px;
}

.cn-add-endpoint-rule-modal__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>

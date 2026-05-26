<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  MappingForm — picks the mapping that EndpointService::processMappingRule
  feeds to mappingService->getMapping(). Stored as a single id string at
  configuration.mapping, NOT under configuration[<type>]. We surface it
  here regardless so the UI is uniform; the parent RuleActionConfig
  knows to write this slot to `configuration.mapping` rather than
  `configuration.mapping.mapping`.
-->
<template>
	<div class="action-form">
		<label class="action-form__label">{{ t('openconnector', 'Mapping') }}</label>
		<NcSelect
			data-testid="action-form-mapping"
			:value="selected"
			:options="options"
			:loading="loading"
			:placeholder="t('openconnector', 'Select a mapping')"
			@input="onPick" />
		<span class="action-form__helper">
			{{ t('openconnector', 'Pick the mapping that will transform the request/response body.') }}
		</span>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import { fetchOpenRegisterCollection } from './shared.js'

export default {
	name: 'MappingForm',
	components: { NcSelect },
	props: {
		// For mapping, the raw value is a bare id string at
		// `configuration.mapping`. Parent supplies it via the `id` prop.
		id: { type: [String, Number, null], default: '' },
	},
	data() { return { options: [], loading: false } },
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3 */
		selected() {
			const idStr = String(this.id || '')
			if (!idStr) return null
			return this.options.find((opt) => opt.id === idStr) ?? { id: idStr, label: idStr }
		},
	},
	/** @spec openspec/changes/retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3 */
	async mounted() {
		this.loading = true
		this.options = await fetchOpenRegisterCollection('mapping')
		this.loading = false
	},
	methods: {
		/** @spec openspec/changes/retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3 */
		onPick(option) {
			this.$emit('update:id', option?.id ? String(option.id) : '')
		},
	},
}
</script>

<style scoped>
.action-form { display: flex; flex-direction: column; gap: 10px; }

.action-form__label { font-weight: bold; }

.action-form__helper { color: var(--color-text-maxcontrast); font-size: 12px; }
</style>

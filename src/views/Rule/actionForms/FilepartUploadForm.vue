<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  FilepartUploadForm — EndpointService::processFilePartUploadRule
  reads `mappingId` (required) plus an optional `mappingOutId`.
-->
<template>
	<div class="action-form">
		<label class="action-form__label">{{
			t('integriq', 'Inbound mapping (required)')
		}}</label>
		<NcSelect
			data-testid="action-form-filepart-upload-mapping"
			:aria-label-combobox="t('integriq', 'Inbound mapping (required)')"
			:modelValue="selectedInbound"
			:options="mappingOptions"
			:loading="loading"
			:placeholder="t('integriq', 'Select a mapping')"
			@update:modelValue="
				(option) => patch('mappingId', option?.id ? String(option.id) : '')
			" />

		<label class="action-form__label">{{
			t('integriq', 'Outbound mapping (optional)')
		}}</label>
		<NcSelect
			:aria-label-combobox="t('integriq', 'Outbound mapping (optional)')"
			:modelValue="selectedOutbound"
			:options="mappingOptions"
			:loading="loading"
			:placeholder="t('integriq', 'Select a mapping')"
			@update:modelValue="
				(option) =>
					patch('mappingOutId', option?.id ? String(option.id) : '')
			" />
		<span class="action-form__helper">
			{{
				t(
					'integriq',
					'Inbound runs before the part is written; outbound runs over the written object before it returns.',
				)
			}}
		</span>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import { fetchOpenRegisterCollection, patchMethod, valueProp } from './shared.js'

export default {
	name: 'FilepartUploadForm',
	components: { NcSelect },
	props: { ...valueProp },
	data() {
		return { mappingOptions: [], loading: false }
	},

	computed: {
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		selectedInbound() {
			const id = String(this.value?.mappingId || '')
			if (!id) return null
			return (
				this.mappingOptions.find((opt) => opt.id === id) ?? { id, label: id }
			)
		},

		/** @spec openspec/specs/rule-editor-ui/spec.md */
		selectedOutbound() {
			const id = String(this.value?.mappingOutId || '')
			if (!id) return null
			return (
				this.mappingOptions.find((opt) => opt.id === id) ?? { id, label: id }
			)
		},
	},

	/** @spec openspec/specs/rule-editor-ui/spec.md */
	async mounted() {
		this.loading = true
		this.mappingOptions = await fetchOpenRegisterCollection('mapping')
		this.loading = false
	},

	methods: { patch: patchMethod() },
}
</script>

<style scoped>
.action-form {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.action-form__label {
	font-weight: bold;
}

.action-form__helper {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}
</style>

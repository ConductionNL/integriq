<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  FilepartsCreateForm — EndpointService::processFilePartRule reads
  `sizeLocation` (required), `schemaId`, `filenameLocation`,
  `filePartLocation`, `mappingId`.
-->
<template>
	<div class="action-form">
		<NcTextField
			:label="t('openconnector', 'Size location (dot path, required)')"
			:model-value="value.sizeLocation || ''"
			placeholder="body.size"
			@update:model-value="(next) => patch('sizeLocation', next)" />
		<label class="action-form__label">{{ t('openconnector', 'Schema') }}</label>
		<NcSelect
			data-testid="action-form-fileparts-schema"
			:aria-label-combobox="t('openconnector', 'Schema')"
			:model-value="selectedSchema"
			:options="schemaOptions"
			:loading="schemasLoading"
			:placeholder="t('openconnector', 'Select a schema')"
			@update:model-value="
				(option) => patch('schemaId', option?.id ? String(option.id) : '')
			" />
		<NcTextField
			:label="t('openconnector', 'Filename location (default: filename)')"
			:model-value="value.filenameLocation || ''"
			placeholder="filename"
			@update:model-value="(next) => patch('filenameLocation', next)" />
		<NcTextField
			:label="t('openconnector', 'Filepart location (default: fileParts)')"
			:model-value="value.filePartLocation || ''"
			placeholder="fileParts"
			@update:model-value="(next) => patch('filePartLocation', next)" />
		<label class="action-form__label">{{
			t('openconnector', 'Mapping (optional)')
		}}</label>
		<NcSelect
			:aria-label-combobox="t('openconnector', 'Mapping (optional)')"
			:model-value="selectedMapping"
			:options="mappingOptions"
			:loading="mappingsLoading"
			:placeholder="t('openconnector', 'Pick a mapping')"
			@update:model-value="
				(option) => patch('mappingId', option?.id ? String(option.id) : '')
			" />
	</div>
</template>

<script>
import { NcSelect, NcTextField } from '@nextcloud/vue'
import { fetchOpenRegisterCollection, patchMethod, valueProp } from './shared.js'

export default {
	name: 'FilepartsCreateForm',
	components: { NcSelect, NcTextField },
	props: { ...valueProp },
	data() {
		return {
			schemaOptions: [],
			schemasLoading: false,
			mappingOptions: [],
			mappingsLoading: false,
		}
	},
	computed: {
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		selectedSchema() {
			const id = String(this.value?.schemaId || '')
			if (!id) return null
			return (
				this.schemaOptions.find((opt) => opt.id === id) ?? { id, label: id }
			)
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		selectedMapping() {
			const id = String(this.value?.mappingId || '')
			if (!id) return null
			return (
				this.mappingOptions.find((opt) => opt.id === id) ?? { id, label: id }
			)
		},
	},
	/** @spec openspec/specs/rule-editor-ui/spec.md */
	async mounted() {
		this.schemasLoading = true
		this.mappingsLoading = true
		const [schemas, mappings] = await Promise.all([
			fetchOpenRegisterCollection('schema', 'openregister'),
			fetchOpenRegisterCollection('mapping'),
		])
		this.schemaOptions = schemas
		this.mappingOptions = mappings
		this.schemasLoading = false
		this.mappingsLoading = false
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
</style>

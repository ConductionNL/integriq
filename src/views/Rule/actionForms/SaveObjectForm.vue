<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  SaveObjectForm — EndpointService::processSaveObjectRule reads
  `register`, `schema`, and an optional `mapping` id (applied before
  save).
-->
<template>
	<div class="action-form">
		<label class="action-form__label">{{
			t('openconnector', 'Register (required)')
		}}</label>
		<NcSelect
			data-testid="action-form-save-register"
			:aria-label-combobox="t('openconnector', 'Register (required)')"
			:model-value="selectedRegister"
			:options="registerOptions"
			:loading="loading"
			:placeholder="t('openconnector', 'Select a register')"
			@update:model-value="
				(option) => patch('register', option?.id ? String(option.id) : '')
			" />

		<label class="action-form__label">{{
			t('openconnector', 'Schema (required)')
		}}</label>
		<NcSelect
			data-testid="action-form-save-schema"
			:aria-label-combobox="t('openconnector', 'Schema (required)')"
			:model-value="selectedSchema"
			:options="schemaOptions"
			:loading="loading"
			:placeholder="t('openconnector', 'Select a schema')"
			@update:model-value="
				(option) => patch('schema', option?.id ? String(option.id) : '')
			" />

		<label class="action-form__label">{{
			t('openconnector', 'Mapping (optional)')
		}}</label>
		<NcSelect
			:aria-label-combobox="t('openconnector', 'Mapping (optional)')"
			:model-value="selectedMapping"
			:options="mappingOptions"
			:loading="loading"
			:placeholder="
				t('openconnector', 'Pick a mapping to transform before save')
			"
			@update:model-value="
				(option) => patch('mapping', option?.id ? String(option.id) : '')
			" />
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import { fetchOpenRegisterCollection, patchMethod, valueProp } from './shared.js'

export default {
	name: 'SaveObjectForm',
	components: { NcSelect },
	props: { ...valueProp },
	data() {
		return {
			registerOptions: [],
			schemaOptions: [],
			mappingOptions: [],
			loading: false,
		}
	},
	computed: {
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		selectedRegister() {
			const id = String(this.value?.register || '')
			if (!id) return null
			return (
				this.registerOptions.find((opt) => opt.id === id) ?? {
					id,
					label: id,
				}
			)
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		selectedSchema() {
			const id = String(this.value?.schema || '')
			if (!id) return null
			return (
				this.schemaOptions.find((opt) => opt.id === id) ?? { id, label: id }
			)
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		selectedMapping() {
			const id = String(this.value?.mapping || '')
			if (!id) return null
			return (
				this.mappingOptions.find((opt) => opt.id === id) ?? { id, label: id }
			)
		},
	},
	/** @spec openspec/specs/rule-editor-ui/spec.md */
	async mounted() {
		this.loading = true
		const [registers, schemas, mappings] = await Promise.all([
			fetchOpenRegisterCollection('register', 'openregister'),
			fetchOpenRegisterCollection('schema', 'openregister'),
			fetchOpenRegisterCollection('mapping'),
		])
		this.registerOptions = registers
		this.schemaOptions = schemas
		this.mappingOptions = mappings
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
</style>

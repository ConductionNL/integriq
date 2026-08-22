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
			t('integriq', 'Register (required)')
		}}</label>
		<NcSelect
			data-testid="action-form-save-register"
			:aria-label-combobox="t('integriq', 'Register (required)')"
			:modelValue="selectedRegister"
			:options="registerOptions"
			:loading="loading"
			:placeholder="t('integriq', 'Select a register')"
			@update:modelValue="
				(option) => patch('register', option?.id ? String(option.id) : '')
			" />

		<label class="action-form__label">{{
			t('integriq', 'Schema (required)')
		}}</label>
		<NcSelect
			data-testid="action-form-save-schema"
			:aria-label-combobox="t('integriq', 'Schema (required)')"
			:modelValue="selectedSchema"
			:options="schemaOptions"
			:loading="loading"
			:placeholder="t('integriq', 'Select a schema')"
			@update:modelValue="
				(option) => patch('schema', option?.id ? String(option.id) : '')
			" />

		<label class="action-form__label">{{
			t('integriq', 'Mapping (optional)')
		}}</label>
		<NcSelect
			:aria-label-combobox="t('integriq', 'Mapping (optional)')"
			:modelValue="selectedMapping"
			:options="mappingOptions"
			:loading="loading"
			:placeholder="
				t('integriq', 'Pick a mapping to transform before save')
			"
			@update:modelValue="
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

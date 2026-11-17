<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  ExtendExternalInputForm — RuleService::extendExternalUrl iterates
  `properties` (each `{ property, schema }`) and validates fetched
  objects against the named schema when `validate` is true.
-->
<template>
	<div class="action-form">
		<NcCheckboxRadioSwitch
			type="switch"
			:modelValue="!!value.validate"
			@update:modelValue="onValidateToggle">
			{{ t('integriq', 'Validate fetched object against schema') }}
		</NcCheckboxRadioSwitch>

		<label class="action-form__label">{{
			t('integriq', 'Properties to fetch')
		}}</label>
		<div v-for="(row, index) in rows" :key="index" class="action-form__row">
			<NcTextField
				:label="t('integriq', 'Property (dot path)')"
				:modelValue="row.property"
				placeholder="body.relations.contact"
				@update:modelValue="onPropertyInput(index, $event)" />
			<NcTextField
				:label="t('integriq', 'Schema ID')"
				:modelValue="row.schema"
				placeholder="contact"
				@update:modelValue="onSchemaInput(index, $event)" />
			<NcButton
				variant="tertiary-no-background"
				:aria-label="t('integriq', 'Remove row')"
				@click="removeRow(index)">
				<template #icon>
					<Close :size="18" />
				</template>
			</NcButton>
		</div>
		<NcButton variant="secondary" @click="addRow">
			<template #icon>
				<Plus :size="18" />
			</template>
			{{ t('integriq', 'Add property') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import propertyRows from '../../../mixins/propertyRows.js'
import { valueProp } from './shared.js'

export default {
	name: 'ExtendExternalInputForm',
	components: { NcButton, NcCheckboxRadioSwitch, NcTextField, Close, Plus },
	mixins: [propertyRows],
	props: { ...valueProp },
	computed: {
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		rows() {
			const props = Array.isArray(this.value?.properties)
				? this.value.properties
				: []
			return props.map((entry) => ({
				property: String(entry?.property || ''),
				schema: String(entry?.schema || ''),
			}))
		},
	},

	methods: {
		/**
		 * An empty row, as the add button appends it.
		 *
		 * @return {object} The row.
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		blankRow() {
			return { property: '', schema: '' }
		},

		/**
		 * Serialise the edited rows back into the action config and emit it.
		 * Rows without a property path are dropped, so a freshly added empty
		 * row never reaches the backend.
		 *
		 * @param {Array<{property: string, schema: string}>} rows The full row
		 *   list after the edit, in display order.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		emitRows(rows) {
			const next = {
				...(this.value || {}),
				properties: rows.filter((row) => row.property),
			}
			this.$emit('update:value', next)
		},

		/**
		 * Persist the "validate fetched object against schema" switch, which
		 * drives RuleService::extendExternalUrl's schema validation.
		 *
		 * @param {boolean} checked New switch state emitted by NcCheckboxRadioSwitch.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onValidateToggle(checked) {
			this.$emit('update:value', {
				...(this.value || {}),
				validate: !!checked,
			})
		},

		/**
		 * Update the schema a row's fetched object is validated against.
		 *
		 * @param {number} index Zero-based position of the edited row in `rows`.
		 * @param {string} value OpenRegister schema ID/slug (e.g. `contact`).
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onSchemaInput(index, value) {
			this.patchRow(index, { schema: value })
		},
	},
}
</script>

<style scoped src="./actionForm.css"></style>

<style scoped>
.action-form__row {
	display: grid;
	grid-template-columns: 1fr 1fr auto;
	gap: 8px;
	align-items: end;
}
@media (max-width: 720px) {
	.action-form__row {
		grid-template-columns: 1fr;
	}
}
</style>

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
			:model-value="!!value.validate"
			@update:model-value="onValidateToggle">
			{{ t('openconnector', 'Validate fetched object against schema') }}
		</NcCheckboxRadioSwitch>

		<label class="action-form__label">{{ t('openconnector', 'Properties to fetch') }}</label>
		<div v-for="(row, index) in rows" :key="index" class="action-form__row">
			<NcTextField
				:label="t('openconnector', 'Property (dot path)')"
				:model-value="row.property"
				placeholder="body.relations.contact"
				@update:model-value="(next) => onPropertyInput(index, next)" />
			<NcTextField
				:label="t('openconnector', 'Schema ID')"
				:model-value="row.schema"
				placeholder="contact"
				@update:model-value="(next) => onSchemaInput(index, next)" />
			<NcButton
				type="tertiary-no-background"
				:aria-label="t('openconnector', 'Remove row')"
				@click="removeRow(index)">
				<template #icon>
					<Close :size="18" />
				</template>
			</NcButton>
		</div>
		<NcButton type="secondary" @click="addRow">
			<template #icon>
				<Plus :size="18" />
			</template>
			{{ t('openconnector', 'Add property') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { valueProp } from './shared.js'

export default {
	name: 'ExtendExternalInputForm',
	components: { NcButton, NcCheckboxRadioSwitch, NcTextField, Close, Plus },
	props: { ...valueProp },
	computed: {
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		rows() {
			const props = Array.isArray(this.value?.properties) ? this.value.properties : []
			return props.map((entry) => ({
				property: String(entry?.property || ''),
				schema: String(entry?.schema || ''),
			}))
		},
	},
	methods: {
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
			const next = { ...(this.value || {}), properties: rows.filter((row) => row.property) }
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
			this.$emit('update:value', { ...(this.value || {}), validate: !!checked })
		},
		/**
		 * Update the dot-path of one row as the user types.
		 *
		 * @param {number} index Zero-based position of the edited row in `rows`.
		 * @param {string} value New dot path on the payload (e.g. `body.relations.contact`).
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onPropertyInput(index, value) {
			const rows = this.rows.slice()
			rows[index] = { ...rows[index], property: value }
			this.emitRows(rows)
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
			const rows = this.rows.slice()
			rows[index] = { ...rows[index], schema: value }
			this.emitRows(rows)
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		addRow() {
			const rows = this.rows.slice()
			rows.push({ property: '', schema: '' })
			this.emitRows(rows)
		},
		/**
		 * Drop one property row from the list.
		 *
		 * @param {number} index Zero-based position of the row to remove.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		removeRow(index) {
			const rows = this.rows.slice()
			rows.splice(index, 1)
			this.emitRows(rows)
		},
	},
}
</script>

<style scoped>
.action-form { display: flex; flex-direction: column; gap: 10px; }

.action-form__label { font-weight: bold; }

.action-form__row { display: grid; grid-template-columns: 1fr 1fr auto; gap: 8px; align-items: end; }
@media (max-width: 720px) { .action-form__row { grid-template-columns: 1fr; } }
</style>

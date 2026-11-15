<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  ExtendInputForm — EndpointService::processExtendInputRule iterates
  `configuration.extend_input.properties`, treating each entry as a
  dotted path on the parameters whose value (a uuid) gets resolved into
  the matching OpenRegister object. Optional `extends` map provides
  per-property extend paths.

  We model this as a list of `{ property, extends }` rows. Properties
  serialise to a flat array of strings (the legacy backend shape) but
  the optional `extends` map is preserved separately for round-tripping.
-->
<template>
	<div class="action-form">
		<label class="action-form__label">{{
			t('integriq', 'Properties to extend')
		}}</label>
		<div v-for="(row, index) in rows" :key="index" class="action-form__row">
			<NcTextField
				:label="t('integriq', 'Property (dot path)')"
				:modelValue="row.property"
				placeholder="a.b"
				@update:modelValue="onPropertyInput(index, $event)" />
			<NcTextField
				:label="t('integriq', 'Extends (comma-separated paths, optional)')"
				:modelValue="(row.extends || []).join(',')"
				placeholder="x.y,z"
				@update:modelValue="onExtendsInput(index, $event)" />
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
import { NcButton, NcTextField } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import propertyRows from '../../../mixins/propertyRows.js'
import { valueProp } from './shared.js'

/**
 * The per-property extend paths of the rows that declare any, keyed by property.
 *
 * @param {Array<{property: string, extends: string[]}>} rows The edited rows.
 * @return {object} The extends map; empty when no row declares paths.
 */
function extendsMapOf(rows) {
	const extendsMap = {}
	for (const row of rows) {
		if (row.property && row.extends && row.extends.length > 0) {
			extendsMap[row.property] = row.extends
		}
	}
	return extendsMap
}

export default {
	name: 'ExtendInputForm',
	components: { NcButton, NcTextField, Close, Plus },
	mixins: [propertyRows],
	props: { ...valueProp },
	computed: {
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		rows() {
			const props = Array.isArray(this.value?.properties)
				? this.value.properties
				: []
			const extendsMap =
				this.value?.extends && typeof this.value.extends === 'object'
					? this.value.extends
					: {}
			return props.map((property) => ({
				property: String(property || ''),
				extends: Array.isArray(extendsMap[property])
					? extendsMap[property]
					: [],
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
			return { property: '', extends: [] }
		},

		/**
		 * Split the edited rows back into the two shapes the backend expects —
		 * a flat `properties` array of dot paths plus an optional `extends` map
		 * keyed by property — and emit the updated config. Blank properties are
		 * dropped, and `extends` is removed entirely when no row declares one.
		 *
		 * @param {Array<{property: string, extends: string[]}>} rows The full
		 *   row list after the edit, in display order.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		emitRows(rows) {
			const properties = rows.map((row) => row.property).filter(Boolean)
			const extendsMap = extendsMapOf(rows)
			const next = { ...(this.value || {}), properties }
			if (Object.keys(extendsMap).length > 0) {
				next.extends = extendsMap
			} else {
				delete next.extends
			}
			this.$emit('update:value', next)
		},

		/**
		 * Update the per-property extend paths of one row, parsing the
		 * comma-separated text field into an array of trimmed, non-empty paths.
		 *
		 * @param {number} index Zero-based position of the edited row in `rows`.
		 * @param {string} value Comma-separated extend paths as typed (e.g. `x.y,z`).
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onExtendsInput(index, value) {
			this.patchRow(index, {
				extends: value
					.split(',')
					.map((entry) => entry.trim())
					.filter(Boolean),
			})
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

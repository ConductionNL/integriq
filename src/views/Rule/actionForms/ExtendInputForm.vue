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
			t('openconnector', 'Properties to extend')
		}}</label>
		<div v-for="(row, index) in rows" :key="index" class="action-form__row">
			<NcTextField
				:label="t('openconnector', 'Property (dot path)')"
				:modelValue="row.property"
				placeholder="a.b"
				@update:modelValue="(next) => onPropertyInput(index, next)" />
			<NcTextField
				:label="
					t('openconnector', 'Extends (comma-separated paths, optional)')
				"
				:modelValue="(row.extends || []).join(',')"
				placeholder="x.y,z"
				@update:modelValue="(next) => onExtendsInput(index, next)" />
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
import { NcButton, NcTextField } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { valueProp } from './shared.js'

export default {
	name: 'ExtendInputForm',
	components: { NcButton, NcTextField, Close, Plus },
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
			const extendsMap = {}
			for (const row of rows) {
				if (row.property && row.extends && row.extends.length > 0) {
					extendsMap[row.property] = row.extends
				}
			}
			const next = { ...(this.value || {}), properties }
			if (Object.keys(extendsMap).length > 0) {
				next.extends = extendsMap
			} else {
				delete next.extends
			}
			this.$emit('update:value', next)
		},

		/**
		 * Update the dot-path of one row as the user types.
		 *
		 * @param {number} index Zero-based position of the edited row in `rows`.
		 * @param {string} value New dotted path on the parameters whose uuid
		 *   value gets resolved into an OpenRegister object (e.g. `a.b`).
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onPropertyInput(index, value) {
			const rows = this.rows.slice()
			rows[index] = { ...rows[index], property: value }
			this.emitRows(rows)
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
			const rows = this.rows.slice()
			rows[index] = {
				...rows[index],
				extends: value
					.split(',')
					.map((entry) => entry.trim())
					.filter(Boolean),
			}
			this.emitRows(rows)
		},

		/** @spec openspec/specs/rule-editor-ui/spec.md */
		addRow() {
			const rows = this.rows.slice()
			rows.push({ property: '', extends: [] })
			this.emitRows(rows)
		},

		/**
		 * Drop one property row, discarding its extend paths with it.
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
.action-form {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.action-form__label {
	font-weight: bold;
}

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

<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V.
  SPDX-License-Identifier: EUPL-1.2

  SyncWriteBackFields: the fields a push from a register/schema source sets on
  the object that started it (REQ-CSD-001), one list for when the target
  accepted it and one for when it refused it. Each row is a field name and a
  value that may hold the placeholders OutcomeWriteBack fills.

  Rows live locally so a row without a field name yet survives while it is
  being typed; the parent receives the stored shape (or null for none) through
  `update:value`.
-->
<template>
	<div class="cn-sync-write-back" data-testid="sync-write-back">
		<h4 class="cn-sync-write-back__title">
			{{ t('integriq', 'Write-back') }}
		</h4>
		<p class="cn-sync-write-back__hint">
			{{
				t(
					'integriq',
					'After each push, these fields are set on the object that started it. A value may hold {placeholders}.',
					{ placeholders: placeholders },
				)
			}}
		</p>
		<fieldset
			v-for="side in sides"
			:key="side.id"
			class="cn-sync-write-back__side"
			:data-testid="'sync-write-back-' + side.id">
			<legend class="cn-sync-write-back__legend">
				{{ side.label }}
			</legend>
			<div
				v-for="(row, index) in rows[side.id]"
				:key="side.id + '-' + index"
				class="cn-sync-write-back__row">
				<NcTextField
					:label="t('integriq', 'Field')"
					:modelValue="String(row.field)"
					:disabled="disabled"
					@update:modelValue="
						(text) => setRow(side.id, index, 'field', text)
					" />
				<NcTextField
					:label="t('integriq', 'Value')"
					:modelValue="display(row.value)"
					:disabled="disabled"
					@update:modelValue="
						(text) => setRow(side.id, index, 'value', text)
					" />
				<NcButton
					variant="tertiary"
					:aria-label="t('integriq', 'Remove field')"
					:title="t('integriq', 'Remove field')"
					:disabled="disabled"
					:data-testid="'sync-write-back-remove-' + side.id + '-' + index"
					@click="removeRow(side.id, index)">
					<template #icon>
						<DeleteOutlineIcon :size="20" />
					</template>
				</NcButton>
			</div>
			<NcButton
				:disabled="disabled"
				:data-testid="'sync-write-back-add-' + side.id"
				@click="addRow(side.id)">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('integriq', 'Add field') }}
			</NcButton>
		</fieldset>
	</div>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'
import DeleteOutlineIcon from 'vue-material-design-icons/DeleteOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import {
	WRITE_BACK_PLACEHOLDERS,
	writeBackFromRows,
	writeBackRows,
} from './writeBack.js'

export default {
	name: 'SyncWriteBackFields',

	components: {
		NcButton,
		NcTextField,
		DeleteOutlineIcon,
		PlusIcon,
	},

	props: {
		/** The synchronization's `writeBack`, or null when it declares none. */
		value: {
			type: Object,
			default: null,
		},

		/** Whether the editor is saving. */
		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:value'],

	data() {
		return {
			rows: writeBackRows(this.value),
		}
	},

	computed: {
		/**
		 * The two lists, labelled with the schema's own titles.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
		 */
		sides() {
			return [
				{ id: 'onSuccess', label: this.t('integriq', 'On success') },
				{ id: 'onFailure', label: this.t('integriq', 'On failure') },
			]
		},

		/**
		 * The placeholders a value may hold, as one readable list.
		 *
		 * @return {string}
		 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
		 */
		placeholders() {
			return WRITE_BACK_PLACEHOLDERS.join(', ')
		},
	},

	watch: {
		/**
		 * Re-seed the rows when the record changes from outside, but not for
		 * the value this component just emitted (that would drop a row whose
		 * field name is still empty).
		 *
		 * @param {?object} value the new write-back
		 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
		 */
		value(value) {
			const current = JSON.stringify(writeBackFromRows(this.rows))
			const incoming = JSON.stringify(writeBackFromRows(writeBackRows(value)))
			if (current !== incoming) {
				this.rows = writeBackRows(value)
			}
		},
	},

	methods: {
		/**
		 * A stored value as input text: strings as they are, anything else as JSON.
		 *
		 * @param {*} value the stored value
		 * @return {string}
		 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
		 */
		display(value) {
			return typeof value === 'string' ? value : JSON.stringify(value ?? '')
		},

		/**
		 * Add an empty row to one side.
		 *
		 * @param {string} side onSuccess or onFailure
		 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
		 */
		addRow(side) {
			this.rows = {
				...this.rows,
				[side]: [...this.rows[side], { field: '', value: '' }],
			}
		},

		/**
		 * Remove one row and tell the parent.
		 *
		 * @param {string} side onSuccess or onFailure
		 * @param {number} index the row's position
		 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
		 */
		removeRow(side, index) {
			this.rows = {
				...this.rows,
				[side]: this.rows[side].filter((row, at) => at !== index),
			}
			this.emitValue()
		},

		/**
		 * Change a row's field name or value and tell the parent.
		 *
		 * @param {string} side onSuccess or onFailure
		 * @param {number} index the row's position
		 * @param {string} key field or value
		 * @param {string} text the typed text
		 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
		 */
		setRow(side, index, key, text) {
			this.rows = {
				...this.rows,
				[side]: this.rows[side].map((row, at) =>
					at === index ? { ...row, [key]: text } : row,
				),
			}
			this.emitValue()
		},

		/**
		 * Emit the stored shape of the current rows.
		 *
		 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
		 */
		emitValue() {
			this.$emit('update:value', writeBackFromRows(this.rows))
		},
	},
}
</script>

<style scoped>
.cn-sync-write-back {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.cn-sync-write-back__title {
	margin: 8px 0 0;
	font-weight: bold;
}

.cn-sync-write-back__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.cn-sync-write-back__side {
	display: flex;
	flex-direction: column;
	gap: 4px;
	border: none;
	margin: 0;
	padding: 0;
}

.cn-sync-write-back__legend {
	font-weight: bold;
	padding: 0;
}

.cn-sync-write-back__row {
	display: grid;
	grid-template-columns: 1fr 1fr auto;
	gap: 4px;
	align-items: end;
}
</style>

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  TablesColumnMapping — column-mapping helper for a `nextcloud-table` target.

  Fetches the selected table's columns via the tables-bridge discovery
  endpoint and renders one input row per column: the column's title + a type
  hint (so the user sees what values a column accepts, matching the coercion
  rules in tables-bridge REQ-003), plus a mapping-output field/expression
  input. The saved mapping is stored in `targetConfig.columnMapping` keyed by
  column TITLE (never numeric id — the backend resolves title→columnId at
  write time, tables-bridge REQ-001).

  Sibling of SyncMappingPicker.vue / SyncReferenceList.vue (REQ-SYNCUI-003)
  but purpose-built for column titles + type hints rather than mapping-object
  selection; it does not replace or alter those components.

  All DOM-free logic lives in ./tablesBridge.js (unit-tested in the repo's
  node-env vitest harness).
-->

<template>
	<div class="tables-column-mapping">
		<header class="tables-column-mapping__header">
			<h4 class="tables-column-mapping__title">
				{{ t('openconnector', 'Column mapping') }}
			</h4>
			<span class="tables-column-mapping__hint">
				{{
					t(
						'openconnector',
						'Map each table column to a source field or Twig expression. Leave blank to skip a column.',
					)
				}}
			</span>
		</header>

		<NcLoadingIcon v-if="loading" :size="24" />

		<span v-else-if="columnsError" class="tables-column-mapping__error">
			{{ columnsError }}
		</span>

		<span v-else-if="columns.length === 0" class="tables-column-mapping__empty">
			{{ t('openconnector', 'This table has no columns to map.') }}
		</span>

		<div
			v-for="column in columns"
			v-else
			:key="column.id"
			class="tables-column-mapping__row">
			<div class="tables-column-mapping__col-meta">
				<span class="tables-column-mapping__col-title">
					{{ column.title }}
					<span
						v-if="column.mandatory"
						class="tables-column-mapping__required"
						:title="t('openconnector', 'Required column')"
						>*</span
					>
				</span>
				<span class="tables-column-mapping__col-type">{{
					typeHint(column)
				}}</span>
			</div>
			<NcTextField
				:label="
					t('openconnector', 'Value for {column}', {
						column: column.title,
					})
				"
				:model-value="mappedValue(column.title)"
				:placeholder="t('openconnector', 'Source field or expression')"
				@update:model-value="
					(value) => onMappingUpdate(column.title, value)
				" />
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

import {
	extractResults,
	mapColumnDescriptors,
	columnTypeHint,
	mappedValueFor,
	upsertColumnMapping,
} from './tablesBridge.js'

export default {
	name: 'TablesColumnMapping',

	components: {
		NcLoadingIcon,
		NcTextField,
	},

	props: {
		/** The Source UUID whose credentials list the columns. */
		sourceId: { type: [String, Number], default: '' },
		/** The selected Tables table id. */
		tableId: { type: [String, Number], default: '' },
		/** The `targetConfig` blob (holds `columnMapping`). */
		config: { type: Object, default: () => ({}) },
	},

	data() {
		return {
			columns: [],
			loading: false,
			columnsError: '',
		}
	},

	watch: {
		tableId: {
			immediate: true,
			/** @spec openspec/specs/sync-editor-ui/spec.md#requirement-column-mapping-helper-prefilled-from-table-schema-req-syncui-007 */
			handler() {
				this.fetchColumns()
			},
		},
		/** @spec openspec/specs/sync-editor-ui/spec.md#requirement-column-mapping-helper-prefilled-from-table-schema-req-syncui-007 */
		sourceId() {
			this.fetchColumns()
		},
	},

	methods: {
		/**
		 * Render the short "what this column accepts" hint shown under a row.
		 *
		 * @param {object} column A normalised column descriptor from
		 *   `mapColumnDescriptors` (`{id,title,type,subtype,mandatory,constraints}`).
		 * @return {string} The hint text, e.g. "number (2 decimals)".
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-column-mapping-helper-prefilled-from-table-schema-req-syncui-007
		 */
		typeHint(column) {
			return columnTypeHint(column)
		},
		/**
		 * Prefill a row's input with the expression already mapped to it.
		 *
		 * @param {string} columnTitle The column TITLE the mapping is keyed by
		 *   (never the numeric id — the backend resolves title→columnId).
		 * @return {string} The mapped field path / expression, '' when unmapped.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-column-mapping-helper-prefilled-from-table-schema-req-syncui-007
		 */
		mappedValue(columnTitle) {
			return mappedValueFor(this.config, columnTitle)
		},
		/**
		 * Write one row's edit back into `targetConfig.columnMapping` and emit
		 * the new config blob (the parent owns the config; this component never
		 * mutates it).
		 *
		 * @param {string} columnTitle The column TITLE being mapped.
		 * @param {string} value The source field path / Twig expression typed by
		 *   the user. Blank removes the entry so the config stays clean.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-column-mapping-helper-prefilled-from-table-schema-req-syncui-007
		 */
		onMappingUpdate(columnTitle, value) {
			this.$emit(
				'update:config',
				upsertColumnMapping(this.config, columnTitle, value),
			)
		},
		/**
		 * Fetch the table's columns via the tables-bridge discovery endpoint.
		 * Soft-fails to an empty list with an inline error so the helper
		 * degrades gracefully (contract.md 4xx/5xx).
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-column-mapping-helper-prefilled-from-table-schema-req-syncui-007
		 */
		async fetchColumns() {
			if (!this.sourceId || !this.tableId) {
				this.columns = []
				return
			}
			this.loading = true
			this.columnsError = ''
			try {
				const response = await axios.get(
					generateUrl(
						'/apps/openconnector/api/synchronizations/tables-bridge/tables/{tableId}/columns',
						{ tableId: this.tableId },
					),
					{ params: { sourceId: this.sourceId } },
				)
				this.columns = mapColumnDescriptors(extractResults(response.data))
			} catch (err) {
				this.columns = []
				this.columnsError =
					err?.response?.data?.error
					|| t('openconnector', 'Could not load columns for this table.')
				// eslint-disable-next-line no-console
				console.warn('[TablesColumnMapping] columns fetch failed', err)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.tables-column-mapping {
	display: flex;
	flex-direction: column;
	gap: 10px;
	margin-top: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.tables-column-mapping__header {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.tables-column-mapping__title {
	margin: 0;
	font-size: 14px;
	font-weight: bold;
}

.tables-column-mapping__hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.tables-column-mapping__row {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.tables-column-mapping__col-meta {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 8px;
}

.tables-column-mapping__col-title {
	font-weight: bold;
	font-size: 13px;
}

.tables-column-mapping__required {
	color: var(--color-error);
}

.tables-column-mapping__col-type {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.tables-column-mapping__error {
	font-size: 12px;
	color: var(--color-error);
}

.tables-column-mapping__empty {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>

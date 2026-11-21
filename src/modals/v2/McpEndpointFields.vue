<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  McpEndpointFields — the fields of an endpoint of targetType `mcp`
  (gateway-mcp-proxy): the tool server's source (`targetId`), a rate limit per
  tool (`mcpToolLimits`, counted per consumer on top of the consumer's own
  limits), the longest an answer is relayed (`mcpMaxStreamSeconds`) and whether
  tool arguments are kept in the call log (`mcpStoreArguments`). Shown by
  EndpointFormFields when the target type is `mcp`.
  @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-a-tool-can-have-its-own-rate-limit-req-mcpx-003
-->
<template>
	<fieldset class="cn-mcp-endpoint-fields" data-testid="mcp-endpoint-fields">
		<legend class="cn-mcp-endpoint-fields__legend">
			{{ t('integriq', 'MCP tool server') }}
		</legend>

		<NcSelect
			:inputLabel="t('integriq', 'Tool server source')"
			:modelValue="sourceOption"
			:options="sourceOptions"
			:loading="sourcesLoading"
			:clearable="false"
			data-testid="mcp-source"
			@update:modelValue="updateField('targetId', $event ? $event.id : '')" />

		<div class="cn-mcp-endpoint-fields__limits">
			<p class="cn-mcp-endpoint-fields__label">
				{{ t('integriq', 'Tool limits') }}
			</p>
			<p v-if="limitRows.length === 0" class="cn-mcp-endpoint-fields__hint">
				{{ t('integriq', 'No tool has its own limit. Every tool shares the consumer\'s limits.') }}
			</p>
			<div
				v-for="(row, index) in limitRows"
				:key="index"
				class="cn-mcp-endpoint-fields__limit"
				data-testid="mcp-tool-limit">
				<NcTextField
					:label="t('integriq', 'Tool')"
					:modelValue="row.tool"
					@update:modelValue="setRow(index, 'tool', $event)" />
				<NcTextField
					:label="t('integriq', 'Calls')"
					type="number"
					:modelValue="String(row.limit)"
					@update:modelValue="setRow(index, 'limit', $event)" />
				<NcTextField
					:label="t('integriq', 'Per seconds')"
					type="number"
					:modelValue="String(row.window)"
					@update:modelValue="setRow(index, 'window', $event)" />
				<NcButton
					variant="tertiary"
					:aria-label="t('integriq', 'Remove the limit of {tool}', { tool: row.tool })"
					@click="removeRow(index)">
					{{ t('integriq', 'Remove') }}
				</NcButton>
			</div>
			<NcButton variant="secondary" data-testid="mcp-add-limit" @click="addRow">
				{{ t('integriq', 'Add a tool limit') }}
			</NcButton>
		</div>

		<NcTextField
			:label="t('integriq', 'Maximum stream duration (seconds)')"
			type="number"
			:modelValue="String(formData.mcpMaxStreamSeconds || 60)"
			data-testid="mcp-max-seconds"
			@update:modelValue="updateField('mcpMaxStreamSeconds', toPositive($event, 60))" />
		<CnFieldHelper
			:text="t('integriq', 'Every open stream holds a web server worker until it ends, so keep this short.')" />

		<NcCheckboxRadioSwitch
			type="switch"
			:modelValue="formData.mcpStoreArguments === true"
			data-testid="mcp-store-arguments"
			@update:modelValue="updateField('mcpStoreArguments', $event === true)">
			{{ t('integriq', 'Store tool arguments in the call log') }}
		</NcCheckboxRadioSwitch>
		<CnFieldHelper
			:text="t('integriq', 'Off by default: tool arguments may carry personal data.')" />
	</fieldset>
</template>

<script>
import { CnFieldHelper } from '@conduction/nextcloud-vue'
import { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import sourceOptions from '../../mixins/sourceOptions.js'
import { limitRowsOf, toolLimitsOf } from './mcpToolLimits.js'

export default {
	name: 'McpEndpointFields',
	components: { CnFieldHelper, NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextField },

	mixins: [sourceOptions],

	props: {
		/** Reactive form data object owned by CnFormDialog. */
		formData: { type: Object, default: () => ({}) },
		/** Mutator forwarded from CnFormDialog. Signature: (key, value). */
		updateField: { type: Function, required: true },
	},

	data() {
		return {
			limitRows: limitRowsOf(this.formData.mcpToolLimits),
		}
	},

	computed: {
		/**
		 * The picked source.
		 *
		 * @return {{id: string, label: string}|null}
		 * @spec openspec/changes/gateway-mcp-proxy/tasks.md#task-4
		 */
		sourceOption() {
			return this.sourceOptions.find((option) => option.id === this.formData.targetId) || null
		},
	},

	async created() {
		await this.fetchSources()
	},

	methods: {
		/**
		 * Add an empty limit row.
		 *
		 * @spec openspec/changes/gateway-mcp-proxy/tasks.md#task-4
		 */
		addRow() {
			this.limitRows = [...this.limitRows, { tool: '', limit: 10, window: 60 }]
			this.write()
		},

		/**
		 * Remove one limit row.
		 *
		 * @param {number} index The row.
		 * @spec openspec/changes/gateway-mcp-proxy/tasks.md#task-4
		 */
		removeRow(index) {
			this.limitRows = this.limitRows.filter((row, position) => position !== index)
			this.write()
		},

		/**
		 * Change one value of a limit row.
		 *
		 * @param {number} index The row.
		 * @param {string} key tool, limit or window.
		 * @param {string} value The typed value.
		 * @spec openspec/changes/gateway-mcp-proxy/tasks.md#task-4
		 */
		setRow(index, key, value) {
			this.limitRows = this.limitRows.map((row, position) => (position === index ? { ...row, [key]: value } : row))
			this.write()
		},

		/**
		 * Write the rows back as the endpoint's `mcpToolLimits`.
		 *
		 * @spec openspec/changes/gateway-mcp-proxy/tasks.md#task-4
		 */
		write() {
			this.updateField('mcpToolLimits', toolLimitsOf(this.limitRows))
		},

		/**
		 * A typed number as a positive integer, else the fallback.
		 *
		 * @param {string} value The typed value.
		 * @param {number} fallback The fallback.
		 * @return {number}
		 * @spec openspec/changes/gateway-mcp-proxy/tasks.md#task-4
		 */
		toPositive(value, fallback) {
			const number = Math.floor(Number(value))
			return Number.isFinite(number) && number > 0 ? number : fallback
		},
	},
}
</script>

<style scoped>
.cn-mcp-endpoint-fields {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.cn-mcp-endpoint-fields__legend,
.cn-mcp-endpoint-fields__label {
	font-weight: bold;
}

.cn-mcp-endpoint-fields__hint {
	color: var(--color-text-maxcontrast);
}

.cn-mcp-endpoint-fields__limit {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: flex-end;
}
</style>

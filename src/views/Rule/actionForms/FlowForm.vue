<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  FlowForm: picks the flow that EndpointService::processFlowRule() runs with
  the request as its input. Stored as a bare id at `configuration.flow`, like
  the mapping id, NOT under a nested `configuration[<type>]` slot, because that
  is the key the runtime reads. The parent (RuleActionConfig, or the rule
  dialog) writes the emitted id there.

  An endpoint is the webhook: its path is the URL a partner calls, and a flow
  rule on it is the whole setup for "start a flow when an outside system calls".
-->
<template>
	<div class="action-form">
		<NcSelect
			data-testid="action-form-flow"
			:inputId="'rule-action-flow-' + uid"
			:inputLabel="t('integriq', 'Flow')"
			:modelValue="selected"
			:options="options"
			:loading="loading"
			:disabled="disabled"
			:placeholder="t('integriq', 'Select a flow')"
			@update:modelValue="onPick" />
		<span class="action-form__helper">
			{{
				t(
					'integriq',
					'The flow gets the request as its input. If the flow run fails, the caller gets an error.',
				)
			}}
		</span>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import { fetchOpenRegisterCollection } from './shared.js'

let flowFormUid = 0

export default {
	name: 'FlowForm',
	components: { NcSelect },
	props: {
		/** The flow id at `configuration.flow`, or empty when none is picked. */
		id: { type: [String, Number, null], default: '' },
		/** Disables the picker, e.g. while the host dialog saves. */
		disabled: { type: Boolean, default: false },
	},

	emits: ['update:id'],

	data() {
		return { uid: ++flowFormUid, options: [], loading: false }
	},

	computed: {
		/** @spec openspec/changes/automation-endpoint-flow-trigger/specs/rule-editor-ui/spec.md#requirement-an-administrator-can-start-a-flow-from-an-endpoint-rule-req-aft-001 */
		selected() {
			const idStr = String(this.id || '')
			if (!idStr) return null
			return (
				this.options.find((opt) => opt.id === idStr) ?? {
					id: idStr,
					label: idStr,
				}
			)
		},
	},

	/** @spec openspec/changes/automation-endpoint-flow-trigger/specs/rule-editor-ui/spec.md#requirement-an-administrator-can-start-a-flow-from-an-endpoint-rule-req-aft-001 */
	async mounted() {
		this.loading = true
		this.options = await fetchOpenRegisterCollection('flow')
		this.loading = false
	},

	methods: {
		/**
		 * Emit the picked flow's id so the host writes it to
		 * `configuration.flow`; clearing the select emits an empty string.
		 *
		 * @param {{id: string, label: string}|null} option The picked flow.
		 *
		 * @spec openspec/changes/automation-endpoint-flow-trigger/specs/rule-editor-ui/spec.md#requirement-an-administrator-can-start-a-flow-from-an-endpoint-rule-req-aft-001
		 */
		onPick(option) {
			this.$emit('update:id', option?.id ? String(option.id) : '')
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

.action-form__helper {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}
</style>

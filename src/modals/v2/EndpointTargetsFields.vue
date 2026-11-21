<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  EndpointTargetsFields — the fields of an endpoint of targetType `api`
  (gateway-upstream-routing): the one source it proxies to (`targetId`), or,
  when it has targets, the sources it routes to by weight (`targets`), and
  whether a consumer stays on the same target (`sticky`). A target's `when`
  rule is kept as stored; the row says it has one. Shown by EndpointFormFields
  when the target type is `api`.
  @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
-->
<template>
	<fieldset class="cn-endpoint-targets-fields" data-testid="endpoint-targets-fields">
		<legend class="cn-endpoint-targets-fields__legend">
			{{ t('integriq', 'Upstream') }}
		</legend>

		<NcSelect
			v-if="rows.length === 0"
			:inputLabel="t('integriq', 'Source')"
			:modelValue="optionFor(formData.targetId)"
			:options="sourceOptions"
			:loading="sourcesLoading"
			data-testid="endpoint-target-source"
			@update:modelValue="updateField('targetId', $event ? $event.id : '')" />

		<div class="cn-endpoint-targets-fields__targets">
			<p class="cn-endpoint-targets-fields__label">
				{{ t('integriq', 'Targets') }}
			</p>
			<p v-if="rows.length === 0" class="cn-endpoint-targets-fields__hint">
				{{ t('integriq', 'No targets: every call goes to the source above. Add targets to split calls over several sources.') }}
			</p>
			<div
				v-for="(row, index) in rows"
				:key="index"
				class="cn-endpoint-targets-fields__target"
				data-testid="endpoint-target">
				<NcSelect
					:inputLabel="t('integriq', 'Target source')"
					:modelValue="optionFor(row.source)"
					:options="sourceOptions"
					:loading="sourcesLoading"
					:clearable="false"
					@update:modelValue="setRow(index, 'source', $event ? $event.id : '')" />
				<NcTextField
					:label="t('integriq', 'Weight')"
					type="number"
					min="0"
					:modelValue="String(row.weight)"
					@update:modelValue="setRow(index, 'weight', $event)" />
				<NcTextField
					:label="t('integriq', 'Group')"
					:modelValue="row.group"
					@update:modelValue="setRow(index, 'group', $event)" />
				<span class="cn-endpoint-targets-fields__share" data-testid="endpoint-target-share">
					{{ t('integriq', '{share}% of its group', { share: shares[index] }) }}
				</span>
				<span v-if="row.when" class="cn-endpoint-targets-fields__hint">
					{{ t('integriq', 'Used when its rule matches') }}
				</span>
				<NcButton
					variant="tertiary"
					:aria-label="t('integriq', 'Remove target {number}', { number: index + 1 })"
					@click="removeRow(index)">
					{{ t('integriq', 'Remove') }}
				</NcButton>
			</div>
			<NcButton variant="secondary" data-testid="endpoint-add-target" @click="addRow">
				{{ t('integriq', 'Add a target') }}
			</NcButton>
			<CnFieldHelper
				:text="t('integriq', 'Calls are split by weight within a group: 95 and 5 send about one call in twenty to the second. A target whose source is down is skipped.')" />
		</div>

		<template v-if="rows.length > 1">
			<NcCheckboxRadioSwitch
				type="switch"
				:modelValue="formData.sticky === true"
				data-testid="endpoint-sticky"
				@update:modelValue="updateField('sticky', $event === true)">
				{{ t('integriq', 'Keep each consumer on the same target') }}
			</NcCheckboxRadioSwitch>
			<CnFieldHelper
				:text="t('integriq', 'With few consumers this unbalances the weights.')" />
		</template>
	</fieldset>
</template>

<script>
import { CnFieldHelper } from '@conduction/nextcloud-vue'
import { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import endpointSourceFields from '../../mixins/endpointSourceFields.js'
import { sharesOf, targetRowsOf, targetsOf } from './endpointTargets.js'

export default {
	name: 'EndpointTargetsFields',
	components: { CnFieldHelper, NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextField },
	mixins: [endpointSourceFields],

	data() {
		return {
			rows: targetRowsOf(this.formData.targets),
		}
	},

	computed: {
		/**
		 * Each row's share of its group's calls.
		 *
		 * @return {number[]}
		 * @spec openspec/changes/gateway-upstream-routing/tasks.md#task-4-the-endpoint-editor-and-the-per-target-split
		 */
		shares() {
			return sharesOf(this.rows)
		},
	},

	methods: {
		/**
		 * Add a target. The first target starts from the endpoint's own source,
		 * so turning one source into a split keeps it.
		 *
		 * @spec openspec/changes/gateway-upstream-routing/tasks.md#task-4-the-endpoint-editor-and-the-per-target-split
		 */
		addRow() {
			const source = this.rows.length === 0 ? (this.formData.targetId || '') : ''
			this.rows = [...this.rows, { source, weight: 1, group: '', when: null }]
			this.write()
		},

		/**
		 * Remove one target.
		 *
		 * @param {number} index The row.
		 * @spec openspec/changes/gateway-upstream-routing/tasks.md#task-4-the-endpoint-editor-and-the-per-target-split
		 */
		removeRow(index) {
			this.rows = this.rows.filter((row, position) => position !== index)
			this.write()
		},

		/**
		 * Change one value of a target.
		 *
		 * @param {number} index The row.
		 * @param {string} key source, weight or group.
		 * @param {string} value The picked or typed value.
		 * @spec openspec/changes/gateway-upstream-routing/tasks.md#task-4-the-endpoint-editor-and-the-per-target-split
		 */
		setRow(index, key, value) {
			this.rows = this.rows.map((row, position) => (position === index ? { ...row, [key]: value } : row))
			this.write()
		},

		/**
		 * Write the rows back as the endpoint's `targets`.
		 *
		 * @spec openspec/changes/gateway-upstream-routing/tasks.md#task-4-the-endpoint-editor-and-the-per-target-split
		 */
		write() {
			this.updateField('targets', targetsOf(this.rows))
		},
	},
}
</script>

<style scoped>
.cn-endpoint-targets-fields {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.cn-endpoint-targets-fields__legend,
.cn-endpoint-targets-fields__label {
	font-weight: bold;
}

.cn-endpoint-targets-fields__hint,
.cn-endpoint-targets-fields__share {
	color: var(--color-text-maxcontrast);
}

.cn-endpoint-targets-fields__target {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: flex-end;
}
</style>

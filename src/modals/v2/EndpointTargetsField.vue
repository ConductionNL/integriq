<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  EndpointTargetsField — the targets of an endpoint that calls several sources
  (gateway-upstream-routing): per target its source, weight, optional group and
  optional JsonLogic rule, and the sticky switch. Rendered by EndpointFormFields;
  writes `targets` and `sticky` through the form's updateField.

  @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
-->
<template>
	<fieldset class="cn-endpoint-targets" data-testid="endpoint-targets">
		<legend class="cn-endpoint-targets__legend">
			{{ t('integriq', 'Targets') }}
		</legend>
		<CnFieldHelper
			:text="
				t(
					'integriq',
					'For an endpoint that calls a source: send its calls to several sources, by weight or by a rule on the request. Without targets the endpoint calls its one target.',
				)
			" />

		<div
			v-for="(row, index) in rows"
			:key="index"
			class="cn-endpoint-targets__row"
			data-testid="endpoint-target-row">
			<NcSelect
				:inputLabel="t('integriq', 'Source')"
				:modelValue="sourceOption(row.source)"
				:options="sourceOptions"
				:loading="sourcesLoading"
				:clearable="false"
				@update:modelValue="change(index, { source: $event ? $event.id : '' })" />
			<NcTextField
				:label="t('integriq', 'Weight')"
				type="number"
				min="0"
				:modelValue="String(row.weight)"
				:helperText="share(index)"
				@update:modelValue="change(index, { weight: $event })" />
			<NcTextField
				:label="t('integriq', 'Group')"
				:modelValue="row.group || ''"
				@update:modelValue="change(index, { group: $event })" />
			<NcTextArea
				:label="t('integriq', 'Rule (JsonLogic, optional)')"
				:modelValue="ruleDrafts[index] ?? ruleText(row.when)"
				:error="ruleErrors[index] === true"
				:helperText="
					ruleErrors[index] === true
						? t('integriq', 'This is not a JSON object; the rule was not changed.')
						: ''
				"
				resize="vertical"
				@update:modelValue="changeRule(index, $event)" />
			<NcButton
				variant="tertiary"
				:aria-label="t('integriq', 'Remove this target')"
				@click="remove(index)">
				{{ t('integriq', 'Remove') }}
			</NcButton>
		</div>

		<NcButton variant="secondary" data-testid="endpoint-target-add" @click="add">
			{{ t('integriq', 'Add target') }}
		</NcButton>

		<NcCheckboxRadioSwitch
			:modelValue="sticky"
			type="switch"
			@update:modelValue="$emit('update:sticky', $event)">
			{{ t('integriq', 'Sticky') }}
		</NcCheckboxRadioSwitch>
		<CnFieldHelper
			:text="
				t(
					'integriq',
					'Keeps each consumer on the same target. With few consumers this unbalances the weights.',
				)
			" />
	</fieldset>
</template>

<script>
import { CnFieldHelper } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import {
	normaliseTargets,
	parseRule,
	ruleText,
	shares,
	updateTarget,
} from './endpointTargets.js'

export default {
	name: 'EndpointTargetsField',

	components: {
		CnFieldHelper,
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** The stored `targets`. */
		targets: { type: Array, default: () => [] },
		/** The stored `sticky`. */
		sticky: { type: Boolean, default: false },
	},

	emits: ['update:targets', 'update:sticky'],

	data() {
		return {
			sourceOptions: [],
			sourcesLoading: false,
			/** Rule text being typed, per row, until it reads as JSON. */
			ruleDrafts: {},
			/** Rows whose typed rule is not a JSON object. */
			ruleErrors: {},
		}
	},

	computed: {
		/**
		 * The targets as rows.
		 *
		 * @return {Array<object>} The rows.
		 */
		rows() {
			return normaliseTargets(this.targets)
		},

		/**
		 * Each row's share of its group.
		 *
		 * @return {Array<string>} The shares.
		 */
		rowShares() {
			return shares(this.rows)
		},
	},

	mounted() {
		this.fetchSources()
	},

	methods: {
		ruleText,

		/**
		 * The helper text of a row's weight: its share of its group.
		 *
		 * @param {number} index The row.
		 * @return {string} E.g. "95% of its group".
		 */
		share(index) {
			const value = this.rowShares[index]
			return value ? this.t('integriq', '{share} of its group', { share: value }) : ''
		},

		/**
		 * The picker option of a stored source; an id not in the list shows as itself.
		 *
		 * @param {string} id The source uuid.
		 * @return {object|null} The option.
		 */
		sourceOption(id) {
			if (!id) {
				return null
			}
			return this.sourceOptions.find((option) => option.id === id) || { id, label: id }
		},

		/**
		 * Change one row.
		 *
		 * @param {number} index The row.
		 * @param {object} patch The fields.
		 * @return {void}
		 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
		 */
		change(index, patch) {
			this.$emit('update:targets', updateTarget(this.rows, index, patch))
		},

		/**
		 * Keep the typed rule; store it once it reads as a JSON object (or is empty).
		 *
		 * @param {number} index The row.
		 * @param {string} text The typed text.
		 * @return {void}
		 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
		 */
		changeRule(index, text) {
			this.ruleDrafts = { ...this.ruleDrafts, [index]: text }
			const rule = parseRule(text)
			this.ruleErrors = { ...this.ruleErrors, [index]: !rule.ok }
			if (rule.ok) {
				this.change(index, { when: rule.value })
			}
		},

		/**
		 * Add a target with weight 1.
		 *
		 * @return {void}
		 */
		add() {
			this.$emit('update:targets', [...this.rows, { source: '', weight: 1 }])
		},

		/**
		 * Remove one target.
		 *
		 * @param {number} index The row.
		 * @return {void}
		 */
		remove(index) {
			this.ruleDrafts = {}
			this.ruleErrors = {}
			this.$emit('update:targets', this.rows.filter((row, at) => at !== index))
		},

		/**
		 * Load the sources to pick from. A failed fetch leaves an empty list.
		 *
		 * @return {Promise<void>}
		 */
		async fetchSources() {
			this.sourcesLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/objects/integriq/source'),
					// `_limit`, not `limit`: an unprefixed param is a property filter.
					{ params: { _limit: 500 } },
				)
				const list = Array.isArray(response?.data?.results) ? response.data.results : []
				this.sourceOptions = list.map((item) => ({
					id: String(item['@self']?.id || item.id || item.uuid),
					label: item.name || item.title || String(item.id),
				}))
			} catch {
				this.sourceOptions = []
			} finally {
				this.sourcesLoading = false
			}
		},
	},
}
</script>

<style scoped>
.cn-endpoint-targets {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 8px 12px;
	margin: 12px 0;
}

.cn-endpoint-targets__legend {
	font-weight: bold;
	padding: 0 4px;
}

.cn-endpoint-targets__row {
	display: grid;
	grid-template-columns: 2fr 1fr 1fr;
	gap: 8px;
	align-items: start;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.cn-endpoint-targets__row > :nth-child(4) {
	grid-column: 1 / 3;
}
</style>

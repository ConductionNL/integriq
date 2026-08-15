<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  ApprovalForm — configures the `approval` rule action (human-in-the-loop
  suspension). EndpointService::processApprovalRule reads
  `configuration.approval.{approverGroup,ttlSeconds,onReject,onTimeout}` at
  suspension time; ApprovalService::suspend() persists them onto the
  approval_request. `approval` is a `before`-timing-only rule
  (approval-workflow REQ-001) — the timing is enforced server-side, the
  helper text below reminds the operator.
-->
<template>
	<div class="action-form">
		<label class="action-form__label" :for="'rule-action-approval-group-' + uid">
			{{ t('openconnector', 'Approver group') }}
		</label>
		<NcSelect
			:input-id="'rule-action-approval-group-' + uid"
			:input-label="t('openconnector', 'Approver group')"
			:model-value="selectedGroup"
			:options="groupOptions"
			:loading="loadingGroups"
			:clearable="false"
			:placeholder="t('openconnector', 'Pick the NC group that may approve')"
			@update:model-value="onGroupPick" />

		<NcTextField
			:label="t('openconnector', 'Time to live (seconds, default 86400)')"
			type="number"
			:model-value="value.ttlSeconds != null ? String(value.ttlSeconds) : ''"
			placeholder="86400"
			@update:model-value="onTtlInput" />

		<label
			class="action-form__label"
			:for="'rule-action-approval-reject-' + uid">
			{{ t('openconnector', 'On reject') }}
		</label>
		<NcSelect
			:input-id="'rule-action-approval-reject-' + uid"
			:input-label="t('openconnector', 'On reject')"
			:model-value="selectedOutcome('onReject')"
			:options="outcomeOptions"
			:clearable="false"
			@update:model-value="(opt) => onOutcomePick('onReject', opt)" />

		<label
			class="action-form__label"
			:for="'rule-action-approval-timeout-' + uid">
			{{ t('openconnector', 'On timeout') }}
		</label>
		<NcSelect
			:input-id="'rule-action-approval-timeout-' + uid"
			:input-label="t('openconnector', 'On timeout')"
			:model-value="selectedOutcome('onTimeout')"
			:options="outcomeOptions"
			:clearable="false"
			@update:model-value="(opt) => onOutcomePick('onTimeout', opt)" />

		<span class="action-form__helper">
			{{
				t(
					'openconnector',
					'Approval rules only run in the "before" phase — they suspend the request until a member of the approver group approves or rejects it. Configure the rule timing to "before".',
				)
			}}
		</span>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { NcSelect, NcTextField } from '@nextcloud/vue'
import { patchMethod, valueProp } from './shared.js'

const OUTCOMES = [
	{ id: 'error', label: 'Return an error to the caller' },
	{ id: 'skip', label: 'Skip (resolve without writing)' },
	{ id: 'dead_letter', label: 'Dead-letter for later review' },
]

let uidCounter = 0

export default {
	name: 'ApprovalForm',
	components: { NcSelect, NcTextField },
	props: { ...valueProp },
	data() {
		return {
			uid: ++uidCounter,
			groups: [],
			loadingGroups: false,
		}
	},
	computed: {
		/** @spec openspec/specs/approval-workflow/spec.md */
		groupOptions() {
			return this.groups.map((gid) => ({ id: gid, label: gid }))
		},
		/** @spec openspec/specs/approval-workflow/spec.md */
		selectedGroup() {
			const gid = this.value.approverGroup
			if (!gid) return null
			return (
				this.groupOptions.find((opt) => opt.id === gid) || {
					id: gid,
					label: gid,
				}
			)
		},
		/** @spec openspec/specs/approval-workflow/spec.md */
		outcomeOptions() {
			return OUTCOMES.map((row) => ({
				id: row.id,
				label: this.t('openconnector', row.label),
			}))
		},
	},
	mounted() {
		this.loadGroups()
	},
	methods: {
		patch: patchMethod(),
		/**
		 * Load NC groups for the approver-group picker.
		 * @spec openspec/specs/approval-workflow/spec.md
		 */
		async loadGroups() {
			this.loadingGroups = true
			try {
				const res = await axios.get(generateOcsUrl('cloud/groups'), {
					params: { format: 'json' },
				})
				this.groups = res.data?.ocs?.data?.groups || []
			} catch (err) {
				// eslint-disable-next-line no-console
				console.warn('[ApprovalForm] failed to load groups', err)
				this.groups = []
			} finally {
				this.loadingGroups = false
			}
		},
		/**
		 * Resolve the currently-selected option for an outcome field.
		 * @param {string} field onReject | onTimeout.
		 * @return {object|null}
		 * @spec openspec/specs/approval-workflow/spec.md
		 */
		selectedOutcome(field) {
			const id = this.value[field] || 'error'
			return this.outcomeOptions.find((opt) => opt.id === id) || null
		},
		/**
		 * Store the NC group whose members may approve the suspended request.
		 *
		 * @param {{id: string, label: string}} option The picked group option;
		 *   `id` is the NC group id. Null/undefined clears the setting.
		 *
		 * @spec openspec/specs/approval-workflow/spec.md
		 */
		onGroupPick(option) {
			this.patch('approverGroup', option?.id || '')
		},
		/**
		 * Store what the engine does when the request is rejected or times out.
		 * Both selects share this handler; the field is bound in the template.
		 *
		 * @param {string} field Which outcome is being set — `onReject` or
		 *   `onTimeout`.
		 * @param {{id: string, label: string}} option The picked outcome option;
		 *   `id` is `error`, `skip` or `dead_letter`. Falls back to `error`.
		 *
		 * @spec openspec/specs/approval-workflow/spec.md
		 */
		onOutcomePick(field, option) {
			this.patch(field, option?.id || 'error')
		},
		/**
		 * Store the suspension time-to-live as a NUMBER (NcTextField hands back
		 * a string even with `type="number"`). Clearing the field removes
		 * `ttlSeconds` altogether so the backend default (86400) applies again;
		 * a non-numeric entry is ignored rather than persisted as NaN.
		 *
		 * @param {string} raw The field's current text; '' or null when cleared.
		 *
		 * @spec openspec/specs/approval-workflow/spec.md
		 */
		onTtlInput(raw) {
			if (raw === '' || raw == null) {
				const next = { ...(this.value || {}) }
				delete next.ttlSeconds
				this.$emit('update:value', next)
				return
			}
			const num = Number(raw)
			if (Number.isNaN(num)) return
			this.patch('ttlSeconds', num)
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

.action-form__helper {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}
</style>

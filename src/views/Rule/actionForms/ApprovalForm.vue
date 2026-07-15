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
			:value="selectedGroup"
			:options="groupOptions"
			:loading="loadingGroups"
			:clearable="false"
			:placeholder="t('openconnector', 'Pick the NC group that may approve')"
			@input="onGroupPick" />

		<NcTextField
			:label="t('openconnector', 'Time to live (seconds, default 86400)')"
			type="number"
			:value="value.ttlSeconds != null ? String(value.ttlSeconds) : ''"
			placeholder="86400"
			@update:value="onTtlInput" />

		<label class="action-form__label" :for="'rule-action-approval-reject-' + uid">
			{{ t('openconnector', 'On reject') }}
		</label>
		<NcSelect
			:input-id="'rule-action-approval-reject-' + uid"
			:input-label="t('openconnector', 'On reject')"
			:value="selectedOutcome('onReject')"
			:options="outcomeOptions"
			:clearable="false"
			@input="(opt) => onOutcomePick('onReject', opt)" />

		<label class="action-form__label" :for="'rule-action-approval-timeout-' + uid">
			{{ t('openconnector', 'On timeout') }}
		</label>
		<NcSelect
			:input-id="'rule-action-approval-timeout-' + uid"
			:input-label="t('openconnector', 'On timeout')"
			:value="selectedOutcome('onTimeout')"
			:options="outcomeOptions"
			:clearable="false"
			@input="(opt) => onOutcomePick('onTimeout', opt)" />

		<span class="action-form__helper">
			{{ t('openconnector', 'Approval rules only run in the "before" phase — they suspend the request until a member of the approver group approves or rejects it. Configure the rule timing to "before".') }}
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
		/** @spec openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-007-pending-approvals-ui */
		groupOptions() {
			return this.groups.map((gid) => ({ id: gid, label: gid }))
		},
		/** @spec openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-007-pending-approvals-ui */
		selectedGroup() {
			const gid = this.value.approverGroup
			if (!gid) return null
			return this.groupOptions.find((opt) => opt.id === gid) || { id: gid, label: gid }
		},
		/** @spec openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-007-pending-approvals-ui */
		outcomeOptions() {
			return OUTCOMES.map((row) => ({ id: row.id, label: this.t('openconnector', row.label) }))
		},
	},
	mounted() {
		this.loadGroups()
	},
	methods: {
		patch: patchMethod(),
		/**
		 * Load NC groups for the approver-group picker.
		 * @spec openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-007-pending-approvals-ui
		 */
		async loadGroups() {
			this.loadingGroups = true
			try {
				const res = await axios.get(generateOcsUrl('cloud/groups'), { params: { format: 'json' } })
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
		 * @spec openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-007-pending-approvals-ui
		 */
		selectedOutcome(field) {
			const id = this.value[field] || 'error'
			return this.outcomeOptions.find((opt) => opt.id === id) || null
		},
		/** @spec openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-007-pending-approvals-ui */
		onGroupPick(option) {
			this.patch('approverGroup', option?.id || '')
		},
		/** @spec openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-007-pending-approvals-ui */
		onOutcomePick(field, option) {
			this.patch(field, option?.id || 'error')
		},
		/** @spec openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-007-pending-approvals-ui */
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
.action-form { display: flex; flex-direction: column; gap: 10px; }

.action-form__label { font-weight: bold; }

.action-form__helper { color: var(--color-text-maxcontrast); font-size: 12px; }
</style>

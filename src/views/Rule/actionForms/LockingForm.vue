<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  LockingForm — EndpointService::processLockingRule reads
  `configuration.locking.action` (lock|unlock) and `duration` (seconds,
  default 3600). Legacy modal used `timeout` (minutes); we expose both
  knobs but the backend currently honours `duration`.
-->
<template>
	<div class="action-form">
		<label class="action-form__label">{{ t('openconnector', 'Lock action') }}</label>
		<NcSelect
			:aria-label-combobox="t('openconnector', 'Lock action')"
			:value="selectedAction"
			:options="actionOptions"
			:clearable="false"
			@input="onActionPick" />
		<NcTextField
			:label="t('openconnector', 'Duration (seconds, default 3600)')"
			type="number"
			:value="value.duration != null ? String(value.duration) : ''"
			placeholder="3600"
			@update:value="onDurationInput" />
		<span class="action-form__helper">
			{{ t('openconnector', 'Lock or unlock the object identified by the request. Duration only applies to lock; unlock ignores it.') }}
		</span>
	</div>
</template>

<script>
import { NcSelect, NcTextField } from '@nextcloud/vue'
import { patchMethod, valueProp } from './shared.js'

const LOCK_ACTIONS = [
	{ id: 'lock', label: 'Lock resource' },
	{ id: 'unlock', label: 'Unlock resource' },
]

export default {
	name: 'LockingForm',
	components: { NcSelect, NcTextField },
	props: { ...valueProp },
	computed: {
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		actionOptions() {
			return LOCK_ACTIONS.map((row) => ({ id: row.id, label: this.t('openconnector', row.label) }))
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		selectedAction() {
			return this.actionOptions.find((opt) => opt.id === this.value.action) || null
		},
	},
	methods: {
		patch: patchMethod(),
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		onActionPick(option) {
			this.patch('action', option?.id || '')
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		onDurationInput(raw) {
			if (raw === '' || raw == null) {
				const next = { ...(this.value || {}) }
				delete next.duration
				this.$emit('update:value', next)
				return
			}
			const num = Number(raw)
			if (Number.isNaN(num)) return
			this.patch('duration', num)
		},
	},
}
</script>

<style scoped>
.action-form { display: flex; flex-direction: column; gap: 10px; }

.action-form__label { font-weight: bold; }

.action-form__helper { color: var(--color-text-maxcontrast); font-size: 12px; }
</style>

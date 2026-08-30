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
		<label class="action-form__label">{{ t('integriq', 'Lock action') }}</label>
		<NcSelect
			:aria-label-combobox="t('integriq', 'Lock action')"
			:modelValue="selectedAction"
			:options="actionOptions"
			:clearable="false"
			@update:modelValue="onActionPick" />
		<NcTextField
			:label="t('integriq', 'Duration (seconds, default 3600)')"
			type="number"
			:modelValue="value.duration != null ? String(value.duration) : ''"
			placeholder="3600"
			@update:modelValue="onDurationInput" />
		<span class="action-form__helper">
			{{
				t(
					'integriq',
					'Lock or unlock the object identified by the request. Duration only applies to lock; unlock ignores it.',
				)
			}}
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
			return LOCK_ACTIONS.map((row) => ({
				id: row.id,
				label: this.t('integriq', row.label),
			}))
		},

		/** @spec openspec/specs/rule-editor-ui/spec.md */
		selectedAction() {
			return (
				this.actionOptions.find((opt) => opt.id === this.value.action)
				|| null
			)
		},
	},

	methods: {
		patch: patchMethod(),
		/**
		 * Store the picked lock action; clearing the select stores an empty
		 * string.
		 *
		 * @param {{id: string, label: string}|null} option The selected entry
		 *   from `actionOptions` (`lock` or `unlock`).
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onActionPick(option) {
			this.patch('action', option?.id || '')
		},

		/**
		 * Coerce the lock-duration field: an empty input removes the key
		 * entirely (falling back to the backend default of 3600 seconds),
		 * non-numeric input is ignored, anything else is stored as a number.
		 *
		 * @param {string|null} raw The raw text emitted by the number NcTextField.
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
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

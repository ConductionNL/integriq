<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  SynchronizationForm — configures a `synchronization` action.

  Fields mirror what EndpointService::processSyncRule reads at
  evaluation time: `synchronization` (id), `retainResponse`,
  `objectIdPath`, `preDelay`, `postDelay`. Picker shells the
  OpenRegister `/objects/openconnector/synchronization` endpoint
  once on mount.
-->
<template>
	<div class="action-form">
		<label class="action-form__label">{{ t('openconnector', 'Synchronization') }}</label>
		<NcSelect
			data-testid="action-form-sync"
			:aria-label-combobox="t('openconnector', 'Synchronization')"
			:model-value="selectedSync"
			:options="syncOptions"
			:loading="loading"
			:placeholder="t('openconnector', 'Select a synchronization')"
			@update:model-value="onSyncPick" />
		<span class="action-form__helper">
			{{ t('openconnector', 'The synchronization that runs when this rule matches. Pick by name; the underlying UUID is what gets stored.') }}
		</span>

		<NcCheckboxRadioSwitch
			type="switch"
			:model-value="!!value.retainResponse"
			@update:model-value="(next) => patch('retainResponse', !!next)">
			{{ t('openconnector', 'Retain original response') }}
		</NcCheckboxRadioSwitch>
		<span class="action-form__helper">
			{{ t('openconnector', 'When enabled, the sync still runs but the rule preserves the original response body instead of replacing it.') }}
		</span>

		<NcTextField
			:label="t('openconnector', 'Object ID path (optional)')"
			:model-value="value.objectIdPath || ''"
			placeholder="body.id"
			@update:model-value="(next) => patch('objectIdPath', next)" />

		<div class="action-form__row">
			<NcTextField
				:label="t('openconnector', 'Pre-delay (seconds)')"
				type="number"
				:model-value="value.preDelay != null ? String(value.preDelay) : ''"
				placeholder="0"
				@update:model-value="(next) => patchNumber('preDelay', next)" />
			<NcTextField
				:label="t('openconnector', 'Post-delay (seconds)')"
				type="number"
				:model-value="value.postDelay != null ? String(value.postDelay) : ''"
				placeholder="0"
				@update:model-value="(next) => patchNumber('postDelay', next)" />
		</div>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import { fetchOpenRegisterCollection, patchMethod, valueProp } from './shared.js'

export default {
	name: 'SynchronizationForm',
	components: { NcCheckboxRadioSwitch, NcSelect, NcTextField },
	props: { ...valueProp },
	data() {
		return { syncOptions: [], loading: false }
	},
	computed: {
		// Both shapes round-trip — legacy `synchronization` was sometimes
		// a bare id, sometimes nested under `synchronization.synchronization`.
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		syncId() {
			return this.value?.synchronization || ''
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		selectedSync() {
			const id = String(this.syncId)
			if (!id) return null
			return this.syncOptions.find((opt) => opt.id === id) ?? { id, label: id }
		},
	},
	/** @spec openspec/specs/rule-editor-ui/spec.md */
	async mounted() {
		this.loading = true
		this.syncOptions = await fetchOpenRegisterCollection('synchronization')
		this.loading = false
	},
	methods: {
		patch: patchMethod(),
		/**
		 * Coerce one of the numeric delay fields: an empty input removes the
		 * key entirely, non-numeric input is ignored, anything else is stored
		 * as a number.
		 * @param {string} key Config field to write — `preDelay` or `postDelay`.
		 * @param {string|null} raw The raw text emitted by the number NcTextField.
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		patchNumber(key, raw) {
			if (raw === '' || raw == null) {
				const next = { ...(this.value || {}) }
				delete next[key]
				this.$emit('update:value', next)
				return
			}
			const num = Number(raw)
			if (Number.isNaN(num)) return
			this.patch(key, num)
		},
		/**
		 * Store the picked synchronization's UUID at `synchronization`;
		 * clearing the select drops the key so no sync is dispatched.
		 * @param {{id: string, label: string, raw: object}|null} option The
		 *   synchronization option selected in the NcSelect.
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onSyncPick(option) {
			const next = { ...(this.value || {}) }
			if (option?.id) {
				next.synchronization = String(option.id)
			} else {
				delete next.synchronization
			}
			this.$emit('update:value', next)
		},
	},
}
</script>

<style scoped>
.action-form { display: flex; flex-direction: column; gap: 10px; }

.action-form__label { font-weight: bold; }

.action-form__helper { color: var(--color-text-maxcontrast); font-size: 12px; }

.action-form__row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 720px) { .action-form__row { grid-template-columns: 1fr; } }
</style>

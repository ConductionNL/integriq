<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  ErrorForm — configures the `error` action's HTTP code, title,
  message, and includeJsonLogicResult toggle. Shape matches what
  EndpointService::processErrorRule reads at evaluation time.
-->
<template>
	<div class="action-form">
		<NcTextField
			:label="t('openconnector', 'HTTP status code')"
			type="number"
			:value="value.code != null ? String(value.code) : ''"
			placeholder="500"
			@update:value="onCodeInput" />
		<NcTextField
			:label="t('openconnector', 'Error title')"
			:value="value.name || ''"
			:placeholder="t('openconnector', 'Something went wrong')"
			@update:value="(next) => patch('name', next)" />
		<label class="action-form__label" :for="'rule-action-error-msg-' + uid">
			{{ t('openconnector', 'Error message') }}
		</label>
		<textarea
			:id="'rule-action-error-msg-' + uid"
			class="action-form__textarea"
			:value="value.message || ''"
			rows="3"
			:placeholder="t('openconnector', 'We encountered an unexpected problem')"
			@input="(event) => patch('message', event.target.value)" />
		<NcCheckboxRadioSwitch
			type="switch"
			:checked="!!value.includeJsonLogicResult"
			@update:checked="(next) => patch('includeJsonLogicResult', !!next)">
			{{ t('openconnector', 'Include JSON Logic results in errors array') }}
		</NcCheckboxRadioSwitch>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'
import { patchMethod, valueProp } from './shared.js'

let uidCounter = 0

export default {
	name: 'ErrorForm',
	components: { NcCheckboxRadioSwitch, NcTextField },
	props: { ...valueProp },
	data() { return { uid: ++uidCounter } },
	methods: {
		patch: patchMethod(),
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		onCodeInput(raw) {
			if (raw === '' || raw == null) {
				const next = { ...(this.value || {}) }
				delete next.code
				this.$emit('update:value', next)
				return
			}
			const num = Number(raw)
			if (Number.isNaN(num)) return
			this.patch('code', num)
		},
	},
}
</script>

<style scoped>
.action-form { display: flex; flex-direction: column; gap: 10px; }

.action-form__label { font-weight: bold; }

.action-form__textarea {
	width: 100%; padding: 8px; font-family: var(--font-face, sans-serif);
	font-size: 14px; background: var(--color-main-background); color: var(--color-main-text);
	border: 1px solid var(--color-border); border-radius: var(--border-radius); resize: vertical;
}
</style>

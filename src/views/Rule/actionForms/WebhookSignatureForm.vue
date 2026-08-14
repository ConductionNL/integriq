<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  WebhookSignatureForm — drives EndpointService::processWebhookSignatureRule.
  Reads/writes the configuration.webhook_signature slot: scheme, header,
  secret and timestamp tolerance. The rule verifies the inbound signature
  over the RAW request body before any other rule runs; order it first.

  @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5
-->
<template>
	<div class="action-form">
		<label class="action-form__label">{{
			t('openconnector', 'Signature scheme')
		}}</label>
		<NcSelect
			:aria-label-combobox="t('openconnector', 'Signature scheme')"
			:modelValue="selectedSchemeOption"
			:options="schemeOptions"
			:clearable="false"
			@update:modelValue="onSchemePick" />
		<NcTextField
			:label="t('openconnector', 'Signature header')"
			:modelValue="value.header || ''"
			placeholder="X-OpenConnector-Signature"
			@update:modelValue="(next) => patch('header', next)" />
		<NcTextField
			:label="t('openconnector', 'Shared secret')"
			type="password"
			:modelValue="value.secret || ''"
			@update:modelValue="(next) => patch('secret', next)" />
		<NcTextField
			v-if="value.scheme !== 'github'"
			:label="t('openconnector', 'Timestamp tolerance (seconds)')"
			:modelValue="String(value.toleranceSeconds || 300)"
			placeholder="300"
			@update:modelValue="
				(next) => patch('toleranceSeconds', Number(next) || 300)
			" />
		<span class="action-form__helper">
			{{
				t(
					'openconnector',
					'The signature is verified over the raw request body before any other rule runs. For scheme "github" the timestamp tolerance is ignored.',
				)
			}}
		</span>
	</div>
</template>

<script>
import { NcSelect, NcTextField } from '@nextcloud/vue'
import { patchMethod, valueProp } from './shared.js'

const SCHEMES = [
	{ id: 'openconnector', label: 'OpenConnector (t=,v1=)' },
	{ id: 'stripe', label: 'Stripe (t=,v1=)' },
	{ id: 'github', label: 'GitHub (sha256=)' },
]

export default {
	name: 'WebhookSignatureForm',
	components: { NcSelect, NcTextField },
	props: { ...valueProp },
	computed: {
		/** @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5 */
		schemeOptions() {
			return SCHEMES.map((row) => ({
				id: row.id,
				label: this.t('openconnector', row.label),
			}))
		},

		/** @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5 */
		selectedSchemeOption() {
			return (
				this.schemeOptions.find(
					(opt) => opt.id === (this.value.scheme || 'openconnector'),
				) || null
			)
		},
	},

	methods: {
		patch: patchMethod(),
		/**
		 * Store the picked signature scheme; clearing the select falls back to
		 * the `openconnector` scheme rather than leaving the rule unsigned.
		 *
		 * @param {{id: string, label: string}|null} option The selected entry
		 *   from `schemeOptions` (openconnector / stripe / github).
		 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5
		 */
		onSchemePick(option) {
			this.patch('scheme', option?.id || 'openconnector')
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

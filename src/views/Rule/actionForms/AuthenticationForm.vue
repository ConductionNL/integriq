<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  AuthenticationForm — drives EndpointService::processAuthenticationRule.
  The `type` discriminator selects between apikey / jwt / jwt-zgw /
  basic / oauth, each of which reads different sub-fields. We expose
  the common header override + a per-type panel for the most-used
  shapes; the rest stay as raw arrays (comma-separated input).
-->
<template>
	<div class="action-form">
		<label class="action-form__label">{{ t('openconnector', 'Authentication type') }}</label>
		<NcSelect
			:value="selectedTypeOption"
			:options="typeOptions"
			:clearable="false"
			@input="onTypePick" />
		<NcTextField
			:label="t('openconnector', 'Header (default: Authorization)')"
			:value="value.header || ''"
			placeholder="Authorization"
			@update:value="(next) => patch('header', next)" />
		<template v-if="value.type === 'apikey'">
			<NcTextField
				:label="t('openconnector', 'API keys (comma-separated)')"
				:value="csv(value.keys)"
				placeholder="key-one,key-two"
				@update:value="(next) => patch('keys', toArray(next))" />
		</template>
		<template v-else-if="value.type === 'basic' || value.type === 'oauth'">
			<NcTextField
				:label="t('openconnector', 'Allowed users (comma-separated UIDs)')"
				:value="csv(value.users)"
				placeholder="alice,bob"
				@update:value="(next) => patch('users', toArray(next))" />
			<NcTextField
				:label="t('openconnector', 'Allowed groups (comma-separated)')"
				:value="csv(value.groups)"
				placeholder="admin,users"
				@update:value="(next) => patch('groups', toArray(next))" />
		</template>
		<span class="action-form__helper">
			{{ t('openconnector', 'For JWT / JWT-ZGW the rule only checks the signed bearer; no extra fields are required.') }}
		</span>
	</div>
</template>

<script>
import { NcSelect, NcTextField } from '@nextcloud/vue'
import { patchMethod, valueProp } from './shared.js'

const AUTH_TYPES = [
	{ id: 'apikey', label: 'API key' },
	{ id: 'jwt', label: 'JWT' },
	{ id: 'jwt-zgw', label: 'JWT (ZGW)' },
	{ id: 'basic', label: 'Basic (users/groups)' },
	{ id: 'oauth', label: 'OAuth (users/groups)' },
]

export default {
	name: 'AuthenticationForm',
	components: { NcSelect, NcTextField },
	props: { ...valueProp },
	computed: {
		typeOptions() {
			return AUTH_TYPES.map((row) => ({ id: row.id, label: this.t('openconnector', row.label) }))
		},
		selectedTypeOption() {
			return this.typeOptions.find((opt) => opt.id === this.value.type) || null
		},
	},
	methods: {
		patch: patchMethod(),
		onTypePick(option) {
			this.patch('type', option?.id || '')
		},
		csv(value) {
			return Array.isArray(value) ? value.join(',') : (value || '')
		},
		toArray(text) {
			return (text || '').split(',').map((entry) => entry.trim()).filter(Boolean)
		},
	},
}
</script>

<style scoped>
.action-form { display: flex; flex-direction: column; gap: 10px; }
.action-form__label { font-weight: bold; }
.action-form__helper { color: var(--color-text-maxcontrast); font-size: 12px; }
</style>

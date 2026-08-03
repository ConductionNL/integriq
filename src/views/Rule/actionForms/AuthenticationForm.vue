<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  AuthenticationForm — drives EndpointService::processAuthenticationRule.
  The `type` discriminator selects between apikey / jwt / jwt-zgw /
  basic / oauth / nc-session, each of which reads different sub-fields.
  We expose the common header override + a per-type panel for the
  most-used shapes; the rest stay as raw arrays (comma-separated input).
  `nc-session` reads no header at all — it authorises the current
  Nextcloud session user (ocon#1068) — so the header override is hidden
  for it and it shares the users/groups allow-list panel.
-->
<template>
	<div class="action-form">
		<label class="action-form__label">{{ t('openconnector', 'Authentication type') }}</label>
		<NcSelect
			:aria-label-combobox="t('openconnector', 'Authentication type')"
			:model-value="selectedTypeOption"
			:options="typeOptions"
			:clearable="false"
			@update:model-value="onTypePick" />
		<NcTextField
			v-if="value.type !== 'nc-session'"
			:label="t('openconnector', 'Header (default: Authorization)')"
			:model-value="value.header || ''"
			placeholder="Authorization"
			@update:model-value="(next) => patch('header', next)" />
		<template v-if="value.type === 'apikey'">
			<NcTextField
				:label="t('openconnector', 'API keys (comma-separated)')"
				:model-value="csv(value.keys)"
				placeholder="key-one,key-two"
				@update:model-value="(next) => patch('keys', toArray(next))" />
		</template>
		<template v-else-if="usesAllowLists">
			<NcTextField
				:label="t('openconnector', 'Allowed users (comma-separated UIDs)')"
				:model-value="csv(value.users)"
				placeholder="alice,bob"
				@update:model-value="(next) => patch('users', toArray(next))" />
			<NcTextField
				:label="t('openconnector', 'Allowed groups (comma-separated)')"
				:model-value="csv(value.groups)"
				placeholder="admin,users"
				@update:model-value="(next) => patch('groups', toArray(next))" />
		</template>
		<span v-if="value.type === 'nc-session'" class="action-form__helper">
			{{ t('openconnector', 'Nextcloud session authorises the logged-in user of the calling browser. The request must carry a valid CSRF request token, so this type is for calls made from inside a Nextcloud page — not for server-to-server clients.') }}
		</span>
		<span v-else class="action-form__helper">
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
	{ id: 'nc-session', label: 'Nextcloud session (users/groups)' },
]

const ALLOW_LIST_TYPES = ['basic', 'oauth', 'nc-session']

export default {
	name: 'AuthenticationForm',
	components: { NcSelect, NcTextField },
	props: { ...valueProp },
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3 */
		typeOptions() {
			return AUTH_TYPES.map((row) => ({ id: row.id, label: this.t('openconnector', row.label) }))
		},
		/** @spec openspec/changes/retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3 */
		selectedTypeOption() {
			return this.typeOptions.find((opt) => opt.id === this.value.type) || null
		},
		/** @spec openspec/changes/retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3 */
		usesAllowLists() {
			return ALLOW_LIST_TYPES.includes(this.value.type)
		},
	},
	methods: {
		patch: patchMethod(),
		/**
		 * Write the picked authentication type discriminator into the config
		 * slot; clearing the select stores an empty string.
		 * @param {{id: string, label: string}|null} option The selected entry
		 *   from `typeOptions` (apikey / jwt / jwt-zgw / basic / oauth / nc-session).
		 * @spec openspec/changes/retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3
		 */
		onTypePick(option) {
			this.patch('type', option?.id || '')
		},
		/**
		 * Render a stored allow-list (keys / users / groups) as the
		 * comma-separated text the NcTextField displays.
		 * @param {Array<string>|string|undefined} value The stored field, either
		 *   an array of entries or an already-flat string.
		 * @return {string} Comma-separated entries, or '' when unset.
		 * @spec openspec/changes/retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3
		 */
		csv(value) {
			return Array.isArray(value) ? value.join(',') : (value || '')
		},
		/**
		 * Parse comma-separated user input back into the array shape the
		 * backend expects, trimming entries and dropping empty ones.
		 * @param {string} text Raw comma-separated text typed into the field.
		 * @return {Array<string>} The cleaned list of entries.
		 * @spec openspec/changes/retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3
		 */
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

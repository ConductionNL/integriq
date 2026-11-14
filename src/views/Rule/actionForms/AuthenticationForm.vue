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
		<label class="action-form__label">{{
			t('integriq', 'Authentication type')
		}}</label>
		<NcSelect
			:aria-label-combobox="t('integriq', 'Authentication type')"
			:modelValue="selectedTypeOption"
			:options="typeOptions"
			:clearable="false"
			@update:modelValue="onTypePick" />
		<NcTextField
			v-if="readsHeader"
			:label="t('integriq', 'Header (default: Authorization)')"
			:modelValue="value.header || ''"
			placeholder="Authorization"
			@update:modelValue="(next) => patch('header', next)" />
		<template v-if="value.type === 'apikey'">
			<NcTextField
				:label="t('integriq', 'API keys (comma-separated)')"
				:modelValue="csv(value.keys)"
				placeholder="key-one,key-two"
				@update:modelValue="(next) => patch('keys', toArray(next))" />
		</template>
		<template v-else-if="usesAllowLists">
			<NcTextField
				:label="t('integriq', 'Allowed users (comma-separated UIDs)')"
				:modelValue="csv(value.users)"
				placeholder="alice,bob"
				@update:modelValue="(next) => patch('users', toArray(next))" />
			<NcTextField
				:label="t('integriq', 'Allowed groups (comma-separated)')"
				:modelValue="csv(value.groups)"
				placeholder="admin,users"
				@update:modelValue="(next) => patch('groups', toArray(next))" />
		</template>
		<template v-else-if="value.type === 'mtls'">
			<NcTextField
				:label="t('integriq', 'Allowed consumers (comma-separated ids, empty for any)')"
				:modelValue="csv(value.consumers)"
				data-testid="auth-mtls-consumers"
				@update:modelValue="(next) => patch('consumers', toArray(next))" />
		</template>
		<template v-else-if="value.type === 'anyOf'">
			<div
				v-for="(method, index) in methods"
				:key="index"
				class="action-form__nested"
				:data-testid="`auth-anyof-method-${index}`">
				<AuthenticationForm
					nested
					:value="method"
					@update:value="(next) => setMethod(index, next)" />
				<NcButton
					variant="tertiary"
					:data-testid="`auth-anyof-remove-${index}`"
					@click="removeMethod(index)">
					{{ t('integriq', 'Remove method') }}
				</NcButton>
			</div>
			<NcButton data-testid="auth-anyof-add" @click="addMethod">
				{{ t('integriq', 'Add method') }}
			</NcButton>
		</template>
		<span v-if="value.type === 'nc-session'" class="action-form__helper">
			{{
				t(
					'integriq',
					'Nextcloud session authorises the logged-in user of the calling browser. The request must carry a valid CSRF request token, so this type is for calls made from inside a Nextcloud page — not for server-to-server clients.',
				)
			}}
		</span>
		<span v-else-if="value.type === 'mtls'" class="action-form__helper">
			{{
				t(
					'integriq',
					'The caller must present a client certificate the web server verified and that is pinned on a consumer. Leave the list empty to accept any consumer with a pinned certificate.',
				)
			}}
		</span>
		<span v-else-if="value.type === 'anyOf'" class="action-form__helper">
			{{
				t(
					'integriq',
					'A call passes when one of these methods passes. With none, the refusal lists why each one failed.',
				)
			}}
		</span>
		<span v-else class="action-form__helper">
			{{
				t(
					'integriq',
					'For JWT / JWT-ZGW the rule only checks the signed bearer; no extra fields are required.',
				)
			}}
		</span>
	</div>
</template>

<script>
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import { patchMethod, valueProp } from './shared.js'

const AUTH_TYPES = [
	{ id: 'apikey', label: 'API key' },
	{ id: 'jwt', label: 'JWT' },
	{ id: 'jwt-zgw', label: 'JWT (ZGW)' },
	{ id: 'basic', label: 'Basic (users/groups)' },
	{ id: 'oauth', label: 'OAuth (users/groups)' },
	{ id: 'nc-session', label: 'Nextcloud session (users/groups)' },
	{ id: 'mtls', label: 'Client certificate (mTLS)' },
]

/** Offered at the top level only: EndpointService refuses an anyOf inside an anyOf. */
const ANY_OF = { id: 'anyOf', label: 'Any one of several methods' }

/** The types that read no header: the session, a client certificate, and a list of methods. */
const NO_HEADER_TYPES = ['nc-session', 'mtls', 'anyOf']

const ALLOW_LIST_TYPES = ['basic', 'oauth', 'nc-session']

export default {
	name: 'AuthenticationForm',
	components: { NcButton, NcSelect, NcTextField },
	props: {
		...valueProp,
		/** A method inside an anyOf list, which cannot itself be an anyOf. */
		nested: { type: Boolean, default: false },
	},

	emits: ['update:value'],
	computed: {
		/** @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3 */
		typeOptions() {
			return (this.nested ? AUTH_TYPES : [...AUTH_TYPES, ANY_OF]).map((row) => ({
				id: row.id,
				label: this.t('integriq', row.label),
			}))
		},

		/** @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3 */
		selectedTypeOption() {
			return this.typeOptions.find((opt) => opt.id === this.value.type) || null
		},

		/** @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3 */
		usesAllowLists() {
			return ALLOW_LIST_TYPES.includes(this.value.type)
		},

		/**
		 * Whether the picked type reads the header field.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005
		 */
		readsHeader() {
			return !NO_HEADER_TYPES.includes(this.value.type)
		},

		/**
		 * The methods of an anyOf rule.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005
		 */
		methods() {
			return Array.isArray(this.value.anyOf) ? this.value.anyOf : []
		},
	},

	methods: {
		patch: patchMethod(),
		/**
		 * Write the picked authentication type discriminator into the config
		 * slot; clearing the select stores an empty string.
		 *
		 * @param {{id: string, label: string}|null} option The selected entry
		 *   from `typeOptions` (apikey / jwt / jwt-zgw / basic / oauth / nc-session).
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3
		 */
		onTypePick(option) {
			const type = option?.id || ''
			if (type === 'anyOf') {
				// Keep what was configured as the first method of the list.
				const first = this.value.type && this.value.type !== 'anyOf' ? { ...this.value } : { type: 'apikey' }
				this.$emit('update:value', { type: 'anyOf', anyOf: this.methods.length > 0 ? this.methods : [first] })
				return
			}

			// EndpointService reads `anyOf` before `type`, so leaving the list
			// behind would keep the old methods in force under a new type.
			const next = { ...this.value, type }
			delete next.anyOf
			this.$emit('update:value', next)
		},

		/**
		 * Replace one method of the anyOf list.
		 *
		 * @param {number} index The method's position
		 * @param {object} method The method as its form changed it
		 * @return {void}
		 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005
		 */
		setMethod(index, method) {
			this.patch('anyOf', this.methods.map((current, at) => (at === index ? method : current)))
		},

		/**
		 * Add a method to the anyOf list.
		 *
		 * @return {void}
		 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005
		 */
		addMethod() {
			this.patch('anyOf', [...this.methods, { type: 'apikey' }])
		},

		/**
		 * Remove a method from the anyOf list.
		 *
		 * @param {number} index The method's position
		 * @return {void}
		 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005
		 */
		removeMethod(index) {
			this.patch('anyOf', this.methods.filter((method, at) => at !== index))
		},

		/**
		 * Render a stored allow-list (keys / users / groups) as the
		 * comma-separated text the NcTextField displays.
		 *
		 * @param {Array<string>|string|undefined} value The stored field, either
		 *   an array of entries or an already-flat string.
		 * @return {string} Comma-separated entries, or '' when unset.
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3
		 */
		csv(value) {
			return Array.isArray(value) ? value.join(',') : value || ''
		},

		/**
		 * Parse comma-separated user input back into the array shape the
		 * backend expects, trimming entries and dropping empty ones.
		 *
		 * @param {string} text Raw comma-separated text typed into the field.
		 * @return {Array<string>} The cleaned list of entries.
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3
		 */
		toArray(text) {
			return (text || '')
				.split(',')
				.map((entry) => entry.trim())
				.filter(Boolean)
		},
	},
}
</script>

<style scoped src="./actionForm.css"></style>

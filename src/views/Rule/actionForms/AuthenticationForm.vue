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
  "Accept any one of several methods" stores `anyOf`: a list of methods,
  each edited by this same form with `nested` set, where the first that
  passes lets the call through. The scope lists stay on the rule and apply
  to every method (access-consumer-credentials, REQ-CRED-005).
-->
<template>
	<div class="action-form">
		<NcCheckboxRadioSwitch
			v-if="!nested"
			type="switch"
			data-testid="rule-auth-any-of"
			:modelValue="anyOf"
			@update:modelValue="onAnyOfToggle">
			{{ t('integriq', 'Accept any one of several methods') }}
		</NcCheckboxRadioSwitch>
		<template v-if="anyOf">
			<div
				v-for="(method, index) in methods"
				:key="index"
				class="action-form__method"
				data-testid="rule-auth-method">
				<label class="action-form__label">{{
					t('integriq', 'Method {number}', { number: index + 1 })
				}}</label>
				<AuthenticationForm
					:value="method"
					nested
					@update:value="(next) => onMethodInput(index, next)" />
				<NcButton
					v-if="methods.length > 1"
					variant="tertiary"
					data-testid="rule-auth-method-remove"
					@click="onMethodRemove(index)">
					{{ t('integriq', 'Remove method') }}
				</NcButton>
			</div>
			<NcButton variant="secondary" data-testid="rule-auth-method-add" @click="onMethodAdd">
				{{ t('integriq', 'Add method') }}
			</NcButton>
		</template>
		<template v-else>
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
				v-if="value.type !== 'nc-session'"
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
		</template>
		<template v-if="showsScopes">
			<NcTextField
				:label="t('integriq', 'Scopes required to read (comma-separated)')"
				:modelValue="csv(readScopes)"
				placeholder="zaken:read"
				data-testid="rule-required-scopes-read"
				@update:modelValue="(next) => setScopes('read', next)" />
			<NcTextField
				:label="t('integriq', 'Scopes required to write (comma-separated)')"
				:modelValue="csv(writeScopes)"
				placeholder="zaken:write"
				data-testid="rule-required-scopes-write"
				@update:modelValue="(next) => setScopes('write', next)" />
			<span class="action-form__helper">
				{{
					t(
						'integriq',
						'A caller without a required scope gets 403 naming it. Reading is GET and HEAD; writing is POST, PUT, PATCH and DELETE. Leave both empty to require no scope.',
					)
				}}
			</span>
		</template>
		<span v-if="anyOf" class="action-form__helper">
			{{
				t(
					'integriq',
					'The methods are tried in order and the first that passes lets the call through. A call that passes none gets 401 with the reason of each method.',
				)
			}}
		</span>
		<span v-else-if="value.type === 'nc-session'" class="action-form__helper">
			{{
				t(
					'integriq',
					'Nextcloud session authorises the logged-in user of the calling browser. The request must carry a valid CSRF request token, so this type is for calls made from inside a Nextcloud page — not for server-to-server clients.',
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
import { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import { isAnyOf, methodsOf, withAddedMethod, withAnyOf, withMethod, withoutMethod } from './authMethods.js'
import { scopesFor, withScopes } from './requiredScopes.js'
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
	components: { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextField },
	props: {
		...valueProp,
		/** One method inside an `anyOf` list: no `anyOf` switch and no scope lists of its own. */
		nested: { type: Boolean, default: false },
	},

	emits: ['update:value'],
	computed: {
		/** @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005 */
		anyOf() {
			return !this.nested && isAnyOf(this.value)
		},

		/** @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005 */
		methods() {
			return methodsOf(this.value)
		},

		/** @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005 */
		showsScopes() {
			if (this.nested) {
				return false
			}
			return this.anyOf || this.value.type !== 'nc-session'
		},

		/** @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3 */
		typeOptions() {
			return AUTH_TYPES.map((row) => ({
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

		/** @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005 */
		readScopes() {
			return scopesFor(this.value.requiredScopes, 'read')
		},

		/** @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005 */
		writeScopes() {
			return scopesFor(this.value.requiredScopes, 'write')
		},
	},

	methods: {
		patch: patchMethod(),

		/**
		 * Switch between one method and any one of several, keeping the
		 * current method as the first of the list.
		 *
		 * @param {boolean} on True for `anyOf`.
		 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005
		 */
		onAnyOfToggle(on) {
			this.$emit('update:value', withAnyOf(this.value, !!on))
		},

		/**
		 * Store an edit of one method of the `anyOf` list.
		 *
		 * @param {number} index The method's position.
		 * @param {object} next The edited method.
		 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005
		 */
		onMethodInput(index, next) {
			this.$emit('update:value', withMethod(this.value, index, next))
		},

		/**
		 * Add a method at the end of the `anyOf` list.
		 *
		 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005
		 */
		onMethodAdd() {
			this.$emit('update:value', withAddedMethod(this.value))
		},

		/**
		 * Remove one method of the `anyOf` list; the last one stays.
		 *
		 * @param {number} index The method's position.
		 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005
		 */
		onMethodRemove(index) {
			this.$emit('update:value', withoutMethod(this.value, index))
		},

		/**
		 * Write the picked authentication type discriminator into the config
		 * slot; clearing the select stores an empty string.
		 *
		 * @param {{id: string, label: string}|null} option The selected entry
		 *   from `typeOptions` (apikey / jwt / jwt-zgw / basic / oauth / nc-session).
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3
		 */
		onTypePick(option) {
			this.patch('type', option?.id || '')
		},

		/**
		 * Store the scopes a caller needs to read or to write.
		 *
		 * @param {'read'|'write'} kind The list that changed.
		 * @param {string} text Its comma-separated scopes.
		 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
		 */
		setScopes(kind, text) {
			this.patch('requiredScopes', withScopes(this.value.requiredScopes, kind, this.toArray(text)))
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

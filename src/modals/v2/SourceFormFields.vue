<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  SourceFormFields — slotted override for CnFormDialog's `#form` content on the
  Sources index page. Mirrors the lib's auto-widget switch (the handful of
  widget types the `source` schema actually uses) and adds the brokered-
  credential authoring UX the lib cannot express declaratively:

    • A "Brokered credential (OpenRegister)" switch. When ON, an accessible
      NcSelect lists the signed-in user's credentials from the OpenRegister
      credential broker (GET /apps/openregister/api/credentials). Picking one
      writes `configuration.authentication.credentialRef = { credentialId }`
      into the Source model (see sourceCredentialRef.js — the exact path the
      backend BrokeredCallService reads).

    • While brokered, the embedded-secret auth fields (apikey / secret /
      username / password / jwt / …) are hidden: the broker treats a
      credentialRef alongside a sibling secret under `authentication` as a
      hard 409 config error, so the UI must never let both be authored. The
      write helper also collapses `authentication` to `{ credentialRef }`
      only, guaranteeing no sibling ever reaches the backend.

    • Soft-fail: if the OR credentials endpoint 404s (broker absent / too old)
      the picker shows a note instead of crashing the editor.

  Wired by CnPageRenderer when the manifest declares
  `pages[].slots = { 'form-fields': 'SourceFormFields' }` (registry.js maps the
  name to this component). Follows the JobFormFields.vue precedent for the
  same slot on the Jobs page. Closes openconnector#102.
-->
<template>
	<div class="cn-source-form-fields">
		<div
			v-for="field in visibleFields"
			:key="field.key"
			class="cn-source-form-fields__field">
			<!-- Source `type` select (enum). Precedes the widget branches so a
			     schema-declared widget='text' cannot short-circuit it. -->
			<div v-if="field.key === 'type'" class="cn-source-form-fields__select-wrapper">
				<label :for="'cn-source-form-' + field.key" class="cn-source-form-fields__label">
					{{ field.label || t('openconnector', 'Type') }}{{ field.required ? ' *' : '' }}
				</label>
				<NcSelect
					:input-id="'cn-source-form-' + field.key"
					:aria-label-combobox="field.label || t('openconnector', 'Type')"
					:value="selectedTypeOption"
					:options="typeOptions"
					:clearable="!field.required"
					:placeholder="t('openconnector', 'Pick a source type')"
					@input="onTypePick" />
				<span class="cn-source-form-fields__helper">
					{{ field.description || t('openconnector', 'The transport used to reach this source.') }}
				</span>
			</div>

			<NcTextField
				v-else-if="field.widget === 'text' || field.widget === 'email' || field.widget === 'url' || field.widget === 'date' || field.widget === 'datetime'"
				:label="field.label + (field.required ? ' *' : '')"
				:value="formData[field.key] != null ? String(formData[field.key]) : ''"
				:helper-text="errors[field.key] || field.description"
				:error="!!errors[field.key]"
				:type="textFieldType(field)"
				:disabled="field.readOnly"
				:placeholder="field.description"
				@update:value="(value) => updateField(field.key, value)" />

			<NcTextField
				v-else-if="field.widget === 'number'"
				:label="field.label + (field.required ? ' *' : '')"
				:value="formData[field.key] != null ? String(formData[field.key]) : ''"
				:helper-text="errors[field.key] || field.description"
				:error="!!errors[field.key]"
				type="number"
				:disabled="field.readOnly"
				:placeholder="field.description"
				@update:value="(value) => updateField(field.key, value !== '' ? Number(value) : null)" />

			<div v-else-if="field.widget === 'textarea'" class="cn-source-form-fields__textarea-wrapper">
				<label :for="'cn-source-form-' + field.key" class="cn-source-form-fields__label">
					{{ field.label }}{{ field.required ? ' *' : '' }}
				</label>
				<textarea
					:id="'cn-source-form-' + field.key"
					class="cn-source-form-fields__textarea"
					:value="formData[field.key] || ''"
					:disabled="field.readOnly"
					:placeholder="field.description"
					rows="3"
					@input="updateField(field.key, $event.target.value)" />
				<span class="cn-source-form-fields__helper" :class="{ 'cn-source-form-fields__helper--error': errors[field.key] }">
					{{ errors[field.key] || field.description }}
				</span>
			</div>

			<NcCheckboxRadioSwitch
				v-else-if="field.widget === 'checkbox'"
				:checked="!!formData[field.key]"
				:disabled="field.readOnly"
				type="switch"
				@update:checked="(value) => updateField(field.key, value)">
				{{ field.label }}{{ field.required ? ' *' : '' }}
			</NcCheckboxRadioSwitch>

			<div v-else-if="field.widget === 'json'" class="cn-source-form-fields__textarea-wrapper">
				<label :for="'cn-source-form-' + field.key" class="cn-source-form-fields__label">
					{{ field.label }}{{ field.required ? ' *' : '' }}
				</label>
				<textarea
					:id="'cn-source-form-' + field.key"
					class="cn-source-form-fields__textarea cn-source-form-fields__textarea--code"
					:value="jsonStringFor(field)"
					:disabled="field.readOnly"
					spellcheck="false"
					rows="5"
					@input="onJsonInput(field, $event.target.value)" />
				<span class="cn-source-form-fields__helper" :class="{ 'cn-source-form-fields__helper--error': jsonErrors[field.key] || errors[field.key] }">
					{{ jsonErrors[field.key] || errors[field.key] || field.description }}
				</span>
			</div>

			<NcTextField
				v-else
				:label="field.label + (field.required ? ' *' : '')"
				:value="formData[field.key] != null ? String(formData[field.key]) : ''"
				:helper-text="errors[field.key] || field.description"
				:error="!!errors[field.key]"
				:disabled="field.readOnly"
				:placeholder="field.description"
				@update:value="(value) => updateField(field.key, value)" />
		</div>

		<!-- Brokered-credential authoring block. -->
		<div class="cn-source-form-fields__field cn-source-form-fields__broker">
			<NcCheckboxRadioSwitch
				:checked="brokeredEnabled"
				type="switch"
				@update:checked="onToggleBrokered">
				{{ t('openconnector', 'Brokered credential (OpenRegister)') }}
			</NcCheckboxRadioSwitch>
			<span class="cn-source-form-fields__helper">
				{{ t('openconnector', 'Reference a credential held by the OpenRegister credential broker instead of storing a secret on this source. The secret never enters OpenConnector.') }}
			</span>

			<template v-if="brokeredEnabled">
				<div v-if="brokerUnavailable" class="cn-source-form-fields__note cn-source-form-fields__note--warn">
					{{ t('openconnector', 'The OpenRegister credential broker is not available (the openregister app is disabled or too old). Brokered credentials cannot be listed here.') }}
				</div>
				<template v-else>
					<label for="cn-source-form-credentialref" class="cn-source-form-fields__label">
						{{ t('openconnector', 'Credential') }}
					</label>
					<NcSelect
						input-id="cn-source-form-credentialref"
						:input-label="t('openconnector', 'Brokered credential')"
						:aria-label-combobox="t('openconnector', 'Brokered credential')"
						:value="selectedCredential"
						:options="credentialOptions"
						:loading="credentialsLoading"
						:clearable="true"
						:placeholder="t('openconnector', 'Select a brokered credential')"
						@input="onCredentialPick" />
					<span class="cn-source-form-fields__helper">
						{{ t('openconnector', 'The credential must allow the calling app "openconnector" in its allowedApps. Note: the app that declared the credential may be a different one.') }}
					</span>
					<div v-if="!credentialsLoading && credentialOptions.length === 0" class="cn-source-form-fields__note">
						{{ t('openconnector', 'You have no brokered credentials yet. Create one in OpenRegister first.') }}
					</div>
				</template>
			</template>
		</div>
	</div>
</template>

<script>
import {
	NcTextField,
	NcSelect,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	EMBEDDED_SECRET_FIELDS,
	readCredentialId,
	isBrokered,
	writeCredentialRef,
	clearCredentialRef,
	extractCredentialResults,
	mapCredentialOptions,
} from './sourceCredentialRef.js'

/**
 * Source transport types (the `source.type` enum). Listed explicitly so the
 * picker is stable regardless of whether the resolved field descriptor carries
 * the enum options — mirrors JobFormFields' hardcoded jobClass list.
 */
const SOURCE_TYPE_OPTIONS = [
	{ id: 'api', label: 'API (REST/JSON)' },
	{ id: 'database', label: 'Database' },
	{ id: 'file', label: 'File' },
	{ id: 'soap', label: 'SOAP' },
	{ id: 'dso', label: 'DSO' },
]

export default {
	name: 'SourceFormFields',

	components: {
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
	},

	props: {
		/** Resolved field descriptors from CnFormDialog. */
		fields: { type: Array, default: () => [] },
		/** Reactive form data object owned by CnFormDialog. */
		formData: { type: Object, default: () => ({}) },
		/** Per-field error map from CnFormDialog. */
		errors: { type: Object, default: () => ({}) },
		/**
		 * Mutator forwarded from CnFormDialog. Signature: (key, value).
		 * Drives the dialog's reactivity + dirty tracking.
		 */
		updateField: { type: Function, required: true },
	},

	data() {
		return {
			/** Local mirror of the brokered switch; stays on after toggle even before a pick. */
			brokeredEnabled: isBrokered(this.formData),
			/** True once the OR credentials endpoint has soft-failed (404 / broker absent). */
			brokerUnavailable: false,
			credentialOptions: [],
			credentialsLoading: false,
			/** Per-key json editor drafts (invalid intermediate strings held here). */
			jsonDrafts: {},
			jsonErrors: {},
		}
	},

	computed: {
		/**
		 * Schema fields to render — the embedded-secret auth fields drop out
		 * while brokered so the mutually-exclusive state can never be authored.
		 *
		 * @return {object[]} The visible field descriptors.
		 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
		 */
		visibleFields() {
			if (!Array.isArray(this.fields)) return []
			if (!this.brokeredEnabled) return this.fields
			return this.fields.filter((field) => !EMBEDDED_SECRET_FIELDS.includes(field.key))
		},

		/**
		 * Source-type options for the `type` select.
		 *
		 * @return {object[]} The select options.
		 * @spec exclude static enum options for the type select — presentation only
		 */
		typeOptions() {
			return SOURCE_TYPE_OPTIONS
		},

		/**
		 * The currently-selected `type` option (or a synthetic passthrough).
		 *
		 * @return {object|null} The selected option.
		 * @spec exclude trivial select-value projection — presentation only
		 */
		selectedTypeOption() {
			const current = this.formData?.type
			if (!current) return null
			return this.typeOptions.find((option) => option.id === current) ?? { id: current, label: current }
		},

		/**
		 * The credential option matching the written credentialId, if any.
		 *
		 * @return {object|null} The selected credential option.
		 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
		 */
		selectedCredential() {
			const id = readCredentialId(this.formData)
			if (!id) return null
			return this.credentialOptions.find((option) => option.id === id) ?? { id, label: id, name: id, provider: '' }
		},
	},

	watch: {
		/**
		 * Keep the local switch honest if CnFormDialog swaps the edited object.
		 *
		 * @return {void}
		 * @spec exclude reactive-state sync passthrough
		 */
		formData() {
			this.brokeredEnabled = isBrokered(this.formData)
		},
	},

	/**
	 * Preload the user's credentials when editing an already-brokered source so
	 * the picker resolves its current selection to a labelled option.
	 *
	 * @return {void}
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
	 */
	created() {
		if (this.brokeredEnabled) {
			this.fetchCredentials()
		}
	},

	methods: {
		/**
		 * Map a schema widget to an <input type>.
		 *
		 * @param {object} field The field descriptor.
		 * @return {string} The input type.
		 * @spec exclude presentation-only widget→input-type map
		 */
		textFieldType(field) {
			if (field.widget === 'email') return 'email'
			if (field.widget === 'url') return 'url'
			if (field.widget === 'date') return 'date'
			if (field.widget === 'datetime') return 'datetime-local'
			return 'text'
		},

		/**
		 * Write the picked source `type`.
		 *
		 * @param {object} option The picked option.
		 * @return {void}
		 * @spec exclude trivial select write-back — presentation only
		 */
		onTypePick(option) {
			this.updateField('type', option?.id ?? null)
		},

		/**
		 * Toggle brokered mode. Turning it off clears the credentialRef; turning
		 * it on reveals the picker and loads the user's credentials.
		 *
		 * @param {boolean} value The new switch state.
		 * @return {void}
		 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
		 */
		onToggleBrokered(value) {
			this.brokeredEnabled = value
			if (!value) {
				this.updateField('configuration', clearCredentialRef(this.formData?.configuration))
				return
			}
			if (this.credentialOptions.length === 0 && !this.brokerUnavailable) {
				this.fetchCredentials()
			}
		},

		/**
		 * Write `configuration.authentication.credentialRef = { credentialId }`
		 * (or clear it when the picker is cleared).
		 *
		 * @param {object} option The picked credential option.
		 * @return {void}
		 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
		 */
		onCredentialPick(option) {
			if (option?.id) {
				this.updateField('configuration', writeCredentialRef(this.formData?.configuration, String(option.id)))
			} else {
				this.updateField('configuration', clearCredentialRef(this.formData?.configuration))
			}
		},

		/**
		 * Load the signed-in user's brokered credentials. Soft-fails (sets
		 * brokerUnavailable) on 404 / broker absence — never crashes the editor.
		 *
		 * @return {Promise<void>} Resolves once the options are loaded.
		 * @spec openspec/specs/http-call-engine/spec.md#requirement-secret-hygiene-and-refusal-logging-for-brokered-calls-req-sbc-004
		 */
		async fetchCredentials() {
			this.credentialsLoading = true
			this.brokerUnavailable = false
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/credentials'))
				this.credentialOptions = mapCredentialOptions(extractCredentialResults(response.data))
			} catch (err) {
				// 404 → the broker (or the whole openregister app) isn't there;
				// any other error also degrades to the soft-fail note rather than
				// tearing down the editor.
				this.brokerUnavailable = true
				this.credentialOptions = []
				// eslint-disable-next-line no-console
				console.warn('[SourceFormFields] brokered-credential fetch failed', err)
			} finally {
				this.credentialsLoading = false
			}
		},

		/**
		 * Render a json field's current value as a string for the textarea.
		 *
		 * @param {object} field The field descriptor.
		 * @return {string} The stringified value.
		 * @spec exclude json-editor draft rendering — presentation only
		 */
		jsonStringFor(field) {
			if (this.jsonDrafts[field.key] !== undefined) {
				return this.jsonDrafts[field.key]
			}
			const raw = this.formData[field.key]
			if (raw === null || raw === undefined) return ''
			if (typeof raw === 'string') return raw
			try {
				return JSON.stringify(raw, null, 2)
			} catch (_e) {
				return String(raw)
			}
		},

		/**
		 * Parse a json field's textarea input; store the parsed value (or an error).
		 *
		 * @param {object} field The field descriptor.
		 * @param {string} raw The raw textarea string.
		 * @return {void}
		 * @spec exclude json-editor input parsing — presentation only
		 */
		onJsonInput(field, raw) {
			this.$set(this.jsonDrafts, field.key, raw)
			const trimmed = raw.trim()
			if (trimmed.length === 0) {
				this.$set(this.jsonErrors, field.key, '')
				this.updateField(field.key, null)
				return
			}
			try {
				const parsed = JSON.parse(trimmed)
				this.$set(this.jsonErrors, field.key, '')
				this.updateField(field.key, parsed)
			} catch (parseErr) {
				this.$set(this.jsonErrors, field.key, t('openconnector', 'Invalid JSON: {message}', { message: parseErr.message }))
			}
		},
	},
}
</script>

<style scoped>
.cn-source-form-fields {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.cn-source-form-fields__field {
	display: flex;
	flex-direction: column;
}

.cn-source-form-fields__label {
	font-weight: bold;
	margin-bottom: 4px;
}

.cn-source-form-fields__textarea-wrapper,
.cn-source-form-fields__select-wrapper {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.cn-source-form-fields__textarea {
	width: 100%;
	padding: 8px;
	font-family: var(--font-face, sans-serif);
	font-size: 14px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.cn-source-form-fields__textarea--code {
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
}

.cn-source-form-fields__helper {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.cn-source-form-fields__helper--error {
	color: var(--color-error);
}

.cn-source-form-fields__broker {
	gap: 6px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, var(--border-radius));
	background: var(--color-background-hover);
}

.cn-source-form-fields__note {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

.cn-source-form-fields__note--warn {
	color: var(--color-warning-text, var(--color-error));
}
</style>

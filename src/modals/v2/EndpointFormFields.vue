<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  EndpointFormFields — slotted override for CnFormDialog's `#form` content on
  the Endpoints index page. Restores the field set the pre-manifest
  EditEndpoint.vue modal offered (src/modals/Endpoint/EditEndpoint.vue), which
  the schema-driven form could not express declaratively:

    • Register + Schema pickers. These are NOT schema properties — the endpoint
      schema stores a single polymorphic `targetId`, and for
      `targetType === 'register/schema'` it holds `<registerId>/<schemaId>`
      (EndpointsController casts both halves with (int), so numeric ids, not
      slugs). The two selects compose that string; the schema select is scoped
      to the chosen register's `schemas` array and clears when the register
      changes.

    • `endpointArray` as a comma-separated text field. The property is
      `string[]`, and the original modal bridged it with `.join(', ')` on load
      and `.split(/ *, */g)` on save rather than rendering a JSON editor.

    • `method` and `targetType` selects. The schema declares both as plain
      strings with no enum, so the lib renders text inputs.

    • `configurations` as a multiselect over OpenRegister's configurations,
      storing ids (what the original modal wrote).

  Wired by CnPageRenderer when the manifest declares
  `pages[].slots = { 'form-fields': 'EndpointFormFields' }` (registry.js maps
  the name to this component). Follows the JobFormFields / SourceFormFields
  precedent for the same slot.

  @spec openspec/specs/endpoint-job-editor-ui/spec.md
-->
<template>
	<div class="cn-endpoint-form-fields">
		<div
			v-for="field in visibleFields"
			:key="field.key"
			class="cn-endpoint-form-fields__field">
			<!-- endpointArray: string[] authored as a comma-separated list. -->
			<template v-if="field.key === 'endpointArray'">
				<NcTextField
					:label="field.label + (field.required ? ' *' : '')"
					:model-value="endpointArrayText"
					:error="!!errors[field.key]"
					:disabled="field.readOnly"
					:placeholder="placeholderFor(field.key)"
					@update:model-value="onEndpointArrayInput" />
				<CnFieldHelper
					:text="field.description || t('openconnector', 'Path segments, split on commas. Left empty the backend derives them from the endpoint path.')"
					:more="field.descriptionLong"
					:error="errors[field.key]" />
			</template>

			<!-- method / targetType: schema declares plain strings, no enum. -->
			<div
				v-else-if="field.key === 'method' || field.key === 'targetType'"
				class="cn-endpoint-form-fields__select-wrapper">
				<label :for="'cn-endpoint-form-' + field.key" class="cn-endpoint-form-fields__label">
					{{ field.label }}{{ field.required ? ' *' : '' }}
				</label>
				<NcSelect
					:input-id="'cn-endpoint-form-' + field.key"
					:aria-label-combobox="field.label"
					:model-value="selectedEnumOption(field.key)"
					:options="field.key === 'method' ? methodOptions : targetTypeOptions"
					:clearable="!field.required"
					@update:model-value="(option) => updateField(field.key, option?.id ?? null)" />
				<CnFieldHelper
					:text="field.description"
					:more="field.descriptionLong"
					:error="errors[field.key]" />

				<!-- Register + Schema compose `targetId`, and only that target kind
				     is addressed by a register/schema pair — so they appear only
				     once Target Type selects it. -->
				<template v-if="field.key === 'targetType' && isRegisterSchemaTarget">
					<div v-if="registerUnavailable" class="cn-endpoint-form-fields__note cn-endpoint-form-fields__note--warn">
						{{ t('openconnector', 'OpenRegister is not available, so registers and schemas cannot be listed. The endpoint target cannot be set here.') }}
					</div>
					<template v-else>
						<!-- Register and Schema sit on one row: they are two halves
						     of a single value (`targetId`), so pairing them reads
						     as one control. -->
						<div class="cn-endpoint-form-fields__row">
							<div class="cn-endpoint-form-fields__col">
								<label for="cn-endpoint-form-register" class="cn-endpoint-form-fields__label">
									{{ t('openconnector', 'Register') }} *
								</label>
								<NcSelect
									input-id="cn-endpoint-form-register"
									:aria-label-combobox="t('openconnector', 'Register')"
									:model-value="selectedRegister"
									:options="registerOptions"
									:loading="registersLoading"
									@update:model-value="onRegisterPick" />
							</div>

							<div class="cn-endpoint-form-fields__col">
								<label for="cn-endpoint-form-schema" class="cn-endpoint-form-fields__label">
									{{ t('openconnector', 'Schema') }} *
								</label>
								<NcSelect
									input-id="cn-endpoint-form-schema"
									:aria-label-combobox="t('openconnector', 'Schema')"
									:model-value="selectedSchema"
									:options="schemaOptions"
									:disabled="!selectedRegister"
									:loading="schemasLoading"
									@update:model-value="onSchemaPick" />
							</div>
						</div>
						<CnFieldHelper
							:text="t('openconnector', 'Stored together as the endpoint\'s target id.')" />
					</template>
				</template>
			</div>

			<!-- configurations: multiselect over OpenRegister configurations. -->
			<div v-else-if="field.key === 'configurations'" class="cn-endpoint-form-fields__select-wrapper">
				<label for="cn-endpoint-form-configurations" class="cn-endpoint-form-fields__label">
					{{ field.label }}
				</label>
				<NcSelect
					input-id="cn-endpoint-form-configurations"
					:aria-label-combobox="field.label"
					:model-value="selectedConfigurations"
					:options="configurationOptions"
					:loading="configurationsLoading"
					:multiple="true"
					:close-on-select="false"
					@update:model-value="onConfigurationsPick" />
				<CnFieldHelper
					:text="field.description"
					:more="field.descriptionLong"
					:error="errors[field.key]" />
			</div>

			<div v-else-if="field.widget === 'textarea'" class="cn-endpoint-form-fields__textarea-wrapper">
				<label :for="'cn-endpoint-form-' + field.key" class="cn-endpoint-form-fields__label">
					{{ field.label }}{{ field.required ? ' *' : '' }}
				</label>
				<textarea
					:id="'cn-endpoint-form-' + field.key"
					class="cn-endpoint-form-fields__textarea"
					:value="formData[field.key] || ''"
					:disabled="field.readOnly"
					rows="3"
					@input="updateField(field.key, $event.target.value)" />
				<CnFieldHelper
					:text="field.description"
					:more="field.descriptionLong"
					:error="errors[field.key]" />
			</div>

			<NcCheckboxRadioSwitch
				v-else-if="field.widget === 'checkbox'"
				:model-value="!!formData[field.key]"
				:disabled="field.readOnly"
				type="switch"
				@update:model-value="(value) => updateField(field.key, value)">
				{{ field.label }}
			</NcCheckboxRadioSwitch>

			<!-- Everything else: plain text (name, description, endpoint,
			     endpointRegex, slug). -->
			<template v-else>
				<NcTextField
					:label="field.label + (field.required ? ' *' : '')"
					:model-value="formData[field.key] != null ? String(formData[field.key]) : ''"
					:error="!!errors[field.key]"
					:disabled="field.readOnly"
					:placeholder="placeholderFor(field.key)"
					@update:model-value="(value) => updateField(field.key, value)" />
				<CnFieldHelper
					:text="field.description"
					:more="field.descriptionLong"
					:error="errors[field.key]" />
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
import { CnFieldHelper } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** HTTP methods the original EditEndpoint modal offered, in its order. */
const METHOD_OPTIONS = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'].map((id) => ({ id, label: id }))

/**
 * Target kinds. The original modal offered ONLY `register/schema`, which is why
 * it always showed (and required) the Register + Schema pickers. The schema
 * documents `api`, `job` and `synchronization` too, but authoring those needs a
 * different target input than a register/schema pair — kept out until asked for.
 */
const TARGET_TYPE_OPTIONS = [{ id: 'register/schema', label: 'register/schema' }]

/**
 * Example values shown as placeholders. `endpoint` and `endpointArray` describe
 * the SAME path two different ways on purpose: the backend derives the segments
 * from the path when they are not supplied (`explode('/', endpoint)`), so the
 * two examples have to correspond or they teach the wrong thing. Both show a
 * `{param}` placeholder, which is what path parameters are extracted from.
 *
 * Deliberately not run through `t()` — these are code-shaped examples, not
 * prose, and a dynamic `t(variable)` would not be picked up by string
 * extraction anyway.
 */
const FIELD_PLACEHOLDERS = {
	endpoint: 'api/v1/objects/{id}',
	endpointArray: 'api, v1, objects, {id}',
}

export default {
	name: 'EndpointFormFields',

	components: {
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
		CnFieldHelper,
	},

	props: {
		/** Resolved field descriptors from CnFormDialog. */
		fields: { type: Array, default: () => [] },
		/** Reactive form data object owned by CnFormDialog. */
		formData: { type: Object, default: () => ({}) },
		/** Per-field error map from CnFormDialog. */
		errors: { type: Object, default: () => ({}) },
		/** Mutator forwarded from CnFormDialog. Signature: (key, value). */
		updateField: { type: Function, required: true },
	},

	data() {
		return {
			/** Local draft so a trailing comma survives while typing. */
			endpointArrayDraft: null,
			/** Register picked this session, before a schema completes `targetId`. */
			pickedRegisterId: null,
			registers: [],
			schemas: [],
			configurationOptions: [],
			registersLoading: false,
			schemasLoading: false,
			configurationsLoading: false,
			/** True once the OpenRegister registers endpoint has soft-failed. */
			registerUnavailable: false,
		}
	},

	computed: {
		/**
		 * Schema fields to render. `targetId` is composed from the Register +
		 * Schema pickers, so it never renders as its own input.
		 *
		 * @return {object[]} The visible field descriptors.
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		visibleFields() {
			if (!Array.isArray(this.fields)) return []
			return this.fields.filter((field) => field.key !== 'targetId')
		},

		/**
		 * @return {object[]} HTTP method options.
		 * @spec exclude static option list — presentation only
		 */
		methodOptions() {
			return METHOD_OPTIONS
		},

		/**
		 * @return {object[]} Target-type options.
		 * @spec exclude static option list — presentation only
		 */
		targetTypeOptions() {
			return TARGET_TYPE_OPTIONS
		},

		/**
		 * `endpointArray` rendered as a comma-separated string.
		 *
		 * @return {string} The joined segments, or the in-progress draft.
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		endpointArrayText() {
			if (this.endpointArrayDraft !== null) return this.endpointArrayDraft
			const raw = this.formData?.endpointArray
			if (Array.isArray(raw)) return raw.join(', ')
			return raw != null ? String(raw) : ''
		},

		/**
		 * Whether the endpoint targets a register/schema pair, which is the only
		 * target kind the Register + Schema selects can express.
		 *
		 * @return {boolean} True when Target Type is `register/schema`.
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		isRegisterSchemaTarget() {
			return this.formData?.targetType === 'register/schema'
		},

		/**
		 * The register currently in play: the one just picked, else the one
		 * parsed back out of a stored `targetId`. Held locally because
		 * `targetId` stays empty until BOTH halves are chosen.
		 *
		 * @return {string|number|null} The active register id.
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		activeRegisterId() {
			return this.pickedRegisterId !== null ? this.pickedRegisterId : this.targetRegisterId
		},

		/** @return {number|null} Register id parsed from `targetId`. */
		targetRegisterId() {
			return this.targetIdParts[0]
		},

		/** @return {number|null} Schema id parsed from `targetId`. */
		targetSchemaId() {
			return this.targetIdParts[1]
		},

		/**
		 * `targetId` split into its register/schema halves.
		 *
		 * @return {Array<string|null>} `[registerId, schemaId]`, either may be null.
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		targetIdParts() {
			const raw = this.formData?.targetId
			if (typeof raw !== 'string' || !raw.includes('/')) return [null, null]
			const [register, schema] = raw.split('/')
			return [register || null, schema || null]
		},

		/** @return {object[]} Register select options. */
		registerOptions() {
			return this.registers.map((register) => ({
				id: register.id,
				label: register.title || register.name || String(register.id),
				schemas: register.schemas || [],
			}))
		},

		/** @return {object|null} The register option currently in play. */
		selectedRegister() {
			if (this.activeRegisterId == null) return null
			return this.registerOptions.find((option) => String(option.id) === String(this.activeRegisterId)) ?? null
		},

		/**
		 * Schemas selectable for the chosen register — the original modal scoped
		 * the list to the register's own `schemas` array.
		 *
		 * @return {object[]} Schema select options.
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		schemaOptions() {
			const allowed = this.selectedRegister?.schemas
			return this.schemas
				.filter((schema) => !Array.isArray(allowed) || allowed.length === 0 || allowed.includes(schema.id))
				.map((schema) => ({ id: schema.id, label: schema.title || schema.name || String(schema.id) }))
		},

		/** @return {object|null} The schema matching `targetId`'s second half. */
		selectedSchema() {
			if (this.targetSchemaId == null) return null
			return this.schemaOptions.find((option) => String(option.id) === String(this.targetSchemaId)) ?? null
		},

		/** @return {object[]} Currently-selected configuration options. */
		selectedConfigurations() {
			const ids = this.formData?.configurations
			if (!Array.isArray(ids)) return []
			return ids.map((id) => this.configurationOptions.find((option) => String(option.id) === String(id))
				?? { id, label: String(id) })
		},
	},

	watch: {
		/**
		 * Drop the locally-held register when CnFormDialog swaps the edited
		 * object, so the pickers re-derive from the new item's `targetId`.
		 *
		 * @return {void}
		 * @spec exclude reactive-state sync passthrough
		 */
		formData() {
			this.pickedRegisterId = null
			this.endpointArrayDraft = null
		},
	},

	/**
	 * Load the pickers' options. All three soft-fail — an endpoint form that
	 * cannot reach OpenRegister still edits its own fields.
	 *
	 * @return {void}
	 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
	 */
	created() {
		this.fetchRegisters()
		this.fetchSchemas()
		this.fetchConfigurations()
	},

	methods: {
		/**
		 * Resolve a select's current value from the form data, tolerating a
		 * stored value that is not among the options.
		 *
		 * @param {string} key The field key.
		 * @return {object|null} The matching option.
		 * @spec exclude trivial select-value projection — presentation only
		 */
		/**
		 * Example placeholder for a field, or '' when it has none.
		 *
		 * @param {string} key The field key.
		 * @return {string} The placeholder text.
		 * @spec exclude static example lookup — presentation only
		 */
		placeholderFor(key) {
			return FIELD_PLACEHOLDERS[key] || ''
		},

		selectedEnumOption(key) {
			const current = this.formData?.[key]
			if (!current) return null
			const options = key === 'method' ? this.methodOptions : this.targetTypeOptions
			return options.find((option) => option.id === current) ?? { id: current, label: String(current) }
		},

		/**
		 * Parse the comma-separated `endpointArray` input into a string[].
		 * Empty segments are dropped so an empty field stores `[]` rather than
		 * `['']`.
		 *
		 * @param {string} raw The raw input.
		 * @return {void}
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		onEndpointArrayInput(raw) {
			this.endpointArrayDraft = raw
			const segments = String(raw)
				.split(/ *, */g)
				.map((segment) => segment.trim())
				.filter((segment) => segment.length > 0)
			this.updateField('endpointArray', segments)
		},

		/**
		 * Hold the picked register locally and clear `targetId`. The composed
		 * value is only written once BOTH halves exist — a half-filled pair must
		 * not satisfy CnFormDialog's required-field check, which is what keeps
		 * the create button disabled until the target is complete. Clearing also
		 * drops the previous schema, which may not belong to the new register.
		 *
		 * @param {object} option The picked register option.
		 * @return {void}
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		onRegisterPick(option) {
			this.pickedRegisterId = option?.id ?? null
			this.updateField('targetId', '')
		},

		/**
		 * Compose `targetId` from the active register and the picked schema.
		 *
		 * @param {object} option The picked schema option.
		 * @return {void}
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		onSchemaPick(option) {
			const register = this.activeRegisterId
			if (register == null || option?.id == null) {
				this.updateField('targetId', '')
				return
			}
			this.updateField('targetId', `${register}/${option.id}`)
		},

		/**
		 * Write the picked configuration ids, as strings. The schema declares
		 * `configurations` as `string[]` while OpenRegister returns numeric ids,
		 * so writing them raw fails validation with "Property 'configurations.0'
		 * should be type 'string' but is 'integer'".
		 *
		 * @param {object[]} options The picked options.
		 * @return {void}
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		onConfigurationsPick(options) {
			this.updateField('configurations', (options || []).map((option) => String(option.id)))
		},

		/**
		 * Load registers. Soft-fails: a missing/older OpenRegister shows a note
		 * instead of tearing down the editor.
		 *
		 * @return {Promise<void>} Resolves once loaded.
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		async fetchRegisters() {
			this.registersLoading = true
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/registers'))
				this.registers = response.data?.results || []
				this.registerUnavailable = false
			} catch (err) {
				this.registerUnavailable = true
				this.registers = []
				// eslint-disable-next-line no-console
				console.warn('[EndpointFormFields] register fetch failed', err)
			} finally {
				this.registersLoading = false
			}
		},

		/**
		 * Load schemas (filtered per register by `schemaOptions`).
		 *
		 * @return {Promise<void>} Resolves once loaded.
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		async fetchSchemas() {
			this.schemasLoading = true
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/schemas'))
				this.schemas = response.data?.results || []
			} catch (err) {
				this.schemas = []
				// eslint-disable-next-line no-console
				console.warn('[EndpointFormFields] schema fetch failed', err)
			} finally {
				this.schemasLoading = false
			}
		},

		/**
		 * Load configuration profiles for the multiselect.
		 *
		 * @return {Promise<void>} Resolves once loaded.
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		async fetchConfigurations() {
			this.configurationsLoading = true
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/configurations'))
				// `title` first: OpenRegister's configurations API returns `title`,
				// not `name`. The original modal read `config.name`, so its labels
				// fell through to the raw id.
				this.configurationOptions = (response.data?.results || []).map((config) => ({
					id: config.id,
					label: config.title || config.name || String(config.id),
				}))
			} catch (err) {
				this.configurationOptions = []
				// eslint-disable-next-line no-console
				console.warn('[EndpointFormFields] configuration fetch failed', err)
			} finally {
				this.configurationsLoading = false
			}
		},
	},
}
</script>

<style scoped>
.cn-endpoint-form-fields {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.cn-endpoint-form-fields__field {
	display: flex;
	flex-direction: column;
}

.cn-endpoint-form-fields__label {
	font-weight: bold;
	margin-bottom: 4px;
}

.cn-endpoint-form-fields__textarea-wrapper,
.cn-endpoint-form-fields__select-wrapper {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.cn-endpoint-form-fields__row {
	display: flex;
	gap: 8px;
	align-items: flex-start;
	/* Stack rather than squeeze the selects to unusable widths in a narrow modal. */
	flex-wrap: wrap;
}

.cn-endpoint-form-fields__col {
	display: flex;
	flex-direction: column;
	gap: 4px;
	/* min-inline-size 0 lets a long option label shrink instead of forcing the
	   row wider than the dialog; the basis is what triggers the wrap above. */
	flex: 1 1 180px;
	min-inline-size: 0;
}

.cn-endpoint-form-fields__textarea {
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

.cn-endpoint-form-fields__note {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

.cn-endpoint-form-fields__note--warn {
	color: var(--color-warning-text, var(--color-error));
}
</style>

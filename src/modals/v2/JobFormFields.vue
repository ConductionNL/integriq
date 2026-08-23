<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  JobFormFields — slotted override for CnFormDialog's `#form` content on the
  Jobs index page. Jobs has no detail page (the manifest declares only `Jobs`
  and `JobLogs`), so this dialog is the ONLY job editor in the app.

  Configuration lives outside this file, by design. The component knows no
  field names apart from the synchronization argument path:

    - field ORDER and the interval/logRetention create defaults come from the
      `job` schema, via lib/Settings/register.d/job-form-fields.json. Before
      that fragment the schema declared no `order` at all, so `fieldsFromSchema`
      fell through to `keyA.localeCompare(keyB)` and the form rendered
      alphabetically — description, interval, isEnabled, jobClass, name.
    - the jobClass option list, the `group` layout key and errorRetention's
      prefill come from `pages[Jobs].config.fieldOverrides` in src/manifest.json.
      `fieldsFromSchema` ends with `Object.assign(field, overrides[key])`, so an
      arbitrary manifest key lands on the descriptor — which is how layout is
      declared without a `CnFormDialog` feature for it.
    - labels and help text come from the schema's `title`/`description`, already
      translated through the library's `cnTranslate`.

  What is left here is the three things no descriptor can express:

    1. Turning the flat ordered field list into nested DOM. `CnFormDialog`
       iterates fields in one flat v-for with no group concept, so the 2x2
       switch grid has to be assembled here — but from `field.group`, not from
       a hardcoded list of the four booleans.
    2. The `datetime` widget. `scheduleAfter` is `format: date-time`, and the
       branch this file used to have fed `'datetime-local'` to NcTextField's
       `type` prop, whose validator only accepts
       text/password/email/tel/url/search/number — so the field was
       unrenderable. NcDateTimePickerNative plus the RFC-3339 round trip in
       jobDraft.js is the fix.
    3. The conditional Synchronization picker: it reads `formData.jobClass`,
       fetches options from OpenRegister, and writes the NESTED key
       `arguments.synchronizationId` that `SynchronizationAction::run()` reads
       at execution time. `fieldsFromSchema` walks top-level properties only,
       so no override can reach a nested key. Note also that CnFormDialog binds
       `:fields="resolvedFields"` — not `visibleFields` — to this slot, so the
       library's native `condition`/`visibleWhen` filtering does not apply on
       the slot path even though it now exists.

  Mounted by CnPageRenderer when the manifest declares
  `pages[].slots = { 'form-fields': 'JobFormFields' }`; the path through
  CnPageRenderer's `<component :is v-bind="slotProps">` → CnIndexPage's
  `#form-fields` → CnFormDialog's `#form` means the slot scope arrives here as
  plain props.

  Closes #847.

  @spec openspec/specs/endpoint-job-editor-ui/spec.md
-->
<template>
	<div class="cn-job-form-fields">
		<template v-for="run in fieldRuns" :key="run.key">
			<!-- An ungrouped run's wrapper is `display: contents`, so its single
			     field stays a direct child of the outer flex column and inherits
			     its gap. That lets one widget switch serve grouped and ungrouped
			     runs alike instead of duplicating it per branch. -->
			<div
				class="cn-job-form-fields__run"
				:class="[{ 'cn-job-form-fields__run--grid': run.group }]">
				<div
					v-for="field in run.fields"
					:key="field.key"
					class="cn-job-form-fields__field">
					<NcTextField
						v-if="
							field.widget === 'text'
							|| field.widget === 'email'
							|| field.widget === 'url'
						"
						:label="field.label + (field.required ? ' *' : '')"
						:modelValue="
							formData[field.key] != null
								? String(formData[field.key])
								: ''
						"
						:helperText="errors[field.key] || field.description"
						:error="!!errors[field.key]"
						:type="textFieldType(field)"
						:disabled="field.readOnly"
						@update:modelValue="
							(value) => updateField(field.key, value)
						" />

					<NcTextField
						v-else-if="field.widget === 'number'"
						:label="field.label + (field.required ? ' *' : '')"
						:modelValue="
							formData[field.key] != null
								? String(formData[field.key])
								: ''
						"
						:helperText="errors[field.key] || field.description"
						:error="!!errors[field.key]"
						type="number"
						:disabled="field.readOnly"
						@update:modelValue="
							(value) => updateField(field.key, coerceNumber(value))
						" />

					<!-- Date/date-time. NcTextField's type validator rejects
					     'date'/'datetime-local', so this must be the native
					     picker; the value is serialised with an offset because
					     ajv-formats' `date-time` requires one. -->
					<div
						v-else-if="
							field.widget === 'date' || field.widget === 'datetime'
						"
						class="cn-job-form-fields__stack">
						<label
							:for="'cn-job-form-' + field.key"
							class="cn-job-form-fields__label">
							{{ field.label }}{{ field.required ? ' *' : '' }}
						</label>
						<NcDateTimePickerNative
							:id="'cn-job-form-' + field.key"
							:type="
								field.widget === 'datetime'
									? 'datetime-local'
									: 'date'
							"
							:label="field.label"
							:hideLabel="true"
							:modelValue="dateValueFor(field)"
							:disabled="field.readOnly"
							@update:modelValue="
								(date) => onDateFieldInput(field, date)
							" />
						<CnFieldHelper
							:text="field.description"
							:more="field.descriptionLong"
							:error="errors[field.key]" />
					</div>

					<!-- Select. Driven by `field.enum` (manifest) with labels
					     resolved through t(), so the option list stays
					     declarative while the text stays translatable. -->
					<div
						v-else-if="field.widget === 'select'"
						class="cn-job-form-fields__stack">
						<label
							:for="'cn-job-form-' + field.key"
							class="cn-job-form-fields__label">
							{{ field.label }}{{ field.required ? ' *' : '' }}
						</label>
						<NcSelect
							:inputId="'cn-job-form-' + field.key"
							:aria-label-combobox="field.label"
							:modelValue="selectedEnumOption(field)"
							:options="enumOptions(field)"
							:clearable="!field.required"
							:disabled="field.readOnly"
							@update:modelValue="
								(option) =>
									updateField(field.key, option ? option.id : null)
							" />
						<CnFieldHelper
							:text="field.description"
							:more="field.descriptionLong"
							:error="errors[field.key]" />
					</div>

					<div
						v-else-if="field.widget === 'textarea'"
						class="cn-job-form-fields__stack">
						<label
							:for="'cn-job-form-' + field.key"
							class="cn-job-form-fields__label">
							{{ field.label }}{{ field.required ? ' *' : '' }}
						</label>
						<textarea
							:id="'cn-job-form-' + field.key"
							class="cn-job-form-fields__textarea"
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
						:modelValue="!!formData[field.key]"
						:disabled="field.readOnly"
						type="switch"
						@update:modelValue="
							(value) => updateField(field.key, value)
						">
						{{ field.label }}{{ field.required ? ' *' : '' }}
					</NcCheckboxRadioSwitch>

					<div
						v-else-if="field.widget === 'json'"
						class="cn-job-form-fields__stack">
						<label
							:for="'cn-job-form-' + field.key"
							class="cn-job-form-fields__label">
							{{ field.label }}{{ field.required ? ' *' : '' }}
						</label>
						<textarea
							:id="'cn-job-form-' + field.key"
							class="cn-job-form-fields__textarea cn-job-form-fields__textarea--code"
							:value="jsonStringFor(field)"
							:disabled="field.readOnly"
							spellcheck="false"
							rows="6"
							@input="onJsonInput(field, $event.target.value)" />
						<CnFieldHelper
							:text="field.description"
							:more="field.descriptionLong"
							:error="jsonErrors[field.key] || errors[field.key]" />
					</div>

					<NcTextField
						v-else
						:label="field.label + (field.required ? ' *' : '')"
						:modelValue="
							formData[field.key] != null
								? String(formData[field.key])
								: ''
						"
						:helperText="errors[field.key] || field.description"
						:error="!!errors[field.key]"
						:disabled="field.readOnly"
						@update:modelValue="
							(value) => updateField(field.key, value)
						" />
				</div>
			</div>

			<!-- Synchronization picker, anchored to the run that holds the class
			     picker rather than appended after the whole list. `arguments` is
			     a bare `type: object` and fieldsFromSchema drops those before
			     overrides apply (it tests `prop.widget`, not
			     `overrides[key].widget`), so there is no field to overlay — and
			     with 12 fields on the form, "after the loop" would bury this
			     below Error Retention on a control that is effectively required
			     for a synchronization job. -->
			<div
				v-if="needsSynchronizationPicker && runHasJobClass(run)"
				class="cn-job-form-fields__field cn-job-form-fields__stack">
				<label
					for="cn-job-form-synchronization"
					class="cn-job-form-fields__label">
					{{ t('integriq', 'Synchronization') }} *
				</label>
				<NcSelect
					inputId="cn-job-form-synchronization"
					:aria-label-combobox="t('integriq', 'Synchronization')"
					:modelValue="selectedSynchronization"
					:options="synchronizationOptions"
					:loading="synchronizationsLoading"
					:clearable="false"
					:placeholder="t('integriq', 'Select a synchronization')"
					@update:modelValue="onSynchronizationPick" />
				<CnFieldHelper
					:text="
						t(
							'integriq',
							'The synchronization this job will run. Written back as arguments.synchronizationId.',
						)
					" />
			</div>
		</template>
	</div>
</template>

<script>
import { CnFieldHelper } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
// Imported rather than leaning on the `t` global: main.js only puts it on
// `app.config.globalProperties`, which reaches templates and `this`, not the
// module scope `jobClassLabel()` below lives in.
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcCheckboxRadioSwitch,
	NcDateTimePickerNative,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import {
	coerceNumber,
	dateValueFromStored,
	formatDateValue,
	groupFieldRuns,
	readSynchronizationId,
	SYNCHRONIZATION_ACTION_CLASS,
	writeSynchronizationId,
} from './jobDraft.js'

/**
 * Display labels for the Action classes the manifest offers, keyed by FQN.
 *
 * The manifest owns WHICH classes exist (`fieldOverrides.jobClass.enum`, kept
 * in step with lib/Action/ by tests/vitest/jobDraft.spec.js); this map owns the
 * human text. The split exists for one reason: manifest values are not `t()`
 * literals, so tests/l10n/check-l10n.js cannot see them and they would be
 * permanently untranslatable. A class with no entry here falls back to its FQN,
 * which is ugly but never wrong — and the spec test fails, so it gets noticed.
 *
 * @param {string} fqn Fully-qualified Action class name.
 *
 * @return {string} The translated label, or the FQN when unmapped.
 *
 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
 */
function jobClassLabel(fqn) {
	const labels = {
		'OCA\\Integriq\\Action\\SynchronizationAction': t(
			'integriq',
			'Run a synchronization',
		),
		'OCA\\Integriq\\Action\\FlowAction': t('integriq', 'Run a flow'),
		'OCA\\Integriq\\Action\\EventAction': t('integriq', 'Dispatch an event'),
		'OCA\\Integriq\\Action\\PingAction': t('integriq', 'Ping a source'),
	}
	return labels[fqn] || fqn
}

export default {
	name: 'JobFormFields',

	components: {
		CnFieldHelper,
		NcCheckboxRadioSwitch,
		NcDateTimePickerNative,
		NcSelect,
		NcTextField,
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
			synchronizationOptions: [],
			synchronizationsLoading: false,
			/**
			 * Per-key json editor drafts. Stored separately from formData so the
			 * textarea can hold invalid intermediate strings without clobbering
			 * the parsed value on every keystroke (the pattern CnFormDialog uses
			 * internally).
			 */
			jsonDrafts: {},
			jsonErrors: {},
		}
	},

	computed: {
		/**
		 * The field list as render runs — consecutive same-`group` fields
		 * coalesced so they can share a grid. See groupFieldRuns().
		 *
		 * @return {Array<object>} Render runs in declared order.
		 *
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		fieldRuns() {
			return groupFieldRuns(this.fields)
		},

		/**
		 * Whether the Synchronization picker applies to the current draft.
		 *
		 * @return {boolean} True when the chosen class is SynchronizationAction.
		 *
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		needsSynchronizationPicker() {
			return this.formData?.jobClass === SYNCHRONIZATION_ACTION_CLASS
		},

		/**
		 * The picked synchronization as an option object. An id with no matching
		 * option (options still loading, or a synchronization since deleted)
		 * yields a constructed option so the value shows rather than reading as
		 * unset.
		 *
		 * @return {object|null} The selected option, or null when none.
		 *
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		selectedSynchronization() {
			const id = readSynchronizationId(this.formData?.arguments)
			if (id === null) return null
			return (
				this.synchronizationOptions.find((option) => option.id === id) ?? {
					id,
					label: id,
				}
			)
		},
	},

	watch: {
		needsSynchronizationPicker: {
			immediate: true,
			/**
			 * @param value
			 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
			 */
			handler(value) {
				if (value && this.synchronizationOptions.length === 0) {
					this.fetchSynchronizations()
				}
			},
		},
	},

	/**
	 * Seeds the enum-option cache before the first render.
	 *
	 * @return {void}
	 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
	 */
	created() {
		/**
		 * Enum option cache, held off `data` deliberately: Vue's reactive proxy
		 * would wrap each option object and break the identity match NcSelect
		 * (vue-select) does between the model and the options array. Returning a
		 * fresh option object per render is the documented failure where the
		 * chip renders but the value never commits. CnFormDialog keeps its own
		 * `_enumOptionCache` off `data` for the same reason.
		 */
		this._enumOptionCache = {}
	},

	methods: {
		coerceNumber,

		/**
		 * Options for a `select` field, cached per key so identical renders
		 * reuse the same option object references. Invalidated when the
		 * declared enum itself changes.
		 *
		 * @param {object} field The field descriptor.
		 *
		 * @return {Array<{id: *, label: string}>} The option list.
		 *
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		enumOptions(field) {
			const cached = this._enumOptionCache[field.key]
			if (cached !== undefined && cached.enum === field.enum) {
				return cached.options
			}
			const options = (Array.isArray(field.enum) ? field.enum : []).map(
				(value) => ({ id: value, label: this.optionLabel(field, value) }),
			)
			this._enumOptionCache[field.key] = { enum: field.enum, options }
			return options
		},

		/**
		 * Human label for one enum value. Only `jobClass` has a curated label
		 * map; any other select renders its raw value, which is correct for the
		 * short lowercase enums the rest of the schema uses.
		 *
		 * @param {object} field The field descriptor.
		 * @param {*}      value The enum member.
		 *
		 * @return {string} The label to display.
		 *
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		optionLabel(field, value) {
			return field.key === 'jobClass' ? jobClassLabel(value) : String(value)
		},

		/**
		 * The selected option for a `select` field — the SAME object instance
		 * from enumOptions() when the stored value is a listed one, so NcSelect's
		 * identity match succeeds. An off-list value still gets an option: jobs
		 * seeded with a non-existent Example*Job class, or a job pointing at an
		 * Action class registered by another app, must display what they hold
		 * instead of appearing unset and being silently overwritten on save.
		 *
		 * @param {object} field The field descriptor.
		 *
		 * @return {object|null} The selected option, or null when unset.
		 *
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		selectedEnumOption(field) {
			const value = this.formData[field.key]
			if (value === null || value === undefined || value === '') return null
			const match = this.enumOptions(field).find(
				(option) => option.id === value,
			)
			return match ?? { id: value, label: this.optionLabel(field, value) }
		},

		/**
		 * Whether a render run contains the class picker — the anchor for the
		 * Synchronization field.
		 *
		 * @param {object} run A render run from fieldRuns.
		 *
		 * @return {boolean} True when the run holds the `jobClass` field.
		 *
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		runHasJobClass(run) {
			return run.fields.some((field) => field.key === 'jobClass')
		},

		/**
		 * Stored value → Date for the native picker.
		 *
		 * @param {object} field The field descriptor.
		 *
		 * @return {Date|null} The parsed date, or null.
		 *
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		dateValueFor(field) {
			return dateValueFromStored(this.formData[field.key])
		},

		/**
		 * Picked Date → stored string. Clearing writes null rather than '',
		 * matching what CnFormDialog's buildSubmitPayload would coerce an empty
		 * string to for any field carrying a `format`.
		 *
		 * @param {object}    field The field descriptor.
		 * @param {Date|null} date  The date emitted by the picker.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		onDateFieldInput(field, date) {
			if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
				this.updateField(field.key, null)
				return
			}
			this.updateField(field.key, formatDateValue(field.widget, date))
		},

		/**
		 * Narrow the widget to a `type` NcTextField's validator accepts. Date
		 * widgets are deliberately absent — they route to the native picker, and
		 * returning 'date'/'datetime-local' here is what made `scheduleAfter`
		 * unrenderable.
		 *
		 * @param {object} field The field descriptor.
		 *
		 * @return {string} A valid NcTextField type.
		 *
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		textFieldType(field) {
			if (field.widget === 'email') return 'email'
			if (field.widget === 'url') return 'url'
			return 'text'
		},

		/**
		 * Write the picked synchronization into the nested argument key.
		 *
		 * @param {object|null} option The chosen option.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		onSynchronizationPick(option) {
			this.updateField(
				'arguments',
				writeSynchronizationId(this.formData?.arguments, option?.id ?? null),
			)
		},

		/**
		 * Load the synchronization options from OpenRegister's object API.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		async fetchSynchronizations() {
			this.synchronizationsLoading = true
			try {
				const response = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/openconnector/synchronization',
					),
					// `_limit`, not `limit` — an unprefixed param is a PROPERTY
					// FILTER in OpenRegister and silently returns `total: 0`
					// under HTTP 200. See FlowDetailPage.fetchPickerOptions().
					{ params: { _limit: 500 } },
				)
				const data = response.data
				const list = Array.isArray(data?.results)
					? data.results
					: Array.isArray(data)
						? data
						: []
				this.synchronizationOptions = list.map((sync) => ({
					id: String(sync.id || sync.uuid),
					label: sync.name || sync.title || sync.id,
				}))
			} catch (err) {
				// Silent fallback — the picker stays empty and the stored id is
				// still displayed by selectedSynchronization, so an existing job
				// is never silently blanked by a failed fetch.
				// eslint-disable-next-line no-console
				console.warn('[JobFormFields] synchronization fetch failed', err)
				this.synchronizationOptions = []
			} finally {
				this.synchronizationsLoading = false
			}
		},

		/**
		 * The textarea contents for a json field — the in-progress draft when
		 * there is one, else the pretty-printed stored value.
		 *
		 * @param {object} field The field descriptor.
		 *
		 * @return {string} The text to show.
		 *
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
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
		 * Parse a json field's edited text, keeping the raw draft so a
		 * half-typed value is not lost and does not clobber formData.
		 *
		 * @param {object} field The field descriptor.
		 * @param {string} raw   The textarea contents.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/endpoint-job-editor-ui/spec.md
		 */
		onJsonInput(field, raw) {
			this.jsonDrafts[field.key] = raw
			const trimmed = raw.trim()
			if (trimmed.length === 0) {
				this.jsonErrors[field.key] = ''
				this.updateField(field.key, null)
				return
			}
			try {
				const parsed = JSON.parse(trimmed)
				this.jsonErrors[field.key] = ''
				this.updateField(field.key, parsed)
			} catch (parseErr) {
				this.jsonErrors[field.key] = t(
					'integriq',
					'Invalid JSON: {message}',
					{ message: parseErr.message },
				)
			}
		},
	},
}
</script>

<style scoped>
.cn-job-form-fields {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

/*
 * Transparent to layout, so an ungrouped run's field remains a direct child of
 * the flex column above and inherits its gap. Without this, every ungrouped
 * field would need its own duplicate of the widget switch.
 */
.cn-job-form-fields__run {
	display: contents;
}

/*
 * Grouped fields sit two-up — four scheduling switches in two rows. minmax(0,
 * 1fr) rather than 1fr because NcSelect's min-width otherwise forces a track
 * wider than its share (the constraint RuleEditorModal's grid documents).
 */
.cn-job-form-fields__run--grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px;
	align-items: start;
}

.cn-job-form-fields__field {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.cn-job-form-fields__stack {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.cn-job-form-fields__label {
	font-weight: bold;
	margin-bottom: 4px;
}

.cn-job-form-fields__textarea {
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

.cn-job-form-fields__textarea--code {
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
}

/* A narrow dialog cannot hold two usable columns — stack instead of squeeze. */
@media (max-width: 768px) {
	.cn-job-form-fields__run--grid {
		grid-template-columns: minmax(0, 1fr);
	}
}
</style>

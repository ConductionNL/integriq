<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  JobFormFields — slotted override for CnFormDialog's `#form` content on
  the Jobs index page. Mirrors the lib's auto-widget switch but adds one
  conditional-visibility behaviour the lib does not yet support natively:

    When `formData.jobClass === 'OCA\\OpenConnector\\Action\\SynchronizationAction'`,
    the `arguments` field is replaced with a Synchronization picker
    (NcSelect populated from OR's `/api/objects/openconnector/synchronization`).
    The picked value is written back as `arguments.synchronizationId`,
    matching what `SynchronizationAction::run()` reads at job-execution
    time (lib/Action/SynchronizationAction.php).

  Closes #847. Tracked upstream as a request for CnFormDialog
  `condition`/`visibleWhen` per-field gating — once that lands, this
  component can collapse to a thin wrapper that just configures the
  picker via fieldOverrides (or be deleted entirely).

  The component is mounted by CnPageRenderer when the manifest declares
  `pages[].slots = { 'form-fields': 'JobFormFields' }`; the v-bind path
  through CnFormDialog → CnIndexPage's `#form-fields` → CnPageRenderer's
  `<component :is v-bind=slotProps>` arrives here as plain props.
-->
<template>
	<div class="cn-job-form-fields">
		<div
			v-for="field in fields"
			:key="field.key"
			class="cn-job-form-fields__field">
			<!-- Custom Synchronization picker for the `arguments` field
			     when jobClass is SynchronizationAction. Skipped when the
			     schema doesn't declare an `arguments` field — the picker
			     is then injected as a standalone block below the field
			     list (see after-loop section). -->
			<template v-if="field.key === 'arguments' && isSynchronizationJob">
				<label :for="'cn-job-form-' + field.key" class="cn-job-form-fields__label">
					{{ t('openconnector', 'Synchronization') }}{{ field.required ? ' *' : '' }}
				</label>
				<NcSelect
					:input-id="'cn-job-form-' + field.key"
					:aria-label-combobox="t('openconnector', 'Synchronization')"
					:model-value="selectedSynchronization"
					:options="synchronizationOptions"
					:loading="synchronizationsLoading"
					:clearable="!field.required"
					:placeholder="t('openconnector', 'Select a synchronization')"
					@update:model-value="onSynchronizationPick" />
				<span class="cn-job-form-fields__helper">
					{{ t('openconnector', 'The synchronization this job will run. Written back as arguments.synchronizationId.') }}
				</span>
			</template>

			<!-- Default widget rendering — mirrors CnFormDialog's switch
			     for the handful of widget types Job schema actually uses
			     (text, textarea, number, checkbox, json). Anything else
			     falls through to a text input. -->
			<template v-else>
				<!-- jobClass select — must precede the widget-based branches
				     because the schema declares jobClass with widget='text'
				     (the default), so an order-by-widget check would
				     short-circuit to NcTextField and the conditional
				     Synchronization picker would never engage. -->
				<div v-if="field.key === 'jobClass'" class="cn-job-form-fields__select-wrapper">
					<label :for="'cn-job-form-' + field.key" class="cn-job-form-fields__label">
						{{ field.label }}{{ field.required ? ' *' : '' }}
					</label>
					<NcSelect
						:input-id="'cn-job-form-' + field.key"
						:aria-label-combobox="field.label || t('openconnector', 'Action class')"
						:model-value="selectedJobClassOption"
						:options="jobClassOptions"
						:clearable="!field.required"
						:placeholder="t('openconnector', 'Pick an action class')"
						@update:model-value="onJobClassPick" />
					<span class="cn-job-form-fields__helper">
						{{ field.description || t('openconnector', 'The PHP class that runs when this job fires.') }}
					</span>
				</div>

				<NcTextField
					v-else-if="field.widget === 'text' || field.widget === 'email' || field.widget === 'url' || field.widget === 'date' || field.widget === 'datetime'"
					:label="field.label + (field.required ? ' *' : '')"
					:model-value="formData[field.key] != null ? String(formData[field.key]) : ''"
					:helper-text="errors[field.key] || field.description"
					:error="!!errors[field.key]"
					:type="textFieldType(field)"
					:disabled="field.readOnly"
					:placeholder="field.description"
					@update:model-value="(value) => updateField(field.key, value)" />

				<NcTextField
					v-else-if="field.widget === 'number'"
					:label="field.label + (field.required ? ' *' : '')"
					:model-value="formData[field.key] != null ? String(formData[field.key]) : ''"
					:helper-text="errors[field.key] || field.description"
					:error="!!errors[field.key]"
					type="number"
					:disabled="field.readOnly"
					:placeholder="field.description"
					@update:model-value="(value) => updateField(field.key, value !== '' ? Number(value) : null)" />

				<div v-else-if="field.widget === 'textarea'" class="cn-job-form-fields__textarea-wrapper">
					<label :for="'cn-job-form-' + field.key" class="cn-job-form-fields__label">
						{{ field.label }}{{ field.required ? ' *' : '' }}
					</label>
					<textarea
						:id="'cn-job-form-' + field.key"
						class="cn-job-form-fields__textarea"
						:value="formData[field.key] || ''"
						:disabled="field.readOnly"
						:placeholder="field.description"
						rows="3"
						@input="updateField(field.key, $event.target.value)" />
					<span class="cn-job-form-fields__helper" :class="{ 'cn-job-form-fields__helper--error': errors[field.key] }">
						{{ errors[field.key] || field.description }}
					</span>
				</div>

				<NcCheckboxRadioSwitch
					v-else-if="field.widget === 'checkbox'"
					:model-value="!!formData[field.key]"
					:disabled="field.readOnly"
					type="switch"
					@update:model-value="(value) => updateField(field.key, value)">
					{{ field.label }}{{ field.required ? ' *' : '' }}
				</NcCheckboxRadioSwitch>

				<!-- JSON / object editor for arguments when NOT a sync job. -->
				<div v-else-if="field.widget === 'json' || (field.key === 'arguments' && !isSynchronizationJob)" class="cn-job-form-fields__textarea-wrapper">
					<label :for="'cn-job-form-' + field.key" class="cn-job-form-fields__label">
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
					<span class="cn-job-form-fields__helper" :class="{ 'cn-job-form-fields__helper--error': jsonErrors[field.key] || errors[field.key] }">
						{{ jsonErrors[field.key] || errors[field.key] || field.description }}
					</span>
				</div>

				<!-- Fallback: text input -->
				<NcTextField
					v-else
					:label="field.label + (field.required ? ' *' : '')"
					:model-value="formData[field.key] != null ? String(formData[field.key]) : ''"
					:helper-text="errors[field.key] || field.description"
					:error="!!errors[field.key]"
					:disabled="field.readOnly"
					:placeholder="field.description"
					@update:model-value="(value) => updateField(field.key, value)" />
			</template>
		</div>

		<!-- Standalone Synchronization picker — rendered when jobClass is
		     SynchronizationAction AND the schema does not declare an
		     `arguments` field (the in-loop branch above handles the case
		     where it does). Keeps the conditional UX working regardless of
		     whether the Job schema gets an explicit arguments field in OR. -->
		<div
			v-if="isSynchronizationJob && !hasArgumentsField"
			class="cn-job-form-fields__field">
			<label for="cn-job-form-arguments" class="cn-job-form-fields__label">
				{{ t('openconnector', 'Synchronization') }} *
			</label>
			<NcSelect
				input-id="cn-job-form-arguments"
				:aria-label-combobox="t('openconnector', 'Synchronization')"
				:model-value="selectedSynchronization"
				:options="synchronizationOptions"
				:loading="synchronizationsLoading"
				:clearable="false"
				:placeholder="t('openconnector', 'Select a synchronization')"
				@update:model-value="onSynchronizationPick" />
			<span class="cn-job-form-fields__helper">
				{{ t('openconnector', 'The synchronization this job will run. Written back as arguments.synchronizationId.') }}
			</span>
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

/**
 * Canonical FQN of the built-in synchronization-runner action. Lives in
 * lib/Action/SynchronizationAction.php; the form-side check has to use
 * the exact same string the backend resolves with `containerInterface->get($jobClass)`.
 */
const SYNCHRONIZATION_ACTION_CLASS = 'OCA\\OpenConnector\\Action\\SynchronizationAction'

/**
 * Built-in Action classes the user can pick. Listing them explicitly
 * (vs a free-text input) prevents typos and lets the form react to the
 * selection — notably swapping the `arguments` field for a
 * Synchronization picker.
 */
const JOB_CLASS_OPTIONS = [
	{
		id: SYNCHRONIZATION_ACTION_CLASS,
		label: 'SynchronizationAction',
	},
	{
		id: 'OCA\\OpenConnector\\Action\\EventAction',
		label: 'EventAction',
	},
	{
		id: 'OCA\\OpenConnector\\Action\\PingAction',
		label: 'PingAction',
	},
]

export default {
	name: 'JobFormFields',

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
			synchronizationOptions: [],
			synchronizationsLoading: false,
			/**
			 * Per-key json editor drafts. Stored separately from formData
			 * so the textarea can hold invalid intermediate strings
			 * without clobbering the parsed value on every keystroke
			 * (same pattern CnFormDialog uses internally).
			 */
			jsonDrafts: {},
			jsonErrors: {},
		}
	},

	computed: {
		isSynchronizationJob() {
			return this.formData?.jobClass === SYNCHRONIZATION_ACTION_CLASS
		},
		/** @spec openspec/specs/endpoint-job-editor-ui/spec.md */
		hasArgumentsField() {
			// True when the schema-derived field list includes an `arguments`
			// field — drives whether the Synchronization picker renders
			// in-loop (overlaying the arguments field) or as a standalone
			// block after the loop. See template for the wire-up.
			return Array.isArray(this.fields) && this.fields.some((f) => f.key === 'arguments')
		},
		/** @spec openspec/specs/endpoint-job-editor-ui/spec.md */
		jobClassOptions() {
			return JOB_CLASS_OPTIONS
		},
		/** @spec openspec/specs/endpoint-job-editor-ui/spec.md */
		selectedJobClassOption() {
			const current = this.formData?.jobClass
			if (!current) return null
			return this.jobClassOptions.find((option) => option.id === current) ?? {
				id: current,
				label: current,
			}
		},
		/** @spec openspec/specs/endpoint-job-editor-ui/spec.md */
		selectedSynchronization() {
			const args = this.formData?.arguments
			const id = args && typeof args === 'object' ? args.synchronizationId : null
			if (!id) return null
			return this.synchronizationOptions.find((option) => option.id === String(id)) ?? {
				id: String(id),
				label: String(id),
			}
		},
	},

	watch: {
		isSynchronizationJob: {
			immediate: true,
			/** @spec openspec/specs/endpoint-job-editor-ui/spec.md */
			handler(value) {
				if (value && this.synchronizationOptions.length === 0) {
					this.fetchSynchronizations()
				}
			},
		},
	},

	methods: {
		/** @spec openspec/specs/endpoint-job-editor-ui/spec.md */
		textFieldType(field) {
			if (field.widget === 'email') return 'email'
			if (field.widget === 'url') return 'url'
			if (field.widget === 'date') return 'date'
			if (field.widget === 'datetime') return 'datetime-local'
			return 'text'
		},

		/** @spec openspec/specs/endpoint-job-editor-ui/spec.md */
		onJobClassPick(option) {
			this.updateField('jobClass', option?.id ?? null)
		},

		/** @spec openspec/specs/endpoint-job-editor-ui/spec.md */
		onSynchronizationPick(option) {
			const current = this.formData?.arguments
			const next = (current && typeof current === 'object' && !Array.isArray(current))
				? { ...current }
				: {}
			if (option?.id) {
				next.synchronizationId = String(option.id)
			} else {
				delete next.synchronizationId
			}
			this.updateField('arguments', next)
		},

		/** @spec openspec/specs/endpoint-job-editor-ui/spec.md */
		async fetchSynchronizations() {
			this.synchronizationsLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/objects/openconnector/synchronization'),
					{ params: { limit: 500 } },
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
				// Silent fallback — picker stays empty, the user can still
				// type the id by toggling jobClass away and back to plain
				// JSON editor.
				// eslint-disable-next-line no-console
				console.warn('[JobFormFields] synchronization fetch failed', err)
				this.synchronizationOptions = []
			} finally {
				this.synchronizationsLoading = false
			}
		},

		/** @spec openspec/specs/endpoint-job-editor-ui/spec.md */
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

		/** @spec openspec/specs/endpoint-job-editor-ui/spec.md */
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
				this.jsonErrors[field.key] = t('openconnector', 'Invalid JSON: {message}', { message: parseErr.message })
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

.cn-job-form-fields__field {
	display: flex;
	flex-direction: column;
}

.cn-job-form-fields__label {
	font-weight: bold;
	margin-bottom: 4px;
}

.cn-job-form-fields__textarea-wrapper,
.cn-job-form-fields__select-wrapper {
	display: flex;
	flex-direction: column;
	gap: 4px;
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

.cn-job-form-fields__helper {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.cn-job-form-fields__helper--error {
	color: var(--color-error);
}
</style>

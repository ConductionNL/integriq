<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  MappingResultPanel — the shared "Output" surface for a mapping test.

  One component owns everything downstream of `POST /api/mappings/test`:
  the optional validation-schema picker, the run itself (debounced live or
  explicit), the valid/invalid indicator, the per-field validation-error
  table, the pretty-printed result, and persisting that result into an
  OpenRegister register.

  Three hosts share it, which is the point — before this component the
  save-to-register capability existed only in the orphaned
  `src/modals/MappingTest/components/TestMappingResult.vue`, which had been
  imported by nothing since the Pinia cutover and is now deleted, so
  REQ-MAPUI-005 had no live implementation at all:

    - MappingEditorModal   — Output column, live preview + footer Test button
    - MappingDetailPage    — live preview pane
    - TestMappingModal     — result pane, explicit run only (`:auto="false"`)

  Input handling: `inputObject` is accepted as either a raw JSON string
  (what a textarea gives you) or an already-parsed object. Parsing happens
  here so there is exactly one implementation of it; the parse message is
  emitted as `input-error` for the host to render next to its own textarea
  rather than duplicated inside this panel.

  @spec openspec/specs/mapping-editor-ui/spec.md
-->
<template>
	<div class="cn-mapping-result">
		<!-- Validation schema picker -->
		<div v-if="showSchemaPicker" class="cn-mapping-result__field">
			<label class="cn-mapping-result__label" :for="schemaInputId">
				{{ t('openconnector', 'Validation schema (optional)') }}
			</label>
			<NcSelect v-model="selectedSchema"
				:aria-label-combobox="t('openconnector', 'Validation schema (optional)')"
				:options="schemaOptions"
				:loading="schemasLoading"
				:placeholder="t('openconnector', 'No validation')"
				:clearable="true"
				:input-id="schemaInputId" />
		</div>

		<!-- Run status -->
		<div class="cn-mapping-result__status">
			<span class="cn-mapping-result__label">{{ t('openconnector', 'Output') }}</span>
			<NcLoadingIcon v-if="running" :size="16" />
		</div>

		<NcNoteCard v-if="runError" type="error">
			<p>{{ runError }}</p>
		</NcNoteCard>

		<!-- Validation outcome -->
		<template v-if="hasResult && selectedSchema">
			<p v-if="isValid" class="cn-mapping-result__valid">
				<CheckCircleIcon :size="18" />
				{{ t('openconnector', 'Result is valid against the selected schema.') }}
			</p>
			<p v-else class="cn-mapping-result__invalid">
				<CloseCircleIcon :size="18" />
				{{ t('openconnector', 'Result is not valid against the selected schema.') }}
			</p>
		</template>

		<div v-if="validationErrorRows.length" class="cn-mapping-result__errors">
			<table>
				<thead>
					<tr>
						<th scope="col">{{ t('openconnector', 'Field') }}</th>
						<th scope="col">{{ t('openconnector', 'Errors') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in validationErrorRows" :key="row.field">
						<td>{{ row.field }}</td>
						<td>
							<ul>
								<li v-for="(message, index) in row.messages" :key="index">
									{{ message }}
								</li>
							</ul>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Result -->
		<pre v-if="hasResult" class="cn-mapping-result__pre"><!--
			-->{{ formattedResult }}</pre>
		<p v-else-if="!runError" class="cn-mapping-result__empty">
			{{ emptyText }}
		</p>

		<!-- Persist the transformed result as an OpenRegister object -->
		<div v-if="showSaveBlock" class="cn-mapping-result__save">
			<h4 class="cn-mapping-result__save-title">
				{{ t('openconnector', 'Save result as object') }}
			</h4>

			<NcSelect v-model="selectedRegister"
				:aria-label-combobox="t('openconnector', 'Register')"
				:options="registerOptions"
				:loading="registersLoading"
				:placeholder="t('openconnector', 'Select a register')"
				:clearable="true"
				:input-id="registerInputId">
				<template #option="{ label, description }">
					<div class="cn-mapping-result__register-option">
						<DatabaseOutlineIcon :size="22" />
						<span>
							<strong>{{ label }}</strong>
							<small>{{ description || t('openconnector', 'No description') }}</small>
						</span>
					</div>
				</template>
			</NcSelect>

			<!-- Only once a register is picked: until then the disabled button
			     is not something the user is reaching for, and the hint is
			     just noise in the column. -->
			<p v-if="selectedRegister && !selectedSchema" class="cn-mapping-result__hint">
				{{ t('openconnector', 'Pick a validation schema above — the result is stored against that schema.') }}
			</p>

			<NcButton type="primary"
				:disabled="!canSaveResult"
				@click="saveResult">
				<template #icon>
					<NcLoadingIcon v-if="savingResult" :size="20" />
					<ContentSaveOutlineIcon v-else :size="20" />
				</template>
				{{ t('openconnector', 'Save to register') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import {
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircleIcon from 'vue-material-design-icons/CloseCircle.vue'
import ContentSaveOutlineIcon from 'vue-material-design-icons/ContentSaveOutline.vue'
import DatabaseOutlineIcon from 'vue-material-design-icons/DatabaseOutline.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import debounce from 'lodash/debounce.js'

/**
 * Per-instance suffix for the input ids. Two panels can be on screen at
 * once — the detail page keeps its preview mounted while TestMappingModal
 * opens on top of it — and duplicate ids would break the label bindings.
 */
let instanceCounter = 0

export default {
	name: 'MappingResultPanel',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		CheckCircleIcon,
		CloseCircleIcon,
		ContentSaveOutlineIcon,
		DatabaseOutlineIcon,
	},

	props: {
		/**
		 * The mapping to test. In the editor modal this is the unsaved draft,
		 * so the backend evaluates exactly what is on screen rather than what
		 * was last persisted.
		 */
		mapping: {
			type: Object,
			default: null,
		},
		/** Test input — a raw JSON string from a textarea, or a parsed object. */
		inputObject: {
			type: [String, Object],
			default: '',
		},
		/** Re-run automatically (debounced) whenever the mapping or input changes. */
		auto: {
			type: Boolean,
			default: true,
		},
		/** Debounce window for the automatic re-run, in milliseconds. */
		debounceMs: {
			type: Number,
			default: 400,
		},
		/** Offer the "save result as object" block. */
		allowSaveToRegister: {
			type: Boolean,
			default: true,
		},
		/** Offer the validation-schema picker. */
		showSchemaPicker: {
			type: Boolean,
			default: true,
		},
	},

	emits: ['input-error'],

	data() {
		return {
			uid: ++instanceCounter,
			schemaOptions: [],
			schemasLoading: false,
			selectedSchema: null,
			registerOptions: [],
			registersLoading: false,
			selectedRegister: null,
			openRegisterAvailable: false,
			running: false,
			runError: '',
			result: null,
			isValid: true,
			validationErrors: null,
			savingResult: false,
		}
	},

	computed: {
		/**
		 * Per-instance id tying the schema picker's `<label for>` to the
		 * NcSelect's own input. Instance-scoped via `uid` because this panel
		 * can be mounted more than once on a page, and duplicate ids would
		 * point every label at the first input (WCAG 1.3.1 / 4.1.2).
		 * @return {string}
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		schemaInputId() {
			return `cn-mapping-result-schema-${this.uid}`
		},
		/**
		 * The same label-association id for the register picker.
		 * @return {string}
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		registerInputId() {
			return `cn-mapping-result-register-${this.uid}`
		},
		hasResult() {
			return this.result !== null
		},
		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		formattedResult() {
			try {
				return JSON.stringify(this.result, null, 2)
			} catch (_e) {
				return String(this.result)
			}
		},
		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		emptyText() {
			return this.auto
				? this.t('openconnector', 'Output appears here once an input object and rules produce a result.')
				: this.t('openconnector', 'Run the test to see the result here.')
		},
		/**
		 * Flatten `validationErrors` into table rows. The backend returns a
		 * `{ field: [message, …] }` map, but a bare string or a single-message
		 * value shows up in older payloads — normalise both.
		 *
		 * @return {Array<{field: string, messages: Array<string>}>} Table rows.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		validationErrorRows() {
			const errors = this.validationErrors
			if (!errors || typeof errors !== 'object') return []
			return Object.keys(errors).map((field) => {
				const raw = errors[field]
				const list = Array.isArray(raw) ? raw : [raw]
				return {
					field,
					messages: list.map((entry) => this.formatValidationError(entry)),
				}
			})
		},
		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		showSaveBlock() {
			return this.allowSaveToRegister
				&& this.openRegisterAvailable
				&& this.hasResult
		},
		/**
		 * A register alone is not enough: `MappingsController::saveObject()`
		 * defaults `schema` to `'mapping'`, so saving without an explicit
		 * schema silently files the transformed payload as a *mapping*
		 * object. Require both, and require the result to have validated.
		 *
		 * @return {boolean} Whether the save button is enabled.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		canSaveResult() {
			return !this.savingResult
				&& this.hasResult
				&& this.isValid
				&& !!this.selectedSchema?.id
				&& !!this.selectedRegister?.id
		},
		/**
		 * Reactivity key for the automatic re-run. Recomputing it is what
		 * fires the debounced request, so it must cover every input the
		 * backend call depends on.
		 *
		 * @return {string} Serialised signal.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		runSignal() {
			try {
				return JSON.stringify({
					mapping: this.mapping?.mapping ?? null,
					cast: this.mapping?.cast ?? null,
					unset: this.mapping?.unset ?? null,
					passThrough: !!this.mapping?.passThrough,
					input: this.inputObject,
					schema: this.selectedSchema?.id ?? null,
				})
			} catch (_e) {
				return ''
			}
		},
	},

	watch: {
		runSignal: {
			immediate: false,
			/** @spec openspec/specs/mapping-editor-ui/spec.md */
			handler() {
				if (!this.auto) return
				this.scheduleRun()
			},
		},
	},

	/** @spec openspec/specs/mapping-editor-ui/spec.md */
	created() {
		// Bound on the instance so each panel owns its timer and `.cancel()`
		// works on teardown.
		this.scheduleRun = debounce(this.run, this.debounceMs)
	},

	/** @spec openspec/specs/mapping-editor-ui/spec.md */
	mounted() {
		if (this.showSchemaPicker) this.fetchSchemas()
		if (this.allowSaveToRegister) this.fetchRegisters()
		if (this.auto) this.scheduleRun()
	},

	/** @spec openspec/specs/mapping-editor-ui/spec.md */
	beforeUnmount() {
		this.scheduleRun?.cancel?.()
	},

	methods: {
		/**
		 * Parse `inputObject` into the payload the backend expects, emitting
		 * the parse failure for the host to render beside its textarea.
		 *
		 * @return {object|null} Parsed input, or `null` when unparseable.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		parseInput() {
			const raw = this.inputObject
			if (raw && typeof raw === 'object') {
				this.$emit('input-error', '')
				return raw
			}
			const text = String(raw ?? '').trim()
			if (text.length === 0) {
				this.$emit('input-error', '')
				return {}
			}
			try {
				const parsed = JSON.parse(text)
				this.$emit('input-error', '')
				return parsed
			} catch (parseErr) {
				this.$emit('input-error', this.t(
					'openconnector',
					'Input is not valid JSON: {message}',
					{ message: parseErr.message },
				))
				return null
			}
		},

		/**
		 * Execute the mapping test immediately, bypassing the debounce. This
		 * is the method hosts call through a `ref` for their explicit "Test"
		 * button.
		 *
		 * @return {Promise<void>} Resolves once the run has settled.
		 *
		 * @public
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		async run() {
			if (!this.mapping) return
			const parsedInput = this.parseInput()
			if (parsedInput === null) return

			this.running = true
			this.runError = ''
			try {
				const payload = {
					inputObject: parsedInput,
					mapping: this.mapping,
				}
				if (this.selectedSchema?.id) {
					payload.schema = this.selectedSchema.id
					payload.validation = true
				}
				const response = await axios.post(
					generateUrl('/apps/openconnector/api/mappings/test'),
					payload,
				)
				this.result = response.data?.resultObject ?? response.data ?? null
				this.isValid = response.data?.isValid !== false
				this.validationErrors = response.data?.validationErrors ?? null
			} catch (err) {
				const status = err?.response?.status
				const message = err?.response?.data?.message
					|| err?.response?.data?.error
					|| err?.message
					|| ''
				this.runError = this.t('openconnector', 'Mapping test failed')
					+ (status ? ` (${status})` : '')
					+ (message ? `: ${message}` : '')
			} finally {
				this.running = false
			}
		},

		/**
		 * Clear the result surface and cancel any pending automatic run.
		 *
		 * @public
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		reset() {
			this.scheduleRun?.cancel?.()
			this.running = false
			this.runError = ''
			this.result = null
			this.isValid = true
			this.validationErrors = null
		},

		/**
		 * Load the schema options for the validation picker. A failure is
		 * non-fatal — the picker stays empty and the mapping test still runs
		 * without validation.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		async fetchSchemas() {
			this.schemasLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/schemas'),
				)
				const list = Array.isArray(response.data?.results)
					? response.data.results
					: Array.isArray(response.data)
						? response.data
						: []
				this.schemaOptions = list.map((schema) => ({
					id: schema.id || schema.uuid,
					label: schema.title || schema.name || schema.slug,
				}))
			} catch (err) {
				// eslint-disable-next-line no-console
				console.warn('[MappingResultPanel] schema fetch failed', err)
				this.schemaOptions = []
			} finally {
				this.schemasLoading = false
			}
		},

		/**
		 * Load the registers the result can be saved into. The endpoint also
		 * reports whether OpenRegister is installed at all; when it is not,
		 * the whole save block stays hidden.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		async fetchRegisters() {
			this.registersLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openconnector/api/mappings/objects'),
				)
				this.openRegisterAvailable = response.data?.openRegisters === true
				const list = Array.isArray(response.data?.availableRegisters)
					? response.data.availableRegisters
					: []
				this.registerOptions = list.map((register) => ({
					id: register.id,
					label: register.title || register.name || register.slug,
					description: register.description || '',
				}))
			} catch (err) {
				// eslint-disable-next-line no-console
				console.warn('[MappingResultPanel] register fetch failed', err)
				this.openRegisterAvailable = false
				this.registerOptions = []
			} finally {
				this.registersLoading = false
			}
		},

		/**
		 * Persist the transformed result as an OpenRegister object.
		 *
		 * @return {Promise<void>} Resolves once the save has settled.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		async saveResult() {
			if (!this.canSaveResult) return
			this.savingResult = true
			try {
				await axios.post(
					generateUrl('/apps/openconnector/api/mappings/objects'),
					{
						object: this.result,
						register: this.selectedRegister.id,
						schema: this.selectedSchema.id,
					},
				)
				showSuccess(this.t('openconnector', 'Result saved as object'))
			} catch (err) {
				const message = err?.response?.data?.error
					|| err?.response?.data?.message
					|| err?.message
					|| ''
				showError(this.t('openconnector', 'Failed to save result')
					+ (message ? `: ${message}` : ''))
			} finally {
				this.savingResult = false
			}
		},

		/**
		 * Render one validation error entry as display text.
		 *
		 * @param {string|object} entry Error as returned by the backend.
		 * @return {string} Human-readable message.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		formatValidationError(entry) {
			if (typeof entry === 'string') return entry
			return entry?.message || entry?.error || JSON.stringify(entry)
		},
	},
}
</script>

<style scoped>
.cn-mapping-result {
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-width: 0;
	/* Fill the host's pane so the result block below can claim the slack
	   instead of leaving dead space under a short payload. */
	flex: 1;
}

.cn-mapping-result__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.cn-mapping-result__label {
	font-weight: 500;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.cn-mapping-result__status {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-top: 8px;
}

.cn-mapping-result__hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.cn-mapping-result__valid,
.cn-mapping-result__invalid {
	display: flex;
	align-items: center;
	gap: 6px;
	margin: 0;
	font-size: 13px;
}

.cn-mapping-result__valid {
	color: var(--color-success);
}

.cn-mapping-result__invalid {
	color: var(--color-error);
}

.cn-mapping-result__errors {
	overflow-x: auto;
	width: 100%;
}

.cn-mapping-result__errors table {
	border-collapse: collapse;
	width: 100%;
}

.cn-mapping-result__errors th,
.cn-mapping-result__errors td {
	border: 1px solid var(--color-border);
	padding: 6px 8px;
	text-align: start;
	vertical-align: top;
	font-size: 12px;
}

.cn-mapping-result__errors ul {
	margin: 0;
	padding-inline-start: 16px;
}

.cn-mapping-result__pre,
.cn-mapping-result__empty {
	/* The result is the point of this pane — let it take the free height
	   rather than sitting as a thin strip above the save block. */
	flex: 1;
	min-height: 120px;
	max-height: 360px;
	margin: 0;
}

.cn-mapping-result__pre {
	background: var(--color-background-dark);
	color: var(--color-main-text);
	padding: 12px;
	border-radius: var(--border-radius);
	overflow: auto;
	font-family: var(--font-face-monospace, monospace);
	font-size: 12px;
	white-space: pre-wrap;
	word-break: break-word;
}

.cn-mapping-result__empty {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
	font-style: italic;
	font-size: 13px;
}

.cn-mapping-result__save {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 8px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.cn-mapping-result__save-title {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
}

.cn-mapping-result__register-option {
	display: flex;
	align-items: center;
	gap: 10px;
	min-width: 0;
}

.cn-mapping-result__register-option > span {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.cn-mapping-result__register-option small {
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.cn-mapping-result :deep(.v-select) {
	width: 100%;
	min-width: auto;
}
</style>

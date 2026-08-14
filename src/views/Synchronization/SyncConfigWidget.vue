<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  SyncConfigWidget — type-specific config blob editor for one side
  (source or target) of a Synchronization.

  Conditional rendering mirrors the legacy modal: the `type` discriminator
  (api / register/schema / file) decides which set of fields is shown,
  swapping the blob shape underneath. This is the same conditional-
  visibility pattern from `JobFormFields.vue` (#867) — CnFormDialog still
  has no native `visibleWhen` per-field gating, so this stays bespoke.

  Two model channels are exposed (both two-way via `update:`):
    - `update:sourceId`  → polymorphic id string (parent stores into
       `sourceId` or `targetId` depending on `kind`)
    - `update:config`    → the type-specific blob (parent stores into
       `sourceConfig` or `targetConfig`)

  For `register/schema` mode the polymorphic id format is
  `<registerId>/<schemaId>` (matching the legacy split logic). For `api`
  mode it is a stored Source UUID/PK. For `file` mode it is a free-form
  file path/glob (no picker yet — flagged below).

  Open follow-ups:
    - Register/Schema picker loads the full schema list for the picked
      register — large registers may benefit from search/pagination, but
      OR caps at `limit: 500` here which mirrors what JobFormFields does
      for synchronizations.

  #878: file mode gained an NcFilePicker via `@nextcloud/dialogs`'s
  `getFilePickerBuilder()` so users browse the user's Files app rather
  than typing a free-text path. Manual text entry is still supported (the
  field stays editable) — the picker is additive, not exclusive.
-->

<template>
	<div class="sync-config">
		<!-- API mode -->
		<template v-if="type === 'api'">
			<div class="sync-config__field">
				<label :for="apiSourceId" class="sync-config__label">
					{{ kindLabel }} {{ t('openconnector', 'source (API)') }}
				</label>
				<NcSelect
					:inputId="apiSourceId"
					:aria-label-combobox="t('openconnector', 'Source (API)')"
					:modelValue="selectedSource"
					:options="sourceOptions"
					:loading="sourcesLoading"
					:placeholder="t('openconnector', 'Pick a configured source')"
					@update:modelValue="onSourcePick" />
				<span class="sync-config__helper">
					{{
						t(
							'openconnector',
							'The Source record that defines the API base URL + auth.',
						)
					}}
				</span>
			</div>

			<div class="sync-config__field">
				<NcTextField
					:label="t('openconnector', 'Endpoint')"
					:modelValue="configValue('endpoint')"
					:placeholder="
						t('openconnector', 'Path appended to the source URL')
					"
					@update:modelValue="
						(value) => onConfigUpdate('endpoint', value)
					" />
			</div>

			<div class="sync-config__field">
				<NcTextField
					:label="t('openconnector', 'ID position')"
					:modelValue="configValue('idPosition')"
					:placeholder="
						t(
							'openconnector',
							'Dot-path to the id field in the API response',
						)
					"
					@update:modelValue="
						(value) => onConfigUpdate('idPosition', value)
					" />
			</div>

			<div class="sync-config__field">
				<NcTextField
					:label="t('openconnector', 'Results position')"
					:modelValue="configValue('resultsPosition')"
					:placeholder="
						t('openconnector', 'Dot-path to the list of items')
					"
					@update:modelValue="
						(value) => onConfigUpdate('resultsPosition', value)
					" />
			</div>
		</template>

		<!-- Register/Schema mode -->
		<template v-else-if="type === 'register/schema'">
			<div class="sync-config__field">
				<label :for="registerSelectId" class="sync-config__label">
					{{ t('openconnector', 'Register') }}
				</label>
				<NcSelect
					:inputId="registerSelectId"
					:aria-label-combobox="t('openconnector', 'Register')"
					:modelValue="selectedRegister"
					:options="registerOptions"
					:loading="registersLoading"
					:placeholder="t('openconnector', 'Pick a register')"
					@update:modelValue="onRegisterPick" />
			</div>

			<div class="sync-config__field">
				<label :for="schemaSelectId" class="sync-config__label">
					{{ t('openconnector', 'Schema') }}
				</label>
				<NcSelect
					:inputId="schemaSelectId"
					:aria-label-combobox="t('openconnector', 'Schema')"
					:modelValue="selectedSchema"
					:options="schemaOptions"
					:disabled="!selectedRegister"
					:placeholder="
						t('openconnector', 'Pick a schema in the register')
					"
					@update:modelValue="onSchemaPick" />
			</div>

			<div class="sync-config__field">
				<NcTextField
					:label="t('openconnector', 'Object filter (optional)')"
					:modelValue="configValue('filter')"
					:placeholder="t('openconnector', 'JSON-encoded OR query filter')"
					@update:modelValue="
						(value) => onConfigUpdate('filter', value)
					" />
			</div>
		</template>

		<!-- File mode -->
		<template v-else-if="type === 'file'">
			<div class="sync-config__field">
				<label :for="filePathId" class="sync-config__label">
					{{ t('openconnector', 'File path or glob') }}
				</label>
				<div class="sync-config__file-row">
					<NcTextField
						:inputId="filePathId"
						class="sync-config__file-field"
						:modelValue="sourceIdValue"
						placeholder="/example/path/*.json"
						@update:modelValue="
							(value) => $emit('update:sourceId', value)
						" />
					<NcButton
						variant="secondary"
						:aria-label="t('openconnector', 'Browse Files app')"
						:disabled="pickingFile"
						@click="openFilePicker">
						<template #icon>
							<NcLoadingIcon v-if="pickingFile" :size="18" />
							<FolderOpenOutline v-else :size="18" />
						</template>
						{{ t('openconnector', 'Browse…') }}
					</NcButton>
				</div>
				<span class="sync-config__helper">
					{{
						t(
							'openconnector',
							'Pick a file from your Nextcloud Files app, or type a path/glob for multiple files.',
						)
					}}
				</span>
				<span v-if="pickerError" class="sync-config__error">
					{{ pickerError }}
				</span>
			</div>

			<div class="sync-config__field">
				<NcTextField
					:label="t('openconnector', 'File format')"
					:modelValue="configValue('format')"
					placeholder="json | xml | csv"
					@update:modelValue="
						(value) => onConfigUpdate('format', value)
					" />
			</div>

			<div class="sync-config__field">
				<NcTextField
					:label="t('openconnector', 'ID position')"
					:modelValue="configValue('idPosition')"
					:placeholder="
						t('openconnector', 'Dot-path to the id field in each record')
					"
					@update:modelValue="
						(value) => onConfigUpdate('idPosition', value)
					" />
			</div>
		</template>

		<!-- Nextcloud Table mode -->
		<template v-else-if="type === 'nextcloud-table'">
			<div class="sync-config__field">
				<label :for="tableSourceId" class="sync-config__label">
					{{ kindLabel }}
					{{ t('openconnector', 'source (Nextcloud instance)') }}
				</label>
				<NcSelect
					:inputId="tableSourceId"
					:aria-label-combobox="
						t('openconnector', 'Source (Nextcloud instance)')
					"
					:modelValue="selectedSource"
					:options="sourceOptions"
					:loading="sourcesLoading"
					:inputLabel="t('openconnector', 'Source (Nextcloud instance)')"
					:placeholder="t('openconnector', 'Pick a configured source')"
					@update:modelValue="onSourcePick" />
				<span class="sync-config__helper">
					{{
						t(
							'openconnector',
							'The Source record whose base URL + credential reach the Tables API.',
						)
					}}
				</span>
			</div>

			<div class="sync-config__field">
				<label :for="tablePickerId" class="sync-config__label">
					{{ t('openconnector', 'Table') }}
				</label>
				<NcSelect
					:inputId="tablePickerId"
					:aria-label-combobox="t('openconnector', 'Table')"
					:modelValue="selectedTable"
					:options="tableOptions"
					:loading="tablesLoading"
					:disabled="!sourceIdValue"
					:inputLabel="t('openconnector', 'Table')"
					:placeholder="
						t('openconnector', 'Pick a table the source can access')
					"
					@update:modelValue="onTablePick" />
				<span v-if="tablesError" class="sync-config__error">
					{{ tablesError }}
				</span>
				<span v-else class="sync-config__helper">
					{{
						t(
							'openconnector',
							'Rows are read from (source) or written to (target) this table.',
						)
					}}
				</span>
			</div>

			<!-- Column-mapping helper (target only) -->
			<TablesColumnMapping
				v-if="kind === 'target' && configValue('tableId')"
				:sourceId="sourceIdValue"
				:tableId="configValue('tableId')"
				:config="config"
				@update:config="(value) => $emit('update:config', value)" />
		</template>

		<!-- Nextcloud Form mode (source only — nextcloud-forms-connector REQ-002) -->
		<template v-else-if="type === 'nextcloud-form'">
			<div class="sync-config__field">
				<label :for="formSourceId" class="sync-config__label">
					{{ kindLabel }}
					{{ t('openconnector', 'source (Nextcloud instance)') }}
				</label>
				<NcSelect
					:inputId="formSourceId"
					:aria-label-combobox="
						t('openconnector', 'Source (Nextcloud instance)')
					"
					:modelValue="selectedSource"
					:options="sourceOptions"
					:loading="sourcesLoading"
					:inputLabel="t('openconnector', 'Source (Nextcloud instance)')"
					:placeholder="t('openconnector', 'Pick a configured source')"
					@update:modelValue="onSourcePick" />
				<span class="sync-config__helper">
					{{
						t(
							'openconnector',
							'The Source record whose base URL + credential reach the Forms API.',
						)
					}}
				</span>
			</div>

			<div class="sync-config__field">
				<label :for="formPickerId" class="sync-config__label">
					{{ t('openconnector', 'Form') }}
				</label>
				<NcSelect
					:inputId="formPickerId"
					:aria-label-combobox="t('openconnector', 'Form')"
					:modelValue="selectedForm"
					:options="formOptions"
					:loading="formsLoading"
					:disabled="!sourceIdValue"
					:inputLabel="t('openconnector', 'Form')"
					:placeholder="
						t('openconnector', 'Pick a form the source can access')
					"
					@update:modelValue="onFormPick" />
				<span v-if="formsError" class="sync-config__error">
					{{ formsError }}
				</span>
				<span v-else class="sync-config__helper">
					{{
						t(
							'openconnector',
							'Submissions are read from this form (nextcloud-form is a source-only type).',
						)
					}}
				</span>
			</div>

			<!-- Field-mapping (question reference) helper -->
			<FormsFieldMapping
				v-if="configValue('formId')"
				:sourceId="sourceIdValue"
				:formId="configValue('formId')" />
		</template>

		<!-- Unknown / not set -->
		<div v-else class="sync-config__placeholder">
			{{
				t('openconnector', 'Pick a {kind} type above to configure it.', {
					kind: kind,
				})
			}}
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { FilePickerType, getFilePickerBuilder } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import FolderOpenOutline from 'vue-material-design-icons/FolderOpenOutline.vue'
import FormsFieldMapping from './FormsFieldMapping.vue'
import TablesColumnMapping from './TablesColumnMapping.vue'
import { mapFormOptions } from './formsBridge.js'
import { extractResults, mapTableOptions } from './tablesBridge.js'

/**
 * Generate a stable input-id suffix so the two SyncConfigWidget
 * instances (source vs target) on the same page don't share `id`
 * attributes — labels would point at the wrong control otherwise.
 */
let widgetSeq = 0

export default {
	name: 'SyncConfigWidget',

	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		FolderOpenOutline,
		TablesColumnMapping,
		FormsFieldMapping,
	},

	props: {
		/**
		 * Side discriminator. Drives the label prefix ("Source"/"Target")
		 * and which polymorphic id field the parent will store the value
		 * in (`sourceId` vs `targetId`).
		 */
		kind: {
			type: String,
			required: true,
			validator: (value) => ['source', 'target'].includes(value),
		},

		/**
		 * The type discriminator picked on the parent. Determines which
		 * branch of the conditional template renders.
		 */
		type: {
			type: String,
			default: '',
		},

		/**
		 * The polymorphic id string. For `api` mode the Source UUID; for
		 * `register/schema` mode `<registerId>/<schemaId>`; for `file`
		 * mode the file path/glob.
		 */
		sourceId: {
			type: [String, Number],
			default: '',
		},

		/**
		 * Type-specific config blob. Different keys are read/written
		 * depending on `type`.
		 */
		config: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		const seq = ++widgetSeq
		return {
			widgetUid: seq,
			sourceOptions: [],
			sourcesLoading: false,
			registerOptions: [],
			registersLoading: false,
			selectedRegisterRecord: null,
			/**
			 * Schema id → display label, from `/apps/openregister/api/schemas`.
			 * A register's own `schemas` array carries bare ids, so without
			 * this the picker can only show the id back to the user.
			 */
			schemaLabelsById: {},
			pickingFile: false,
			pickerError: '',
			tableOptions: [],
			tablesLoading: false,
			tablesError: '',
			formOptions: [],
			formsLoading: false,
			formsError: '',
		}
	},

	computed: {
		/** @spec openspec/specs/sync-editor-ui/spec.md */
		kindLabel() {
			return this.kind === 'source'
				? t('openconnector', 'Source')
				: t('openconnector', 'Target')
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		apiSourceId() {
			return `sync-config-${this.widgetUid}-api-source`
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		registerSelectId() {
			return `sync-config-${this.widgetUid}-register`
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		schemaSelectId() {
			return `sync-config-${this.widgetUid}-schema`
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		filePathId() {
			return `sync-config-${this.widgetUid}-file-path`
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		sourceIdValue() {
			return this.sourceId != null ? String(this.sourceId) : ''
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		selectedSource() {
			if (!this.sourceIdValue) return null
			return (
				this.sourceOptions.find((opt) => opt.id === this.sourceIdValue) ?? {
					id: this.sourceIdValue,
					label: this.sourceIdValue,
				}
			)
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		selectedRegister() {
			const [registerId] = this.sourceIdValue.split('/')
			if (!registerId) return null
			return (
				this.registerOptions.find(
					(opt) => String(opt.id) === String(registerId),
				) ?? null
			)
		},

		/**
		 * Schema options for the selected register.
		 *
		 * A register's `schemas` is an array of bare ids — `[25, 26]` — not
		 * expanded records, so a label has to be resolved from the separately
		 * fetched schema list. Without that lookup the picker fell back to
		 * `String(schema)` and showed the user the id. Expanded objects are
		 * still accepted in case the registers endpoint ever inlines them.
		 *
		 * @return {Array<{id: string, label: string}>} Options for the picker.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		schemaOptions() {
			const reg = this.selectedRegister || this.selectedRegisterRecord
			if (!reg) return []
			const schemas = Array.isArray(reg.schemas) ? reg.schemas : []
			return schemas.map((schema) => {
				const isRecord = schema !== null && typeof schema === 'object'
				const id = String(
					isRecord ? (schema.id ?? schema.slug ?? '') : schema,
				)
				const inlineLabel = isRecord
					? schema.title || schema.name || schema.slug || ''
					: ''
				return {
					id,
					// Fall back to the id only when the schema list has not
					// resolved (or no longer contains this schema) — better a
					// visible id than an empty option.
					label: inlineLabel || this.schemaLabelsById[id] || id,
				}
			})
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		selectedSchema() {
			const parts = this.sourceIdValue.split('/')
			if (parts.length < 2) return null
			const schemaId = parts[1]
			return (
				this.schemaOptions.find((opt) => String(opt.id) === String(schemaId))
				?? null
			)
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md#requirement-table-picker-for-the-nextcloud-table-sourcetarget-kind-req-syncui-006 */
		tableSourceId() {
			return `sync-config-${this.widgetUid}-table-source`
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md#requirement-table-picker-for-the-nextcloud-table-sourcetarget-kind-req-syncui-006 */
		tablePickerId() {
			return `sync-config-${this.widgetUid}-table`
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md#requirement-table-picker-for-the-nextcloud-table-sourcetarget-kind-req-syncui-006 */
		selectedTable() {
			const tableId = this.configValue('tableId')
			if (!tableId) return null
			return (
				this.tableOptions.find(
					(opt) => String(opt.id) === String(tableId),
				) ?? {
					id: Number(tableId),
					label: String(tableId),
				}
			)
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md#requirement-form-picker-for-the-nextcloud-form-source-kind-req-syncui-008 */
		formSourceId() {
			return `sync-config-${this.widgetUid}-form-source`
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md#requirement-form-picker-for-the-nextcloud-form-source-kind-req-syncui-008 */
		formPickerId() {
			return `sync-config-${this.widgetUid}-form`
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md#requirement-form-picker-for-the-nextcloud-form-source-kind-req-syncui-008 */
		selectedForm() {
			const formId = this.configValue('formId')
			if (!formId) return null
			return (
				this.formOptions.find(
					(opt) => String(opt.id) === String(formId),
				) ?? {
					id: Number(formId),
					label: String(formId),
				}
			)
		},
	},

	watch: {
		type: {
			immediate: true,
			/**
			 * Lazily load the option lists the newly-selected branch of the
			 * template needs, skipping any list that is already populated so
			 * re-picking the same type costs no requests.
			 *
			 * @param {string} value The `type` discriminator now in effect — one of
			 *   `api`, `register/schema`, `nextcloud-table`, `nextcloud-form`.
			 * @return {void}
			 *
			 * @spec openspec/specs/sync-editor-ui/spec.md
			 */
			handler(value) {
				if (value === 'api' && this.sourceOptions.length === 0) {
					this.fetchSources()
				}
				if (value === 'register/schema') {
					if (this.registerOptions.length === 0) {
						this.fetchRegisters()
					}
					// Separate call, separately guarded: registers carry schema
					// IDS only, so the names come from the schema endpoint, and
					// the two caches must not be able to drift apart.
					if (Object.keys(this.schemaLabelsById).length === 0) {
						this.fetchSchemaLabels()
					}
				}
				if (value === 'nextcloud-table') {
					if (this.sourceOptions.length === 0) {
						this.fetchSources()
					}
					if (this.sourceIdValue) {
						this.fetchTables()
					}
				}
				if (value === 'nextcloud-form') {
					if (this.sourceOptions.length === 0) {
						this.fetchSources()
					}
					if (this.sourceIdValue) {
						this.fetchForms()
					}
				}
			},
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md#requirement-table-picker-for-the-nextcloud-table-sourcetarget-kind-req-syncui-006 */
		sourceId() {
			// A source change under nextcloud-table invalidates the table list.
			if (this.type === 'nextcloud-table' && this.sourceIdValue) {
				this.fetchTables()
			}
			// A source change under nextcloud-form invalidates the form list
			// (sync-editor-ui REQ-SYNCUI-008).
			if (this.type === 'nextcloud-form' && this.sourceIdValue) {
				this.fetchForms()
			}
		},
	},

	methods: {
		/**
		 * Read one key out of the `config` blob as a string for binding to a
		 * text input. Missing keys and null become '' so inputs never bind
		 * to undefined, and non-strings (e.g. a numeric tableId) are cast.
		 *
		 * @param {string} key Config key to read, e.g. `endpoint`, `idPosition`, `tableId`.
		 * @return {string} The stored value as text, or '' when unset.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		configValue(key) {
			if (!this.config || typeof this.config !== 'object') return ''
			const v = this.config[key]
			if (v == null) return ''
			return typeof v === 'string' ? v : String(v)
		},

		/**
		 * Write one key into the `config` blob and emit the whole object
		 * back to the parent. Copies before mutating so the prop is never
		 * edited in place, and an emptied field drops its key rather than
		 * persisting an empty string.
		 *
		 * @param {string} key Config key to set, e.g. `endpoint`, `filter`, `format`.
		 * @param {string} value New value from the input; '' or null removes the key.
		 * @return {void}
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		onConfigUpdate(key, value) {
			const next =
				this.config
				&& typeof this.config === 'object'
				&& !Array.isArray(this.config)
					? { ...this.config }
					: {}
			if (value === '' || value == null) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update:config', next)
		},

		/**
		 * Handle a pick in the Source selector, emitting the Source UUID up
		 * to the parent's `sourceId`/`targetId` field. Clearing the select
		 * emits '' so the parent unsets the id.
		 *
		 * @param {{ id: string, label: string }|null} option Chosen entry from
		 *   `sourceOptions`, or null when the select is cleared.
		 * @return {void}
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		onSourcePick(option) {
			this.$emit('update:sourceId', option?.id ? String(option.id) : '')
		},

		/**
		 * Handle a pick in the Table selector under the `nextcloud-table`
		 * kind.
		 *
		 * @param {{ id: number|string, label: string }|null} option Chosen entry from
		 *   `tableOptions`; null (or a missing id) clears `config.tableId`.
		 * @return {void}
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-table-picker-for-the-nextcloud-table-sourcetarget-kind-req-syncui-006
		 */
		onTablePick(option) {
			// Store the numeric table id in the config blob; clear any stale
			// column mapping since it referenced the previous table's columns.
			const next =
				this.config
				&& typeof this.config === 'object'
				&& !Array.isArray(this.config)
					? { ...this.config }
					: {}
			if (option?.id) {
				next.tableId = Number(option.id)
			} else {
				delete next.tableId
			}
			delete next.columnMapping
			this.$emit('update:config', next)
		},

		/**
		 * Fetch the tables the selected Source can access via the tables-bridge
		 * discovery endpoint. Soft-fails to an empty list with an inline error
		 * message so the picker degrades gracefully (contract.md 4xx/5xx).
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-table-picker-for-the-nextcloud-table-sourcetarget-kind-req-syncui-006
		 */
		async fetchTables() {
			if (!this.sourceIdValue) {
				this.tableOptions = []
				return
			}
			this.tablesLoading = true
			this.tablesError = ''
			try {
				const response = await axios.get(
					generateUrl(
						'/apps/openconnector/api/synchronizations/tables-bridge/tables',
					),
					{ params: { sourceId: this.sourceIdValue } },
				)
				this.tableOptions = mapTableOptions(extractResults(response.data))
			} catch (err) {
				this.tableOptions = []
				this.tablesError =
					err?.response?.data?.error
					|| t('openconnector', 'Could not load tables for this source.')
				// eslint-disable-next-line no-console
				console.warn('[SyncConfigWidget] tables fetch failed', err)
			} finally {
				this.tablesLoading = false
			}
		},

		/**
		 * Handle a pick in the Form selector under the `nextcloud-form`
		 * source kind.
		 *
		 * @param {{ id: number|string, label: string }|null} option Chosen entry from
		 *   `formOptions`; null (or a missing id) clears `config.formId`.
		 * @return {void}
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-form-picker-for-the-nextcloud-form-source-kind-req-syncui-008
		 */
		onFormPick(option) {
			// Store the numeric form id in the config blob; clear no other
			// keys — unlike nextcloud-table there is no columnMapping stored
			// here (FormsFieldMapping is read-only labelling, no write payload).
			const next =
				this.config
				&& typeof this.config === 'object'
				&& !Array.isArray(this.config)
					? { ...this.config }
					: {}
			if (option?.id) {
				next.formId = Number(option.id)
			} else {
				delete next.formId
			}
			this.$emit('update:config', next)
		},

		/**
		 * Fetch the forms the selected Source can access via the forms-bridge
		 * discovery endpoint. Soft-fails to an empty list with an inline error
		 * message so the picker degrades gracefully.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-form-picker-for-the-nextcloud-form-source-kind-req-syncui-008
		 */
		async fetchForms() {
			if (!this.sourceIdValue) {
				this.formOptions = []
				return
			}
			this.formsLoading = true
			this.formsError = ''
			try {
				const response = await axios.get(
					generateUrl(
						'/apps/openconnector/api/synchronizations/forms-bridge/forms',
					),
					{ params: { sourceId: this.sourceIdValue } },
				)
				this.formOptions = mapFormOptions(extractResults(response.data))
			} catch (err) {
				this.formOptions = []
				this.formsError =
					err?.response?.data?.error
					|| t('openconnector', 'Could not load forms for this source.')
				// eslint-disable-next-line no-console
				console.warn('[SyncConfigWidget] forms fetch failed', err)
			} finally {
				this.formsLoading = false
			}
		},

		/**
		 * Handle a pick in the Register selector of `register/schema` mode.
		 * Emits the half-formed `<registerId>/` id — the schema half stays
		 * empty until the user picks below — and caches the full record so
		 * `schemaOptions` keeps its schemas after the prop round-trips.
		 *
		 * @param {{ id: string, label: string, schemas: object[] }|null} option Chosen
		 *   entry from `registerOptions`; null clears the id and the cache.
		 * @return {void}
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		onRegisterPick(option) {
			if (!option?.id) {
				this.$emit('update:sourceId', '')
				this.selectedRegisterRecord = null
				return
			}
			// Cache the full register record so schemaOptions can read its
			// schemas array even after the parent prop round-trips.
			this.selectedRegisterRecord = option
			// Clear schema half — parent will store register alone until
			// the user picks a schema below.
			this.$emit('update:sourceId', String(option.id) + '/')
		},

		/**
		 * Handle a pick in the Schema selector of `register/schema` mode,
		 * emitting the combined `<registerId>/<schemaId>` id. Clearing the
		 * schema falls back to the register half alone.
		 *
		 * @param {{ id: string, label: string }|null} option Chosen entry from
		 *   `schemaOptions`, or null when the select is cleared.
		 * @return {void}
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		onSchemaPick(option) {
			const reg = this.selectedRegister || this.selectedRegisterRecord
			if (!reg || !option?.id) {
				this.$emit('update:sourceId', reg ? String(reg.id) + '/' : '')
				return
			}
			this.$emit('update:sourceId', String(reg.id) + '/' + String(option.id))
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		async fetchSources() {
			this.sourcesLoading = true
			try {
				const response = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/openconnector/source',
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
				this.sourceOptions = list.map((row) => ({
					id: String(row.id || row.uuid),
					label: row.name || row.title || row.id,
				}))
			} catch (err) {
				// eslint-disable-next-line no-console
				console.warn('[SyncConfigWidget] sources fetch failed', err)
				this.sourceOptions = []
			} finally {
				this.sourcesLoading = false
			}
		},

		/**
		 * Open the Nextcloud Files file picker and write the chosen path
		 * back through the `update:sourceId` channel — same mutation path
		 * as typing into the text field, just sourced from a real browse.
		 *
		 * The `@nextcloud/dialogs@^3.2.0` API is the builder pattern
		 * (`getFilePickerBuilder().setX().build().pick()`); the newer 4.x
		 * `showFilePicker` shape is not installed. We feed an empty
		 * mime-type filter so all files are reachable — sync source files
		 * are commonly XML/CSV/JSON without consistent mime detection on
		 * server uploads.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		async openFilePicker() {
			this.pickerError = ''
			this.pickingFile = true
			try {
				const picker = getFilePickerBuilder(
					t('openconnector', 'Pick a sync source file'),
				)
					.setMultiSelect(false)
					.setMimeTypeFilter([])
					.setModal(true)
					.setType(FilePickerType.Choose)
					.allowDirectories(false)
					.build()
				const path = await picker.pick()
				if (path) {
					this.$emit('update:sourceId', String(path))
				}
			} catch (err) {
				// User-cancellation in @nextcloud/dialogs 3.x rejects the
				// promise — treat any non-string-path error as a soft
				// dismissal and only surface a real error message.
				if (err && err.message && !/cancel/i.test(err.message)) {
					this.pickerError = err.message
				}
				// eslint-disable-next-line no-console
				console.debug('[SyncConfigWidget] file picker closed', err)
			} finally {
				this.pickingFile = false
			}
		},

		/**
		 * Build the schema id → name lookup the schema picker labels itself
		 * with. `/apps/openregister/api/registers` returns each register's
		 * `schemas` as bare ids, so this second call is what turns "25" into
		 * the schema's title. Soft-fails: on error the picker falls back to
		 * showing ids, which is what it did unconditionally before.
		 *
		 * @return {Promise<void>} Resolves once the lookup is populated.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		async fetchSchemaLabels() {
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/schemas'),
				)
				const data = response.data
				const list = Array.isArray(data?.results)
					? data.results
					: Array.isArray(data)
						? data
						: []
				const labels = {}
				for (const schema of list) {
					const id = schema?.id ?? schema?.uuid
					if (id === undefined || id === null) continue
					labels[String(id)] =
						schema.title || schema.name || schema.slug || String(id)
				}
				this.schemaLabelsById = labels
			} catch (err) {
				// eslint-disable-next-line no-console
				console.warn('[SyncConfigWidget] schemas fetch failed', err)
				this.schemaLabelsById = {}
			}
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		async fetchRegisters() {
			this.registersLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/registers'),
				)
				const data = response.data
				const list = Array.isArray(data?.results)
					? data.results
					: Array.isArray(data)
						? data
						: []
				this.registerOptions = list.map((reg) => ({
					id: String(reg.id),
					label: reg.title || reg.name || reg.slug || String(reg.id),
					schemas: Array.isArray(reg.schemas) ? reg.schemas : [],
				}))
				// If the parent has a pre-existing register/schema id,
				// seed selectedRegisterRecord so the schema picker has its
				// options ready without a second round-trip.
				const [regId] = this.sourceIdValue.split('/')
				if (regId) {
					this.selectedRegisterRecord =
						this.registerOptions.find(
							(opt) => String(opt.id) === String(regId),
						) || null
				}
			} catch (err) {
				// eslint-disable-next-line no-console
				console.warn('[SyncConfigWidget] registers fetch failed', err)
				this.registerOptions = []
			} finally {
				this.registersLoading = false
			}
		},
	},
}
</script>

<style scoped>
.sync-config {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.sync-config__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.sync-config__label {
	font-weight: bold;
	font-size: 13px;
}

.sync-config__helper {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.sync-config__placeholder {
	padding: 12px;
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	text-align: center;
}

.sync-config__file-row {
	display: flex;
	align-items: stretch;
	gap: 8px;
}

.sync-config__file-field {
	flex: 1;
	min-width: 0;
}

.sync-config__error {
	font-size: 12px;
	color: var(--color-error);
}
</style>

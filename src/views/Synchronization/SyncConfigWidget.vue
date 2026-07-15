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
					:input-id="apiSourceId"
					:aria-label-combobox="t('openconnector', 'Source (API)')"
					:value="selectedSource"
					:options="sourceOptions"
					:loading="sourcesLoading"
					:placeholder="t('openconnector', 'Pick a configured source')"
					@input="onSourcePick" />
				<span class="sync-config__helper">
					{{ t('openconnector', 'The Source record that defines the API base URL + auth.') }}
				</span>
			</div>

			<div class="sync-config__field">
				<NcTextField
					:label="t('openconnector', 'Endpoint')"
					:value="configValue('endpoint')"
					:placeholder="t('openconnector', 'Path appended to the source URL')"
					@update:value="(value) => onConfigUpdate('endpoint', value)" />
			</div>

			<div class="sync-config__field">
				<NcTextField
					:label="t('openconnector', 'ID position')"
					:value="configValue('idPosition')"
					:placeholder="t('openconnector', 'Dot-path to the id field in the API response')"
					@update:value="(value) => onConfigUpdate('idPosition', value)" />
			</div>

			<div class="sync-config__field">
				<NcTextField
					:label="t('openconnector', 'Results position')"
					:value="configValue('resultsPosition')"
					:placeholder="t('openconnector', 'Dot-path to the list of items')"
					@update:value="(value) => onConfigUpdate('resultsPosition', value)" />
			</div>
		</template>

		<!-- Register/Schema mode -->
		<template v-else-if="type === 'register/schema'">
			<div class="sync-config__field">
				<label :for="registerSelectId" class="sync-config__label">
					{{ t('openconnector', 'Register') }}
				</label>
				<NcSelect
					:input-id="registerSelectId"
					:aria-label-combobox="t('openconnector', 'Register')"
					:value="selectedRegister"
					:options="registerOptions"
					:loading="registersLoading"
					:placeholder="t('openconnector', 'Pick a register')"
					@input="onRegisterPick" />
			</div>

			<div class="sync-config__field">
				<label :for="schemaSelectId" class="sync-config__label">
					{{ t('openconnector', 'Schema') }}
				</label>
				<NcSelect
					:input-id="schemaSelectId"
					:aria-label-combobox="t('openconnector', 'Schema')"
					:value="selectedSchema"
					:options="schemaOptions"
					:disabled="!selectedRegister"
					:placeholder="t('openconnector', 'Pick a schema in the register')"
					@input="onSchemaPick" />
			</div>

			<div class="sync-config__field">
				<NcTextField
					:label="t('openconnector', 'Object filter (optional)')"
					:value="configValue('filter')"
					:placeholder="t('openconnector', 'JSON-encoded OR query filter')"
					@update:value="(value) => onConfigUpdate('filter', value)" />
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
						:input-id="filePathId"
						class="sync-config__file-field"
						:value="sourceIdValue"
						:placeholder="'/example/path/*.json'"
						@update:value="(value) => $emit('update:sourceId', value)" />
					<NcButton
						type="secondary"
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
					{{ t('openconnector', 'Pick a file from your Nextcloud Files app, or type a path/glob for multiple files.') }}
				</span>
				<span v-if="pickerError" class="sync-config__error">
					{{ pickerError }}
				</span>
			</div>

			<div class="sync-config__field">
				<NcTextField
					:label="t('openconnector', 'File format')"
					:value="configValue('format')"
					:placeholder="'json | xml | csv'"
					@update:value="(value) => onConfigUpdate('format', value)" />
			</div>

			<div class="sync-config__field">
				<NcTextField
					:label="t('openconnector', 'ID position')"
					:value="configValue('idPosition')"
					:placeholder="t('openconnector', 'Dot-path to the id field in each record')"
					@update:value="(value) => onConfigUpdate('idPosition', value)" />
			</div>
		</template>

		<!-- Nextcloud Table mode -->
		<template v-else-if="type === 'nextcloud-table'">
			<div class="sync-config__field">
				<label :for="tableSourceId" class="sync-config__label">
					{{ kindLabel }} {{ t('openconnector', 'source (Nextcloud instance)') }}
				</label>
				<NcSelect
					:input-id="tableSourceId"
					:aria-label-combobox="t('openconnector', 'Source (Nextcloud instance)')"
					:value="selectedSource"
					:options="sourceOptions"
					:loading="sourcesLoading"
					:input-label="t('openconnector', 'Source (Nextcloud instance)')"
					:placeholder="t('openconnector', 'Pick a configured source')"
					@input="onSourcePick" />
				<span class="sync-config__helper">
					{{ t('openconnector', 'The Source record whose base URL + credential reach the Tables API.') }}
				</span>
			</div>

			<div class="sync-config__field">
				<label :for="tablePickerId" class="sync-config__label">
					{{ t('openconnector', 'Table') }}
				</label>
				<NcSelect
					:input-id="tablePickerId"
					:aria-label-combobox="t('openconnector', 'Table')"
					:value="selectedTable"
					:options="tableOptions"
					:loading="tablesLoading"
					:disabled="!sourceIdValue"
					:input-label="t('openconnector', 'Table')"
					:placeholder="t('openconnector', 'Pick a table the source can access')"
					@input="onTablePick" />
				<span v-if="tablesError" class="sync-config__error">
					{{ tablesError }}
				</span>
				<span v-else class="sync-config__helper">
					{{ t('openconnector', 'Rows are read from (source) or written to (target) this table.') }}
				</span>
			</div>

			<!-- Column-mapping helper (target only) -->
			<TablesColumnMapping
				v-if="kind === 'target' && configValue('tableId')"
				:source-id="sourceIdValue"
				:table-id="configValue('tableId')"
				:config="config"
				@update:config="(value) => $emit('update:config', value)" />
		</template>

		<!-- Unknown / not set -->
		<div v-else class="sync-config__placeholder">
			{{ t('openconnector', 'Pick a {kind} type above to configure it.', { kind: kind }) }}
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getFilePickerBuilder, FilePickerType } from '@nextcloud/dialogs'
import FolderOpenOutline from 'vue-material-design-icons/FolderOpenOutline.vue'

import TablesColumnMapping from './TablesColumnMapping.vue'
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
			pickingFile: false,
			pickerError: '',
			tableOptions: [],
			tablesLoading: false,
			tablesError: '',
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-2 */
		kindLabel() {
			return this.kind === 'source'
				? t('openconnector', 'Source')
				: t('openconnector', 'Target')
		},
		/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-2 */
		apiSourceId() {
			return `sync-config-${this.widgetUid}-api-source`
		},
		/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-2 */
		registerSelectId() {
			return `sync-config-${this.widgetUid}-register`
		},
		/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-2 */
		schemaSelectId() {
			return `sync-config-${this.widgetUid}-schema`
		},
		/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-2 */
		filePathId() {
			return `sync-config-${this.widgetUid}-file-path`
		},
		/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-2 */
		sourceIdValue() {
			return this.sourceId != null ? String(this.sourceId) : ''
		},
		/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-2 */
		selectedSource() {
			if (!this.sourceIdValue) return null
			return this.sourceOptions.find((opt) => opt.id === this.sourceIdValue) ?? {
				id: this.sourceIdValue,
				label: this.sourceIdValue,
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-2 */
		selectedRegister() {
			const [registerId] = this.sourceIdValue.split('/')
			if (!registerId) return null
			return this.registerOptions.find((opt) => String(opt.id) === String(registerId)) ?? null
		},
		/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-2 */
		schemaOptions() {
			const reg = this.selectedRegister || this.selectedRegisterRecord
			if (!reg) return []
			const schemas = Array.isArray(reg.schemas) ? reg.schemas : []
			return schemas.map((schema) => ({
				id: String(schema.id ?? schema.slug ?? schema),
				label: schema.title || schema.name || schema.slug || String(schema),
			}))
		},
		/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-2 */
		selectedSchema() {
			const parts = this.sourceIdValue.split('/')
			if (parts.length < 2) return null
			const schemaId = parts[1]
			return this.schemaOptions.find((opt) => String(opt.id) === String(schemaId)) ?? null
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
			return this.tableOptions.find((opt) => String(opt.id) === String(tableId)) ?? {
				id: Number(tableId),
				label: String(tableId),
			}
		},
	},

	watch: {
		type: {
			immediate: true,
			/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-2 */
			handler(value) {
				if (value === 'api' && this.sourceOptions.length === 0) {
					this.fetchSources()
				}
				if (value === 'register/schema' && this.registerOptions.length === 0) {
					this.fetchRegisters()
				}
				if (value === 'nextcloud-table') {
					if (this.sourceOptions.length === 0) {
						this.fetchSources()
					}
					if (this.sourceIdValue) {
						this.fetchTables()
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
		},
	},

	methods: {
		/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-2 */
		configValue(key) {
			if (!this.config || typeof this.config !== 'object') return ''
			const v = this.config[key]
			if (v == null) return ''
			return typeof v === 'string' ? v : String(v)
		},
		/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-2 */
		onConfigUpdate(key, value) {
			const next = (this.config && typeof this.config === 'object' && !Array.isArray(this.config))
				? { ...this.config }
				: {}
			if (value === '' || value == null) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update:config', next)
		},
		/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-2 */
		onSourcePick(option) {
			this.$emit('update:sourceId', option?.id ? String(option.id) : '')
		},
		/** @spec openspec/specs/sync-editor-ui/spec.md#requirement-table-picker-for-the-nextcloud-table-sourcetarget-kind-req-syncui-006 */
		onTablePick(option) {
			// Store the numeric table id in the config blob; clear any stale
			// column mapping since it referenced the previous table's columns.
			const next = (this.config && typeof this.config === 'object' && !Array.isArray(this.config))
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
					generateUrl('/apps/openconnector/api/synchronizations/tables-bridge/tables'),
					{ params: { sourceId: this.sourceIdValue } },
				)
				this.tableOptions = mapTableOptions(extractResults(response.data))
			} catch (err) {
				this.tableOptions = []
				this.tablesError = err?.response?.data?.error
					|| t('openconnector', 'Could not load tables for this source.')
				// eslint-disable-next-line no-console
				console.warn('[SyncConfigWidget] tables fetch failed', err)
			} finally {
				this.tablesLoading = false
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-2 */
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
		/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-2 */
		onSchemaPick(option) {
			const reg = this.selectedRegister || this.selectedRegisterRecord
			if (!reg || !option?.id) {
				this.$emit('update:sourceId', reg ? String(reg.id) + '/' : '')
				return
			}
			this.$emit('update:sourceId', String(reg.id) + '/' + String(option.id))
		},
		/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-2 */
		async fetchSources() {
			this.sourcesLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/objects/openconnector/source'),
					{ params: { limit: 500 } },
				)
				const data = response.data
				const list = Array.isArray(data?.results) ? data.results : (Array.isArray(data) ? data : [])
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
		 * @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-2
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
		/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-2 */
		async fetchRegisters() {
			this.registersLoading = true
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/registers'))
				const data = response.data
				const list = Array.isArray(data?.results) ? data.results : (Array.isArray(data) ? data : [])
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
					this.selectedRegisterRecord = this.registerOptions.find((opt) => String(opt.id) === String(regId)) || null
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

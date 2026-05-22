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
    - File mode is currently a plain text field. The legacy modal didn't
      build it out either; once a real file-source kernel lands we'll
      revisit with a path picker.
    - Register/Schema picker loads the full schema list for the picked
      register — large registers may benefit from search/pagination, but
      OR caps at `limit: 500` here which mirrors what JobFormFields does
      for synchronizations.
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
				<NcTextField
					:label="t('openconnector', 'File path or glob')"
					:value="sourceIdValue"
					:placeholder="'/example/path/*.json'"
					@update:value="(value) => $emit('update:sourceId', value)" />
				<span class="sync-config__helper">
					{{ t('openconnector', 'Stored as the polymorphic id. Use a glob for multiple files.') }}
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

		<!-- Unknown / not set -->
		<div v-else class="sync-config__placeholder">
			{{ t('openconnector', 'Pick a {kind} type above to configure it.', { kind: kind }) }}
		</div>
	</div>
</template>

<script>
import { NcSelect, NcTextField } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Generate a stable input-id suffix so the two SyncConfigWidget
 * instances (source vs target) on the same page don't share `id`
 * attributes — labels would point at the wrong control otherwise.
 */
let widgetSeq = 0

export default {
	name: 'SyncConfigWidget',

	components: {
		NcSelect,
		NcTextField,
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
		}
	},

	computed: {
		kindLabel() {
			return this.kind === 'source'
				? t('openconnector', 'Source')
				: t('openconnector', 'Target')
		},
		apiSourceId() {
			return `sync-config-${this.widgetUid}-api-source`
		},
		registerSelectId() {
			return `sync-config-${this.widgetUid}-register`
		},
		schemaSelectId() {
			return `sync-config-${this.widgetUid}-schema`
		},
		sourceIdValue() {
			return this.sourceId != null ? String(this.sourceId) : ''
		},
		selectedSource() {
			if (!this.sourceIdValue) return null
			return this.sourceOptions.find((opt) => opt.id === this.sourceIdValue) ?? {
				id: this.sourceIdValue,
				label: this.sourceIdValue,
			}
		},
		selectedRegister() {
			const [registerId] = this.sourceIdValue.split('/')
			if (!registerId) return null
			return this.registerOptions.find((opt) => String(opt.id) === String(registerId)) ?? null
		},
		schemaOptions() {
			const reg = this.selectedRegister || this.selectedRegisterRecord
			if (!reg) return []
			const schemas = Array.isArray(reg.schemas) ? reg.schemas : []
			return schemas.map((schema) => ({
				id: String(schema.id ?? schema.slug ?? schema),
				label: schema.title || schema.name || schema.slug || String(schema),
			}))
		},
		selectedSchema() {
			const parts = this.sourceIdValue.split('/')
			if (parts.length < 2) return null
			const schemaId = parts[1]
			return this.schemaOptions.find((opt) => String(opt.id) === String(schemaId)) ?? null
		},
	},

	watch: {
		type: {
			immediate: true,
			handler(value) {
				if (value === 'api' && this.sourceOptions.length === 0) {
					this.fetchSources()
				}
				if (value === 'register/schema' && this.registerOptions.length === 0) {
					this.fetchRegisters()
				}
			},
		},
	},

	methods: {
		configValue(key) {
			if (!this.config || typeof this.config !== 'object') return ''
			const v = this.config[key]
			if (v == null) return ''
			return typeof v === 'string' ? v : String(v)
		},
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
		onSourcePick(option) {
			this.$emit('update:sourceId', option?.id ? String(option.id) : '')
		},
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
		onSchemaPick(option) {
			const reg = this.selectedRegister || this.selectedRegisterRecord
			if (!reg || !option?.id) {
				this.$emit('update:sourceId', reg ? String(reg.id) + '/' : '')
				return
			}
			this.$emit('update:sourceId', String(reg.id) + '/' + String(option.id))
		},
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
</style>

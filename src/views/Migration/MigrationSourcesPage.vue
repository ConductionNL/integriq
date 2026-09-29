<!--
  MigrationSourcesPage: pick the system or file a migration reads from, write
  the column mapping a delivered file is read through, and run a test that
  reads and writes nothing.

  The backend was complete (GET /api/migration-sources, POST .../preview,
  POST .../column-mapping/validate, GET .../column-mapping/presets) and no
  screen reached it. A column mapping is stored as a `column_mapping` object,
  so a second delivery of the same shape is a pick from the list, not a
  morning of mapping columns again. Every save runs the check first and is
  refused, naming the field, when it maps onto a field the schema lacks.

  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
-->
<template>
	<NcAppContent>
		<div class="migration-page">
			<h2 class="migration-page__title">
				{{ t('integriq', 'Migrations') }}
			</h2>
			<p class="migration-page__intro">
				{{
					t(
						'integriq',
						'Pick where the data comes from and test what a migration would bring. A test run reads and writes nothing.',
					)
				}}
			</p>

			<section class="migration-page__section">
				<h3>{{ t('integriq', 'Source') }}</h3>
				<NcSelect
					v-model="selectedSource"
					data-testid="migration-source-picker"
					:inputLabel="t('integriq', 'Migrate from')"
					:options="sourceOptions"
					:loading="loadingSources"
					:clearable="false" />
				<ul
					v-if="selectedSourceDescription"
					class="migration-page__kinds"
					data-testid="migration-source-kinds">
					<li
						v-for="kind in selectedSourceDescription.kinds"
						:key="kind.kind">
						{{ kind.label }}
						<span
							v-if="!kind.stableIdentifier"
							class="migration-page__muted">
							{{
								t(
									'integriq',
									'no stable identifier, so a second run cannot match these',
								)
							}}
						</span>
					</li>
				</ul>
			</section>

			<section
				v-if="isFileSource"
				class="migration-page__section"
				data-testid="migration-column-mapping">
				<h3>{{ t('integriq', 'Column mapping') }}</h3>
				<div class="migration-page__row">
					<NcSelect
						v-model="selectedStored"
						data-testid="migration-stored-mapping"
						:inputLabel="t('integriq', 'Saved mapping')"
						:options="storedOptions"
						:placeholder="t('integriq', 'Start a new mapping')"
						@update:modelValue="onPickStored" />
					<NcSelect
						v-model="selectedPreset"
						data-testid="migration-preset"
						:inputLabel="t('integriq', 'Start from a preset')"
						:options="presetOptions"
						@update:modelValue="onPickPreset" />
				</div>

				<div class="migration-page__row">
					<NcTextField
						v-model="draft.name"
						data-testid="migration-mapping-name"
						:label="t('integriq', 'Name')" />
					<NcTextField
						v-model="draft.kind"
						:label="t('integriq', 'Record kind')" />
				</div>
				<div class="migration-page__row">
					<NcSelect
						v-model="selectedSchema"
						data-testid="migration-target-schema"
						:inputLabel="t('integriq', 'Target schema')"
						:options="schemaOptions"
						:loading="loadingSchemas" />
					<NcTextField
						v-model="draft.identifierColumn"
						:label="t('integriq', 'Identifier column')" />
				</div>

				<table class="migration-page__columns">
					<thead>
						<tr>
							<th scope="col">
								{{ t('integriq', 'Column in the file') }}
							</th>
							<th scope="col">
								{{ t('integriq', 'Field it fills') }}
							</th>
							<th scope="col">
								<span class="hidden-visually">{{
									t('integriq', 'Actions')
								}}</span>
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="(row, index) in draft.rows"
							:key="index"
							data-testid="migration-column-row">
							<td>
								<NcTextField
									v-model="row.column"
									:label="t('integriq', 'Column in the file')"
									:labelOutside="true" />
							</td>
							<td>
								<NcTextField
									v-model="row.target"
									:label="t('integriq', 'Field it fills')"
									:labelOutside="true" />
							</td>
							<td>
								<NcButton
									variant="tertiary"
									:aria-label="t('integriq', 'Remove column')"
									@click="removeRow(index)">
									{{ t('integriq', 'Remove') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
				<NcButton variant="secondary" @click="addRow">
					{{ t('integriq', 'Add column') }}
				</NcButton>

				<NcNoteCard
					v-if="saveErrors.length > 0"
					type="error"
					data-testid="migration-mapping-refused">
					<p>{{ t('integriq', 'The mapping was not saved.') }}</p>
					<ul>
						<li v-for="error in saveErrors" :key="error">
							{{ error }}
						</li>
					</ul>
				</NcNoteCard>
				<NcNoteCard
					v-if="savedMessage"
					type="success"
					data-testid="migration-mapping-saved">
					{{ savedMessage }}
				</NcNoteCard>
				<NcButton
					variant="primary"
					data-testid="migration-mapping-save"
					:disabled="saving || !canSave"
					@click="save">
					{{ t('integriq', 'Save mapping') }}
				</NcButton>
			</section>

			<section v-if="selectedSource" class="migration-page__section">
				<h3>{{ t('integriq', 'Test run') }}</h3>
				<NcTextField
					v-if="isFileSource"
					v-model="filePath"
					data-testid="migration-file-path"
					:label="
						t('integriq', 'Path of the delivered file in your Files')
					" />
				<NcTextField
					v-else
					v-model="configuredSource"
					:label="
						t('integriq', 'Source to read (leave empty for the default)')
					" />
				<NcButton
					variant="primary"
					data-testid="migration-preview"
					:disabled="previewing"
					@click="runPreview">
					{{ t('integriq', 'Test run') }}
				</NcButton>
				<NcNoteCard v-if="previewError" type="error">
					{{ previewError }}
				</NcNoteCard>
				<table
					v-if="preview"
					class="migration-page__preview"
					data-testid="migration-preview-result">
					<thead>
						<tr>
							<th scope="col">
								{{ t('integriq', 'Record kind') }}
							</th>
							<th scope="col">
								{{ t('integriq', 'Count') }}
							</th>
							<th scope="col">
								{{ t('integriq', 'Read') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="kind in preview.kinds" :key="kind.kind">
							<td>{{ kind.label }}</td>
							<td>{{ kind.count }}</td>
							<td>
								{{
									kind.complete
										? t('integriq', 'complete')
										: t(
												'integriq',
												'incomplete, so the count is not the size',
											)
								}}
							</td>
						</tr>
					</tbody>
				</table>
			</section>
		</div>
	</NcAppContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcAppContent,
	NcButton,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import {
	draftFromPreset,
	draftFromStored,
	emptyDraft,
	previewRequest,
	storedPayload,
	validateRequest,
} from './columnMappingDraft.js'

const API = '/apps/integriq/api/migration-sources'
const STORE = '/apps/openregister/api/objects/integriq/column_mapping'

/**
 * The list a response carries, whichever of the two shapes it has.
 *
 * @param {object|Array} data The response body.
 *
 * @return {Array} The rows.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
 */
function listOf(data) {
	if (Array.isArray(data?.results)) {
		return data.results
	}
	return Array.isArray(data) ? data : []
}

export default {
	name: 'MigrationSourcesPage',

	components: {
		NcAppContent,
		NcButton,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	data() {
		return {
			sources: [],
			loadingSources: false,
			selectedSource: null,
			presets: [],
			selectedPreset: null,
			stored: [],
			selectedStored: null,
			schemas: [],
			loadingSchemas: false,
			selectedSchema: null,
			draft: emptyDraft(),
			saving: false,
			saveErrors: [],
			savedMessage: '',
			filePath: '',
			configuredSource: '',
			previewing: false,
			preview: null,
			previewError: '',
		}
	},

	computed: {
		/** @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-migration-source-is-an-adapter-behind-one-contract-req-msa-001 */
		sourceOptions() {
			return this.sources.map((source) => ({
				id: source.id,
				label: source.label,
			}))
		},

		/** @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-migration-source-is-an-adapter-behind-one-contract-req-msa-001 */
		selectedSourceDescription() {
			const id = this.selectedSource?.id
			return this.sources.find((source) => source.id === id) || null
		},

		/** @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002 */
		isFileSource() {
			return this.selectedSource?.id === 'file'
		},

		/** @spec openspec/specs/migration-mapping-presets/spec.md */
		presetOptions() {
			return this.presets.map((preset) => ({
				id: preset.id,
				label: preset.sourceSystem || preset.id,
				preset,
			}))
		},

		/** @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#scenario-an-administrator-maps-a-delivered-file-once-and-runs-it-twice */
		storedOptions() {
			return this.stored.map((row) => ({
				id: String(row.id ?? row.uuid),
				label: `${row.name} (v${row.version ?? 1})`,
				row,
			}))
		},

		/** @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#scenario-a-mapping-onto-a-field-that-does-not-exist-is-refused-at-save */
		schemaOptions() {
			return this.schemas.map((schema) => ({
				id: String(schema.slug || schema.id),
				label: schema.title || schema.slug || String(schema.id),
				schema,
			}))
		},

		/** @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002 */
		canSave() {
			return this.draft.name.trim() !== '' && this.selectedSchema !== null
		},
	},

	watch: {
		/**
		 * Keep the draft's target schema in step with the picker.
		 *
		 * @param {object|null} option The picked schema option.
		 *
		 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
		 */
		selectedSchema(option) {
			if (option) {
				this.draft.targetSchema = option.id
			}
		},
	},

	mounted() {
		this.fetchSources()
		this.fetchPresets()
		this.fetchStored()
		this.fetchSchemas()
	},

	methods: {
		t,

		/** @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-migration-source-is-an-adapter-behind-one-contract-req-msa-001 */
		async fetchSources() {
			this.loadingSources = true
			try {
				const response = await axios.get(generateUrl(API))
				this.sources = listOf(response.data)
			} catch {
				this.sources = []
			} finally {
				this.loadingSources = false
			}
		},

		/** @spec openspec/specs/migration-mapping-presets/spec.md */
		async fetchPresets() {
			try {
				const response = await axios.get(
					generateUrl(`${API}/column-mapping/presets`),
				)
				this.presets = listOf(response.data)
			} catch {
				this.presets = []
			}
		},

		/** @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#scenario-an-administrator-maps-a-delivered-file-once-and-runs-it-twice */
		async fetchStored() {
			try {
				const response = await axios.get(generateUrl(STORE), {
					params: { _limit: 500 },
				})
				this.stored = listOf(response.data)
			} catch {
				this.stored = []
			}
		},

		/** @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#scenario-a-mapping-onto-a-field-that-does-not-exist-is-refused-at-save */
		async fetchSchemas() {
			this.loadingSchemas = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/schemas'),
					{
						params: { _limit: 1000 },
					},
				)
				this.schemas = listOf(response.data)
			} catch {
				this.schemas = []
			} finally {
				this.loadingSchemas = false
			}
		},

		/**
		 * Load a saved mapping: a second delivery of the same shape needs
		 * nothing more.
		 *
		 * @param {object|null} option The picked saved mapping.
		 *
		 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#scenario-an-administrator-maps-a-delivered-file-once-and-runs-it-twice
		 */
		onPickStored(option) {
			this.selectedPreset = null
			this.draft = option ? draftFromStored(option.row) : emptyDraft()
			this.selectSchemaById(this.draft.targetSchema)
			this.clearMessages()
		},

		/**
		 * Start a new mapping from a named-incumbent preset.
		 *
		 * @param {object|null} option The picked preset.
		 *
		 * @spec openspec/specs/migration-mapping-presets/spec.md
		 */
		onPickPreset(option) {
			if (!option) {
				return
			}
			this.selectedStored = null
			this.draft = draftFromPreset(option.preset)
			this.clearMessages()
		},

		/**
		 * Pick the schema option with this id, if it is listed.
		 *
		 * @param {string} schemaId The schema slug or id.
		 *
		 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
		 */
		selectSchemaById(schemaId) {
			this.selectedSchema =
				this.schemaOptions.find((option) => option.id === schemaId) || null
		},

		/** @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002 */
		addRow() {
			this.draft.rows.push({ column: '', target: '' })
		},

		/**
		 * @param {number} index The row to remove.
		 *
		 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
		 */
		removeRow(index) {
			this.draft.rows.splice(index, 1)
			if (this.draft.rows.length === 0) {
				this.addRow()
			}
		},

		/** @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002 */
		clearMessages() {
			this.saveErrors = []
			this.savedMessage = ''
		},

		/**
		 * Check the mapping, then store it. A refused check stores nothing
		 * and shows each reason, which names the field.
		 *
		 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#scenario-a-mapping-onto-a-field-that-does-not-exist-is-refused-at-save
		 */
		async save() {
			this.clearMessages()
			this.saving = true
			try {
				try {
					await axios.post(
						generateUrl(`${API}/column-mapping/validate`),
						validateRequest(
							this.draft,
							this.selectedSchema?.schema || null,
						),
					)
				} catch (error) {
					const errors = error?.response?.data?.errors
					this.saveErrors =
						Array.isArray(errors) && errors.length > 0
							? errors.map(String)
							: [t('integriq', 'The mapping could not be checked.')]
					return
				}

				const payload = storedPayload(this.draft)
				const response = this.draft.id
					? await axios.put(
							generateUrl(`${STORE}/${this.draft.id}`),
							payload,
						)
					: await axios.post(generateUrl(STORE), payload)
				const saved = response?.data || {}
				this.draft = draftFromStored({ ...payload, ...saved })
				this.savedMessage = t('integriq', 'Saved as version {version}.', {
					version: payload.version,
				})
				await this.fetchStored()
				this.selectedStored =
					this.storedOptions.find((option) => option.id === this.draft.id)
					|| null
			} catch {
				this.saveErrors = [t('integriq', 'The mapping could not be saved.')]
			} finally {
				this.saving = false
			}
		},

		/**
		 * The read-only pass: counts per record kind and whether each read
		 * was complete. Nothing is written.
		 *
		 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-read-only-pass-reports-what-a-migration-would-bring-req-msa-004
		 */
		async runPreview() {
			this.previewing = true
			this.previewError = ''
			this.preview = null
			try {
				const body = previewRequest(this.selectedSource.id, this.draft, {
					path: this.filePath,
					source: this.configuredSource,
				})
				const response = await axios.post(
					generateUrl(`${API}/preview`),
					body,
				)
				this.preview = response.data
			} catch (error) {
				this.previewError =
					error?.response?.data?.error
					|| t('integriq', 'The test run failed.')
			} finally {
				this.previewing = false
			}
		},
	},
}
</script>

<style scoped>
.migration-page {
	padding: 16px 24px;
	max-width: 960px;
}

.migration-page__intro,
.migration-page__muted {
	color: var(--color-text-maxcontrast);
}

.migration-page__section {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-block: 24px;
}

.migration-page__row {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
}

.migration-page__row > * {
	flex: 1 1 240px;
}

.migration-page__columns,
.migration-page__preview {
	width: 100%;
	border-collapse: collapse;
}

.migration-page__columns th,
.migration-page__preview th,
.migration-page__preview td {
	text-align: start;
	padding: 4px 8px;
}
</style>

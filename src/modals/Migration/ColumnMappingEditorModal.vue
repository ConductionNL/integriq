<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  ColumnMappingEditorModal: the create and edit surface for a column mapping
  on the Migrations index (the page's `form-dialog` slot).

  A column mapping says which column of a delivered file fills which field of
  a schema. It is stored once and picked again for every delivery with the
  same columns. Every save runs POST /api/migration-sources/column-mapping/validate
  first and is refused, naming the field, when a column lands on a field the
  schema does not have or a required field is left unmapped. Only a mapping
  the check accepts reaches the slot's `confirm`, one version up.

  @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
-->
<template>
	<NcDialog
		v-if="show"
		:name="dialogTitle"
		size="large"
		:noClose="saving"
		@closing="onCancel">
		<div class="column-mapping-editor">
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

			<NcSelect
				v-if="isCreate && presetOptions.length > 0"
				v-model="selectedPreset"
				data-testid="migration-preset"
				:inputLabel="t('integriq', 'Start from a preset')"
				:options="presetOptions"
				@update:modelValue="onPickPreset" />

			<div class="column-mapping-editor__row">
				<NcTextField
					v-model="draft.name"
					data-testid="migration-mapping-name"
					:label="t('integriq', 'Name')" />
				<NcTextField
					v-model="draft.kind"
					:label="t('integriq', 'Record kind')" />
			</div>
			<div class="column-mapping-editor__row">
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

			<table class="column-mapping-editor__columns">
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
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="onCancel">
				{{ t('integriq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="migration-mapping-save"
				:disabled="saving || !canSave"
				@click="onSave">
				{{ t('integriq', 'Save mapping') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import {
	draftFromPreset,
	draftFromStored,
	emptyDraft,
	listOf,
	storedPayload,
	validateRequest,
} from '../../views/Migration/columnMappingDraft.js'

const API = '/apps/integriq/api/migration-sources'

export default {
	name: 'ColumnMappingEditorModal',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** Slot scope: whether CnIndexPage wants the form dialog open. */
		show: {
			type: Boolean,
			default: false,
		},

		/** Slot scope: the mapping being edited, or `null` in create mode. */
		item: {
			type: Object,
			default: null,
		},

		/** Slot scope: the effective JSON schema. Unused, the fields are bespoke. */
		schema: {
			type: Object,
			default: null,
		},

		/** Slot scope: persists through CnIndexPage's save path and refreshes the list. */
		confirm: {
			type: Function,
			default: null,
		},

		/** Slot scope: closes the form dialog on CnIndexPage. */
		close: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			draft: emptyDraft(),
			presets: [],
			selectedPreset: null,
			schemas: [],
			loadingSchemas: false,
			selectedSchema: null,
			saving: false,
			saveErrors: [],
		}
	},

	computed: {
		/** @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002 */
		isCreate() {
			return !this.item
		},

		/** @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002 */
		dialogTitle() {
			return this.isCreate
				? t('integriq', 'New column mapping')
				: t('integriq', 'Edit column mapping')
		},

		/** @spec openspec/specs/migration-mapping-presets/spec.md */
		presetOptions() {
			return this.presets.map((preset) => ({
				id: preset.id,
				label: preset.sourceSystem || preset.id,
				preset,
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
			return (
				this.draft.name.trim() !== ''
				&& this.selectedSchema !== null
				&& typeof this.confirm === 'function'
			)
		},
	},

	watch: {
		/**
		 * Seed the draft each time the dialog opens.
		 *
		 * @param {boolean} open Whether the dialog is open.
		 *
		 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#scenario-an-administrator-maps-a-delivered-file-once-and-runs-it-twice
		 */
		show: {
			immediate: true,
			handler(open) {
				if (open) {
					this.seed()
				}
			},
		},

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

	methods: {
		t,

		/**
		 * Start from the row being edited, or blank, and load the pickers.
		 *
		 * @return {Promise<void>} Resolves once the pickers are loaded.
		 *
		 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#scenario-an-administrator-maps-a-delivered-file-once-and-runs-it-twice
		 */
		async seed() {
			this.draft = this.item ? draftFromStored(this.item) : emptyDraft()
			this.selectedPreset = null
			this.saveErrors = []
			await Promise.all([this.fetchSchemas(), this.fetchPresets()])
			this.selectedSchema =
				this.schemaOptions.find(
					(option) => option.id === this.draft.targetSchema,
				) || null
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
		 * Start a new mapping from a named-incumbent preset.
		 *
		 * @param {object|null} option The picked preset.
		 *
		 * @spec openspec/specs/migration-mapping-presets/spec.md
		 */
		onPickPreset(option) {
			if (option) {
				this.draft = {
					...draftFromPreset(option.preset),
					targetSchema: this.draft.targetSchema,
				}
			}
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
		onCancel() {
			if (!this.saving) {
				this.close?.()
			}
		},

		/**
		 * Check the mapping, then store it through the slot's `confirm`. A
		 * refused check stores nothing and shows each reason, which names
		 * the field.
		 *
		 * @return {Promise<void>} Resolves once the save has settled.
		 *
		 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#scenario-a-mapping-onto-a-field-that-does-not-exist-is-refused-at-save
		 */
		async onSave() {
			if (!this.canSave) {
				return
			}
			this.saving = true
			this.saveErrors = []
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
				// The row's server-managed keys ride along; a value the draft
				// cleared is dropped rather than kept from the old row.
				const saved = { ...(this.item || {}), ...payload }
				for (const key of ['kind', 'targetSchema', 'identifierColumn']) {
					if (!(key in payload)) {
						delete saved[key]
					}
				}
				await this.confirm(saved)
				showSuccess(
					t('integriq', 'Saved as version {version}.', {
						version: payload.version,
					}),
				)
				this.close?.()
			} catch (error) {
				this.saveErrors = [
					error?.message
						|| t('integriq', 'The mapping could not be saved.'),
				]
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.column-mapping-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-block-end: 12px;
}

.column-mapping-editor__row {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
}

.column-mapping-editor__row > * {
	flex: 1 1 240px;
}

.column-mapping-editor__columns {
	width: 100%;
	border-collapse: collapse;
}

.column-mapping-editor__columns th {
	text-align: start;
	padding: 4px 8px;
}
</style>

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  MigrationTestRunPanel: the Migrations index's `below-header` slot. Pick the
  system or file a migration reads from and run a test that reads and writes
  nothing (POST /api/migration-sources/preview).

  For a delivered file, the test reads the file through a saved column
  mapping, so a second delivery of the same shape is a pick from the list.
  Each count is shown beside whether the read that produced it was complete,
  so a read that stopped early never passes for a small source.

  @spec openspec/specs/migration-sources/spec.md#requirement-a-read-only-pass-reports-what-a-migration-would-bring-req-msa-004
-->
<template>
	<section class="migration-test-run" data-testid="migration-test-run">
		<h3>{{ t('integriq', 'Test a migration') }}</h3>
		<p class="migration-test-run__muted">
			{{
				t(
					'integriq',
					'Pick where the data comes from and see what a migration would bring. A test run reads and writes nothing.',
				)
			}}
		</p>
		<div class="migration-test-run__row">
			<NcSelect
				v-model="selectedSource"
				data-testid="migration-source-picker"
				:inputLabel="t('integriq', 'Migrate from')"
				:options="sourceOptions"
				:loading="loadingSources" />
			<NcSelect
				v-if="isFileSource"
				v-model="selectedStored"
				data-testid="migration-stored-mapping"
				:inputLabel="t('integriq', 'Saved mapping')"
				:options="storedOptions" />
		</div>
		<ul v-if="selectedSourceDescription" class="migration-test-run__kinds">
			<li v-for="kind in selectedSourceDescription.kinds" :key="kind.kind">
				{{ kind.label }}
				<span
					v-if="!kind.stableIdentifier"
					class="migration-test-run__muted">
					{{
						t(
							'integriq',
							'no stable identifier, so a second run cannot match these',
						)
					}}
				</span>
			</li>
		</ul>
		<div v-if="selectedSource" class="migration-test-run__row">
			<NcTextField
				v-if="isFileSource"
				v-model="filePath"
				data-testid="migration-file-path"
				:label="t('integriq', 'Path of the delivered file in your Files')" />
			<NcTextField
				v-else
				v-model="configuredSource"
				:label="
					t('integriq', 'Source to read (leave empty for the default)')
				" />
			<NcButton
				variant="primary"
				data-testid="migration-preview"
				:disabled="!canRun"
				@click="runPreview">
				{{ t('integriq', 'Test run') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="previewError" type="error">
			{{ previewError }}
		</NcNoteCard>
		<table
			v-if="preview"
			class="migration-test-run__result"
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
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import {
	draftFromStored,
	listOf,
	previewRequest,
} from '../views/Migration/columnMappingDraft.js'

const API = '/apps/integriq/api/migration-sources'
const STORE = '/apps/openregister/api/objects/integriq/column_mapping'

export default {
	name: 'MigrationTestRunPanel',

	components: {
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
			stored: [],
			selectedStored: null,
			filePath: '',
			configuredSource: '',
			previewing: false,
			preview: null,
			previewError: '',
		}
	},

	computed: {
		/** @spec openspec/specs/migration-sources/spec.md#requirement-a-migration-source-is-an-adapter-behind-one-contract-req-msa-001 */
		sourceOptions() {
			return this.sources.map((source) => ({
				id: source.id,
				label: source.label,
			}))
		},

		/** @spec openspec/specs/migration-sources/spec.md#requirement-a-migration-source-is-an-adapter-behind-one-contract-req-msa-001 */
		selectedSourceDescription() {
			const id = this.selectedSource?.id
			return this.sources.find((source) => source.id === id) || null
		},

		/** @spec openspec/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002 */
		isFileSource() {
			return this.selectedSource?.id === 'file'
		},

		/** @spec openspec/specs/migration-sources/spec.md#scenario-an-administrator-maps-a-delivered-file-once-and-runs-it-twice */
		storedOptions() {
			return this.stored.map((row) => ({
				id: String(row.id ?? row.uuid),
				label: `${row.name} (v${row.version ?? 1})`,
				row,
			}))
		},

		/** @spec openspec/specs/migration-sources/spec.md#requirement-a-read-only-pass-reports-what-a-migration-would-bring-req-msa-004 */
		canRun() {
			if (this.previewing || !this.selectedSource) {
				return false
			}
			return (
				!this.isFileSource
				|| (this.selectedStored !== null && this.filePath.trim() !== '')
			)
		},
	},

	/** @spec openspec/specs/migration-sources/spec.md#requirement-a-migration-source-is-an-adapter-behind-one-contract-req-msa-001 */
	mounted() {
		this.fetchSources()
		this.fetchStored()
	},

	methods: {
		t,

		/** @spec openspec/specs/migration-sources/spec.md#requirement-a-migration-source-is-an-adapter-behind-one-contract-req-msa-001 */
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

		/** @spec openspec/specs/migration-sources/spec.md#scenario-an-administrator-maps-a-delivered-file-once-and-runs-it-twice */
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

		/**
		 * The read-only pass: counts per record kind and whether each read
		 * was complete. Nothing is written.
		 *
		 * @return {Promise<void>} Resolves once the result is shown.
		 *
		 * @spec openspec/specs/migration-sources/spec.md#requirement-a-read-only-pass-reports-what-a-migration-would-bring-req-msa-004
		 */
		async runPreview() {
			this.previewing = true
			this.previewError = ''
			this.preview = null
			try {
				const draft = this.selectedStored
					? draftFromStored(this.selectedStored.row)
					: null
				const body = previewRequest(this.selectedSource.id, draft, {
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
.migration-test-run {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px 16px;
	margin-block-end: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.migration-test-run__muted {
	color: var(--color-text-maxcontrast);
}

.migration-test-run__row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 12px;
}

.migration-test-run__row > * {
	flex: 1 1 240px;
}

.migration-test-run__result {
	width: 100%;
	border-collapse: collapse;
}

.migration-test-run__result th,
.migration-test-run__result td {
	text-align: start;
	padding: 4px 8px;
}
</style>

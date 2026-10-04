<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<!--
  DsoActivityMappingSettings: the DSO activity mapping table on the admin
  settings page (change dso-activity-mapping-table, REQ-DSO-012; ADR-079,
  the table is instance configuration). Rows are read and written through
  OpenRegister's object API, which keeps them admin-only. Below the table,
  the activities of real verzoeken that no row maps, each with a button that
  opens the add dialog prefilled.

  @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#requirement-administrators-maintain-the-activity-table-on-the-admin-settings-page-req-dso-012
-->
<template>
	<section
		class="integriq-admin__section"
		data-testid="admin-dso-activities-section">
		<h3>{{ t('integriq', 'DSO activities') }}</h3>
		<p class="integriq-admin__hint">
			{{
				t(
					'integriq',
					'Each row says which case types a DSO activity becomes. A verzoek activity matches on its imow-id first, then on its activity id.',
				)
			}}
		</p>

		<div v-if="error" class="integriq-admin__action-error" role="alert">
			{{ error }}
		</div>

		<p v-if="loading" class="integriq-admin__hint">
			{{ t('integriq', 'Loading the DSO activities…') }}
		</p>
		<template v-else>
			<p
				v-if="rows.length === 0"
				class="integriq-admin__hint"
				data-testid="admin-dso-activities-empty">
				{{
					t(
						'integriq',
						'No DSO activities are mapped yet. There is no public list to load them from. The activities of real verzoeken appear under unmapped DSO activities below.',
					)
				}}
			</p>
			<table v-else class="dso-activities__table">
				<thead>
					<tr>
						<th>{{ t('integriq', 'Activity name') }}</th>
						<th>{{ t('integriq', 'Imow-id or activity id') }}</th>
						<th>{{ t('integriq', 'Case types') }}</th>
						<th>{{ t('integriq', 'Samenloop') }}</th>
						<th>{{ t('integriq', 'Active') }}</th>
						<th>
							<span class="hidden-visually">{{
								t('integriq', 'Actions')
							}}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="row in rows"
						:key="row.id"
						data-testid="admin-dso-activities-row">
						<td>{{ row.activityName }}</td>
						<td>{{ row.imowId || row.activityId }}</td>
						<td>{{ caseTypeText(row) }}</td>
						<td>{{ row.samenloopStrategy }}</td>
						<td>
							{{
								row.isActive === false
									? t('integriq', 'No')
									: t('integriq', 'Yes')
							}}
						</td>
						<td class="dso-activities__actions">
							<NcButton
								variant="tertiary"
								data-testid="admin-dso-activities-edit"
								@click="openDialog(row)">
								{{ t('integriq', 'Edit') }}
							</NcButton>
							<NcButton
								variant="tertiary"
								:disabled="busy"
								data-testid="admin-dso-activities-toggle"
								@click="toggleActive(row)">
								{{
									row.isActive === false
										? t('integriq', 'Activate')
										: t('integriq', 'Deactivate')
								}}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<NcButton
				variant="secondary"
				data-testid="admin-dso-activities-add"
				@click="openDialog({})">
				{{ t('integriq', 'Add DSO activity') }}
			</NcButton>

			<h4>{{ t('integriq', 'Unmapped DSO activities') }}</h4>
			<p class="integriq-admin__hint">
				{{
					t(
						'integriq',
						'Activities on recent verzoeken that no active row maps. Map one to send its next verzoeken to the right case type.',
					)
				}}
			</p>
			<p
				v-if="unmapped.activities.length === 0"
				class="integriq-admin__hint"
				data-testid="admin-dso-unmapped-empty">
				{{ t('integriq', 'Every activity on recent verzoeken is mapped.') }}
			</p>
			<table v-else class="dso-activities__table">
				<thead>
					<tr>
						<th>{{ t('integriq', 'Activity name') }}</th>
						<th>{{ t('integriq', 'Imow-id or activity id') }}</th>
						<th>{{ t('integriq', 'Times seen') }}</th>
						<th>{{ t('integriq', 'Last seen') }}</th>
						<th>
							<span class="hidden-visually">{{
								t('integriq', 'Actions')
							}}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="activity in unmapped.activities"
						:key="activity.imowId + '|' + activity.activityId"
						data-testid="admin-dso-unmapped-row">
						<td>{{ activity.activityName }}</td>
						<td>{{ activity.imowId || activity.activityId }}</td>
						<td>{{ activity.count }}</td>
						<td>{{ activity.lastSeen }}</td>
						<td>
							<NcButton
								variant="tertiary"
								data-testid="admin-dso-unmapped-map"
								@click="openDialog(draftFromUnmapped(activity))">
								{{ t('integriq', 'Map') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<p
				v-if="unmapped.withoutIdentifier > 0"
				class="integriq-admin__hint"
				data-testid="admin-dso-unmapped-without-id">
				{{
					n(
						'integriq',
						'{count} activity arrived without an imow-id or activity id and cannot be mapped.',
						'{count} activities arrived without an imow-id or activity id and cannot be mapped.',
						unmapped.withoutIdentifier,
						{ count: unmapped.withoutIdentifier },
					)
				}}
			</p>
		</template>

		<DsoActivityMappingDialog
			v-if="dialogOpen"
			:open="dialogOpen"
			:row="dialogRow"
			:error="dialogError"
			:saving="busy"
			@close="dialogOpen = false"
			@save="save" />
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import DsoActivityMappingDialog from '../../dialogs/DsoActivityMappingDialog.vue'
import {
	draftFromRow,
	draftFromUnmapped,
	MAPPING_URL,
	payloadFromDraft,
	refusalMessage,
	UNMAPPED_URL,
} from './dsoActivityMapping.js'

export default {
	name: 'DsoActivityMappingSettings',

	components: { DsoActivityMappingDialog, NcButton },

	data() {
		return {
			loading: true,
			busy: false,
			error: '',
			rows: [],
			unmapped: { activities: [], withoutIdentifier: 0 },
			dialogOpen: false,
			dialogRow: {},
			dialogError: '',
		}
	},

	/**
	 * Read the rows and the unmapped activities.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-4.1
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		t,
		n,
		draftFromUnmapped,

		/**
		 * Read the rows and the unmapped activities.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-4.2
		 */
		async load() {
			this.error = ''
			try {
				const [rows, unmapped] = await Promise.all([
					axios.get(generateUrl(MAPPING_URL), { params: { _limit: 500 } }),
					axios.get(generateUrl(UNMAPPED_URL)),
				])
				this.rows = (rows.data?.results || []).map((row) => ({
					...row,
					id: draftFromRow(row).id,
				}))
				this.unmapped = {
					activities: unmapped.data?.activities || [],
					withoutIdentifier: unmapped.data?.withoutIdentifier || 0,
				}
			} catch (e) {
				this.error = refusalMessage(
					e,
					t('integriq', 'The DSO activities could not be read.'),
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * The case types of a row, as one line.
		 *
		 * @param {object} row The row.
		 * @return {string}
		 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-4.1
		 */
		caseTypeText(row) {
			return (row.caseTypes || [])
				.map((caseType) => caseType.title || caseType.reference)
				.join(', ')
		},

		/**
		 * Open the dialog for a row, an unmapped activity, or a new row.
		 *
		 * @param {object} row The row or draft.
		 * @return {void}
		 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#scenario-an-unmapped-activity-can-be-mapped-from-the-list
		 */
		openDialog(row) {
			this.dialogRow = row
			this.dialogError = ''
			this.dialogOpen = true
		},

		/**
		 * Store a row: create it, or replace the stored one.
		 *
		 * @param {{id: string|null, payload: object}} change The row.
		 * @return {Promise<void>}
		 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#scenario-add-a-row-with-two-zaaktypen
		 */
		async save({ id, payload }) {
			this.busy = true
			this.dialogError = ''
			try {
				await this.store(id, payload)
				this.dialogOpen = false
				showSuccess(t('integriq', 'Saved.'))
				await this.load()
			} catch (e) {
				this.dialogError = refusalMessage(
					e,
					t('integriq', 'The DSO activity could not be saved.'),
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Switch a row on or off.
		 *
		 * @param {object} row The row.
		 * @return {Promise<void>}
		 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-4.1
		 */
		async toggleActive(row) {
			this.busy = true
			this.error = ''
			try {
				const draft = draftFromRow(row)
				draft.isActive = !draft.isActive
				await this.store(draft.id, payloadFromDraft(draft))
				await this.load()
			} catch (e) {
				this.error = refusalMessage(
					e,
					t('integriq', 'The DSO activity could not be saved.'),
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * POST a new row, PUT a stored one.
		 *
		 * @param {string|null} id The row's id.
		 * @param {object} payload The row.
		 * @return {Promise<object>}
		 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-4.1
		 */
		store(id, payload) {
			if (id) {
				return axios.put(
					generateUrl(`${MAPPING_URL}/${encodeURIComponent(id)}`),
					payload,
				)
			}
			return axios.post(generateUrl(MAPPING_URL), payload)
		},
	},
}
</script>

<style scoped>
.dso-activities__table {
	width: 100%;
	margin-block: calc(var(--default-grid-baseline) * 2);
	border-collapse: collapse;
}

.dso-activities__table th,
.dso-activities__table td {
	padding: var(--default-grid-baseline);
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.dso-activities__actions {
	display: flex;
	gap: var(--default-grid-baseline);
}
</style>

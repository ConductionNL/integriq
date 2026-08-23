<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  NotificatiesAbonnementenPage — the ZGW Notificaties API abonnementen
  operations view (manifest `type: custom`, `component:
  NotificatiesAbonnementenPage`).

  Why a custom page rather than a plain CnIndexPage: create/update/delete
  MUST also register/update/delete the abonnement against the remote
  Notificaties API and provision/cascade-delete a companion consumer
  (NotificatiesSubscriberService) — not the generic OR object CRUD a
  CnIndexPage drives against `/api/objects/openconnector/{schema}`. Mirrors
  the EventDeliveries/Approvals precedent for "operational view backed by a
  dedicated non-CRUD endpoint."

  @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008
-->
<template>
	<div class="notificatiesAbonnementen">
		<div class="notificatiesAbonnementen__header">
			<h2>{{ t('integriq', 'Abonnementen') }}</h2>
			<NcButton
				variant="primary"
				data-testid="add-abonnement"
				@click="openCreate">
				{{ t('integriq', 'Add Item') }}
			</NcButton>
		</div>

		<p class="notificatiesAbonnementen__intro">
			{{
				t(
					'integriq',
					"Manage this app's subscriber registrations against remote ZGW Notificaties API kanalen (Logius/VNG API Notificatiestandaard voor ZGW APIs).",
				)
			}}
		</p>

		<NcLoadingIcon
			v-if="loading"
			:size="32"
			class="notificatiesAbonnementen__loading" />

		<NcEmptyContent
			v-else-if="!rows.length"
			data-testid="empty-state"
			:name="t('integriq', 'No abonnementen yet')"
			:description="
				t(
					'integriq',
					'Register an abonnement to subscribe to a Notificaties API kanaal.',
				)
			">
			<template #icon>
				<BellRingOutline :size="48" />
			</template>
		</NcEmptyContent>

		<table
			v-else
			class="notificatiesAbonnementen__table"
			data-testid="abonnementen-table">
			<thead>
				<tr>
					<th scope="col">{{ t('integriq', 'Name') }}</th>
					<th scope="col">{{ t('integriq', 'Kanalen') }}</th>
					<th scope="col">{{ t('integriq', 'Status') }}</th>
					<th scope="col">{{ t('integriq', 'Last error') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rows" :key="row.id || row.uuid">
					<td>{{ row.name || '—' }}</td>
					<td>{{ kanaalNames(row) }}</td>
					<td>
						<span
							class="notificatiesAbonnementen__badge"
							:class="`notificatiesAbonnementen__badge--${row.status}`">
							{{ row.status }}
						</span>
					</td>
					<td>
						{{ row.status === 'error' ? row.lastError || '—' : '—' }}
					</td>
					<td class="notificatiesAbonnementen__actions">
						<NcButton variant="tertiary" @click="openEdit(row)">
							{{ t('integriq', 'Edit') }}
						</NcButton>
						<NcButton variant="tertiary" @click="remove(row)">
							{{ t('integriq', 'Delete') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<NotificatiesAbonnementForm
			:open="form.open"
			:abonnement="form.abonnement"
			@close="form.open = false"
			@saved="onSaved" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import BellRingOutline from 'vue-material-design-icons/BellRingOutline.vue'
import NotificatiesAbonnementForm from '../../modals/NotificatiesAbonnement/NotificatiesAbonnementForm.vue'

export default {
	name: 'NotificatiesAbonnementenPage',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		BellRingOutline,
		NotificatiesAbonnementForm,
	},

	data() {
		return {
			rows: [],
			loading: false,
			form: {
				open: false,
				abonnement: null,
			},
		}
	},

	mounted() {
		this.reload()
	},

	methods: {
		t,
		/**
		 * Reload the abonnementen list.
		 *
		 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008
		 */
		async reload() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/integriq/api/notificaties/abonnementen'),
				)
				this.rows = response.data?.results || []
			} catch (err) {
				showError(t('integriq', 'Failed to load abonnementen'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Format a row's kanalen array as a comma-separated list of names.
		 *
		 * @param {object} row An abonnement row.
		 * @return {string}
		 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008
		 */
		kanaalNames(row) {
			const kanalen = Array.isArray(row.kanalen) ? row.kanalen : []
			return (
				kanalen
					.map((k) => k.naam)
					.filter(Boolean)
					.join(', ') || '—'
			)
		},

		/**
		 * Open the creation modal.
		 *
		 * Clears `abonnement` first — leaving the previously edited row in
		 * place would open "Add" pre-filled and turn a create into an
		 * accidental update of that row.
		 *
		 * @return {void}
		 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008
		 */
		openCreate() {
			this.form.abonnement = null
			this.form.open = true
		},

		/**
		 * Open the edit modal for an existing abonnement.
		 *
		 * @param {object} row The abonnement to edit.
		 * @return {void}
		 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008
		 */
		openEdit(row) {
			this.form.abonnement = row
			this.form.open = true
		},

		/**
		 * Delete an abonnement (remote DELETE + cascade-delete companion consumer).
		 *
		 * The cascade is server-side (REQ-004) — this only issues the DELETE
		 * and reloads. A row with no id is ignored rather than sent as a
		 * DELETE against an undefined path.
		 *
		 * @param {object} row The abonnement to delete.
		 * @return {Promise<void>}
		 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnement-registration-update-and-deletion-against-the-remote-api-req-001
		 */
		async remove(row) {
			const id = row.id || row.uuid
			if (!id) {
				return
			}

			try {
				await axios.delete(
					generateUrl(
						`/apps/integriq/api/notificaties/abonnementen/${id}`,
					),
				)
				showSuccess(t('integriq', 'Abonnement deleted'))
				this.reload()
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				showError(
					t('integriq', 'Failed to delete abonnement')
						+ (detail ? `: ${detail}` : ''),
				)
			}
		},

		/**
		 * Reload after the create/edit modal saves.
		 *
		 * Reloads from the server rather than patching the row in place: the
		 * registration round-trip sets `status`/`lastError` server-side
		 * (REQ-007), so a locally patched row would show a stale status.
		 *
		 * @return {void}
		 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008
		 */
		onSaved() {
			this.form.open = false
			this.reload()
		},
	},
}
</script>

<style scoped>
.notificatiesAbonnementen {
	padding: 20px;
}

.notificatiesAbonnementen__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 8px;
}

.notificatiesAbonnementen__intro {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.notificatiesAbonnementen__loading {
	margin-top: 40px;
}

.notificatiesAbonnementen__table {
	width: 100%;
	border-collapse: collapse;
}

.notificatiesAbonnementen__table th,
.notificatiesAbonnementen__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.notificatiesAbonnementen__actions {
	display: flex;
	gap: 8px;
}

.notificatiesAbonnementen__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 10px);
	font-size: 12px;
	background: var(--color-background-dark);
}

.notificatiesAbonnementen__badge--active {
	color: var(--color-success-text, #2d7d46);
}

.notificatiesAbonnementen__badge--error {
	color: var(--color-error-text, #d43131);
}

.notificatiesAbonnementen__badge--pending {
	color: var(--color-warning-text, #8a6d3b);
}
</style>

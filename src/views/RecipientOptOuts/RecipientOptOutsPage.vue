<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  RecipientOptOutsPage: the opt-out list every sender honours (manifest
  `type: custom`, `component: RecipientOptOutsPage`).

  A custom page rather than a logs page over `recipient_opt_out`, because the
  opt-outs now live in integriq's own table: the unsubscribe link writes them
  from a public request, which OpenRegister refuses. This page reads that
  table through GET /api/outbound/opt-outs (administrators only). Read only.

  @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
-->
<template>
	<div class="recipientOptOuts">
		<h2>{{ t('integriq', 'Opt-outs') }}</h2>
		<p class="recipientOptOuts__intro">
			{{ t('integriq', 'Addresses that asked not to be written to. Statutory notices, such as a besluit, are still sent.') }}
		</p>

		<NcLoadingIcon v-if="loading && !rows.length" :size="32" />

		<NcEmptyContent
			v-else-if="!rows.length"
			data-testid="opt-outs-empty"
			:name="t('integriq', 'No opt-outs yet')"
			:description="t('integriq', 'An opt-out appears here when a recipient follows the unsubscribe link in a message.')">
			<template #icon>
				<EmailOffOutline :size="48" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<table class="recipientOptOuts__table" data-testid="opt-outs-table">
				<thead>
					<tr>
						<th scope="col">{{ t('integriq', 'Since') }}</th>
						<th scope="col">{{ t('integriq', 'Address') }}</th>
						<th scope="col">{{ t('integriq', 'Scope') }}</th>
						<th scope="col">{{ t('integriq', 'Case') }}</th>
						<th scope="col">{{ t('integriq', 'Added by') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in rows" :key="row.id">
						<td>{{ formatDate(row.createdAt) }}</td>
						<td>{{ row.address }}</td>
						<td>{{ scopeLabel(row.scope) }}</td>
						<td>{{ row.caseRef || '-' }}</td>
						<td>{{ row.source || '-' }}</td>
					</tr>
				</tbody>
			</table>
			<p class="recipientOptOuts__count">
				{{ t('integriq', '{shown} of {total}', { shown: rows.length, total }) }}
			</p>
			<NcButton
				v-if="rows.length < total"
				:disabled="loading"
				@click="load(rows.length)">
				{{ t('integriq', 'Show more') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import EmailOffOutline from 'vue-material-design-icons/EmailOffOutline.vue'

const PAGE = 50

export default {
	name: 'RecipientOptOutsPage',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		EmailOffOutline,
	},
	data() {
		return {
			rows: [],
			total: 0,
			loading: false,
		}
	},
	mounted() {
		this.load(0)
	},
	methods: {
		t,
		/**
		 * Load one page of opt-outs and append it.
		 *
		 * @param {number} offset How many rows are already shown.
		 * @return {Promise<void>}
		 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
		 */
		async load(offset) {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/integriq/api/outbound/opt-outs'),
					{ params: { limit: PAGE, offset } },
				)
				const results = response.data?.results || []
				this.rows = offset === 0 ? results : this.rows.concat(results)
				this.total = Number(response.data?.total || 0)
			} catch (err) {
				showError(t('integriq', 'Failed to load the opt-outs'))
			} finally {
				this.loading = false
			}
		},
		/**
		 * A stored ISO date in the reader's locale.
		 *
		 * @param {string} value The ISO 8601 date.
		 * @return {string}
		 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
		 */
		formatDate(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString()
		},
		/**
		 * The scope as a reader says it.
		 *
		 * @param {string} scope `instance` or `case`.
		 * @return {string}
		 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
		 */
		scopeLabel(scope) {
			return scope === 'case'
				? t('integriq', 'This case')
				: t('integriq', 'Everything')
		},
	},
}
</script>

<style scoped>
.recipientOptOuts {
	padding: calc(var(--default-grid-baseline) * 4);
}

.recipientOptOuts__intro,
.recipientOptOuts__count {
	color: var(--color-text-maxcontrast);
}

.recipientOptOuts__table {
	width: 100%;
	border-collapse: collapse;
	margin-block: calc(var(--default-grid-baseline) * 3);
}

.recipientOptOuts__table th,
.recipientOptOuts__table td {
	text-align: start;
	padding: calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
}
</style>

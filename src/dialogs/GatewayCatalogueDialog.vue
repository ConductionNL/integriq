<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  GatewayCatalogueDialog: "Statutory gateways" on the Sources page.
  Mounted once in ModalHost.

  The gateway catalogue (which laws this instance reaches, and how firmly it
  claims to) and the jurisdiction overview (where each gateway's endpoint
  sits) had routes and no screen. Here an administrator filters the catalogue
  by standard, reads each gateway's claim in the words the catalogue carries,
  sees where its endpoint sits, and downloads the overview as a file. An
  undeclared jurisdiction reads as "not declared", never as a place.

  @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-gateway-declares-its-standard-and-its-conformance-claim-req-sg-001
  @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-gateway-declares-where-its-endpoint-sits-req-sg-008
-->
<template>
	<NcDialog
		:open="open"
		:name="t('integriq', 'Statutory gateways')"
		size="large"
		data-testid="gateway-catalogue-dialog"
		@update:open="onOpenChanged">
		<div class="gateway-catalogue">
			<p class="gateway-catalogue__muted">
				{{
					t(
						'integriq',
						'Each gateway names the law it serves and how firmly integriq claims to meet it.',
					)
				}}
			</p>

			<NcSelect
				v-model="standard"
				data-testid="gateway-catalogue-standard"
				:inputLabel="t('integriq', 'Standard')"
				:placeholder="t('integriq', 'All standards')"
				:options="standards"
				:clearable="true"
				@update:modelValue="loadCatalogue" />

			<NcNoteCard
				v-if="error"
				type="error"
				data-testid="gateway-catalogue-error">
				{{ error }}
			</NcNoteCard>

			<table
				v-if="gateways.length > 0"
				class="gateway-catalogue__table"
				data-testid="gateway-catalogue-rows">
				<thead>
					<tr>
						<th scope="col">
							{{ t('integriq', 'Gateway') }}
						</th>
						<th scope="col">
							{{ t('integriq', 'Standard') }}
						</th>
						<th scope="col">
							{{ t('integriq', 'Claim') }}
						</th>
						<th scope="col">
							{{ t('integriq', 'Where the endpoint sits') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="gateway in gateways"
						:key="gateway.id"
						:data-gateway="gateway.id">
						<td>{{ gateway.label }}</td>
						<td>{{ gateway.standard }}</td>
						<td>{{ claimText(gateway) }}</td>
						<td>{{ jurisdictionText(gateway) }}</td>
					</tr>
				</tbody>
			</table>
			<p v-else-if="!loading" class="gateway-catalogue__muted">
				{{ t('integriq', 'No gateway serves this standard.') }}
			</p>

			<h3>{{ t('integriq', 'Where data goes') }}</h3>
			<ul
				class="gateway-catalogue__overview"
				data-testid="gateway-overview-rows">
				<li v-for="row in overview" :key="row.id">
					{{
						t(
							'integriq',
							'{gateway}: {jurisdiction}',
							{
								gateway: row.label,
								jurisdiction: jurisdictionText(row),
							},
							undefined,
							{ escape: false },
						)
					}}
				</li>
			</ul>
			<NcButton :href="exportUrl" data-testid="gateway-overview-export">
				{{ t('integriq', 'Download the overview') }}
			</NcButton>
		</div>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { claimText, jurisdictionText } from './gatewayCatalogue.js'

export default {
	name: 'GatewayCatalogueDialog',

	components: { NcButton, NcDialog, NcNoteCard, NcSelect },

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close'],

	data() {
		return {
			standard: null,
			standards: [],
			gateways: [],
			overview: [],
			loading: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Where the overview file downloads from.
		 *
		 * @return {string}
		 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-gateway-declares-where-its-endpoint-sits-req-sg-008
		 */
		exportUrl() {
			return generateUrl('/apps/integriq/api/gateways/overview/export')
		},
	},

	watch: {
		/**
		 * Load the catalogue and the overview each time the dialog opens.
		 *
		 * @param {boolean} isOpen Whether the dialog is open.
		 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-gateway-declares-its-standard-and-its-conformance-claim-req-sg-001
		 */
		open(isOpen) {
			if (isOpen) {
				this.standard = null
				this.loadCatalogue()
				this.loadOverview()
			}
		},
	},

	methods: {
		t,
		claimText,
		jurisdictionText,

		/**
		 * Read the catalogue, filtered by the chosen standard.
		 *
		 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-gateway-declares-its-standard-and-its-conformance-claim-req-sg-001
		 */
		async loadCatalogue() {
			this.loading = true
			this.error = ''
			try {
				const params = this.standard ? { standard: this.standard } : {}
				const res = await axios.get(
					generateUrl('/apps/integriq/api/gateways'),
					{ params },
				)
				if (!this.standard) {
					this.standards = res.data?.standards ?? []
				}
				this.gateways = res.data?.results ?? []
			} catch {
				this.error = t(
					'integriq',
					'The gateway catalogue could not be read.',
				)
				this.gateways = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Read where each gateway's endpoint sits.
		 *
		 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-gateway-declares-where-its-endpoint-sits-req-sg-008
		 */
		async loadOverview() {
			try {
				const res = await axios.get(
					generateUrl('/apps/integriq/api/gateways/overview'),
				)
				this.overview = res.data?.results ?? []
			} catch {
				this.overview = []
			}
		},

		/**
		 * Close when the dialog reports it closed.
		 *
		 * @param {boolean} isOpen Whether the dialog is open.
		 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-gateway-declares-its-standard-and-its-conformance-claim-req-sg-001
		 */
		onOpenChanged(isOpen) {
			if (!isOpen) {
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped>
.gateway-catalogue {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0 12px;
}

.gateway-catalogue__muted {
	color: var(--color-text-maxcontrast);
}

.gateway-catalogue__table {
	width: 100%;
	border-collapse: collapse;
}

.gateway-catalogue__table th,
.gateway-catalogue__table td {
	text-align: start;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	overflow-wrap: anywhere;
}

.gateway-catalogue__overview {
	margin: 0;
	padding-inline-start: 20px;
	list-style: disc;
}
</style>

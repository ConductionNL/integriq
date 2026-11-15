<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  EgressDestinationsPage: "Outbound destinations" under Connections
  (manifest `type: custom`, `component: EgressDestinationsPage`).

  One list of every place integriq sends data: each enabled source and every
  host of the last 30 days' call log that no source covers, with the country
  and zone it sits in, how that was established, and the evidence. Above it the
  verdict (EU only, or not proven) and the count per zone, and the EU-only
  switch. Switching it on is refused while a destination is not shown to be in
  the EU, and the refusal names them. The page says plainly that calls other
  apps make without integriq are not on it.

  @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
-->
<template>
	<div class="egressDestinations" data-testid="egress-destinations-page">
		<div class="egressDestinations__header">
			<h2>{{ t('integriq', 'Outbound destinations') }}</h2>
			<NcCheckboxRadioSwitch
				type="switch"
				data-testid="egress-eu-only"
				:modelValue="euOnly"
				:disabled="loading || saving"
				@update:modelValue="setEuOnly">
				{{ t('integriq', 'EU only mode') }}
			</NcCheckboxRadioSwitch>
		</div>

		<p class="egressDestinations__intro">
			{{
				t(
					'integriq',
					'Every place integriq sends data to: each enabled source, and each host in the call log of the last 30 days that no source covers. With EU only mode on, integriq refuses to call a destination that is not shown to be in the EU.',
				)
			}}
		</p>
		<p class="egressDestinations__intro" data-testid="egress-not-covered">
			{{
				t(
					'integriq',
					'Calls that other apps make without integriq are not covered by this page.',
				)
			}}
		</p>

		<NcNoteCard
			v-if="error"
			type="error"
			data-testid="egress-error">
			{{ error }}
		</NcNoteCard>

		<NcNoteCard
			v-if="loaded"
			:type="verdict === 'eu-only' ? 'success' : 'warning'"
			data-testid="egress-verdict">
			{{ verdictLine }}
		</NcNoteCard>

		<ul v-if="loaded" class="egressDestinations__counts" data-testid="egress-counts">
			<li v-for="zone in zones" :key="zone">
				{{ zoneText(zone) }}: <strong>{{ counts[zone] || 0 }}</strong>
			</li>
		</ul>

		<CnDataTable
			:columns="columns"
			:rows="destinations"
			:loading="loading"
			rowKey="host"
			:emptyText="t('integriq', 'Integriq has no outbound destinations yet.')"
			data-testid="egress-table">
			<template #column-name="{ row }">
				{{ row.name || t('integriq', 'No source') }}
			</template>
			<template #column-country="{ row }">
				{{ row.country || t('integriq', 'Unknown') }}
			</template>
			<template #column-zone="{ row }">
				<span class="egressDestinations__zone" :class="`egressDestinations__zone--${row.zone}`">
					{{ zoneText(row.zone) }}
				</span>
			</template>
			<template #column-basis="{ row }">
				{{ basisText(row.basis) }}
			</template>
			<template #row-actions="{ row }">
				<NcButton
					v-if="row.sourceId"
					variant="tertiary"
					data-testid="egress-set-residency"
					@click="openResidency(row)">
					{{ t('integriq', 'Set residency') }}
				</NcButton>
			</template>
		</CnDataTable>
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import {
	EVENT_OPEN_SOURCE_RESIDENCY,
	EVENT_SOURCE_RESIDENCY_SAVED,
	modalBus,
} from '../../handlers/modalBus.js'
import { blockingText, verdictText, zoneLabel } from './residency.js'

export default {
	name: 'EgressDestinationsPage',

	components: {
		CnDataTable,
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
	},

	data() {
		return {
			destinations: [],
			verdict: '',
			counts: {},
			euOnly: false,
			loading: false,
			loaded: false,
			saving: false,
			error: '',
			zones: ['eu', 'eea', 'outside', 'unknown'],
		}
	},

	computed: {
		/** @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002 */
		columns() {
			return [
				{ key: 'name', label: t('integriq', 'Source') },
				{ key: 'host', label: t('integriq', 'Host') },
				{ key: 'country', label: t('integriq', 'Country') },
				{ key: 'zone', label: t('integriq', 'Zone') },
				{ key: 'basis', label: t('integriq', 'Established by') },
				{ key: 'evidence', label: t('integriq', 'Evidence') },
			]
		},

		/** @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002 */
		verdictLine() {
			return verdictText(this.verdict, t)
		},
	},

	mounted() {
		modalBus.on(EVENT_SOURCE_RESIDENCY_SAVED, this.load)
		this.load()
	},

	beforeUnmount() {
		modalBus.off(EVENT_SOURCE_RESIDENCY_SAVED, this.load)
	},

	methods: {
		t,

		/**
		 * Read the destinations, the verdict, the counts and the mode.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(generateUrl('/apps/integriq/api/egress/destinations'))
				this.destinations = Array.isArray(data?.destinations) ? data.destinations : []
				this.verdict = data?.verdict ?? 'not-proven'
				this.counts = data?.counts ?? {}
				this.euOnly = data?.euOnly === true
				this.loaded = true
			} catch (exception) {
				this.error = exception?.response?.data?.error || t('integriq', 'The outbound destinations could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Switch EU only mode, keeping it off when the backend refuses.
		 *
		 * @param {boolean} enabled The wanted state.
		 * @return {Promise<void>}
		 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-an-administrator-can-enforce-eu-only-egress-req-res-003
		 */
		async setEuOnly(enabled) {
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.put(generateUrl('/apps/integriq/api/egress/eu-only'), { enabled })
				this.euOnly = data?.euOnly === true
			} catch (exception) {
				const status = exception?.response?.status
				this.error = status === 409
					? blockingText(exception.response.data, t)
					: exception?.response?.data?.error || t('integriq', 'EU only mode could not be saved.')
			} finally {
				this.saving = false
			}
		},

		/**
		 * Open the residency dialog for a destination's source.
		 *
		 * @param {object} row The destination.
		 * @return {void}
		 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-a-source-records-its-residency-with-a-basis-and-evidence-req-res-001
		 */
		openResidency(row) {
			modalBus.emit(EVENT_OPEN_SOURCE_RESIDENCY, {
				source: {
					id: row.sourceId,
					name: row.name,
					residency: row.country ? { country: row.country, basis: row.basis, evidence: row.evidence } : null,
				},
			})
		},

		/**
		 * @param {string} zone The zone.
		 * @return {string} Its label.
		 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
		 */
		zoneText(zone) {
			return zoneLabel(zone, t)
		},

		/**
		 * @param {string} basis `set-by-admin`, `declared-by-app` or empty.
		 * @return {string} How the residency was established.
		 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-a-source-records-its-residency-with-a-basis-and-evidence-req-res-001
		 */
		basisText(basis) {
			if (basis === 'set-by-admin') {
				return t('integriq', 'Set by an administrator')
			}
			if (basis === 'declared-by-app') {
				return t('integriq', 'Declared by the app')
			}
			return t('integriq', 'Not recorded')
		},
	},
}
</script>

<style scoped>
.egressDestinations {
	padding: 20px;
}

.egressDestinations__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
}

.egressDestinations__intro {
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.egressDestinations__counts {
	display: flex;
	gap: 24px;
	flex-wrap: wrap;
	margin: 12px 0;
}

.egressDestinations__zone {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.egressDestinations__zone--eu {
	color: var(--color-success-text);
}

.egressDestinations__zone--outside,
.egressDestinations__zone--unknown {
	color: var(--color-warning-text);
}
</style>

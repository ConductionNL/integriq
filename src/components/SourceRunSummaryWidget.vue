<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  SourceRunSummaryWidget: a source's pulls per day and its latest runs, on the
  source page, with Run again on a failed run.

  Mounted as a body widget on SourceDetail and reads the loaded source off
  `cnSectionContext`, like DocumentGenerationSourcePanel. The sums come from
  runSummary#show, because the widget dialect cannot sum several counters
  per day in one table (design D2). Run again posts straight away, with no
  dialog (design D3), and reads the summary again afterwards.

  @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
-->
<template>
	<section class="runSummary" data-testid="source-run-summary">
		<h3>{{ t('integriq', 'Pulls per day') }}</h3>

		<p v-if="loading">
			{{ t('integriq', 'Reading the pulls of this source') }}
		</p>
		<p v-else-if="error" class="runSummary__error" role="alert">
			{{ error }}
		</p>
		<template v-else>
			<table class="runSummary__table" data-testid="run-summary-days">
				<thead>
					<tr>
						<th scope="col">
							{{ t('integriq', 'Day') }}
						</th>
						<th
							v-for="column in dayColumns"
							:key="column.key"
							scope="col">
							{{ column.label }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="day in days" :key="day.date">
						<td>{{ day.date }}</td>
						<td
							v-for="column in dayColumns"
							:key="column.key"
							:data-col="column.key">
							{{ day[column.key] }}
						</td>
					</tr>
				</tbody>
			</table>

			<h4>{{ t('integriq', 'Pull runs') }}</h4>
			<p v-if="runs.length === 0" data-testid="run-summary-no-runs">
				{{ t('integriq', 'This source has no pulls in this period.') }}
			</p>
			<table v-else class="runSummary__table" data-testid="run-summary-runs">
				<thead>
					<tr>
						<th scope="col">
							{{ t('integriq', 'Started') }}
						</th>
						<th scope="col">
							{{ t('integriq', 'Status') }}
						</th>
						<th scope="col">
							{{ t('integriq', 'Started by') }}
						</th>
						<th scope="col">
							{{ t('integriq', 'Found') }}
						</th>
						<th scope="col">
							{{ t('integriq', 'Invalid') }}
						</th>
						<th scope="col">
							<span class="hidden-visually">{{
								t('integriq', 'Actions')
							}}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="run in runs" :key="run.id">
						<td>{{ formatTime(run.startedAt) }}</td>
						<td>{{ statusLabel(run.status) }}</td>
						<td>{{ triggerLabel(run.triggeredBy) }}</td>
						<td>{{ run.found }}</td>
						<td>{{ run.invalid }}</td>
						<td>
							<NcButton
								v-if="run.status === 'failed'"
								variant="secondary"
								:disabled="rerunning !== null"
								data-testid="run-again"
								@click="rerun(run)">
								{{ t('integriq', 'Run again') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</template>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import { runAgain } from '../handlers/runAgain.js'

export default {
	name: 'SourceRunSummaryWidget',

	components: { NcButton },

	inject: {
		cnSectionContext: {
			from: 'cnSectionContext',
			default: () => ({ value: { objectId: null, object: null } }),
		},
	},

	data() {
		return {
			loading: false,
			error: '',
			days: [],
			runs: [],
			rerunning: null,
		}
	},

	computed: {
		/**
		 * The source's id, from the detail page's section context.
		 *
		 * @return {string}
		 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
		 */
		objectId() {
			const ctx = this.cnSectionContext
			const value = ctx && ctx.value !== undefined ? ctx.value : ctx
			const source = (value && value.object) || {}
			return String(
				(value && value.objectId)
					|| source['@self']?.id
					|| source.uuid
					|| source.id
					|| '',
			)
		},

		/**
		 * The per-day counters, in the order the table shows them.
		 *
		 * @return {Array<{key: string, label: string}>}
		 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
		 */
		dayColumns() {
			return [
				{ key: 'runs', label: t('integriq', 'Runs') },
				{ key: 'succeeded', label: t('integriq', 'Succeeded') },
				{ key: 'failed', label: t('integriq', 'Failed') },
				{ key: 'found', label: t('integriq', 'Found') },
				{ key: 'created', label: t('integriq', 'Created') },
				{ key: 'updated', label: t('integriq', 'Updated') },
				{ key: 'invalid', label: t('integriq', 'Invalid') },
			]
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/**
			 * Read the summary once the source is known.
			 *
			 * @return {void}
			 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
			 */
			handler() {
				if (this.objectId) {
					this.load()
				}
			},
		},
	},

	methods: {
		t,

		/**
		 * Read the last seven days of pulls and the latest runs.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/integriq/api/sources/${encodeURIComponent(this.objectId)}/run-summary`,
					),
				)
				this.days = Array.isArray(data?.days) ? data.days : []
				this.runs = Array.isArray(data?.runs) ? data.runs : []
			} catch (e) {
				this.days = []
				this.runs = []
				this.error =
					e?.response?.data?.error
					|| t('integriq', 'The pulls of this source could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Run a failed run's synchronization again, then read the summary again.
		 *
		 * @param {object} run The failed run.
		 * @return {Promise<void>}
		 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-failed-pull-restarts-with-one-click-req-crun-003
		 */
		async rerun(run) {
			this.rerunning = run.id
			try {
				await runAgain(run)
			} finally {
				this.rerunning = null
			}
			await this.load()
		},

		/**
		 * A run's status in words.
		 *
		 * @param {string} status The run status.
		 * @return {string}
		 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
		 */
		statusLabel(status) {
			const labels = {
				running: t('integriq', 'Running'),
				success: t('integriq', 'Succeeded'),
				failed: t('integriq', 'Failed'),
			}
			return labels[status] || status || ''
		},

		/**
		 * What started a run, in words.
		 *
		 * @param {string} trigger The run's triggeredBy.
		 * @return {string}
		 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-every-run-records-its-source-and-what-started-it-req-crun-001
		 */
		triggerLabel(trigger) {
			const labels = {
				cron: t('integriq', 'Schedule'),
				manual: t('integriq', 'An administrator'),
				rerun: t('integriq', 'Run again'),
			}
			return labels[trigger] || t('integriq', 'Unknown')
		},

		/**
		 * A run's start time for the reader's locale.
		 *
		 * @param {string} value An ISO date-time.
		 * @return {string}
		 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
		 */
		formatTime(value) {
			if (!value) {
				return ''
			}
			const date = new Date(value)
			return Number.isNaN(date.getTime())
				? String(value)
				: date.toLocaleString()
		},
	},
}
</script>

<style scoped>
.runSummary {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.runSummary__table {
	width: 100%;
	border-collapse: collapse;
}

.runSummary__table th,
.runSummary__table td {
	padding: calc(var(--default-grid-baseline) * 1)
		calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.runSummary__error {
	color: var(--color-error-text);
}
</style>

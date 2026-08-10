<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  FlowRunLog — past flow_run / flow_run_log records for one flow
  (visual-flow-orchestration REQ-007/REQ-008, design.md Decision 7's
  "FlowRunLog sub-view"). Mirrors the SyncDeadLetters / Job logs list
  pattern already in the manifest: a plain, admin-facing read list over
  OR's generic objects API — no bespoke backend endpoint needed beyond
  the existing /api/objects/openconnector/{flow_run,flow_run_log} routes.
-->
<template>
	<div class="flow-run-log">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcEmptyContent
			v-else-if="runs.length === 0"
			:name="t('openconnector', 'No runs yet')"
			:description="t('openconnector', 'Trigger a run to see its trace here.')" />
		<ul v-else class="flow-run-log__list">
			<li v-for="run in runs" :key="run.id" class="flow-run-log__run">
				<button
					type="button"
					class="flow-run-log__run-header"
					:aria-expanded="!!expanded[run.id]"
					@click="toggle(run.id)">
					<span class="flow-run-log__status" :class="'flow-run-log__status--' + run.status">{{ run.status }}</span>
					<span>{{ t('openconnector', 'Trigger: {trigger}', { trigger: run.triggerSource || 'unknown' }) }}</span>
					<span>{{ formatDate(run.startedAt) }}</span>
				</button>
				<ul v-if="expanded[run.id]" class="flow-run-log__steps">
					<li v-if="!stepLogs[run.id] || stepLogs[run.id].length === 0" class="flow-run-log__step">
						{{ t('openconnector', 'Loading…') }}
					</li>
					<li v-for="entry in stepLogs[run.id]" :key="entry.id" class="flow-run-log__step">
						<span class="flow-run-log__step-order">#{{ entry.stepOrder }}</span>
						<span>{{ entry.type }}</span>
						<span class="flow-run-log__status" :class="'flow-run-log__status--' + entry.status">{{ entry.status }}</span>
						<span v-if="entry.error" class="flow-run-log__error">{{ entry.error }}</span>
					</li>
				</ul>
			</li>
		</ul>
	</div>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'FlowRunLog',

	components: {
		NcEmptyContent,
		NcLoadingIcon,
	},

	props: {
		flowId: { type: String, required: true },
	},

	data() {
		return {
			loading: false,
			runs: [],
			expanded: {},
			stepLogs: {},
		}
	},

	watch: {
		flowId: {
			immediate: true,
			/**
			 * Reload the history when the page moves to a different flow.
			 *
			 * @return {void}
			 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-flow-s-run-history-is-inspectable-per-step-req-014
			 */
			handler() {
				this.fetchRuns()
			},
		},
	},

	methods: {
		/**
		 * The runs of this flow, most recent first. Capped at 50: a long-lived
		 * flow's full history is not what an operator opening this panel is
		 * asking for.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-flow-s-run-history-is-inspectable-per-step-req-014
		 */
		async fetchRuns() {
			if (!this.flowId) return
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/objects/openconnector/flow_run'),
					{ params: { flowId: this.flowId, limit: 50, _sort: '-startedAt' } },
				)
				const data = response.data
				const list = Array.isArray(data?.results) ? data.results : (Array.isArray(data) ? data : [])
				this.runs = list.map((row) => ({
					id: String(row.id || row.uuid),
					status: row.status || 'running',
					triggerSource: row.triggerSource || '',
					startedAt: row.startedAt || row.created || '',
				}))
			} catch (err) {
				// eslint-disable-next-line no-console
				console.warn('[FlowRunLog] fetch failed', err)
				this.runs = []
			} finally {
				this.loading = false
			}
		},
		/**
		 * Expand or collapse a run, fetching its step logs the first time it
		 * is opened. Fetching lazily is the point: a flow with a long history
		 * would otherwise issue one request per run before the operator has
		 * asked for any of them.
		 *
		 * @param {string} runId The run's id.
		 * @return {Promise<void>}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-flow-s-run-history-is-inspectable-per-step-req-014
		 */
		async toggle(runId) {
			this.expanded[runId] = !this.expanded[runId]
			if (this.expanded[runId] && !this.stepLogs[runId]) {
				await this.fetchStepLogs(runId)
			}
		},
		/**
		 * One run's per-step entries, in step order (REQ-008's trace).
		 *
		 * @param {string} runId The run's id.
		 * @return {Promise<void>}
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-flow-s-run-history-is-inspectable-per-step-req-014
		 */
		async fetchStepLogs(runId) {
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/objects/openconnector/flow_run_log'),
					{ params: { flowRunId: runId, limit: 200, _sort: 'stepOrder' } },
				)
				const data = response.data
				const list = Array.isArray(data?.results) ? data.results : (Array.isArray(data) ? data : [])
				this.stepLogs[runId] = list.map((row) => ({
					id: String(row.id || row.uuid),
					stepOrder: row.stepOrder,
					type: row.type,
					status: row.status,
					error: row.error || '',
				}))
			} catch (err) {
				// eslint-disable-next-line no-console
				console.warn('[FlowRunLog] step-log fetch failed', err)
				this.stepLogs[runId] = []
			}
		},
		/**
		 * A run timestamp in the reader's locale, or the raw value when it is
		 * not a date this browser can parse — showing "Invalid Date" tells an
		 * operator less than the string the server sent.
		 *
		 * @param {string} value The stored timestamp.
		 * @return {string} The display value.
		 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-flow-s-run-history-is-inspectable-per-step-req-014
		 */
		formatDate(value) {
			if (!value) return ''
			try {
				return new Date(value).toLocaleString()
			} catch (e) {
				return value
			}
		},
	},
}
</script>

<style scoped>
.flow-run-log__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.flow-run-log__run {
	border-bottom: 1px solid var(--color-border);
}

/* A real <button>, not a clickable <div>: the row is a disclosure control, so
   it needs the keyboard and role a native button already has. These are the
   resets that make one look like the row it replaced. */
.flow-run-log__run-header {
	display: flex;
	gap: 16px;
	width: 100%;
	padding: 8px 4px;
	border: none;
	background: none;
	color: inherit;
	font: inherit;
	text-align: left;
	cursor: pointer;
}

.flow-run-log__steps {
	list-style: none;
	margin: 0 0 8px 24px;
	padding: 0;
}

.flow-run-log__step {
	display: flex;
	gap: 12px;
	padding: 4px 0;
	font-size: 0.9em;
}

.flow-run-log__step-order {
	font-weight: bold;
}

.flow-run-log__error {
	color: var(--color-error);
}

.flow-run-log__status {
	text-transform: uppercase;
	font-size: 0.8em;
	font-weight: bold;
}

.flow-run-log__status--completed {
	color: var(--color-success);
}

.flow-run-log__status--stopped,
.flow-run-log__status--failed,
.flow-run-log__status--dead_letter {
	color: var(--color-error);
}

.flow-run-log__status--suspended,
.flow-run-log__status--skipped {
	color: var(--color-warning);
}
</style>

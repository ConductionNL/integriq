<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  TraceDetailPage — one execution_trace's detail view (manifest
  `type: custom`, `component: TraceDetailPage`, route `/traces/:id`).

  Renders the ordered step timeline (TraceTimelineWidget) and a Replay
  action: clicking "Replay" always runs a dry-run preview first (POST
  .../replay with no body / {force:false}) and shows the resulting preview
  trace's own timeline; a SEPARATE, explicitly confirmed action is required
  before a forced (real-write) replay is sent (execution-trace REQ-005/
  REQ-006/REQ-007). No inline NcModal/NcDialog is used for the confirmation
  step, so the interaction stays within the page (mirrors ApprovalDetail).

  @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
-->
<template>
	<div class="traceDetail">
		<NcButton type="tertiary" class="traceDetail__back" @click="goBack">
			{{ t('openconnector', 'Back to traces') }}
		</NcButton>

		<NcLoadingIcon v-if="loading" :size="32" class="traceDetail__loading" />

		<div v-else-if="trace" class="traceDetail__body">
			<div class="traceDetail__meta">
				<h2>{{ t('openconnector', 'Execution trace') }}</h2>
				<span class="traceDetail__badge" :class="`traceDetail__badge--${trace.status}`">
					{{ trace.status }}
				</span>
			</div>

			<dl class="traceDetail__fields">
				<dt>{{ t('openconnector', 'Entry point') }}</dt>
				<dd>{{ trace.entryPoint }} ({{ trace.entryPointId || '—' }})</dd>
				<dt>{{ t('openconnector', 'Started') }}</dt>
				<dd>{{ trace.startedAt || '—' }}</dd>
				<dt>{{ t('openconnector', 'Finished') }}</dt>
				<dd>{{ trace.finishedAt || '—' }}</dd>
				<dt>{{ t('openconnector', 'Duration') }}</dt>
				<dd>{{ trace.durationMs || 0 }} ms</dd>
				<dt v-if="trace.replayOf">
					{{ t('openconnector', 'Replay of') }}
				</dt>
				<dd v-if="trace.replayOf">
					{{ trace.replayOf }}
				</dd>
			</dl>

			<div v-if="trace.error" class="traceDetail__error" data-testid="trace-error">
				<strong>{{ t('openconnector', 'Error') }}:</strong> {{ trace.error.message }}
			</div>

			<h3>{{ t('openconnector', 'Steps') }}</h3>
			<TraceTimelineWidget :steps="trace.steps || []" />

			<div class="traceDetail__replay">
				<h3>{{ t('openconnector', 'Replay') }}</h3>

				<NcButton v-if="!preview"
					type="secondary"
					:disabled="busy"
					@click="runDryRun">
					{{ t('openconnector', 'Replay (dry-run preview)') }}
				</NcButton>

				<template v-else>
					<p data-testid="replay-preview-notice">
						{{ t('openconnector', 'Dry-run preview complete — no writes were performed.') }}
					</p>
					<TraceTimelineWidget :steps="preview.steps || []" />

					<template v-if="!confirmingForce">
						<div class="traceDetail__replayButtons">
							<NcButton type="secondary" :disabled="busy" @click="runDryRun">
								{{ t('openconnector', 'Re-run preview') }}
							</NcButton>
							<NcButton type="error" :disabled="busy" @click="confirmingForce = true">
								{{ t('openconnector', 'Force replay (real write)') }}
							</NcButton>
						</div>
					</template>
					<template v-else>
						<p class="traceDetail__forceWarning" data-testid="force-confirm-notice">
							{{ t('openconnector', 'This will perform a REAL write, reusing the original entry point\'s dispatch path. Are you sure?') }}
						</p>
						<div class="traceDetail__replayButtons">
							<NcButton type="error"
								:disabled="busy"
								data-testid="confirm-force-replay"
								@click="runForced">
								{{ t('openconnector', 'Confirm forced replay') }}
							</NcButton>
							<NcButton :disabled="busy" @click="confirmingForce = false">
								{{ t('openconnector', 'Cancel') }}
							</NcButton>
						</div>
					</template>
				</template>
			</div>
		</div>

		<NcEmptyContent v-else
			:name="t('openconnector', 'Trace not found')"
			:description="t('openconnector', 'It may have expired or you are not authorized to view it.')">
			<template #icon>
				<AlertCircleOutline :size="48" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
} from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import TraceTimelineWidget from './TraceTimelineWidget.vue'

export default {
	name: 'TraceDetailPage',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		AlertCircleOutline,
		TraceTimelineWidget,
	},

	data() {
		return {
			trace: null,
			loading: false,
			busy: false,
			preview: null,
			confirmingForce: false,
		}
	},

	computed: {
		/** @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007 */
		traceId() {
			return this.$route?.params?.id
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		/**
		 * Navigate back to the traces list.
		 * @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
		 */
		goBack() {
			this.$router.push('/traces')
		},
		/**
		 * Fetch this trace's detail, including its full ordered steps array.
		 * @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
		 */
		async load() {
			this.loading = true
			try {
				const res = await axios.get(generateUrl(`/apps/openconnector/api/execution-traces/${this.traceId}`))
				this.trace = res.data
			} catch (err) {
				this.trace = null
			} finally {
				this.loading = false
			}
		},
		/**
		 * Run a dry-run replay preview (default — no writes performed).
		 * @spec openspec/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005
		 */
		async runDryRun() {
			this.busy = true
			this.confirmingForce = false
			try {
				const res = await axios.post(
					generateUrl(`/apps/openconnector/api/execution-traces/${this.traceId}/replay`),
					{ force: false },
				)
				this.preview = res.data
				showSuccess(t('openconnector', 'Dry-run preview complete'))
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				showError(t('openconnector', 'Dry-run preview failed') + (detail ? `: ${detail}` : ''))
			} finally {
				this.busy = false
			}
		},
		/**
		 * Run a forced (real-write) replay — only reachable after the
		 * explicit confirmation step.
		 * @spec openspec/specs/execution-trace/spec.md#requirement-forced-replay-reuses-the-original-entry-points-real-dispatch-path-req-006
		 */
		async runForced() {
			this.busy = true
			try {
				const res = await axios.post(
					generateUrl(`/apps/openconnector/api/execution-traces/${this.traceId}/replay`),
					{ force: true },
				)
				this.preview = res.data
				this.confirmingForce = false
				showSuccess(t('openconnector', 'Forced replay complete'))
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				showError(t('openconnector', 'Forced replay failed') + (detail ? `: ${detail}` : ''))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.traceDetail {
	padding: 20px;
	max-width: 900px;
}

.traceDetail__back {
	margin-bottom: 16px;
}

.traceDetail__meta {
	display: flex;
	gap: 12px;
	align-items: center;
	margin-bottom: 16px;
}

.traceDetail__badge {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	font-weight: 600;
}

.traceDetail__badge--success {
	background: var(--color-success, #46ba61);
	color: var(--color-primary-text);
}

.traceDetail__badge--failed {
	background: var(--color-error, #e9322d);
	color: var(--color-primary-text);
}

.traceDetail__badge--running {
	background: var(--color-warning, #e9a13b);
	color: var(--color-primary-text);
}

.traceDetail__badge--short_circuited {
	background: var(--color-text-maxcontrast);
	color: var(--color-main-background);
}

.traceDetail__fields {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 6px 16px;
	margin-bottom: 20px;
}

.traceDetail__fields dt {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.traceDetail__error {
	margin-bottom: 16px;
	padding: 10px 12px;
	background: var(--color-error, #e9322d);
	color: var(--color-primary-text);
	border-radius: var(--border-radius);
}

.traceDetail__replay {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.traceDetail__replayButtons {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

.traceDetail__forceWarning {
	color: var(--color-error, #e9322d);
	font-weight: 600;
}

.traceDetail__loading {
	margin: 32px auto;
}
</style>

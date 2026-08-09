<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  TraceTimelineWidget — renders one execution_trace's ordered `steps` array
  (type/duration/status per step), each expandable to reveal its redacted
  input/output. Pure presentational sub-component used by TraceDetailPage;
  not a manifest-registered page/widget slot itself.

  @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
-->
<template>
	<ol class="traceTimeline" data-testid="trace-timeline">
		<li v-for="step in steps"
			:key="step.order"
			class="traceTimeline__step"
			:class="`traceTimeline__step--${step.status}`">
			<button
				type="button"
				class="traceTimeline__row"
				:aria-expanded="expanded.includes(step.order)"
				@click="toggle(step.order)">
				<span class="traceTimeline__order">{{ step.order }}</span>
				<span class="traceTimeline__type">{{ step.type }}</span>
				<span class="traceTimeline__name">{{ step.name }}</span>
				<span class="traceTimeline__badge" :class="`traceTimeline__badge--${step.status}`">
					{{ step.status }}
				</span>
				<span class="traceTimeline__duration">{{ step.durationMs }} ms</span>
			</button>
			<div v-if="expanded.includes(step.order)" class="traceTimeline__detail" data-testid="step-detail">
				<div class="traceTimeline__detailCol">
					<h4>{{ t('openconnector', 'Input') }}</h4>
					<pre>{{ formatJson(step.input) }}</pre>
				</div>
				<div class="traceTimeline__detailCol">
					<h4>{{ t('openconnector', 'Output') }}</h4>
					<pre>{{ formatJson(step.output) }}</pre>
				</div>
			</div>
		</li>
		<li v-if="!steps.length" class="traceTimeline__empty">
			{{ t('openconnector', 'No steps recorded for this trace.') }}
		</li>
	</ol>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'TraceTimelineWidget',

	props: {
		steps: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			expanded: [],
		}
	},

	methods: {
		t,
		/**
		 * Toggle a step's expanded (redacted input/output) state.
		 * @param {number} order The step's `order` field.
		 * @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
		 */
		toggle(order) {
			if (this.expanded.includes(order)) {
				this.expanded = this.expanded.filter((x) => x !== order)
			} else {
				this.expanded = [...this.expanded, order]
			}
		},
		/**
		 * Pretty-print a step's snapshot for display.
		 * @param {object} value The already-redacted input/output snapshot.
		 * @return {string}
		 */
		/**
		 * Pretty-prints a step's input/output payload for the expanded detail.
		 * Falls back to the stringified value on a circular structure rather
		 * than throwing — a step whose payload cannot be serialised must still
		 * render its place in the timeline.
		 *
		 * @param {*} value The step payload.
		 * @return {string}
		 * @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
		 */
		formatJson(value) {
			try {
				return JSON.stringify(value ?? {}, null, 2)
			} catch (err) {
				return String(value)
			}
		},
	},
}
</script>

<style scoped>
.traceTimeline {
	list-style: none;
	margin: 0;
	padding: 0;
}

.traceTimeline__step {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 8px;
	overflow: hidden;
}

/* A real <button>, not a clickable <div>: the row is a disclosure control, so
   it needs the keyboard and role a native button already has. These are the
   resets that make one look like the row it replaced. */
.traceTimeline__row {
	display: flex;
	align-items: center;
	gap: 12px;
	width: 100%;
	padding: 8px 12px;
	border: none;
	background: none;
	color: inherit;
	font: inherit;
	text-align: left;
	cursor: pointer;
}

.traceTimeline__row:hover {
	background: var(--color-background-hover);
}

.traceTimeline__order {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
	min-width: 24px;
}

.traceTimeline__type {
	text-transform: capitalize;
	min-width: 100px;
}

.traceTimeline__name {
	flex: 1;
	color: var(--color-text-maxcontrast);
}

.traceTimeline__badge {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
}

.traceTimeline__badge--success {
	background: var(--color-success, #46ba61);
	color: var(--color-primary-text);
}

.traceTimeline__badge--error {
	background: var(--color-error, #e9322d);
	color: var(--color-primary-text);
}

.traceTimeline__badge--skipped,
.traceTimeline__badge--skipped_dry_run {
	background: var(--color-text-maxcontrast);
	color: var(--color-main-background);
}

.traceTimeline__duration {
	color: var(--color-text-maxcontrast);
	min-width: 64px;
	text-align: right;
}

.traceTimeline__detail {
	display: flex;
	gap: 16px;
	padding: 12px;
	background: var(--color-background-hover);
	border-top: 1px solid var(--color-border);
}

.traceTimeline__detailCol {
	flex: 1;
	min-width: 0;
}

.traceTimeline__detailCol h4 {
	margin: 0 0 4px;
}

.traceTimeline__detailCol pre {
	white-space: pre-wrap;
	word-break: break-word;
	background: var(--color-main-background);
	padding: 8px;
	border-radius: var(--border-radius);
	max-height: 240px;
	overflow: auto;
}

.traceTimeline__empty {
	color: var(--color-text-maxcontrast);
	padding: 16px 0;
}
</style>

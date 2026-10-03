<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  CallBulkReplayModal: select several failed outbound calls and replay them
  together, with an outcome per call.

  The log page itself has no row selection, so the selection lives here: the
  modal lists the recent calls whose last attempt failed, every one checked,
  and replays the checked ones in one request. The answer is per item, so
  one partner still being down does not hide that the others went through.

  @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
-->
<template>
	<NcModal
		v-if="open"
		labelId="call-bulk-replay-title"
		data-testid="call-bulk-replay-modal"
		@close="$emit('close')">
		<div class="callBulkReplay">
			<h2 id="call-bulk-replay-title">
				{{ t('integriq', 'Replay failed calls') }}
			</h2>

			<p v-if="loading">
				{{ t('integriq', 'Loading the failed calls') }}
			</p>
			<p v-else-if="error" class="callBulkReplay__error" role="alert">
				{{ error }}
			</p>
			<p v-else-if="calls.length === 0" data-testid="call-bulk-replay-empty">
				{{
					t(
						'integriq',
						'No recent call failed. There is nothing to replay.',
					)
				}}
			</p>

			<ul
				v-else
				class="callBulkReplay__list"
				data-testid="call-bulk-replay-list">
				<li v-for="call in calls" :key="idOf(call)">
					<NcCheckboxRadioSwitch
						:modelValue="selected.includes(idOf(call))"
						@update:modelValue="toggle(idOf(call), $event)">
						{{ labelOf(call) }}
					</NcCheckboxRadioSwitch>
					<span
						v-if="outcomes[idOf(call)]"
						class="callBulkReplay__outcome"
						:class="
							outcomes[idOf(call)].succeeded
								? 'callBulkReplay__outcome--ok'
								: 'callBulkReplay__outcome--failed'
						"
						data-testid="call-bulk-replay-item-outcome">
						{{ outcomeOf(outcomes[idOf(call)]) }}
					</span>
				</li>
			</ul>

			<p v-if="summary" role="status" data-testid="call-bulk-replay-summary">
				{{ summary }}
			</p>

			<div class="callBulkReplay__actions">
				<NcButton
					variant="primary"
					:disabled="selected.length === 0 || busy"
					data-testid="call-bulk-replay-send"
					@click="replay">
					{{
						n(
							'integriq',
							'Replay %n call',
							'Replay %n calls',
							selected.length,
						)
					}}
				</NcButton>
				<NcButton @click="$emit('close')">
					{{ t('integriq', 'Close') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcModal } from '@nextcloud/vue'
import { callId, recentFailedCalls, replayCalls } from './callLogApi.js'

export default {
	name: 'CallBulkReplayModal',

	components: { NcButton, NcCheckboxRadioSwitch, NcModal },

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'replayed'],

	data() {
		return {
			loading: false,
			busy: false,
			error: '',
			calls: [],
			selected: [],
			outcomes: {},
			summary: '',
		}
	},

	watch: {
		open: {
			immediate: true,
			/**
			 * Load the failed calls each time the modal opens.
			 *
			 * @param {boolean} isOpen whether the modal is open
			 * @return {void}
			 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
			 */
			handler(isOpen) {
				if (isOpen) {
					this.load()
				}
			},
		},
	},

	methods: {
		t,
		n,
		idOf: callId,

		/**
		 * Load the recent failed calls, all of them selected.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
		 */
		async load() {
			this.loading = true
			this.error = ''
			this.outcomes = {}
			this.summary = ''
			try {
				this.calls = await recentFailedCalls()
				this.selected = this.calls.map(callId).filter((id) => id !== '')
			} catch {
				this.calls = []
				this.selected = []
				this.error = t('integriq', 'The failed calls could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Check or uncheck one call.
		 *
		 * @param {string} id the call uuid
		 * @param {boolean} checked whether it is now checked
		 * @return {void}
		 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
		 */
		toggle(id, checked) {
			this.selected = checked
				? [...new Set([...this.selected, id])]
				: this.selected.filter((item) => item !== id)
		},

		/**
		 * One line per call: when, what, and what the partner said.
		 *
		 * @param {object} call a call_log object
		 * @return {string}
		 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
		 */
		labelOf(call) {
			const target =
				call.target
				|| call.request?.url
				|| call.request?.endpoint
				|| callId(call)
			return `${call.created || ''} ${target} (${call.statusCode ?? 0} ${call.statusMessage || ''})`.trim()
		},

		/**
		 * One outcome, in words.
		 *
		 * @param {object} item a per-item outcome
		 * @return {string}
		 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
		 */
		outcomeOf(item) {
			return item.succeeded
				? t('integriq', 'Sent, the partner answered {status}', {
						status: item.statusCode,
					})
				: t('integriq', 'Failed: {detail}', { detail: item.detail || '' })
		},

		/**
		 * Replay the checked calls and show each one's outcome.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
		 */
		async replay() {
			this.busy = true
			this.error = ''
			try {
				const result = await replayCalls([...this.selected])
				const outcomes = {}
				for (const item of result?.items ?? []) {
					outcomes[String(item.call)] = item
				}
				this.outcomes = outcomes
				this.summary = t('integriq', '{succeeded} sent, {failed} failed.', {
					succeeded: result?.succeeded ?? 0,
					failed: result?.failed ?? 0,
				})
				this.$emit('replayed', result)
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| t('integriq', 'The replay could not be started.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.callBulkReplay {
	padding: calc(var(--default-grid-baseline) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.callBulkReplay__list {
	max-height: 50vh;
	overflow: auto;
	list-style: none;
	padding: 0;
}

.callBulkReplay__outcome {
	margin-inline-start: calc(var(--default-grid-baseline) * 8);
}

.callBulkReplay__outcome--ok {
	color: var(--color-success-text);
}

.callBulkReplay__outcome--failed,
.callBulkReplay__error {
	color: var(--color-error-text);
}

.callBulkReplay__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	justify-content: flex-end;
}
</style>

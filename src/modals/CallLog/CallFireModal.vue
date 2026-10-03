<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  CallFireModal: fire an outbound call by hand, for a message that has not
  been sent yet (a partner asks for it again today).

  The administrator picks the source, the method and the endpoint, and writes
  the body. A dry run shows the request that would go out and sends nothing.
  A real call is recorded as hand-fired and names who fired it; to the
  receiver it is the same request a triggered call would send.

  @spec openspec/specs/outbound-call-log/spec.md#requirement-a-call-can-be-fired-by-hand-req-ocd-003
-->
<template>
	<NcModal
		v-if="open"
		labelId="call-fire-title"
		data-testid="call-fire-modal"
		@close="$emit('close')">
		<form class="callFire" @submit.prevent="fire(false)">
			<h2 id="call-fire-title">
				{{ t('integriq', 'Fire a call by hand') }}
			</h2>

			<NcSelect
				inputId="call-fire-source"
				:inputLabel="t('integriq', 'Source to call')"
				:ariaLabelCombobox="t('integriq', 'Source to call')"
				:modelValue="selectedSource"
				:options="sourceOptions"
				:loading="sourcesLoading"
				:clearable="false"
				:placeholder="t('integriq', 'Select a source')"
				@update:modelValue="selectedSource = $event" />

			<NcSelect
				inputId="call-fire-method"
				:inputLabel="t('integriq', 'Method')"
				:ariaLabelCombobox="t('integriq', 'Method')"
				:modelValue="method"
				:options="methods"
				:clearable="false"
				@update:modelValue="method = $event" />

			<NcTextField
				v-model="endpoint"
				:label="t('integriq', 'Endpoint, relative to the source')"
				data-testid="call-fire-endpoint" />

			<NcTextArea
				v-model="body"
				:label="t('integriq', 'Body, as JSON')"
				resize="vertical"
				data-testid="call-fire-body" />
			<p v-if="bodyError" class="callFire__error" role="alert">
				{{ bodyError }}
			</p>

			<p v-if="error" class="callFire__error" role="alert">
				{{ error }}
			</p>
			<div
				v-if="outcome"
				role="status"
				class="callFire__outcome"
				data-testid="call-fire-outcome">
				<p>{{ outcomeText }}</p>
				<pre v-if="outcome.request" class="callFire__request">{{
					JSON.stringify(outcome.request, null, 2)
				}}</pre>
			</div>

			<div class="callFire__actions">
				<NcButton
					:disabled="!canFire || busy"
					data-testid="call-fire-dry-run"
					@click="fire(true)">
					{{ t('integriq', 'Dry run') }}
				</NcButton>
				<NcButton
					type="submit"
					variant="primary"
					:disabled="!canFire || busy"
					data-testid="call-fire-send">
					{{ t('integriq', 'Send') }}
				</NcButton>
				<NcButton @click="$emit('close')">
					{{ t('integriq', 'Close') }}
				</NcButton>
			</div>
		</form>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcModal, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import { fireCall } from './callLogApi.js'

export default {
	name: 'CallFireModal',

	components: { NcButton, NcModal, NcSelect, NcTextArea, NcTextField },

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'fired'],

	data() {
		return {
			sourceOptions: [],
			sourcesLoading: false,
			selectedSource: null,
			methods: ['POST', 'PUT', 'PATCH', 'GET', 'DELETE'],
			method: 'POST',
			endpoint: '',
			body: '',
			busy: false,
			error: '',
			outcome: null,
		}
	},

	computed: {
		/**
		 * The body parsed as JSON, or undefined when it is empty or broken.
		 *
		 * @return {object|undefined}
		 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-call-can-be-fired-by-hand-req-ocd-003
		 */
		parsedBody() {
			if (this.body.trim() === '') {
				return undefined
			}
			try {
				return JSON.parse(this.body)
			} catch {
				return undefined
			}
		},

		/**
		 * Why the body cannot be sent, when it cannot.
		 *
		 * @return {string}
		 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-call-can-be-fired-by-hand-req-ocd-003
		 */
		bodyError() {
			if (this.body.trim() !== '' && this.parsedBody === undefined) {
				return t('integriq', 'The body is not valid JSON.')
			}
			return ''
		},

		/**
		 * Whether there is enough to fire.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-call-can-be-fired-by-hand-req-ocd-003
		 */
		canFire() {
			return this.selectedSource !== null && this.bodyError === ''
		},

		/**
		 * One sentence on what the last dry run or call did.
		 *
		 * @return {string}
		 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-call-can-be-fired-by-hand-req-ocd-003
		 */
		outcomeText() {
			const item = this.outcome
			if (!item) {
				return ''
			}
			if (item.sent === false && item.succeeded === true) {
				return t(
					'integriq',
					'Dry run: this is what would be sent. Nothing was sent.',
				)
			}
			if (item.succeeded === true) {
				return t(
					'integriq',
					'Sent. The partner answered {status}, and the call is in the log.',
					{
						status: item.statusCode,
					},
				)
			}
			return t('integriq', 'The call failed: {detail}', {
				detail: item.detail || '',
			})
		},
	},

	watch: {
		open: {
			immediate: true,
			/**
			 * Load the sources each time the modal opens.
			 *
			 * @param {boolean} isOpen whether the modal is open
			 * @return {void}
			 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-call-can-be-fired-by-hand-req-ocd-003
			 */
			handler(isOpen) {
				if (isOpen) {
					this.outcome = null
					this.error = ''
					this.fetchSources()
				}
			},
		},
	},

	methods: {
		t,

		/**
		 * Load the sources a call can go to.
		 *
		 * @return {Promise<void>}
		 * @spec exclude data-fetch helper, presentation only
		 */
		async fetchSources() {
			this.sourcesLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/objects/integriq/source'),
					// `_limit`, not `limit`: an unprefixed param is a property filter.
					{ params: { _limit: 500 } },
				)
				const list = Array.isArray(response.data?.results)
					? response.data.results
					: []
				this.sourceOptions = list.map((item) => ({
					id: String(item['@self']?.id || item.id || item.uuid),
					label: item.name || item.title || String(item.id),
				}))
			} catch {
				this.sourceOptions = []
				this.error = t('integriq', 'The sources could not be loaded.')
			} finally {
				this.sourcesLoading = false
			}
		},

		/**
		 * Dry run or send the call, and show the outcome.
		 *
		 * @param {boolean} dryRun show what would be sent instead of sending it
		 * @return {Promise<void>}
		 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-call-can-be-fired-by-hand-req-ocd-003
		 */
		async fire(dryRun) {
			if (!this.canFire) {
				return
			}
			this.busy = true
			this.error = ''
			const request = { method: this.method, endpoint: this.endpoint.trim() }
			if (this.parsedBody !== undefined) {
				request.body = this.parsedBody
			}
			try {
				this.outcome = await fireCall(
					this.selectedSource.id,
					request,
					dryRun,
				)
				if (!dryRun) {
					this.$emit('fired', this.outcome)
				}
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| t('integriq', 'The call could not be fired.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.callFire {
	padding: calc(var(--default-grid-baseline) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.callFire__request {
	max-height: 30vh;
	overflow: auto;
	padding: calc(var(--default-grid-baseline) * 2);
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.callFire__error {
	color: var(--color-error-text);
}

.callFire__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	justify-content: flex-end;
}
</style>

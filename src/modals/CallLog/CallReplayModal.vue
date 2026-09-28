<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  CallReplayModal: replay one outbound call from the call log.

  Opens on the preview (what a replay would send, and the mapping version the
  call ran under beside the current one), then lets the administrator dry run
  it or send it. A dry run shows the request and writes nothing; a replay
  appends an attempt to the same record. Neither version is chosen silently:
  the recorded one is preselected, and the outcome names the one used.

  @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
-->
<template>
	<NcModal
		v-if="open"
		labelId="call-replay-title"
		data-testid="call-replay-modal"
		@close="$emit('close')">
		<div class="callReplay">
			<h2 id="call-replay-title">
				{{ t('integriq', 'Replay a call') }}
			</h2>

			<p v-if="loading">
				{{ t('integriq', 'Loading what a replay would send') }}
			</p>
			<p v-else-if="error" class="callReplay__error" role="alert">
				{{ error }}
			</p>

			<template v-else-if="preview">
				<fieldset
					v-if="versionChoice"
					class="callReplay__versions"
					data-testid="call-replay-versions">
					<legend>
						{{ t('integriq', 'Mapping version to replay under') }}
					</legend>
					<NcCheckboxRadioSwitch
						v-model="mappingVersion"
						type="radio"
						name="call-replay-version"
						:value="preview.versions.recorded">
						{{
							t(
								'integriq',
								'Version {version}, the one the call ran under',
								{
									version: preview.versions.recorded,
								},
							)
						}}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="mappingVersion"
						type="radio"
						name="call-replay-version"
						:value="preview.versions.current">
						{{
							t('integriq', 'Version {version}, the current one', {
								version: preview.versions.current,
							})
						}}
					</NcCheckboxRadioSwitch>
				</fieldset>

				<h3>{{ t('integriq', 'Request to send') }}</h3>
				<pre class="callReplay__request" data-testid="call-replay-request">{{
					prettyRequest
				}}</pre>
			</template>

			<div
				v-if="outcome"
				class="callReplay__outcome"
				role="status"
				data-testid="call-replay-outcome">
				<p>{{ outcomeText }}</p>
				<pre v-if="outcome.request" class="callReplay__request">{{
					JSON.stringify(outcome.request, null, 2)
				}}</pre>
			</div>

			<div class="callReplay__actions">
				<NcButton
					:disabled="!preview || busy"
					data-testid="call-replay-dry-run"
					@click="run(true)">
					{{ t('integriq', 'Dry run') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="!preview || busy"
					data-testid="call-replay-send"
					@click="run(false)">
					{{ t('integriq', 'Replay') }}
				</NcButton>
				<NcButton @click="$emit('close')">
					{{ t('integriq', 'Close') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcModal } from '@nextcloud/vue'
import { previewCall, replayCalls } from './callLogApi.js'

export default {
	name: 'CallReplayModal',

	components: { NcButton, NcCheckboxRadioSwitch, NcModal },

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		callId: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'replayed'],

	data() {
		return {
			loading: false,
			busy: false,
			error: '',
			preview: null,
			mappingVersion: '',
			outcome: null,
		}
	},

	computed: {
		/**
		 * Whether there are two mapping versions to choose between.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-replay-names-the-mapping-version-it-ran-under-req-ocd-005
		 */
		versionChoice() {
			return this.preview?.versions?.differ === true
		},

		/**
		 * The request a replay would send, for reading.
		 *
		 * @return {string}
		 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
		 */
		prettyRequest() {
			return JSON.stringify(this.preview?.request ?? {}, null, 2)
		},

		/**
		 * One sentence on what the last dry run or replay did.
		 *
		 * @return {string}
		 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
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
					'Replayed under mapping version {version}. The partner answered {status}.',
					{
						version: item.mappingVersion || '-',
						status: item.statusCode,
					},
				)
			}
			return t('integriq', 'The replay failed: {detail}', {
				detail: item.detail || '',
			})
		},
	},

	watch: {
		open: {
			immediate: true,
			/**
			 * Load the preview each time the modal opens.
			 *
			 * @param {boolean} isOpen whether the modal is open
			 * @return {void}
			 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
			 */
			handler(isOpen) {
				if (isOpen && this.callId) {
					this.load()
				}
			},
		},
	},

	methods: {
		t,

		/**
		 * Fetch the preview and preselect the recorded mapping version.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-replay-names-the-mapping-version-it-ran-under-req-ocd-005
		 */
		async load() {
			this.loading = true
			this.error = ''
			this.outcome = null
			try {
				this.preview = await previewCall(this.callId)
				this.mappingVersion = this.preview?.versions?.recorded || ''
			} catch (e) {
				this.preview = null
				this.error =
					e?.response?.data?.error
					|| t('integriq', 'This call could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Dry run or replay the call, and show the outcome.
		 *
		 * @param {boolean} dryRun show what would be sent instead of sending it
		 * @return {Promise<void>}
		 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
		 */
		async run(dryRun) {
			this.busy = true
			this.error = ''
			try {
				const result = await replayCalls([this.callId], {
					dryRun,
					mappingVersion: this.mappingVersion,
				})
				this.outcome = result?.items?.[0] ?? null
				if (!dryRun) {
					this.$emit('replayed', result)
				}
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
.callReplay {
	padding: calc(var(--default-grid-baseline) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.callReplay__request {
	max-height: 40vh;
	overflow: auto;
	padding: calc(var(--default-grid-baseline) * 2);
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.callReplay__error {
	color: var(--color-error-text);
}

.callReplay__versions {
	border: none;
	padding: 0;
}

.callReplay__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	justify-content: flex-end;
}
</style>

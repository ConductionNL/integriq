<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Test source modal — manifest-driven revival of the legacy
  src/modals/TestSource/TestSource.vue, ported to the modalBus/ModalHost v2
  pattern (the old one referenced the removed navigationStore/sourceStore and
  was never mounted, so the "Test connection" row action was a dead end).

  Runs one live request against the selected source and shows the full
  response so a connection — including a vault-injected credentialRef — can be
  verified from the UI.

  Backend contract (SourcesController::test):
    POST /api/sources/test/{id}  body: { method, endpoint, body, type }
    Response: the CallLog object, whose `response` sub-object is
      { statusCode, statusMessage, responseTime, size, remoteIp, headers, body, encoding }
    On a failed call the controller returns { error: "<message>" } instead.
-->
<template>
	<NcModal v-if="open" labelId="testSourceModal" size="large" @close="onClose">
		<div class="cn-test-source-modal">
			<h2>{{ t('integriq', 'Test connection') }}</h2>

			<NcNoteCard v-if="sourceName" type="info">
				<p>
					{{
						t('integriq', 'Testing source: {name}', {
							name: sourceName,
						})
					}}
				</p>
			</NcNoteCard>

			<div class="cn-test-source-modal__panes">
				<!-- Left: request -->
				<section class="cn-test-source-modal__pane">
					<h3>{{ t('integriq', 'Request') }}</h3>

					<div class="cn-test-source-modal__row">
						<NcSelect
							id="cn-test-source-method"
							v-model="method"
							:options="methodOptions"
							:aria-label-combobox="t('integriq', 'Method')"
							:clearable="false"
							inputId="cn-test-source-method" />
						<NcSelect
							id="cn-test-source-type"
							v-model="type"
							:options="typeOptions"
							:aria-label-combobox="t('integriq', 'Body type')"
							:clearable="false"
							inputId="cn-test-source-type" />
					</div>

					<label for="cn-test-source-endpoint">
						{{
							t(
								'integriq',
								'Endpoint (path appended to the source location)',
							)
						}}
					</label>
					<NcTextField
						id="cn-test-source-endpoint"
						v-model="endpoint"
						:label="t('integriq', 'Endpoint')"
						placeholder="/health" />

					<label for="cn-test-source-body">
						{{ t('integriq', 'Body (optional)') }}
					</label>
					<textarea
						id="cn-test-source-body"
						v-model="body"
						class="cn-test-source-modal__textarea"
						rows="8"
						spellcheck="false"
						:placeholder="t('integriq', 'Request body for POST/PUT')" />

					<div class="cn-test-source-modal__actions">
						<NcButton @click="onClose">
							{{ t('integriq', 'Close') }}
						</NcButton>
						<NcButton
							variant="primary"
							:disabled="running || !canRun"
							@click="runTest">
							<template #icon>
								<NcLoadingIcon v-if="running" :size="20" />
								<PlayOutlineIcon v-else :size="20" />
							</template>
							{{ t('integriq', 'Run test') }}
						</NcButton>
					</div>
				</section>

				<!-- Right: response -->
				<section class="cn-test-source-modal__pane">
					<h3>{{ t('integriq', 'Response') }}</h3>

					<div
						v-if="!hasResult && !runError"
						class="cn-test-source-modal__empty">
						{{ t('integriq', 'Run the test to see the response here.') }}
					</div>

					<NcNoteCard v-if="runError" type="error">
						<p>{{ runError }}</p>
					</NcNoteCard>

					<template v-if="hasResult">
						<NcNoteCard v-if="isSuccess" type="success">
							<p>
								{{
									t(
										'integriq',
										'The connection to the source was successful.',
									)
								}}
							</p>
						</NcNoteCard>
						<NcNoteCard v-else type="warning">
							<p>
								{{
									t(
										'integriq',
										'The source responded with a non-2xx status.',
									)
								}}
							</p>
						</NcNoteCard>

						<dl class="cn-test-source-modal__meta">
							<dt>{{ t('integriq', 'Status') }}</dt>
							<dd>
								{{ response.statusMessage }} ({{
									response.statusCode
								}})
							</dd>
							<dt>{{ t('integriq', 'Response time') }}</dt>
							<dd>{{ formatMs(response.responseTime) }}</dd>
							<dt>{{ t('integriq', 'Size') }}</dt>
							<dd>{{ response.size }} {{ t('integriq', 'bytes') }}</dd>
							<dt v-if="response.remoteIp">
								{{ t('integriq', 'Remote IP') }}
							</dt>
							<dd v-if="response.remoteIp">
								{{ response.remoteIp }}
							</dd>
						</dl>

						<label>{{ t('integriq', 'Headers') }}</label>
						<pre class="cn-test-source-modal__pre">{{
							prettify(response.headers)
						}}</pre>

						<label>{{ t('integriq', 'Body') }}</label>
						<pre class="cn-test-source-modal__pre">{{
							prettify(response.body)
						}}</pre>
					</template>
				</section>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import PlayOutlineIcon from 'vue-material-design-icons/PlayOutline.vue'

export default {
	name: 'TestSourceModal',

	components: {
		NcModal,
		NcButton,
		NcSelect,
		NcTextField,
		NcLoadingIcon,
		NcNoteCard,
		PlayOutlineIcon,
	},

	props: {
		/** Whether the modal is mounted/visible. */
		open: { type: Boolean, default: false },
		/** Pre-selected source row to test (from the row-action context). */
		source: { type: Object, default: null },
	},

	data() {
		return {
			method: 'GET',
			type: 'JSON',
			endpoint: '',
			body: '',
			methodOptions: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
			typeOptions: ['JSON', 'XML', 'YAML'],
			running: false,
			runError: '',
			response: null,
		}
	},

	computed: {
		/** @spec openspec/specs/http-call-engine/spec.md */
		sourceId() {
			return (
				this.source?.id
				|| this.source?.uuid
				|| this.source?.['@self']?.id
				|| null
			)
		},

		/** @spec openspec/specs/http-call-engine/spec.md */
		sourceName() {
			return this.source?.name || ''
		},

		/** @spec openspec/specs/http-call-engine/spec.md */
		canRun() {
			return !!this.sourceId
		},

		hasResult() {
			return this.response !== null
		},

		/** @spec openspec/specs/http-call-engine/spec.md */
		isSuccess() {
			return String(this.response?.statusCode ?? '').startsWith('2')
		},
	},

	watch: {
		/**
		 * @param value
		 * @spec openspec/specs/http-call-engine/spec.md
		 */
		open(value) {
			if (value) {
				this.resetState()
			}
		},
	},

	methods: {
		/** @spec openspec/specs/http-call-engine/spec.md */
		onClose() {
			this.$emit('close')
		},

		/** @spec openspec/specs/http-call-engine/spec.md */
		resetState() {
			this.method = 'GET'
			this.type = 'JSON'
			this.endpoint = ''
			this.body = ''
			this.runError = ''
			this.response = null
		},

		/** @spec openspec/specs/http-call-engine/spec.md */
		async runTest() {
			if (!this.sourceId) {
				return
			}
			this.runError = ''
			this.response = null
			this.running = true
			try {
				const payload = {
					method: this.method,
					endpoint: this.endpoint,
					type: (this.type || 'JSON').toLowerCase(),
				}
				if (this.body && this.body.trim().length > 0) {
					payload.body = this.body
				}
				const res = await axios.post(
					generateUrl(`/apps/integriq/api/sources/test/${this.sourceId}`),
					payload,
				)
				// The controller returns the CallLog object; the live request/response
				// lives under `response`. A failed call returns { error } instead.
				const data = res.data || {}
				if (data.response && typeof data.response === 'object') {
					this.response = data.response
					showSuccess(t('integriq', 'Test completed.'))
				} else if (data.error) {
					this.runError = data.error
					showError(this.runError)
				} else {
					this.runError = t(
						'integriq',
						'The source test returned no response data.',
					)
					showError(this.runError)
				}
			} catch (err) {
				const message =
					err?.response?.data?.error
					|| err?.response?.data?.message
					|| err?.message
					|| ''
				this.runError =
					t('integriq', 'Source test failed')
					+ (message ? `: ${message}` : '')
				showError(this.runError)
			} finally {
				this.running = false
			}
		},

		/**
		 * @param ms
		 * @spec openspec/specs/http-call-engine/spec.md
		 */
		formatMs(ms) {
			if (ms === null || ms === undefined) return '—'
			return `${Math.round(Number(ms))} ${t('integriq', 'ms')}`
		},

		/**
		 * @param value
		 * @spec openspec/specs/http-call-engine/spec.md
		 */
		prettify(value) {
			if (value === null || value === undefined || value === '') return '—'
			if (typeof value === 'string') {
				try {
					return JSON.stringify(JSON.parse(value), null, 2)
				} catch (_e) {
					return value
				}
			}
			try {
				return JSON.stringify(value, null, 2)
			} catch (_e) {
				return String(value)
			}
		},
	},
}
</script>

<style scoped>
.cn-test-source-modal {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 20px;
	min-width: 720px;
	max-width: 1100px;
}

.cn-test-source-modal__panes {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
	gap: 16px;
}

.cn-test-source-modal__pane {
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-width: 0;
}

.cn-test-source-modal__row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
}

.cn-test-source-modal__textarea {
	width: 100%;
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
	resize: vertical;
	padding: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.cn-test-source-modal__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.cn-test-source-modal__meta {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 2px 12px;
	margin: 4px 0;
}

.cn-test-source-modal__meta dt {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.cn-test-source-modal__meta dd {
	margin: 0;
}

.cn-test-source-modal__pre {
	background: var(--color-background-dark);
	color: var(--color-main-text);
	padding: 12px;
	border-radius: var(--border-radius);
	overflow: auto;
	max-height: 220px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 12px;
	white-space: pre-wrap;
	word-break: break-word;
}

.cn-test-source-modal__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>

<!--
  Live-output console for a streamed synchronization run or test (#1082).

  Consumes the SSE stream the run/test endpoints emit when opted into with
  `stream=1`, rendering each frame as it arrives so an operator can watch a long
  run instead of waiting on a response that may die at the proxy.

  fetch() + ReadableStream rather than EventSource: the endpoints are POST and
  require Nextcloud's requesttoken CSRF header, and EventSource is GET-only with no
  way to set headers. See design.md Decision 6.

  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcModal v-if="open"
		:name="title"
		size="large"
		@close="close">
		<div class="run-console">
			<h2>{{ title }}</h2>

			<div class="run-console__status">
				<NcLoadingIcon v-if="running" :size="20" />
				<span>{{ statusLabel }}</span>
				<span v-if="traceId" class="run-console__trace">{{ t('openconnector', 'Trace') }}: {{ traceId }}</span>
			</div>

			<!--
				aria-live so a screen reader announces frames as they arrive rather
				than leaving the operator to poll a silent region (WCAG 2.1 AA, and
				the spec's accessibility NFR).
			-->
			<div ref="log"
				class="run-console__log"
				role="log"
				aria-live="polite"
				:aria-label="t('openconnector', 'Live run output')">
				<p v-if="events.length === 0" class="run-console__empty">
					{{ t('openconnector', 'Waiting for output…') }}
				</p>
				<div v-for="(event, index) in events"
					:key="index"
					:class="['run-console__line', 'run-console__line--' + event.kind]">
					<span class="run-console__kind">{{ event.kind }}</span>
					<span class="run-console__text">{{ event.text }}</span>
				</div>
			</div>

			<div v-if="result" class="run-console__result">
				<h3>{{ t('openconnector', 'Result') }}</h3>
				<pre>{{ prettyResult }}</pre>
			</div>

			<div class="run-console__actions">
				<NcButton v-if="running" type="secondary" @click="abort">
					{{ t('openconnector', 'Stop watching') }}
				</NcButton>
				<NcButton type="primary" @click="close">
					{{ t('openconnector', 'Close') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcLoadingIcon, NcModal } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { getRequestToken } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'RunOutputConsole',

	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
	},

	data() {
		return {
			open: false,
			running: false,
			mode: 'run',
			name: '',
			traceId: '',
			events: [],
			result: null,
			controller: null,
			failed: false,
		}
	},

	computed: {
		title() {
			if (this.mode === 'test') {
				return t('openconnector', 'Test output: {name}', { name: this.name })
			}
			return t('openconnector', 'Run output: {name}', { name: this.name })
		},

		statusLabel() {
			if (this.running) {
				return t('openconnector', 'Running…')
			}
			if (this.failed) {
				return t('openconnector', 'Finished with errors')
			}
			if (this.result) {
				return t('openconnector', 'Finished')
			}
			return t('openconnector', 'Stopped')
		},

		prettyResult() {
			return JSON.stringify(this.result, null, 2)
		},
	},

	beforeDestroy() {
		// Never leave a reader attached to a dead component.
		this.abort()
	},

	methods: {
		t,

		/**
		 * Open the console and start streaming.
		 *
		 * @param {object} payload Bus payload.
		 * @param {object} payload.item The synchronization row.
		 * @param {string} payload.mode Either 'run' or 'test'.
		 */
		start({ item, mode }) {
			this.open = true
			this.mode = mode || 'run'
			this.name = item?.name || item?.id || item?.uuid || ''
			this.events = []
			this.result = null
			this.traceId = ''
			this.failed = false

			const id = item?.id || item?.uuid
			if (!id) {
				this.push('error', t('openconnector', 'No synchronization id on this row.'))
				return
			}

			this.stream(id)
		},

		/**
		 * POST with the streaming flag and read the response body as it arrives.
		 *
		 * @param {string} id The synchronization id.
		 */
		async stream(id) {
			this.running = true
			this.controller = new AbortController()

			const path = `/apps/openconnector/api/synchronizations/${id}/${this.mode}?stream=1`

			try {
				const response = await fetch(generateUrl(path), {
					method: 'POST',
					headers: {
						// EventSource cannot send this, which is why fetch is used.
						requesttoken: getRequestToken(),
						Accept: 'text/event-stream',
					},
					signal: this.controller.signal,
				})

				if (!response.ok) {
					// A rejection arrives as a real status because the streaming
					// branch sits after the auth checks — so it is a status here,
					// not an error frame.
					this.push('error', t('openconnector', 'Request rejected with status {status}', { status: response.status }))
					this.failed = true
					return
				}

				await this.consume(response.body.getReader())
			} catch (error) {
				if (error.name !== 'AbortError') {
					this.push('error', error.message)
					this.failed = true
				}
			} finally {
				this.running = false
				this.controller = null
			}
		},

		/**
		 * Read chunks and split them into whole SSE frames.
		 *
		 * A chunk boundary can fall anywhere, including mid-frame, so a buffer holds
		 * the remainder until the blank-line terminator arrives. Parsing per chunk
		 * instead would silently drop or corrupt any frame that straddles a
		 * boundary — which is exactly what happens under load, when frames are
		 * largest and matter most.
		 *
		 * @param {ReadableStreamDefaultReader} reader The body reader.
		 */
		async consume(reader) {
			const decoder = new TextDecoder()
			let buffer = ''

			while (true) {
				const { done, value } = await reader.read()
				if (done) {
					break
				}

				buffer += decoder.decode(value, { stream: true })

				let boundary = buffer.indexOf('\n\n')
				while (boundary !== -1) {
					this.handleFrame(buffer.slice(0, boundary))
					buffer = buffer.slice(boundary + 2)
					boundary = buffer.indexOf('\n\n')
				}
			}

			// A trailing frame without its terminator means the socket died
			// mid-write — which is precisely the case this feature exists to show,
			// so parse it rather than discarding it.
			if (buffer.trim() !== '') {
				this.handleFrame(buffer)
			}
		},

		/**
		 * Turn one raw SSE frame into a rendered line.
		 *
		 * @param {string} frame The raw frame text.
		 */
		handleFrame(frame) {
			let event = 'message'
			let data = ''

			for (const line of frame.split('\n')) {
				if (line.startsWith('event: ')) {
					event = line.slice(7).trim()
				} else if (line.startsWith('data: ')) {
					data += line.slice(6)
				}
			}

			let payload = {}
			try {
				payload = data ? JSON.parse(data) : {}
			} catch (error) {
				this.push('error', t('openconnector', 'Unreadable frame: {data}', { data }))
				return
			}

			if (event === 'open') {
				this.traceId = payload.traceId || ''
				this.push('open', t('openconnector', 'Connected, run started.'))
				return
			}

			if (event === 'progress') {
				const ms = payload.durationMs ?? 0
				this.push(
					payload.status === 'error' ? 'error' : 'progress',
					`${payload.type || '?'} · ${payload.name || '?'} · ${payload.status || '?'} (${ms}ms)`,
				)
				return
			}

			if (event === 'result') {
				this.result = payload
				this.push('result', t('openconnector', 'Run finished.'))
				return
			}

			if (event === 'error' || event === 'fatal') {
				this.failed = true
				const where = payload.file ? ` (${payload.file}:${payload.line})` : ''
				this.push(event, `${payload.class || payload.type || event}: ${payload.message || ''}${where}`)
				return
			}

			this.push('progress', data)
		},

		/**
		 * Append a line and keep the newest visible.
		 *
		 * @param {string} kind The line kind, used for styling.
		 * @param {string} text The line text.
		 */
		push(kind, text) {
			this.events.push({ kind, text })
			this.$nextTick(() => {
				const log = this.$refs.log
				if (log) {
					log.scrollTop = log.scrollHeight
				}
			})
		},

		/** Stop reading without closing the modal, so output stays inspectable. */
		abort() {
			if (this.controller) {
				this.controller.abort()
			}
			this.running = false
		},

		/** Close, aborting any read in flight. */
		close() {
			this.abort()
			this.open = false
		},
	},
}
</script>

<style scoped>
.run-console {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.run-console__status {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-maxcontrast);
}

.run-console__trace {
	font-family: monospace;
	font-size: 0.85em;
}

.run-console__log {
	max-height: 45vh;
	overflow-y: auto;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 10px;
	font-family: monospace;
	font-size: 0.85em;
}

.run-console__empty {
	color: var(--color-text-maxcontrast);
}

.run-console__line {
	display: flex;
	gap: 8px;
	padding: 1px 0;
	white-space: pre-wrap;
	word-break: break-word;
}

.run-console__kind {
	flex: 0 0 70px;
	color: var(--color-text-maxcontrast);
}

.run-console__line--error .run-console__text,
.run-console__line--fatal .run-console__text {
	color: var(--color-error-text, var(--color-error));
	font-weight: bold;
}

.run-console__line--result .run-console__text {
	color: var(--color-success-text, var(--color-success));
}

.run-console__result pre {
	max-height: 20vh;
	overflow: auto;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 10px;
	font-size: 0.85em;
}

.run-console__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>

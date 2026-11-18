<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Test connection for an SFTP or FTPS source (sources-sftp-adapter, REQ-SFTP-001).

  The first test of a new SFTP source shows the server's host key fingerprint
  and sends no credential. The administrator checks it with the partner and
  confirms it, which pins it on the source. After that every connection
  compares the key with the pin and refuses a different one.

  Backend contract (FileServerSourcesController):
    POST /api/sources/{id}/host-key/test
      -> { fingerprint, pinned, matches, connected, message }
    POST /api/sources/{id}/host-key  body: { fingerprint }
      -> { hostKeyFingerprint } or 409 { error, fingerprint }
-->
<template>
	<NcModal v-if="open" labelId="fileServerTestModal" size="normal" @close="onClose">
		<div class="cn-file-server-test" data-testid="file-server-test">
			<h2 id="fileServerTestModal">
				{{ t('integriq', 'Test connection') }}
			</h2>

			<p v-if="sourceName">
				{{ t('integriq', 'Testing source: {name}', { name: sourceName }) }}
			</p>

			<NcNoteCard v-if="runError" type="error">
				<p>{{ runError }}</p>
			</NcNoteCard>

			<template v-if="result">
				<NcNoteCard v-if="state === 'confirm'" type="info">
					<p>{{ t('integriq', 'The server presents this host key fingerprint. Check it with the partner, then confirm it to pin it.') }}</p>
				</NcNoteCard>
				<NcNoteCard v-else-if="state === 'mismatch'" type="error">
					<p>{{ t('integriq', 'The server presents a different host key than the one pinned. The connection was refused.') }}</p>
				</NcNoteCard>
				<NcNoteCard v-else-if="state === 'connected'" type="success">
					<p>{{ t('integriq', 'Connected. The server is who the source says it is.') }}</p>
				</NcNoteCard>
				<NcNoteCard v-else type="warning">
					<p>{{ t('integriq', 'The server was reached, but the connection failed: {message}', { message: result.message || '' }) }}</p>
				</NcNoteCard>

				<dl class="cn-file-server-test__keys">
					<template v-if="result.fingerprint">
						<dt>{{ t('integriq', 'Fingerprint the server presents') }}</dt>
						<dd data-testid="presented-fingerprint">
							<code>{{ result.fingerprint }}</code>
						</dd>
					</template>
					<template v-if="result.pinned">
						<dt>{{ t('integriq', 'Pinned fingerprint') }}</dt>
						<dd><code>{{ result.pinned }}</code></dd>
					</template>
				</dl>

				<p v-if="!result.fingerprint && isFtps">
					{{ t('integriq', 'An FTPS server is checked through its certificate, so there is no fingerprint to pin.') }}
				</p>
			</template>

			<div class="cn-file-server-test__actions">
				<NcButton @click="onClose">
					{{ t('integriq', 'Close') }}
				</NcButton>
				<NcButton
					v-if="state === 'confirm'"
					variant="primary"
					data-testid="pin-fingerprint"
					:disabled="running"
					@click="pin">
					{{ t('integriq', 'Confirm and pin') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="running || !sourceId"
					@click="runTest">
					<template #icon>
						<NcLoadingIcon v-if="running" :size="20" />
						<PlayOutlineIcon v-else :size="20" />
					</template>
					{{ t('integriq', 'Run test') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard } from '@nextcloud/vue'
import PlayOutlineIcon from 'vue-material-design-icons/PlayOutline.vue'
import { fileServerTestState } from './fileServerTestState.js'

export default {
	name: 'FileServerTestModal',

	components: {
		NcModal,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		PlayOutlineIcon,
	},

	props: {
		/** Whether the modal is mounted/visible. */
		open: { type: Boolean, default: false },
		/** The SFTP or FTPS source row. */
		source: { type: Object, default: null },
	},

	emits: ['close'],

	data() {
		return {
			running: false,
			runError: '',
			result: null,
		}
	},

	computed: {
		/** @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001 */
		sourceId() {
			return this.source?.id || this.source?.uuid || this.source?.['@self']?.id || null
		},

		/** @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001 */
		sourceName() {
			return this.source?.name || ''
		},

		/** @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001 */
		isFtps() {
			return this.source?.type === 'ftps'
		},

		/** @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001 */
		state() {
			return fileServerTestState(this.result)
		},
	},

	watch: {
		/**
		 * @param {boolean} value whether the modal opened
		 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
		 */
		open(value) {
			if (value) {
				this.runError = ''
				this.result = null
			}
		},
	},

	methods: {
		/** @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001 */
		onClose() {
			this.$emit('close')
		},

		/** @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001 */
		async runTest() {
			this.running = true
			this.runError = ''
			try {
				const { data } = await axios.post(generateUrl('/apps/integriq/api/sources/{id}/host-key/test', { id: this.sourceId }))
				this.result = data
			} catch (error) {
				this.runError = error?.response?.data?.error || t('integriq', 'The test could not be run.')
			} finally {
				this.running = false
			}
		},

		/** @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001 */
		async pin() {
			this.running = true
			this.runError = ''
			try {
				await axios.post(
					generateUrl('/apps/integriq/api/sources/{id}/host-key', { id: this.sourceId }),
					{ fingerprint: this.result.fingerprint },
				)
				showSuccess(t('integriq', 'Host key pinned.'))
				this.result = { ...this.result, pinned: this.result.fingerprint, matches: true }
			} catch (error) {
				this.runError = error?.response?.data?.error || t('integriq', 'The fingerprint could not be pinned.')
			} finally {
				this.running = false
			}
		},
	},
}
</script>

<style scoped>
.cn-file-server-test {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.cn-file-server-test__keys code {
	word-break: break-all;
}

.cn-file-server-test__keys dt {
	font-weight: bold;
}

.cn-file-server-test__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>

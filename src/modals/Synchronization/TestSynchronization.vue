<script setup>
import { translate as t } from '@nextcloud/l10n'
import { synchronizationStore, navigationStore } from '../../store/store.js'
import { getTheme } from '../../services/getTheme.js'
</script>

<template>
	<NcModal ref="modalRef"
		label-id="testSynchronization"
		@close="closeModal">
		<div class="modalContent">
			<h2>{{ t('openconnector', 'Test synchronization') }}</h2>

			<div v-if="loading">
				<NcLoadingIcon :size="64" :name="t('openconnector', 'Running synchronization test')" />
			</div>

			<NcNoteCard v-if="success === false" type="error">
				<p>{{ t('openconnector', 'An error occurred while testing the synchronization.') }}</p>
			</NcNoteCard>

			<div v-if="success !== null">
				<NcNoteCard v-if="response?.ok" type="success">
					<p>{{ t('openconnector', 'The connection to the synchronization was successful.') }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="!response?.ok || error" type="error">
					<p>
						{{ t('openconnector', 'An error occurred while testing the connection:') }} {{
							synchronizationStore.synchronizationTest
								? synchronizationStore.synchronizationTest.message
									? synchronizationStore.synchronizationTest.message
									: synchronizationStore.synchronizationTest.error
								: response?.statusMessage
									? response?.statusMessage
									: `${response?.status} - ${response?.statusText}`
						}}
					</p>
				</NcNoteCard>

				<div v-if="response" class="detailTable">
					<table>
						<tr>
							<td><b>{{ t('openconnector', 'Status') }}:</b></td>
							<td>{{ response?.statusText }} ({{ response?.status }})</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Response time') }}:</b></td>
							<td>{{ response?.responseTime ?? t('openconnector', 'Unknown') }} ({{ t('openconnector', 'Milliseconds') }})</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Size') }}:</b></td>
							<td>{{ response?.size ?? t('openconnector', 'Unknown') }} ({{ t('openconnector', 'Bytes') }})</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Remote IP') }}:</b></td>
							<td>{{ response?.remoteIp ?? t('openconnector', 'Unknown') }}</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Headers') }}:</b></td>
							<td>
								<table>
									<tr v-for="(header, index) in response?.headers" :key="index">
										<td><b>{{ header[0] }}:</b></td>
										<td>{{ header[1] }}</td>
									</tr>
								</table>
							</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Body') }}:</b></td>
							<td :class="`codeMirrorContainer ${getTheme()}`">
								<CodeMirror v-model="responseBody"
									:basic="true"
									:dark="getTheme() === 'dark'"
									:linter="jsonParseLinter()"
									:lang="json()"
									:readonly="true" />
							</td>
						</tr>
					</table>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcModal,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import CodeMirror from 'vue-codemirror6'
import { json, jsonParseLinter } from '@codemirror/lang-json'

export default {
	name: 'TestSynchronization',
	components: {
		NcModal,
		NcLoadingIcon,
		NcNoteCard,
		CodeMirror,
	},
	data() {
		return {
			response: null,
			responseBody: '',
			success: null,
			loading: false,
			error: false,
		}
	},
	mounted() {
		this.testSynchronization()
	},
	methods: {
		/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-5 */
		closeModal() {
			navigationStore.setModal(false)
			synchronizationStore.synchronizationTest = null
		},
		/** @spec openspec/changes/retrofit-2026-05-25-sync-editor-ui/tasks.md#task-5 */
		async testSynchronization() {
			this.success = null
			this.loading = true
			this.error = false

			synchronizationStore.testSynchronization()
				.then(({ response, data }) => {
					this.response = response
					this.responseBody = JSON.stringify({ ...data }, null, 2)
					this.success = response.ok

					synchronizationStore.refreshSynchronizationLogs()
					synchronizationStore.refreshSynchronizationContracts(synchronizationStore.synchronizationItem.id)
				}).catch((error) => {
					this.success = false
					this.error = error.message || 'An error occurred while testing the synchronization'
					console.error(error)
				}).finally(() => {
					this.loading = false
				})
		},
	},
}
</script>
<style scoped>
.detailGrid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 5px;
}

.detailTable {
	overflow-x: auto;
}

.detailTable > table {
	width: 100%;
	border: 1px solid grey;
	border-collapse: collapse;
}

.detailTable > table > tr > td,
.detailTable > table > tr > th {
	border: 1px solid grey;
	padding: 5px;
}
</style>

<style scoped>
.codeMirrorContainer {
	margin-block-start: 6px;
}

.codeMirrorContainer :deep(.cm-content) {
	border-radius: 0 !important;
	border: none !important;
}

.codeMirrorContainer :deep(.cm-editor) {
	outline: none !important;
}

.codeMirrorContainer.light > .vue-codemirror {
	border: 1px dotted silver;
}

.codeMirrorContainer.dark > .vue-codemirror {
	border: 1px dotted grey;
}

/* value text color */
.codeMirrorContainer.light :deep(.ͼe) {
	color: #448c27;
}

.codeMirrorContainer.dark :deep(.ͼe) {
	color: #88c379;
}

/* text cursor */
.codeMirrorContainer :deep(.cm-content) * {
	cursor: text !important;
}

/* value number color */
.codeMirrorContainer.light :deep(.ͼd) {
	color: #c68447;
}

.codeMirrorContainer.dark :deep(.ͼd) {
	color: #d19a66;
}

/* value boolean color */
.codeMirrorContainer.light :deep(.ͼc) {
	color: #221199;
}

.codeMirrorContainer.dark :deep(.ͼc) {
	color: #260dd4;
}
</style>

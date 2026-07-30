<script setup>
import { translate as t } from '@nextcloud/l10n'
import { synchronizationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcModal ref="modalRef"
		label-id="runSynchronization"
		@close="closeModal">
		<div class="modalContent runSynchronization">
			<h2>{{ t('openconnector', 'Run synchronization') }}</h2>

			<div v-if="response === null" class="runOptions">
				<div class="optionsGrid">
					<NcNoteCard type="info">
						<p>
							{{ t('openconnector', "Test mode will run all the synchronization code and logic without saving or updating the contract or updating the target system. This allows you to verify the mapping and configuration before running a real synchronization by doing a 'dry run'.") }}
						</p>
					</NcNoteCard>
					<NcCheckboxRadioSwitch
						:model-value="testMode"
						type="switch"
						@update:model-value="testMode = $event">
						{{ t('openconnector', 'Test mode') }}
					</NcCheckboxRadioSwitch>
					<NcNoteCard type="info">
						<p>
							{{ t('openconnector', 'Forcing the synchronization will make the synchronization service update the contract even if no update was deemed necessary (see docs). The resulting updated contract can still be withheld from saving by activating test mode.') }}
						</p>
					</NcNoteCard>
					<NcCheckboxRadioSwitch
						:model-value="forceSync"
						type="switch"
						@update:model-value="forceSync = $event">
						{{ t('openconnector', 'Force synchronization') }}
					</NcCheckboxRadioSwitch>
				</div>

				<div class="runButtonContainer">
					<NcButton type="primary" @click="runSynchronization">
						<template #icon>
							<Play :size="20" />
						</template>
						{{ testMode ? t('openconnector', 'Test') : t('openconnector', 'Run') }}
					</NcButton>
				</div>
			</div>

			<div v-if="loading">
				<NcLoadingIcon :size="64" :name="t('openconnector', 'Running synchronization')" />
			</div>

			<NcNoteCard v-if="success === false" type="error">
				<p>{{ t('openconnector', 'An error occurred while running the synchronization.') }}</p>
			</NcNoteCard>

			<div v-if="success !== null">
				<div v-if="success" class="SuccessMarker">
					{{ t('openconnector', 'Success Marker to expand the modal') }}
				</div>

				<NcNoteCard v-if="response?.ok" type="success">
					<p>{{ t('openconnector', 'The synchronization was run successfully.') }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="!response?.ok || error" type="error">
					<p>
						{{ t('openconnector', 'An error occurred while running the synchronization:') }} {{
							synchronizationStore.synchronizationRun
								? synchronizationStore.synchronizationRun.message
									? synchronizationStore.synchronizationRun.message
									: synchronizationStore.synchronizationRun.error
								: response?.statusMessage
									? response?.statusMessage
									: `${response?.status} - ${response?.statusText}`
						}}
					</p>
				</NcNoteCard>

				<div v-if="response" class="detailTable">
					<table>
						<tr>
							<td><b>{{ t('openconnector', 'Message') }}:</b></td>
							<td>{{ responseBody?.message }} (ms)</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Execution time') }}:</b></td>
							<td>{{ responseBody?.executionTime }}</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'ID') }}:</b></td>
							<td>{{ responseBody?.id }}</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'UUID') }}:</b></td>
							<td>{{ responseBody?.uuid }}</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Synchronization ID') }}:</b></td>
							<td>{{ responseBody?.synchronizationId }}</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Objects Found') }}:</b></td>
							<td>{{ responseBody?.result?.objects?.found }}</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Objects Skipped') }}:</b></td>
							<td>{{ responseBody?.result?.objects?.skipped }}</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Objects Created') }}:</b></td>
							<td>{{ responseBody?.result?.objects?.created }}</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Objects Updated') }}:</b></td>
							<td>{{ responseBody?.result?.objects?.updated }}</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Objects Deleted') }}:</b></td>
							<td>{{ responseBody?.result?.objects?.deleted }}</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Objects Invalid') }}:</b></td>
							<td>{{ responseBody?.result?.objects?.invalid }}</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Contracts') }}:</b></td>
							<td>
								<div v-for="(contract, index) in responseBody?.result?.contracts" :key="index">
									{{ contract }}
								</div>
							</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Logs') }}:</b></td>
							<td>
								<div v-for="(log, index) in responseBody?.result?.logs" :key="index">
									{{ log }}
								</div>
							</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'User ID') }}:</b></td>
							<td>{{ responseBody?.userId }}</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Session ID') }}:</b></td>
							<td>{{ responseBody?.sessionId }}</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Test Mode') }}:</b></td>
							<td>{{ responseBody?.test }}</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Force Mode') }}:</b></td>
							<td>{{ responseBody?.force }}</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Created') }}:</b></td>
							<td>{{ responseBody?.created }}</td>
						</tr>
						<tr>
							<td><b>{{ t('openconnector', 'Expires') }}:</b></td>
							<td>{{ responseBody?.expires }}</td>
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
	NcButton,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'

import Play from 'vue-material-design-icons/Play.vue'

export default {
	name: 'RunSynchronization',
	components: {
		NcModal,
		NcLoadingIcon,
		NcNoteCard,
		NcButton,
		NcCheckboxRadioSwitch,
	},
	data() {
		return {
			response: null,
			responseBody: '',
			responseBodyString: '',
			success: null,
			loading: false,
			error: false,
			testMode: false,
			forceSync: false,
		}
	},
	methods: {
		/** @spec openspec/specs/sync-editor-ui/spec.md */
		closeModal() {
			navigationStore.setModal(false)
			synchronizationStore.synchronizationRun = null
		},
		/** @spec openspec/specs/sync-editor-ui/spec.md */
		async runSynchronization() {
			this.success = null
			this.loading = true
			this.error = false

			synchronizationStore.runSynchronization(
				synchronizationStore.synchronizationItem.id,
				this.testMode,
				this.forceSync,
			)
				.then(({ response, data }) => {
					this.response = response
					this.responseBody = data
					this.responseBodyString = JSON.stringify(data, null, 2)
					this.success = response.ok

					synchronizationStore.refreshSynchronizationLogs()
					synchronizationStore.refreshSynchronizationContracts(synchronizationStore.synchronizationItem.id)
				}).catch((error) => {
					this.success = false
					this.error = error.message || 'An error occurred while running the synchronization'
					console.error(error)
				}).finally(() => {
					this.loading = false
				})
		},
	},
}
</script>
<style scoped>
div[class='modal-container']:has(.runSynchronization .SuccessMarker) {
	width: 900px !important;
}

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
.runButtonContainer {
	display: flex;
	justify-content: center;
	margin-block-start: 10px;
}

.SuccessMarker {
	display: none;
}

/* ================ */
/*    CodeMirror    */
/* ================ */
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

.optionsGrid {
	display: grid;
	gap: 1rem;
	margin-bottom: 1.5rem;
}

.runOptions {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	margin-block-start: 10px;
}
</style>

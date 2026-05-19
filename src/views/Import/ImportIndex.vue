<template>
	<NcAppContent>
		<CnDetailPage
			:title="t('openconnector', 'Import')"
			:description="t('openconnector', 'Upload JSON or YAML files to import them into openconnector.')"
			max-width="900px">
			<div ref="dropZoneRef" class="import-dropzone">
				<TrayArrowDown :size="48" class="import-dropzone__icon" />
				<h3 class="import-dropzone__title">
					{{ t('openconnector', 'Drag and drop files here') }}
				</h3>
				<p class="import-dropzone__hint">
					{{ t('openconnector', 'Allowed: .json, .yaml, .yml') }}
				</p>
				<NcButton :disabled="loading"
					type="secondary"
					@click="openFileUpload()">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('openconnector', 'Pick files') }}
				</NcButton>
			</div>

			<NcEmptyContent v-if="!files || files.length === 0"
				:name="t('openconnector', 'No files selected')"
				:description="t('openconnector', 'Drop files above or click Pick files.')" />

			<template v-else>
				<div class="import-actions">
					<NcButton :disabled="loading"
						type="primary"
						@click="importFiles()">
						<template #icon>
							<NcLoadingIcon v-if="loading" :size="20" />
							<FileImportOutline v-else :size="20" />
						</template>
						{{ t('openconnector', 'Import') }}
					</NcButton>
				</div>
				<ul class="import-files">
					<NcListItem v-for="file of files"
						:key="file.name"
						:name="file.name"
						:details="formatFileSize(file.size)"
						:bold="false"
						:force-display-actions="true">
						<template #icon>
							<FileDocumentOutline :size="32" />
						</template>
						<template #actions>
							<NcActionButton :disabled="loading" @click="reset(file.name)">
								<template #icon>
									<Minus :size="20" />
								</template>
								{{ t('openconnector', 'Remove') }}
							</NcActionButton>
						</template>
					</NcListItem>
				</ul>
			</template>
		</CnDetailPage>
	</NcAppContent>
</template>

<script>
import { ref, watch } from 'vue'
import { NcAppContent, NcButton, NcLoadingIcon, NcEmptyContent, NcListItem, NcActionButton } from '@nextcloud/vue'
import { CnDetailPage } from '@conduction/nextcloud-vue'

import { importExportStore } from '../../store/store.js'
import { useFileSelection } from '../../composables/UseFileSelection.js'

import TrayArrowDown from 'vue-material-design-icons/TrayArrowDown.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Minus from 'vue-material-design-icons/Minus.vue'
import FileImportOutline from 'vue-material-design-icons/FileImportOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'

const VALID_EXTENSION = /\.(json|ya?ml)$/i

export default {
	name: 'ImportIndex',
	components: {
		NcAppContent,
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcListItem,
		NcActionButton,
		CnDetailPage,
		TrayArrowDown,
		Plus,
		Minus,
		FileImportOutline,
		FileDocumentOutline,
	},
	setup() {
		const dropZoneRef = ref()
		const { openFileUpload, files, reset, setFiles } = useFileSelection({
			allowMultiple: true,
			dropzone: dropZoneRef,
			allowedFileTypes: ['.json', '.yaml', '.yml'],
		})

		// useFileSelection's drop handler accepts everything (dataTypes: '*'),
		// and the file-dialog `accept` attribute is only a hint. Reject
		// disallowed extensions here so they never reach the list.
		watch(files, (current) => {
			if (!current || current.length === 0) return
			const accepted = current.filter(file => VALID_EXTENSION.test(file.name))
			const rejected = current.filter(file => !VALID_EXTENSION.test(file.name))
			if (rejected.length === 0) return
			setFiles(accepted.length > 0 ? accepted : null)
			OC.Notification.showError(
				t('openconnector', 'Skipped {files} — only .json, .yaml, .yml files are allowed', {
					files: rejected.map(f => f.name).join(', '),
				}),
			)
		})

		return { dropZoneRef, openFileUpload, files, reset }
	},
	data() {
		return {
			loading: false,
		}
	},
	methods: {
		formatFileSize(bytes) {
			if (bytes < 1024) return `${bytes} B`
			if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
			if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
			return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`
		},
		importFiles() {
			this.loading = true
			importExportStore.importFiles(this.files, this.reset)
				.then(() => {
					OC.Notification.showSuccess(t('openconnector', 'Files imported successfully'))
					this.reset()
				})
				.catch((err) => {
					const message = err.response?.data?.error ?? err.message ?? String(err)
					OC.Notification.showError(t('openconnector', 'Import failed: {error}', { error: message }))
				})
				.finally(() => {
					this.loading = false
				})
		},
	},
}
</script>

<style scoped>
.import-dropzone {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 6) calc(var(--default-grid-baseline) * 4);
	border: 2px dashed var(--color-border-dark);
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);
	text-align: center;
}

.import-dropzone__icon {
	color: var(--color-primary-element);
}

.import-dropzone__title {
	margin: 0;
	font-weight: bold;
}

.import-dropzone__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.import-actions {
	display: flex;
	justify-content: flex-end;
	margin-block: calc(var(--default-grid-baseline) * 3);
}

.import-files {
	list-style: none;
	padding: 0;
	margin: 0;
}
</style>

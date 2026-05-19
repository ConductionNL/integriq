<script setup>
import { navigationStore, importExportStore } from '../../store/store.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'importFile'"
		ref="modalRef"
		label-id="ImportFileModal"
		@close="closeModal()">
		<div class="modalContent">
			<h2>Import {{ importExportStore.importFileName }}</h2>

			<div v-if="success !== null || error">
				<NcNoteCard v-if="success" type="success">
					<p>Successfully imported file</p>
				</NcNoteCard>
				<NcNoteCard v-if="!success" type="error">
					<p>Something went wrong while importing</p>
				</NcNoteCard>
				<NcNoteCard v-if="error && !success" type="error">
					<p>{{ error }}</p>
				</NcNoteCard>
			</div>
			<div v-if="success === null" class="form-group">
				<div class="addFileContainer">
					<div :ref="'dropZoneRef'" class="filesListDragDropNotice">
						<div class="filesListDragDropNoticeWrapper">
							<div class="filesListDragDropNoticeWrapperIcon">
								<TrayArrowDown :size="48" />
								<h3 class="filesListDragDropNoticeTitle">
									Drag and drop a file here
								</h3>
							</div>

							<h3 class="filesListDragDropNoticeTitle">
								Or
							</h3>

							<div class="filesListDragDropNoticeTitle">
								<NcButton v-if="success === null && (!files || !files.length)"
									:disabled="loading"
									type="primary"
									@click="openFileUpload()">
									<template #icon>
										<Plus :size="20" />
									</template>
									Add file
								</NcButton>

								<div v-if="success === null && files && files.length"
									class="fileCardCustom"
									role="group"
									aria-label="Selected file">
									<div class="fileCardCustom__left">
										<div class="fileCardCustom__name" :title="files[0].name">
											{{ files[0].name }}
										</div>
										<div class="fileCardCustom__meta">
											<span class="fileCardCustom__metaItem">{{ files[0].name.split('.').pop() }}</span>
											<span class="fileCardCustom__dot">•</span>
											<span class="fileCardCustom__metaItem">{{ formatBytes(files[0].size) }}</span>
										</div>
									</div>
									<button class="fileCardCustom__remove"
										type="button"
										:disabled="loading"
										aria-label="Remove file"
										@click="reset()">
										<TrashCanOutline :size="18" />
									</button>
								</div>
							</div>
						</div>
					</div>
				</div>
				<div class="modal-actions">
					<NcButton v-if="success === null"
						@click="closeModal">
						<template #icon>
							<CancelIcon size="20" />
						</template>
						Cancel
					</NcButton>
					<NcButton v-if="success === null"
						type="primary"
						:disabled="!files || !files.length"
						@click="importFile()">
						<template #icon>
							<NcLoadingIcon v-if="loading" :size="20" />
							<FileImportOutline v-if="!loading" :size="20" />
						</template>
						Import
					</NcButton>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard } from '@nextcloud/vue'
import { useFileSelection } from '../../composables/UseFileSelection.js'

import { ref } from 'vue'

import Plus from 'vue-material-design-icons/Plus.vue'
import TrayArrowDown from 'vue-material-design-icons/TrayArrowDown.vue'
import FileImportOutline from 'vue-material-design-icons/FileImportOutline.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

const dropZoneRef = ref()
const { openFileUpload, files, reset, setFiles } = useFileSelection({ allowMultiple: false, dropzone: dropZoneRef, allowedFileTypes: ['.json', '.yaml', '.yml'] })

export default {
	name: 'ImportFile',
	components: {
		NcModal,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		CancelIcon,
	},
	props: {
		dropFiles: {
			type: Array,
			required: false,
			default: null,
		},
	},
	data() {
		return {
			loading: false,
			success: null,
			error: false,
			labelOptions: {
				inputLabel: 'Labels',
				multiple: true,
				options: ['Besluit', 'Convenant', 'Document', 'Informatieverzoek', 'Inventarisatielijst'],
			},
		}
	},
	watch: {
		dropFiles: {
			handler(addedFiles) {
				setFiles(addedFiles)
			},
			deep: true,
		},
	},
	mounted() {
	},
	methods: {

		closeModal() {
			navigationStore.setModal(false)
			reset()

		},
		formatBytes(bytes) {
			// handle empty or invalid values
			if (!bytes && bytes !== 0) {
				return ''
			}
			const units = ['B', 'KB', 'MB', 'GB', 'TB']
			let size = bytes
			let unitIndex = 0
			while (size >= 1024 && unitIndex < units.length - 1) {
				size = size / 1024
				unitIndex++
			}
			return `${size.toFixed(size >= 10 || unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`
		},
		importFile() {
			this.loading = true
			this.errorMessage = false
			importExportStore.importFile(files, reset).then((response) => {
				this.success = true

				const self = this
				setTimeout(function() {
					self.success = null
					self.closeModal()
				}, 2000)
			}).catch((err) => {
				this.error = err.response?.data?.error ?? err
				this.loading = false
			})
		},
	},
}
</script>

<style scoped>
.addFileContainer{
	margin-block-end: var(--OC-margin-20);
}

.addFileContainer--disabled{
	opacity: 0.4;
}

.zaakDetailsContainer {
	margin-block-start: var(--OC-margin-20);
	margin-inline-start: var(--OC-margin-20);
	margin-inline-end: var(--OC-margin-20);
}

.success {
	color: green;
}

.fileCardCustom {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 12px 14px;
	border: 1px solid rgba(0,0,0,0.1);
	border-radius: 10px;
	background: #ffffff;
	box-shadow: 0 1px 2px rgba(0,0,0,0.04);
	text-align: left;
}

.fileCardCustom__left {
	min-width: 0;
	flex: 1;
}

.fileCardCustom__name {
	font-weight: 600;
	line-height: 1.3;
	word-break: break-word;
	overflow-wrap: anywhere;
}

.fileCardCustom__meta {
	margin-top: 4px;
	color: #5f6c7b;
	font-size: 0.9em;
	display: flex;
	align-items: center;
	gap: 6px;
}

.fileCardCustom__dot {
	opacity: 0.6;
}

.fileCardCustom__remove {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 34px;
	height: 34px;
	border: 1px solid rgba(0,0,0,0.08);
	border-radius: 8px;
	background: #f7f8fa;
	cursor: pointer;
	transition: background 150ms ease, transform 50ms ease;
}

.fileCardCustom__remove:hover {
	background: #eef1f5;
}

.fileCardCustom__remove:active {
	transform: translateY(1px);
}

.fileCardCustom__remove:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}
</style>

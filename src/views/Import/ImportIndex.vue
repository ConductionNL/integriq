<script setup>
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { importExportStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div ref="dropZoneRef" class="container">
			<div class="filesListDragDropNotice" :class="'tabPanelFileUpload'">
				<NcNoteCard type="info">
					<p>{{ t('openconnector', 'Allowed extensions are: .json, .yaml, .yml') }}</p>
				</NcNoteCard>
				<div v-if="success !== null || error">
					<NcNoteCard v-if="success" type="success">
						<p>{{ t('openconnector', 'Successfully imported files') }}</p>
					</NcNoteCard>
					<NcNoteCard v-if="error && !success" type="error">
						<p>{{ t('openconnector', 'Something went wrong while importing') }}</p>
					</NcNoteCard>
					<NcNoteCard v-if="error && !success" type="error">
						<p>{{ error }}</p>
					</NcNoteCard>
				</div>
				<div v-if="validateFileExtension(files)">
					<NcNoteCard type="error">
						<p>{{ t('openconnector', 'Please select files with the correct extension') }}</p>
					</NcNoteCard>
				</div>
				<div class="filesListDragDropNoticeWrapper">
					<div class="filesListDragDropNoticeWrapperIcon">
						<TrayArrowDown :size="48" />
						<h3 class="filesListDragDropNoticeTitle">
							{{ t('openconnector', 'Drag and drop a file or files here') }}
						</h3>
					</div>

					<h3 class="filesListDragDropNoticeTitle">
						{{ t('openconnector', 'Or') }}
					</h3>

					<div class="filesListDragDropNoticeTitle">
						<NcButton
							:disabled="loading"
							type="primary"
							@click="openFileUpload()">
							<template #icon>
								<Plus :size="20" />
							</template>
							{{ t('openconnector', 'Add a file or files') }}
						</NcButton>
					</div>
				</div>
			</div>
			<div v-if="!files">
				{{ t('openconnector', 'No files selected') }}
			</div>
			<div v-if="files" class="importButtonContainer">
				<NcButton
					:disabled="loading || validateFileExtension(files)"
					type="primary"
					@click="importFiles()">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<FileImportOutline v-if="!loading" :size="20" />
					</template>
					{{ t('openconnector', 'Import') }}
				</NcButton>
			</div>
			<table v-if="files" class="files-table">
				<thead>
					<tr class="files-table-tr">
						<th>
							{{ t('openconnector', 'Name') }}
						</th>
						<th>
							{{ t('openconnector', 'Size') }}
						</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="file of files" :key="file.name" class="files-table-tr">
						<td class="files-table-td-name" :class="{ 'files-table-name-wrong': !['json', 'yaml', 'yml'].includes(getFileNameAndExtension(file.name).extension) }">
							<span class="files-table-name">{{ getFileNameAndExtension(file.name).name }}</span>
							<span class="files-table-extension">.{{ getFileNameAndExtension(file.name).extension }}</span>
						</td>
						<td>
							{{ bytesToSize(file.size) }}
						</td>
						<td class="files-table-remove-button">
							<NcButton
								:disabled=" loading"
								type="primary"
								@click="reset(file.name)">
								<template #icon>
									<Minus :size="20" />
								</template>
								<span>{{ t('openconnector', 'Remove') }}</span>
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcButton, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'
import { useFileSelection } from './../../composables/UseFileSelection.js'

import TrayArrowDown from 'vue-material-design-icons/TrayArrowDown.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Minus from 'vue-material-design-icons/Minus.vue'
import FileImportOutline from 'vue-material-design-icons/FileImportOutline.vue'

const dropZoneRef = ref()
const { openFileUpload, files, reset } = useFileSelection({ allowMultiple: true, dropzone: dropZoneRef, allowedFileTypes: ['.json', '.yaml', '.yml'] })

export default {
	name: 'ImportIndex',
	components: {
		NcAppContent,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
	},
	data() {
		return {
			loading: false,
			success: null,
			error: false,
		}
	},

	methods: {
		bytesToSize(bytes) {
			const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB']
			if (bytes === 0) return 'n/a'
			const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)))
			if (i === 0 && sizes[i] === 'Bytes') return '< 1 KB'
			if (i === 0) return bytes + ' ' + sizes[i]
			return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i]
		},

		getFileNameAndExtension(fullname) {
			const lastDot = fullname.lastIndexOf('.')
			const name = fullname.slice(0, lastDot)
			const extension = fullname.slice(lastDot + 1)
			return { name, extension }
		},

		validateFileExtension(files) {
			if (!files) return false
			const wrongFiles = files.filter(file => {
				return !['json', 'yaml', 'yml'].includes(this.getFileNameAndExtension(file.name).extension)
			})

			return wrongFiles.length > 0
		},

		importFiles() {
			this.loading = true
			this.error = null

			importExportStore.importFiles(files, reset)
				.then((response) => {
					this.success = true

					const self = this
					setTimeout(function() {
						self.loading = false
						self.success = null
						reset()
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
.importButtonContainer {
	display: flex;
	justify-content: flex-end;
}

.container {
	padding-inline: 25px;
	margin-block: 50px;
}

.files-table-name-wrong > span {
	color: #ff0000 !important;
}

.files-table {
	width: 100%;
	border-collapse: collapse;
}

.files-table-td-name{
	overflow: hidden;
	white-space: nowrap;
	text-overflow: ellipsis;
	max-width: 75ch;
}

.files-table-td-name span {
	float: left;
	overflow: hidden;
	white-space: nowrap;
	text-overflow: ellipsis;
	max-width: calc(100% - 10%);
}

.files-table-name {
	color: var(--color-main-text);
}

.files-table-extension {
	color: var(--color-text-maxcontrast);
}

.files-table-tr {
	color: var(--color-text-maxcontrast);
	border-bottom: 1px solid var(--color-border);
}

.files-table-tr > td {
	height: 55px;
}

.files-table-remove-button {
	text-align: -webkit-right;
}

.files-list__row-icon {
	position: relative;
	display: flex;
	overflow: visible;
	align-items: center;
	flex: 0 0 32px;
	justify-content: center;
	width: 32px;
	height: 100%;
	margin-right: var(--checkbox-padding);
	color: var(--color-primary-element);
}
</style>

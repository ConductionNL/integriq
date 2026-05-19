<script setup>
import { ruleStore, navigationStore, mappingStore, synchronizationStore, sourceStore } from '../../store/store.js'
import { getTheme } from '../../services/getTheme.js'
import { Rule } from '../../entities/index.js'
</script>

<template>
	<NcModal ref="modalRef"
		label-id="editRule"
		@close="closeModal">
		<div class="modalContent">
			<h2>{{ ruleItem.id ? 'Edit' : 'Add' }} Rule</h2>

			<div v-if="!openRegister.isInstalled && !closeAlert" class="openregister-notecard">
				<NcNoteCard
					:type="openRegister.isAvailable ? 'info' : 'error'"
					:heading="openRegister.isAvailable ? 'Open Register is not installed' : 'Failed to install Open Register'">
					<p>
						{{ openRegister.isAvailable
							? 'Some features require Open Register to be installed'
							: 'This either means that you do not have sufficient rights to install Open Register or that Open Register is not available on this server or you need to confirm your password' }}
					</p>

					<div class="install-buttons">
						<NcButton v-if="openRegister.isAvailable"
							aria-label="Install OpenRegister"
							size="small"
							type="primary"
							@click="installOpenRegister">
							<template #icon>
								<CloudDownload :size="20" />
							</template>
							Install OpenRegister
						</NcButton>
						<NcButton
							aria-label="Install OpenRegister Manually"
							size="small"
							type="secondary"
							@click="openLink('/index.php/settings/apps/organization/openregister', '_blank')">
							<template #icon>
								<OpenInNew :size="20" />
							</template>
							Install OpenRegister Manually
						</NcButton>
					</div>
					<div class="close-button">
						<NcActions>
							<NcActionButton close-after-click @click="closeAlert = true">
								<template #icon>
									<Close :size="20" />
								</template>
								Close
							</NcActionButton>
						</NcActions>
					</div>
				</NcNoteCard>
			</div>

			<!-- ====================== -->
			<!-- Success/Error/Warning notecard -->
			<!-- ====================== -->
			<div v-if="success || error || warning">
				<NcNoteCard v-if="success" type="success">
					<p>Rule successfully saved</p>
				</NcNoteCard>
				<NcNoteCard v-if="error" type="error">
					<p>{{ error || 'An error occurred' }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="warning" type="warning">
					<p>{{ warning }}</p>
				</NcNoteCard>
			</div>

			<!-- ====================== -->
			<!--          Form          -->
			<!-- ====================== -->
			<form v-if="!success" @submit.prevent="handleSubmit">
				<NcTextField :value.sync="ruleItem.name"
					label="Name"
					required />

				<NcTextArea
					resize="vertical"
					:value.sync="ruleItem.description"
					label="Description" />

				<div class="json-editor">
					<label>Conditions (JSON Logic)</label>
					<div :class="`codeMirrorContainer ${getTheme()}`">
						<CodeMirror v-model="ruleItem.conditions"
							:basic="true"
							placeholder="{&quot;and&quot;: [{&quot;==&quot;: [{&quot;var&quot;: &quot;status&quot;}, &quot;active&quot;]}, {&quot;>=&quot;: [{&quot;var&quot;: &quot;age&quot;}, 18]}]}"
							:dark="getTheme() === 'dark'"
							:linter="jsonParseLinter()"
							:lang="json()"
							:tab-size="2" />

						<NcButton class="format-json-button"
							type="secondary"
							size="small"
							@click="formatJSONCondictions">
							Format JSON
						</NcButton>
					</div>
					<span v-if="!isValidJson(ruleItem.conditions)" class="error-message">
						Invalid JSON format
					</span>
				</div>

				<div>
					<NcSelect
						v-bind="timingOptions"
						v-model="timingOptions.value"
						:clearable="false"
						input-label="Timing" />
				</div>

				<NcTextField :value.sync="ruleItem.order"
					label="Order"
					type="number" />

				<NcSelect v-bind="actionOptions"
					v-model="actionOptions.value"
					:clearable="false"
					input-label="Action" />

				<NcSelect v-bind="typeOptions"
					v-model="typeOptions.value"
					:selectable="(option) => option.label === 'Fileparts Create' || option.label === 'Filepart Upload' ? openRegister?.isInstalled : true"
					input-label="Type" />

				<!-- Add mapping select -->
				<NcSelect v-if="typeOptions.value?.id === 'mapping' || typeOptions.value?.id === 'save_object'"
					v-bind="mappingOptions"
					v-model="mappingOptions.value"
					:loading="mappingOptions.loading"
					input-label="Select Mapping"
					:multiple="false"
					:clearable="false" />

				<!-- Add synchronization select -->
				<template v-if="typeOptions.value?.id === 'synchronization'">
					<NcSelect
						v-bind="syncOptions"
						v-model="syncOptions.value"
						:loading="syncOptions.loading"
						input-label="Select Synchronization"
						:multiple="false"
						:clearable="false" />

					<NcCheckboxRadioSwitch
						type="checkbox"
						label="Retain response"
						:checked.sync="ruleItem.configuration.synchronization.retainResponse">
						Retain original response
					</NcCheckboxRadioSwitch>
				</template>

				<!-- Error Configuration -->
				<template v-if="typeOptions.value?.id === 'error'">
					<NcInputField
						type="number"
						label="Error Code"
						:min="100"
						:max="999"
						:value.sync="ruleItem.configuration.error.code"
						placeholder="500" />

					<NcTextField
						label="Error Title"
						maxlength="255"
						:value.sync="ruleItem.configuration.error.name"
						placeholder="Something went wrong" />

					<NcTextArea
						label="Error Message"
						resize="vertical"
						maxlength="2550"
						:value.sync="ruleItem.configuration.error.message"
						placeholder="We encountered an unexpected problem" />

					<NcCheckboxRadioSwitch
						type="checkbox"
						label="Include JSON Logic results in errors array"
						:checked.sync="ruleItem.configuration.error.includeJsonLogicResult">
						Include JSON Logic results in errors array
					</NcCheckboxRadioSwitch>
				</template>

				<!-- JavaScript Configuration -->
				<template v-if="typeOptions.value?.id === 'javascript'">
					<NcTextArea
						resize="vertical"
						label="JavaScript Code"
						:value.sync="ruleItem.configuration.javascript"
						class="code-editor"
						placeholder="Enter your JavaScript code here..."
						rows="10" />
				</template>

				<!-- Authentication Configuration -->
				<template v-if="typeOptions.value?.id === 'authentication'">
					<NcSelect
						v-model="authenticationTypeOptions.value"
						:options="authenticationTypeOptions.options"
						input-label="Authentication Type" />
					<template v-if="authenticationTypeOptions.value.value === 'api-key'">
						<VueDraggable v-model="apiKeys" easing="ease-in-out" draggable="div:not(:last-child)">
							<div v-for="(item, index) in apiKeys" :key="index" class="draggable-item-container">
								<div :class="`draggable-form-item ${getTheme()}`">
									<Drag class="drag-handle" :size="40" />
									<NcTextArea
										:value.sync="item.apiKey"
										:disabled="loading"
										label="Api-key"
										resize="none"
										class="apiKeyTextArea" />
									<NcSelect
										v-model="item.user"
										v-bind="usersList"
										aria-label-combobox="Select allowed user"
										:user-select="true"
										:clearable="true"
										placeholder="Select allowed user"
										class="apiKeyUserSelect" />
								</div>
							</div>
						</VueDraggable>
					</template>
					<template v-else>
						<!-- Users Multi-Select -->
						<NcSelect
							v-model="ruleItem.configuration.authentication.users"
							v-bind="usersList"
							input-label="Allowed Users"
							:user-select="true"
							:multiple="true"
							:clearable="true"
							placeholder="Select users who can access" />

						<!-- Groups Multi-Select -->
						<NcSelect
							v-model="ruleItem.configuration.authentication.groups"
							v-bind="groupsList"
							input-label="Allowed Groups"
							:multiple="true"
							:clearable="true"
							placeholder="Select groups who can access" />
					</template>
				</template>

				<!-- Extend Input Configuration -->
				<template v-if="typeOptions.value?.id === 'extend_input'">
					<div class="extendList">
						<div v-for="(item, idx) in ruleItem.configuration.extend_input.items" :key="idx" class="extendItem">
							<div class="extendItemProperty">
								<label>Property (dot path)</label>
								<NcTextField
									:value.sync="item.property"
									placeholder="a.b" />
							</div>
							<div class="extendItemProperty">
								<label>Extends (dot array)</label>
								<NcSelect
									v-model="item.extends"
									:taggable="true"
									:multiple="true"
									:clearable="true"
									:options="[]">
									<template #no-options>
										type to add path to extend
									</template>
								</NcSelect>
							</div>
							<NcButton class="remove-action"
								size="small"
								type="tertiary"
								:disabled="idx === 0"
								@click="removeExtendInputItem(idx)">
								<template #icon>
									<TrashCanOutline :size="18" />
								</template>
							</NcButton>
						</div>
					</div>
				</template>

				<!-- Extend External Input Configuration -->
				<template v-if="typeOptions.value?.id === 'extend_external_input'">
					<NcCheckboxRadioSwitch
						type="checkbox"
						label="Validate fetched object with schema"
						:checked.sync="ruleItem.configuration.extend_external_input.validate">
						Validate fetched object with schema
					</NcCheckboxRadioSwitch>

					<div class="extendList">
						<div v-for="(item, idx) in ruleItem.configuration.extend_external_input.properties" :key="idx" class="extendItem">
							<div class="extendItemProperty">
								<label>Property</label>
								<NcTextField
									:value.sync="item.property"
									placeholder="path.to.url" />
							</div>
							<div class="extendItemProperty">
								<label>Schema ID</label>
								<NcTextField
									:value.sync="item.schema"
									placeholder="schemaId" />
							</div>
							<NcButton class="remove-action"
								size="small"
								type="tertiary"
								:disabled="idx === 0"
								@click="removeExtendExternalItem(idx)">
								<template #icon>
									<TrashCanOutline :size="18" />
								</template>
							</NcButton>
						</div>
					</div>
				</template>

				<!-- Download Configuration -->
				<template v-if="typeOptions.value?.id === 'download'">
					<NcTextField
						label="File ID Position"
						type="number"
						:min="0"
						:value.sync="ruleItem.configuration.download.fileIdPosition"
						placeholder="Position of file ID in URL path (e.g. 2)" />

					<div class="info-text">
						<p>The system will automatically check if the authenticated user has access rights to the requested file.</p>
					</div>
				</template>

				<!-- Upload Configuration -->
				<template v-if="typeOptions.value?.id === 'upload'">
					<NcTextField
						label="Upload Path"
						:value.sync="ruleItem.configuration.upload.path"
						placeholder="/path/to/upload/directory" />

					<NcTextField
						label="Allowed File Types"
						:value.sync="ruleItem.configuration.upload.allowedTypes"
						placeholder="jpg,png,pdf" />

					<NcInputField
						type="number"
						label="Max File Size (MB)"
						:min="1"
						:value.sync="ruleItem.configuration.upload.maxSize"
						placeholder="10" />

					<div class="info-text">
						<p>Configure file upload settings including path, allowed types and maximum file size.</p>
					</div>
				</template>

				<!-- Locking Configuration -->
				<template v-if="typeOptions.value?.id === 'locking'">
					<NcSelect
						v-model="ruleItem.configuration.locking.action"
						:options="[
							{ label: 'Lock Resource', value: 'lock' },
							{ label: 'Unlock Resource', value: 'unlock' }
						]"
						input-label="Lock Action" />

					<NcInputField
						type="number"
						label="Lock Timeout (minutes)"
						:min="1"
						:value.sync="ruleItem.configuration.locking.timeout"
						placeholder="30" />

					<div class="info-text">
						<p>Lock or unlock resources for exclusive access by the current user.</p>
					</div>
				</template>

				<!-- Fetch File Configuration -->
				<template v-if="typeOptions.value?.id === 'fetch_file'">
					<NcSelect
						v-bind="sourceOptions"
						v-model="sourceOptions.sourceValue"
						required
						:loading="sourcesLoading"
						input-label="Source ID *" />

					<NcSelect
						v-bind="methodOptions"
						v-model="methodOptions.value"
						input-label="Method" />

					<NcSelect v-model="ruleItem.configuration.fetch_file.tags"
						:taggable="true"
						:multiple="true"
						input-label="Tags">
						<template #no-options>
							type to add tags
						</template>
					</NcSelect>

					<NcTextField
						label="File Path"
						:value.sync="ruleItem.configuration.fetch_file.filePath"
						placeholder="path.to.fetch.file" />

					<NcTextField
						label="File path in sub object(s) (optional)"
						:value.sync="ruleItem.configuration.fetch_file.subObjectFilepath"
						placeholder="path.to.fetch.file.objects" />

					<NcTextField
						label="Object id path (optional)"
						:value.sync="ruleItem.configuration.fetch_file.objectIdPath"
						placeholder="path.to.fetch.file.objects" />

					<NcCheckboxRadioSwitch
						type="checkbox"
						label="Auto Share"
						:checked.sync="ruleItem.configuration.fetch_file.autoShare">
						Auto share
					</NcCheckboxRadioSwitch>

					<div class="json-editor">
						<label>Source Configuration (JSON)</label>
						<div :class="`codeMirrorContainer ${getTheme()}`">
							<CodeMirror v-model="ruleItem.configuration.fetch_file.sourceConfiguration"
								:basic="true"
								placeholder="[]"
								:dark="getTheme() === 'dark'"
								:linter="jsonParseLinter()"
								:lang="json()"
								:tab-size="2" />

							<NcButton class="format-json-button"
								type="secondary"
								size="small"
								@click="formatJSONSourceConfiguration">
								Format JSON
							</NcButton>
						</div>
						<span v-if="!isValidJson(ruleItem.configuration.fetch_file.sourceConfiguration)" class="error-message">
							Invalid JSON format
						</span>
					</div>

					<NcTextField
						label="Origin id path (optional)"
						:value.sync="ruleItem.configuration.fetch_file.originIdPath"
						placeholder="path.to.fetch.file.objects" />

					<NcTextField
						label="Content path (optional)"
						:value.sync="ruleItem.configuration.fetch_file.contentPath"
						placeholder="path.to.fetch.file.objects" />

					<NcTextField
						label="Filename path (optional)"
						:value.sync="ruleItem.configuration.fetch_file.filenamePath"
						placeholder="path.to.fetch.file.objects" />

					<NcTextField
						label="File extension (optional)"
						:value.sync="ruleItem.configuration.fetch_file.fileExtension"
						placeholder="path.to.fetch.file.objects" />

					<NcTextField
						label="Endpoint (optional)"
						:value.sync="ruleItem.configuration.fetch_file.endpoint"
						placeholder="path.to.fetch.file.objects" />
				</template>

				<!-- Write File Configuration -->
				<template v-if="typeOptions.value?.id === 'write_file'">
					<NcTextField
						label="File Path"
						required
						:value.sync="ruleItem.configuration.write_file.filePath"
						placeholder="path.to.file.content" />
					<NcTextField
						label="File Name Path"
						required
						:value.sync="ruleItem.configuration.write_file.fileNamePath"
						placeholder="path.to.file.name" />

					<NcSelect v-model="ruleItem.configuration.write_file.tags"
						:taggable="true"
						:multiple="true"
						input-label="Tags">
						<template #no-options>
							type to add tags
						</template>
					</NcSelect>

					<NcCheckboxRadioSwitch
						type="checkbox"
						label="Auto Share"
						:checked.sync="ruleItem.configuration.write_file.autoShare">
						Auto share
					</NcCheckboxRadioSwitch>
				</template>

				<!-- Fileparts Create Configuration -->
				<template v-if="typeOptions.value?.id === 'fileparts_create'">
					<NcTextField
						label="Size Location"
						required
						:value.sync="ruleItem.configuration.fileparts_create.sizeLocation"
						placeholder="path.to.size.location" />

					<NcSelect v-bind="schemaOptions"
						v-model="schemaOptions.value"
						input-label="Schema *"
						:loading="schemasLoading"
						:disabled="!openRegister.isInstalled"
						required>
						<template #no-options="{ loading: schemasTemplateLoading }">
							<p v-if="schemasTemplateLoading">
								Loading...
							</p>
							<p v-if="!schemasTemplateLoading && !schemaOptions.options?.length">
								Er zijn geen schemas beschikbaar
							</p>
						</template>
						<template #option="{ id, label, fullSchema, removeStyle }">
							<div :key="id" :class="removeStyle !== true && 'schema-option'">
								<!-- custom style is enabled -->
								<FileTreeOutline v-if="!removeStyle" :size="25" />
								<span v-if="!removeStyle">
									<h6 style="margin: 0">
										{{ label }}
									</h6>
									{{ fullSchema.summary }}
								</span>
								<!-- custom style is disabled -->
								<p v-if="removeStyle">
									{{ label }}
								</p>
							</div>
						</template>
					</NcSelect>

					<NcTextField
						label="Filename Location"
						:value.sync="ruleItem.configuration.fileparts_create.filenameLocation"
						placeholder="path.to.filename.location" />

					<NcTextField
						label="Filepart Location"
						:value.sync="ruleItem.configuration.fileparts_create.filePartLocation"
						placeholder="path.to.filepart.location" />

					<NcSelect
						v-bind="filepartsCreateMappingOptions"
						v-model="filepartsCreateMappingOptions.value"
						:loading="mappingOptions.loading"
						input-label="Mapping ID" />
				</template>

				<!-- Filepart Upload Configuration -->
				<template v-if="typeOptions.value?.id === 'filepart_upload'">
					<NcSelect
						v-bind="filepartUploadMappingOptions"
						v-model="filepartUploadMappingOptions.value"
						required
						:loading="mappingOptions.loading"
						input-label="Mapping ID*" />
				</template>

				<!-- Save object Configuration -->
				<template v-if="typeOptions.value?.id === 'save_object'">
					<NcTextField
						label="Register"
						:value.sync="ruleItem.configuration.save_object.register"
						placeholder="id of register"
						required />

					<NcTextField
						label="Schema"
						:value.sync="ruleItem.configuration.save_object.schema"
						placeholder="id of schema"
						required />
				</template>
			</form>

			<div class="modal-actions">
				<NcButton v-if="!success"
					@click="closeModal">
					<template #icon>
						<CancelIcon size="20" />
					</template>
					Cancel
				</NcButton>
				<NcButton v-if="!success"
					:disabled="(loading
						|| !ruleItem.name
						|| !isValidJson(ruleItem.conditions)
						|| typeOptions.value?.id === 'fetch_file' && (!sourceOptions.sourceValue)
						|| typeOptions.value?.id === 'save_object' && (!ruleItem.configuration.save_object.schema || !ruleItem.configuration.save_object.register)
						|| typeOptions.value?.id === 'write_file' && (!ruleItem.configuration.write_file.filePath || !ruleItem.configuration.write_file.fileNamePath)
						|| typeOptions.value?.id === 'fileparts_create' && (!schemaOptions.value || !ruleItem.configuration.fileparts_create.sizeLocation)
						|| typeOptions.value?.id === 'filepart_upload' && !filepartUploadMappingOptions.value
						|| typeOptions.value?.id === 'extend_input' && !(ruleItem.configuration.extend_input.items && ruleItem.configuration.extend_input.items.filter(p => p.property && p.property.trim()).length > 0)
						|| typeOptions.value?.id === 'extend_external_input' && !(ruleItem.configuration.extend_external_input.properties && ruleItem.configuration.extend_external_input.properties.filter(p => p.property && p.property.trim() && p.schema && p.schema.trim()).length > 0)
					)"
					type="primary"
					@click="editRule()">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<ContentSaveOutline v-if="!loading" :size="20" />
					</template>
					Save
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcModal,
	NcTextField,
	NcTextArea,
	NcSelect,
	NcLoadingIcon,
	NcNoteCard,
	NcInputField,
	NcActions,
	NcActionButton,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'
import { json, jsonParseLinter } from '@codemirror/lang-json'
import CodeMirror from 'vue-codemirror6'
import { VueDraggable } from 'vue-draggable-plus'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Drag from 'vue-material-design-icons/Drag.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import CloudDownload from 'vue-material-design-icons/CloudDownload.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

import openLink from '../../services/openLink.js'

export default {
	name: 'EditRule',
	components: {
		NcModal,
		NcButton,
		NcTextField,
		NcTextArea,
		NcSelect,
		NcLoadingIcon,
		NcNoteCard,
		NcInputField,
		NcActions,
		NcActionButton,
		NcCheckboxRadioSwitch,
		VueDraggable,
		CancelIcon,
	},
	data() {
		return {
			IS_EDIT: !!ruleStore.ruleItem?.id,
			success: null,
			loading: false,
			error: false,
			warning: null,
			closeAlert: false,
			sourcesLoading: false,
			usersLoading: false,
			groupsLoading: false,
			openRegister: {
				isInstalled: true,
				isAvailable: true,
			},
			mappingOptions: {
				options: [],
				value: null,
				loading: false,
			},
			syncOptions: {
				options: [],
				value: null,
				loading: false,
			},
			usersList: [],
			groupsList: [],

			authenticationTypeOptions: {
				options: [
					{ label: 'Basic Authentication', value: 'basic' },
					{ label: 'JWT', value: 'jwt' },
					{ label: 'JWT-ZGW', value: 'jwt-zgw' },
					{ label: 'OAuth', value: 'oauth' },
					{ label: 'Api-key', value: 'api-key' },
				],
				value: {
					label: 'Basic Authentication',
					value: 'basic',
				},
			},
			apiKeyUsers: [],
			apiKeys: [{ apiKey: '', user: this.apiKeyUsers }],
			ruleItem: {
				name: '',
				description: '',
				conditions: '',
				order: 0,
				action: '',
				type: '',
				actionConfig: '{}',
				timing: '',
				configuration: {
					extend_input: {
						items: [
							{ property: '', extends: [] },
						],
					},
					extend_external_input: {
						validate: true,
						properties: [
							{ property: '', schema: '' },
						],
					},
					mapping: null,
					synchronization: {
						synchronization: null,
						retainResponse: false,
					},
					error: {
						code: 500,
						name: 'Something went wrong',
						message: 'We encountered an unexpected problem',
						includeJsonLogicResult: false,
					},
					javascript: '',
					authentication: {
						type: { label: 'Basic Authentication', value: 'basic' },
						users: [],
						groups: [],
					},
					download: {
						fileIdPosition: 0,
					},
					upload: {
						path: '',
						allowedTypes: '',
						maxSize: 10,
					},
					locking: {
						action: 'lock',
						timeout: 30,
					},
					fetch_file: {
						source: '',
						filePath: '',
						subObjectFilepath: '',
						objectIdPath: '',
						method: '',
						tags: [],
						sourceConfiguration: '[]',
						autoShare: false,
						endpoint: '',
						contentPath: '',
						originIdPath: '',
						filenamePath: '',
						fileExtension: '',
					},
					write_file: {
						filePath: '',
						tags: [],
						fileNamePath: '',
						autoShare: false,
					},
					fileparts_create: {
						sizeLocation: '',
						schemaId: '',
						filenameLocation: '',
						filePartLocation: '',
						mappingId: '',
					},
					filepart_upload: {
						mappingId: '',
					},
					save_object: {
						register: '',
						schema: '',
						mapping: '',
					},
				},
			},

			actionOptions: {},
			timingOptions: {},
			sourceOptions: {},
			methodOptions: {},
			filepartUploadMappingOptions: {},
			filepartsCreateMappingOptions: {},
			schemaOptions: {},
			typeOptions: {
				options: [
					{ label: 'Error', id: 'error' },
					{ label: 'Mapping', id: 'mapping' },
					{ label: 'Synchronization', id: 'synchronization' },
					{ label: 'JavaScript', id: 'javascript' },
					{ label: 'Authentication', id: 'authentication' },
					{ label: 'Download', id: 'download' },
					{ label: 'Upload', id: 'upload' },
					{ label: 'Locking', id: 'locking' },
					{ label: 'Fetch File', id: 'fetch_file' },
					{ label: 'Write File', id: 'write_file' },
					{ label: 'Fileparts Create', id: 'fileparts_create' },
					{ label: 'Filepart Upload', id: 'filepart_upload' },
					{ label: 'Save object', id: 'save_object' },
					{ label: 'Extend input', id: 'extend_input' },
					{ label: 'Extend external input', id: 'extend_external_input' },
				],
				value: { label: 'Error', id: 'error' },
			},

			closeTimeoutFunc: null,
		}
	},
	watch: {
		apiKeys: {
			handler(newVal) {
				const currentApiKeysLength = newVal.length

				if (this.apiKeys[currentApiKeysLength - 1]?.apiKey !== '') {
					this.apiKeys.push({ apiKey: '', user: [] })
				}

				if (currentApiKeysLength > 1) {
					for (let i = currentApiKeysLength - 2; i >= 0; i--) {
						if (this.apiKeys[i].apiKey.trim() === '') {
							this.apiKeys.splice(i, 1)
						}
					}
				}
			},
			deep: true,
		},
		// Auto-add empty extend_input item when last one is filled
		'ruleItem.configuration.extend_input.items': {
			handler(newVal) {
				if (!newVal || newVal.length === 0) return

				const lastItem = newVal[newVal.length - 1]
				// If last item has a property value, add a new empty item
				if (lastItem.property && lastItem.property.trim() !== '') {
					this.ruleItem.configuration.extend_input.items.push({ property: '', extends: [] })
				}

				// Remove empty items from the middle (except the last one)
				if (newVal.length > 1) {
					for (let i = newVal.length - 2; i >= 0; i--) {
						if (!newVal[i].property || newVal[i].property.trim() === '') {
							this.ruleItem.configuration.extend_input.items.splice(i, 1)
						}
					}
				}
			},
			deep: true,
		},
		// Auto-add empty extend_external_input property when last one is filled
		'ruleItem.configuration.extend_external_input.properties': {
			handler(newVal) {
				if (!newVal || newVal.length === 0) return

				const lastItem = newVal[newVal.length - 1]
				// If last item has both property and schema values, add a new empty item
				if (lastItem.property && lastItem.property.trim() !== ''
					&& lastItem.schema && lastItem.schema.trim() !== '') {
					this.ruleItem.configuration.extend_external_input.properties.push({ property: '', schema: '' })
				}

				// Remove empty items from the middle (except the last one)
				if (newVal.length > 1) {
					for (let i = newVal.length - 2; i >= 0; i--) {
						const item = newVal[i]
						if ((!item.property || item.property.trim() === '')
							&& (!item.schema || item.schema.trim() === '')) {
							this.ruleItem.configuration.extend_external_input.properties.splice(i, 1)
						}
					}
				}
			},
			deep: true,
		},
	},
	mounted() {

		if (this.IS_EDIT) {
			const originalConfig = ruleStore.ruleItem.configuration || {}

			this.ruleItem = {
				...ruleStore.ruleItem,

				configuration: {
					...originalConfig,
					error: {
						code: originalConfig.error?.code ?? 500,
						name: originalConfig.error?.name ?? 'Something went wrong',
						message: originalConfig.error?.message ?? 'We encountered an unexpected problem',
						includeJsonLogicResult: originalConfig.error?.includeJsonLogicResult ?? false,
					},
					synchronization: {
						synchronization: originalConfig.synchronization?.synchronization ?? null,
						retainResponse: originalConfig.synchronization?.retainResponse ?? false,
					},
					authentication: {
						type: originalConfig.authentication?.type ?? { label: 'Basic Authentication', value: 'basic' },
						users: originalConfig.authentication?.users ?? [],
						groups: originalConfig.authentication?.groups ?? [],
						keys: originalConfig.authentication?.keys ?? [],
					},
					download: {
						fileIdPosition: originalConfig.download?.fileIdPosition ?? 0,
					},
					upload: {
						path: originalConfig.upload?.path ?? '',
						allowedTypes: originalConfig.upload?.allowedTypes ?? '',
						maxSize: originalConfig.upload?.maxSize ?? 10,
					},
					locking: {
						action: originalConfig.locking?.action ?? 'lock',
						timeout: originalConfig.locking?.timeout ?? 30,
					},
					fetch_file: {
						source: originalConfig.fetch_file?.source ?? '',
						filePath: originalConfig.fetch_file?.filePath ?? '',
						subObjectFilepath: originalConfig.fetch_file?.subObjectFilepath ?? '',
						objectIdPath: originalConfig.fetch_file?.objectIdPath ?? '',
						method: originalConfig.fetch_file?.method ?? '',
						tags: originalConfig.fetch_file?.tags ?? [],
						sourceConfiguration: originalConfig.fetch_file?.sourceConfiguration
							? JSON.stringify(originalConfig.fetch_file.sourceConfiguration, null, 2)
							: '[]',
						autoShare: originalConfig.fetch_file?.autoShare ?? false,
						endpoint: originalConfig.fetch_file?.endpoint ?? '',
						contentPath: originalConfig.fetch_file?.contentPath ?? '',
						originIdPath: originalConfig.fetch_file?.originIdPath ?? '',
						filenamePath: originalConfig.fetch_file?.filenamePath ?? '',
						fileExtension: originalConfig.fetch_file?.fileExtension ?? '',
					},
					write_file: {
						filePath: originalConfig.write_file?.filePath ?? '',
						fileNamePath: originalConfig.write_file?.fileNamePath ?? '',
						tags: originalConfig.write_file?.tags ?? [],
						autoShare: originalConfig.write_file?.autoShare ?? false,
					},
					fileparts_create: {
						sizeLocation: originalConfig.fileparts_create?.sizeLocation ?? '',
						schemaId: originalConfig.fileparts_create?.schemaId ?? '',
						filenameLocation: originalConfig.fileparts_create?.filenameLocation ?? '',
						filePartLocation: originalConfig.fileparts_create?.filePartLocation ?? '',
						mappingId: originalConfig.fileparts_create?.mappingId ?? '',
					},
					filepart_upload: {
						mappingId: originalConfig.filepart_upload?.mappingId ?? '',
					},
					save_object: {
						register: originalConfig.save_object?.register ?? '',
						schema: originalConfig.save_object?.schema ?? '',
						mapping: originalConfig.save_object?.mapping ?? '',
					},
				},
				conditions: JSON.stringify(ruleStore.ruleItem.conditions, null, 2),
				actionConfig: JSON.stringify(ruleStore.ruleItem.actionConfig),
			}

			const foundType = this.typeOptions.options.find(
				option => option.id === this.ruleItem.type,
			)

			if (foundType) {
				this.typeOptions.value = foundType
			} else {
				console.warn(`Unknown rule type: ${this.ruleItem.type}. Configuration preserved.`)
				this.typeOptions.value = {
					label: `Unknown: ${this.ruleItem.type}`,
					id: this.ruleItem.type,
				}
				this.warning = `Unknown rule type: ${this.ruleItem.type}. Some configuration may not be editable in this UI.`
			}

			this.authenticationTypeOptions.value = this.authenticationTypeOptions.options.find(
				option => option.value === (originalConfig.authentication?.type ?? Symbol('backup value')),
			)
		}
		if (!this.IS_EDIT) {
			this.authenticationTypeOptions.value = { label: 'Basic Authentication', value: 'basic' }
		}
		this.setMethodOptions()
		this.setActionOptions()
		this.setTimingOptions()
		this.getMappings()
		this.getSynchronizations()
		this.getSources()
		this.getSchemas()
		this.getAllowedUsers()
		this.getGroups()
		this.getApiKeysUsers()

		// Initialize extend_input/extend_external_input structures for new items
		if (!this.ruleItem.configuration.extend_external_input) {
			this.$set?.(this.ruleItem.configuration, 'extend_external_input', {
				validate: true,
				properties: [{ property: '', schema: '' }],
			})
		} else if (!this.ruleItem.configuration.extend_external_input.properties || this.ruleItem.configuration.extend_external_input.properties.length === 0) {
			this.ruleItem.configuration.extend_external_input.properties = [{ property: '', schema: '' }]
		}

		if (this.ruleItem.configuration?.extend_input?.properties) {
			const props = this.ruleItem.configuration.extend_input.properties || []
			const ext = this.ruleItem.configuration.extend_input.extends || {}
			this.ruleItem.configuration.extend_input = {
				items: props.map((p) => ({ property: p, extends: ext[p] || [] })),
			}
			if (this.ruleItem.configuration.extend_input.items.length === 0 || this.ruleItem.configuration.extend_input.items[this.ruleItem.configuration.extend_input.items.length - 1].property) {
				this.ruleItem.configuration.extend_input.items.push({ property: '', extends: [] })
			}
		} else if (!this.ruleItem.configuration.extend_input) {
			this.$set?.(this.ruleItem.configuration, 'extend_input', {
				items: [{ property: '', extends: [] }],
			})
		} else if (!this.ruleItem.configuration.extend_input.items || this.ruleItem.configuration.extend_input.items.length === 0) {
			this.ruleItem.configuration.extend_input.items = [{ property: '', extends: [] }]
		}
	},
	methods: {
		async getMappings() {
			try {
				this.mappingOptions.loading = true
				await mappingStore.refreshList()

				// Use the store's list directly
				const mappings = mappingStore.list
				if (mappings?.length) {

					// Set active filepart upload mapping
					const activeFilepartUploadMapping = mappings.find((mapping) => mapping?.id.toString() === (this.ruleItem.configuration.filepart_upload.mappingId?.toString() ?? ''))
					this.filepartUploadMappingOptions = {
						options: mappings.map(mapping => ({
							label: mapping.name,
							value: mapping.id,
						})),
						value: activeFilepartUploadMapping
							? {
								label: activeFilepartUploadMapping.name,
								value: activeFilepartUploadMapping.id,
							}
							: null,
					}

					// Set active filepart upload mapping
					const activeFilepartsCreateMapping = mappings.find((mapping) => mapping?.id.toString() === (this.ruleItem.configuration.fileparts_create.mappingId?.toString() ?? ''))
					this.filepartsCreateMappingOptions = {
						options: mappings.map(mapping => ({
							label: mapping.name,
							value: mapping.id,
						})),
						value: activeFilepartsCreateMapping
							? {
								label: activeFilepartsCreateMapping.name,
								value: activeFilepartsCreateMapping.id,
							}
							: null,
					}

					// Set mapping options
					this.mappingOptions.options = mappings.map(mapping => ({
						label: mapping.name,
						value: mapping.id,
					}))

					// Set active mapping if editing
					if (this.IS_EDIT && this.ruleItem.configuration?.mapping) {
						const activeMapping = this.mappingOptions.options.find(
							option => option.value === this.ruleItem.configuration.mapping,
						)
						if (activeMapping) {
							this.mappingOptions.value = activeMapping
						}
					}
				}
			} catch (error) {
				console.error('Failed to fetch mappings:', error)
			} finally {
				this.mappingOptions.loading = false
			}
		},

		getSources() {
			this.sourcesLoading = true

			sourceStore.refreshList()
				.then(() => {

					const sources = sourceStore.list

					const activeSourceSource = sources.find(source => source.id.toString() === (this.ruleItem.configuration.fetch_file.source.toString() ?? ''))

					this.sourceOptions = {
						options: sources.map(source => ({
							label: source.name,
							id: source.id,
						})),
						sourceValue: activeSourceSource
							? {
								label: activeSourceSource.name,
								id: activeSourceSource.id,
							}
							: null,
					}
				})
				.finally(() => {
					this.sourcesLoading = false
				})
		},
		async getSchemas() {
			this.schemasLoading = true

			// checking if OpenRegister is installed
			console.info('Fetching schemas from Open Register')
			const response = await fetch('/index.php/apps/openregister/api/schemas', {
				headers: {
					accept: '*/*',
					'accept-language': 'en-US,en;q=0.9,nl;q=0.8',
					'cache-control': 'no-cache',
					pragma: 'no-cache',
					'x-requested-with': 'XMLHttpRequest',
				},
				referrerPolicy: 'no-referrer',
				body: null,
				method: 'GET',
				mode: 'cors',
				credentials: 'include',
			})

			if (!response.ok) {
				console.info('Open Register is not installed')
				this.schemasLoading = false
				this.openRegister.isInstalled = false
				return
			}

			this.typeOptions.options = [
				...this.typeOptions.options,

			]

			const responseData = (await response.json()).results

			const activeSchema = responseData.find(schema => schema.id.toString() === (this.ruleItem.configuration.fileparts_create.schemaId.toString() ?? ''))

			this.schemaOptions = {
				options: responseData.map((schema) => ({
					id: schema.id,
					label: schema.title,
					fullSchema: schema,
				})),
				value: activeSchema
					? {
						id: activeSchema.id,
						label: activeSchema.title,
					}
					: null,
			}

			this.schemasLoading = false
		},

		async getSynchronizations() {
			try {
				this.syncOptions.loading = true
				await synchronizationStore.refreshSynchronizationList()

				// Use the store's synchronizationList directly
				const synchronizations = synchronizationStore.synchronizationList
				if (synchronizations?.length) {
					this.syncOptions.options = synchronizations.map(sync => ({
						label: sync.name,
						value: sync.id,
					}))

					// Set active synchronization if editing
					if (this.IS_EDIT && this.ruleItem.configuration?.synchronization.synchronization) {
						const activeSync = this.syncOptions.options.find(
							option => option.value === this.ruleItem.configuration.synchronization.synchronization,
						)
						if (activeSync) {
							this.syncOptions.value = activeSync
						}
					}
				}
			} catch (error) {
				console.error('Failed to fetch synchronizations:', error)
			} finally {
				this.syncOptions.loading = false
			}
		},

		async getAllowedUsers() {
			this.usersLoading = true
			const response = await fetch('/ocs/v1.php/cloud/users/details', {
				method: 'GET',
				headers: {
					Accept: 'application/json',
					'OCS-APIRequest': 'true',
				},
			})
			if (!response.ok) {
				console.info('Fetching users was not successful')
				this.usersLoading = false
				return
			}

			const responseData = await response.json()

			const selectedUsersValues = await Object.values(responseData.ocs.data.users).filter(user => this.ruleItem.configuration.authentication.users.includes(user.id))

			this.usersList = {
				options: Object.values(responseData.ocs.data.users).map((user) => ({
					id: user.id,
					displayName: user.displayname,
					subname: user.email,
					user: user.id,
				})),
				value: selectedUsersValues
					? selectedUsersValues.map(user => ({
						id: user.id,
						displayName: user.displayname,
						subname: user.email,
						user: user.id,
					}))
					: [],
			}

			this.ruleItem.configuration.authentication.users = selectedUsersValues.map(user => ({
				id: user.id,
				displayName: user.displayname,
				subname: user.email,
				user: user.id,
			}))

			this.usersLoading = false
		},

		async getApiKeysUsers() {
			this.usersLoading = true
			const response = await fetch('/ocs/v1.php/cloud/users/details', {
				method: 'GET',
				headers: {
					Accept: 'application/json',
					'OCS-APIRequest': 'true',
				},
			})
			if (!response.ok) {
				console.info('Fetching users was not successful')
				this.usersLoading = false
				return
			}

			const responseData = await response.json()

			this.apiKeyUsers = {
				options: Object.values(responseData.ocs.data.users).map((user) => ({
					id: user.id,
					displayName: user.displayname,
					subname: user.email,
					user: user.id,
					name: user.displayname,
				})),
			}

			if (this.ruleItem.configuration.authentication.keys) {

				this.apiKeys = this.ruleItem.configuration.authentication.keys.map((key) => {

					let user = null
					let apiKey = null

					Object.entries(key).forEach(([key, value]) => {
						apiKey = key
						user = value

					})

					const selectedUser = Object.values(responseData.ocs.data.users).find(_user => user === _user.id)
					return {
						apiKey,
						user: selectedUser
							? {
								id: selectedUser.id,
								displayName: selectedUser.displayname,
								subname: selectedUser.email,
								user: selectedUser.id,
							}
							: null,
					}
				})

			}

		},

		async getGroups() {
			this.groupsLoading = true
			const response = await fetch('/ocs/v1.php/cloud/groups/details', {
				method: 'GET',
				headers: {
					Accept: 'application/json',
					'OCS-APIRequest': 'true',
				},
			},
			)
			if (!response.ok) {
				console.info('Fetching groups was not successful')
				this.groupsLoading = false
				return
			}

			const responseData = await response.json()

			const selectedGroupsValues = await responseData.ocs.data.groups.filter(group => this.ruleItem.configuration.authentication.groups.includes(group.id))

			this.groupsList = {
				options: await responseData.ocs.data.groups.map(group => ({
					label: group.displayname,
					value: group.id,
				})),
				value: selectedGroupsValues
					? selectedGroupsValues.map(group => ({
						label: group.displayname,
						value: group.id,
					}))
					: [],
			}

			this.ruleItem.configuration.authentication.groups = selectedGroupsValues.map(group => ({
				label: group.displayname,
				value: group.id,
			}))

			this.groupsLoading = false
		},

		setMethodOptions() {
			const options = [
				{ label: 'GET' },
				{ label: 'POST' },
				{ label: 'PUT' },
				{ label: 'DELETE' },
				{ label: 'PATCH' },
			]

			this.methodOptions = {
				options,
				value: options.find(option => option.label === this.ruleItem.configuration.fetch_file.method),
			}
		},

		setActionOptions() {
			const options = [
				{ label: 'Post (Create)', id: 'post' },
				{ label: 'Get (Read)', id: 'get' },
				{ label: 'Put (Update)', id: 'put' },
				{ label: 'Delete (Delete)', id: 'delete' },
			]

			this.actionOptions = {
				options,
				value: options.find(option => option.id === this.ruleItem.action) || options[0],
			}
		},

		setTimingOptions() {
			const options = [
				{ label: 'Before', id: 'before' },
				{ label: 'After', id: 'after' },
			]

			this.timingOptions = {
				options,
				value: options.find(option => option.id === this.ruleItem.timing) || options[0],
			}
		},

		addExtendExternalItem() {
			if (!this.ruleItem.configuration.extend_external_input) {
				this.ruleItem.configuration.extend_external_input = { validate: true, properties: [] }
			}
			this.ruleItem.configuration.extend_external_input.properties.push({ property: '', schema: '' })
		},
		removeExtendExternalItem(index) {
			if (index === 0) return
			this.ruleItem.configuration.extend_external_input.properties.splice(index, 1)
		},
		addExtendInputItem() {
			if (!this.ruleItem.configuration.extend_input) {
				this.ruleItem.configuration.extend_input = { items: [] }
			}
			this.ruleItem.configuration.extend_input.items.push({ property: '', extends: [] })
		},
		removeExtendInputItem(index) {
			if (index === 0) return
			this.ruleItem.configuration.extend_input.items.splice(index, 1)
		},

		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeTimeoutFunc)
		},

		isValidJson(str) {
			if (!str) return true
			try {
				JSON.parse(str)
				return true
			} catch (e) {
				return false
			}
		},

		formatJSONCondictions() {
			try {
				if (this.ruleItem.conditions) {
					// Format the JSON with proper indentation
					const parsed = JSON.parse(this.ruleItem.conditions)
					this.ruleItem.conditions = JSON.stringify(parsed, null, 2)
				}
			} catch (e) {
				// Keep invalid JSON as-is to allow user to fix it
			}
		},

		formatJSONSourceConfiguration() {
			try {
				if (this.ruleItem.configuration.fetch_file.sourceConfiguration) {
					const parsed = JSON.parse(this.ruleItem.configuration.fetch_file.sourceConfiguration)
					this.ruleItem.configuration.fetch_file.sourceConfiguration = JSON.stringify(parsed, null, 2)
				}
			} catch (e) {
				// Keep invalid JSON as-is to allow user to fix it
			}
		},

		async installOpenRegister() {
			console.info('Installing Open Register')
			const token = document.querySelector('head[data-requesttoken]').getAttribute('data-requesttoken')

			const response = await fetch('/index.php/settings/apps/enable', {
				headers: {
					accept: '*/*',
					'accept-language': 'en-US,en;q=0.9,nl;q=0.8',
					'cache-control': 'no-cache',
					'content-type': 'application/json',
					pragma: 'no-cache',
					requesttoken: token,
					'x-requested-with': 'XMLHttpRequest, XMLHttpRequest',
				},
				referrerPolicy: 'no-referrer',
				body: '{"appIds":["openregister"],"groups":[]}',
				method: 'POST',
				mode: 'cors',
				credentials: 'include',
			})

			if (!response.ok) {
				console.info('Failed to install Open Register')
				this.openRegister.isAvailable = false
			} else {
				console.info('Open Register installed')
				this.openRegister.isInstalled = true
				this.getSchemas()
			}
		},

		editRule() {
			this.loading = true

			// Create clean configuration for the current type only
			const configuration = {}
			const type = this.typeOptions.value?.id

			// Build configuration based on type
			switch (type) {
			case 'error':
				configuration.error = {
					code: this.ruleItem.configuration.error.code,
					name: this.ruleItem.configuration.error.name,
					message: this.ruleItem.configuration.error.message,
					includeJsonLogicResult: this.ruleItem.configuration.error.includeJsonLogicResult,
				}
				break
			case 'mapping':
				configuration.mapping = this.mappingOptions.value?.value
				break
			case 'synchronization':
				configuration.synchronization = {}
				configuration.synchronization.synchronization = this.syncOptions.value?.value
				configuration.synchronization.retainResponse = this.ruleItem.configuration.synchronization.retainResponse
				break
			case 'javascript':
				configuration.javascript = this.ruleItem.configuration.javascript
				break
			case 'authentication':
				configuration.authentication = {
					type: this.authenticationTypeOptions.value.value,
					users: this.ruleItem.configuration.authentication.users.map(user => user.id),
					groups: this.ruleItem.configuration.authentication.groups.map(group => group.value),
					keys: this.apiKeys
						.filter(key => key.apiKey && key.user?.id) // Filter out incomplete entries
						.map(key => ({
							[key.apiKey]: key.user.id,
						}))
						.filter(Boolean),
				}
				break
			case 'download':
				configuration.download = {
					fileIdPosition: this.ruleItem.configuration.download.fileIdPosition,
				}
				break
			case 'upload':
				configuration.upload = {
					path: this.ruleItem.configuration.upload.path,
					allowedTypes: this.ruleItem.configuration.upload.allowedTypes,
					maxSize: this.ruleItem.configuration.upload.maxSize,
				}
				break
			case 'locking':
				configuration.locking = {
					action: this.ruleItem.configuration.locking.action.value || this.ruleItem.configuration.locking.action,
					timeout: this.ruleItem.configuration.locking.timeout,
				}
				break
			case 'extend_input':
				configuration.extend_input = {
					properties: (this.ruleItem.configuration.extend_input?.items ?? [])
						.filter(i => i.property && i.property.trim())
						.map(i => i.property),
					extends: (this.ruleItem.configuration.extend_input?.items ?? [])
						.filter(i => i.property && i.property.trim())
						.reduce((acc, i) => { acc[i.property] = i.extends || []; return acc }, {}),
				}
				break
			case 'extend_external_input':
				configuration.extend_external_input = {
					validate: this.ruleItem.configuration.extend_external_input?.validate ?? true,
					properties: (this.ruleItem.configuration.extend_external_input?.properties ?? [])
						.filter(p => p.property && p.property.trim() && p.schema && p.schema.trim())
						.map(p => ({ property: p.property, schema: p.schema })),
				}
				break
			case 'fetch_file':
				configuration.fetch_file = {
					source: this.sourceOptions.sourceValue?.id,
					filePath: this.ruleItem.configuration.fetch_file.filePath,
					subObjectFilepath: this.ruleItem.configuration.fetch_file.subObjectFilepath,
					objectIdPath: this.ruleItem.configuration.fetch_file.objectIdPath,
					method: this.methodOptions.value?.label,
					tags: this.ruleItem.configuration.fetch_file.tags,
					sourceConfiguration: this.ruleItem.configuration.fetch_file.sourceConfiguration ? JSON.parse(this.ruleItem.configuration.fetch_file.sourceConfiguration) : [],
					autoShare: this.ruleItem.configuration.fetch_file.autoShare,
					endpoint: this.ruleItem.configuration?.fetch_file?.endpoint ?? '',
					contentPath: this.ruleItem.configuration?.fetch_file?.contentPath ?? '',
					originIdPath: this.ruleItem.configuration?.fetch_file?.originIdPath ?? '',
					filenamePath: this.ruleItem.configuration?.fetch_file?.filenamePath ?? '',
					fileExtension: this.ruleItem.configuration?.fetch_file?.fileExtension ?? '',

				}
				break
			case 'write_file':
				configuration.write_file = {
					filePath: this.ruleItem.configuration.write_file.filePath,
					fileNamePath: this.ruleItem.configuration.write_file.fileNamePath,
					tags: this.ruleItem.configuration.write_file.tags,
					autoShare: this.ruleItem.configuration.write_file.autoShare,
				}
				break
			case 'fileparts_create':
				configuration.fileparts_create = {
					sizeLocation: this.ruleItem.configuration.fileparts_create.sizeLocation,
					schemaId: this.schemaOptions.value?.id,
					filenameLocation: this.ruleItem.configuration.fileparts_create.filenameLocation,
					filePartLocation: this.ruleItem.configuration.fileparts_create.filePartLocation,
					mappingId: this.filepartsCreateMappingOptions.value?.value,
				}
				break
			case 'filepart_upload':
				configuration.filepart_upload = {
					mappingId: this.filepartUploadMappingOptions.value?.value,
				}
				break
			case 'save_object':
				configuration.save_object = {
					register: this.ruleItem.configuration.save_object.register,
					schema: this.ruleItem.configuration.save_object.schema,
					mapping: this.mappingOptions.value?.value,
				}
				break
			}

			const newRuleItem = new Rule({
				...this.ruleItem,
				conditions: this.ruleItem.conditions ? JSON.parse(this.ruleItem.conditions) : [],
				action: this.actionOptions.value?.id || null,
				timing: this.timingOptions.value?.id || null,
				type: type || null,
				configuration,
			})

			ruleStore.saveRule(newRuleItem)
				.then(({ response, data }) => {
					this.success = response.ok
					this.error = !response.ok && 'Failed to save rule'

					// Warn if configuration contains unknown keys for current type
					if (response.ok) {
						const cfg = newRuleItem.configuration || {}
						const known = {
							error: ['error'],
							mapping: ['mapping'],
							synchronization: ['synchronization'],
							javascript: ['javascript'],
							authentication: ['authentication'],
							download: ['download'],
							upload: ['upload'],
							locking: ['locking'],
							fetch_file: ['fetch_file'],
							write_file: ['write_file'],
							fileparts_create: ['fileparts_create'],
							filepart_upload: ['filepart_upload'],
							save_object: ['save_object'],
							extend_input: ['extend_input'],
							extend_external_input: ['extend_external_input'],
						}
						const allowed = new Set(known[type] || [])
						const unknown = Object.keys(cfg).filter(k => !allowed.has(k))
						if (unknown.length) {
							this.warning = `Configuration contains unrecognized keys: ${unknown.join(', ')} — they were preserved.`
						}
					}

					response.ok && (this.closeTimeoutFunc = setTimeout(this.closeModal, 2000))
				})
				.catch(error => {
					this.success = false
					this.error = error.message || 'An error occurred while saving the rule'
				})
				.finally(() => {
					this.loading = false
				})
		},
	},
}
</script>

<style scoped>
.json-editor {
	position: relative;
	margin-bottom: 2.5rem;
}

.json-editor label {
	display: block;
	margin-bottom: 0.5rem;
	font-weight: bold;
}

.install-buttons {
	display: flex;
	gap: 0.5rem;
	margin-block-start: 1rem;
}

.close-button {
	position: absolute;
	top: 5px;
	right: 5px;
}

.close-button .button-vue--vue-tertiary:hover:not(:disabled) {
	background-color: rgba(var(--color-info-rgb), 0.1);
}

.json-editor .error-message {
	position: absolute;
	bottom: 0;
	right: 50%;
	transform: translateY(100%) translateX(50%);

	color: var(--color-error);
	font-size: 0.8rem;
	padding-top: 0.25rem;
	display: block;
}

.json-editor .format-json-button {
	position: absolute;
	bottom: 0;
	right: 0;
	transform: translateY(100%);
}

/* Add styles for the code editor */
.code-editor {
	font-family: monospace;
	width: 100%;
	background-color: var(--color-background-dark);
}

.info-text {
	margin: 1rem 0;
	padding: 0.5rem;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
}

/* Extend lists */
.extendList {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.extendItem {
	display: flex;
	justify-content: space-between;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px 12px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.extendItem :deep(.v-select) {
	min-width: 260px;
}

.extendItem .remove-action.button-vue--vue-tertiary {
	color: var(--color-error);
	margin-inline-end: 15px;
	background-color: rgba(var(--color-error-rgb), 0.08);
}

.extendItem .remove-action.button-vue--vue-tertiary:hover:not(:disabled) {
	background-color: rgba(var(--color-error-rgb), 0.14);
}

.extendItemProperty {
	display: flex;
	flex-direction: column;
	gap: 4px;
	align-items: center;
	justify-content: center;
}

/* CodeMirror */
.codeMirrorContainer {
	margin-block-start: 6px;
	text-align: left;
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

/* Open Register notecard */
.openregister-notecard {
    display: flex;
    justify-content: center;
    margin-bottom: 20px;
}
.openregister-notecard .notecard {
    position: relative;
    width: fit-content;
}

/* Schema option */
.schema-option {
	display: flex;
	align-items: center;
	gap: 10px;
}

.schema-option > .material-design-icon {
	margin-block-start: 2px;
}

.schema-option > h6 {
	line-height: 0.8;
}

.draggable-form-item {
	display: flex;
	align-items: center;
	gap: 3px;

	background-color: rgba(255, 255, 255, 0.05);
	padding: 4px;
	border-radius: 12px;

	margin-block: 8px;
}

.draggable-form-item.light {
	background-color: rgba(0, 0, 0, 0.05);
}

.draggable-form-item :deep(.v-select) {
	min-width: 150px;
}

.draggable-form-item :deep(.input-field__label) {
	margin-block-start: 0 !important;
}

.draggable-form-item .input-field {
	margin-block-start: 0 !important;
}

.draggable-item-container:last-child .drag-handle {
	cursor: not-allowed;
}

.apiKeyTextArea {
	flex: 1 0 0;
}

.apiKeyUserSelect {
	width: 45%;
	margin-left: 10px;
	margin-right: 8px;
}
</style>

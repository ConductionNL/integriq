<script setup>
import { translate as t } from '@nextcloud/l10n'
import { ruleStore, navigationStore, mappingStore, synchronizationStore, sourceStore } from '../../store/store.js'
import { Rule } from '../../entities/index.js'
import { ruleSchema, RULE_TYPE_KEYS, ruleTypeLabel } from '../../views/rule/ruleSchema.js'
</script>

<template>
	<CnFormDialog
		v-if="navigationStore.modal === 'editRule'"
		ref="formDialog"
		:item="initialItem"
		:schema="schemaForDialog"
		:fields="formFields"
		:dialog-title="initialItem?.id ? t('openconnector', 'Edit rule') : t('openconnector', 'Add rule')"
		name-field="name"
		@confirm="onConfirm"
		@close="closeModal">
		<!-- Open Register install prompt -->
		<template v-if="!openRegister.isInstalled && !closeAlert" #before-fields>
			<NcNoteCard
				:type="openRegister.isAvailable ? 'info' : 'error'"
				:heading="openRegister.isAvailable
					? t('openconnector', 'Open Register is not installed')
					: t('openconnector', 'Failed to install Open Register')">
				<p>
					{{ openRegister.isAvailable
						? t('openconnector', 'Some features require Open Register to be installed')
						: t('openconnector', 'You may not have sufficient rights to install Open Register, or Open Register is not available on this server.') }}
				</p>
				<div class="install-buttons">
					<NcButton v-if="openRegister.isAvailable"
						size="small"
						type="primary"
						@click="installOpenRegister">
						<template #icon>
							<CloudDownload :size="20" />
						</template>
						{{ t('openconnector', 'Install Open Register') }}
					</NcButton>
					<NcButton
						size="small"
						type="secondary"
						@click="openLink('/index.php/settings/apps/organization/openregister', '_blank')">
						<template #icon>
							<OpenInNew :size="20" />
						</template>
						{{ t('openconnector', 'Install manually') }}
					</NcButton>
					<NcButton size="small" type="tertiary" @click="closeAlert = true">
						<template #icon>
							<Close :size="20" />
						</template>
						{{ t('openconnector', 'Close') }}
					</NcButton>
				</div>
			</NcNoteCard>
		</template>

		<!-- Conditions: shorter editor height than the CnFormDialog default -->
		<template #field-conditions="{ field, updateField }">
			<div class="cn-form-dialog__json-wrapper">
				<label class="cn-form-dialog__label">
					{{ field.label }}
				</label>
				<CnJsonViewer
					:value="conditionsDraft"
					language="json"
					height="150px"
					:error-text="conditionsError || ''"
					@update:value="value => onConditionsInput(value, updateField)" />
			</div>
		</template>

		<!-- Synthetic row hosting action + type in a 2-col grid.
		     Values are tracked in component-local `meta`; merged in onConfirm. -->
		<template #field-_rowMeta>
			<div class="ruleMetaRow">
				<div class="cn-form-dialog__select-wrapper">
					<label class="cn-form-dialog__label">
						{{ t('openconnector', 'Action') }} *
					</label>
					<NcSelect
						:options="actionSelectOptions"
						:value="actionSelectOptions.find(o => o.id === meta.action) || actionSelectOptions[0]"
						:clearable="false"
						@input="opt => (meta.action = opt ? opt.id : 'post')" />
				</div>
				<div class="cn-form-dialog__select-wrapper">
					<label class="cn-form-dialog__label">
						{{ t('openconnector', 'Type') }} *
					</label>
					<NcSelect
						:options="typeSelectOptions"
						:value="resolvedTypeOption(meta.type)"
						:selectable="isTypeSelectable"
						:clearable="false"
						@input="opt => onTypeChange(opt)" />
				</div>
			</div>
		</template>

		<!-- Mapping select (mapping & save_object types) -->
		<template #field-mappingId="{ value, updateField }">
			<div class="cn-form-dialog__select-wrapper">
				<label class="cn-form-dialog__label">
					{{ t('openconnector', 'Mapping') }}
				</label>
				<NcSelect
					:options="mappingSelectOptions"
					:value="resolvedMappingOption(value)"
					:loading="mappingsLoading"
					:clearable="false"
					@input="opt => updateField('mappingId', opt ? opt.id : null)" />
			</div>
		</template>

		<!-- Synchronization select -->
		<template #field-synchronizationId="{ value, updateField }">
			<div class="cn-form-dialog__select-wrapper">
				<label class="cn-form-dialog__label">
					{{ t('openconnector', 'Synchronization') }}
				</label>
				<NcSelect
					:options="synchronizationSelectOptions"
					:value="resolvedSynchronizationOption(value)"
					:loading="synchronizationsLoading"
					:clearable="false"
					@input="opt => updateField('synchronizationId', opt ? opt.id : null)" />
			</div>
		</template>

		<!-- Authentication type select + dependent users/groups/api-keys widgets -->
		<template #field-authType="{ value, updateField }">
			<div class="cn-form-dialog__select-wrapper">
				<label class="cn-form-dialog__label">
					{{ t('openconnector', 'Authentication type') }}
				</label>
				<NcSelect
					:options="authTypeOptions"
					:value="resolvedAuthTypeOption(value)"
					:clearable="false"
					@input="opt => onAuthTypeChange(opt, updateField)" />
			</div>
		</template>

		<template #field-authUsersGroups>
			<template v-if="authTypeLocal === 'api-key'">
				<label class="cn-form-dialog__label">{{ t('openconnector', 'API keys') }}</label>
				<VueDraggable v-model="apiKeys" easing="ease-in-out" draggable="div:not(:last-child)">
					<div v-for="(item, index) in apiKeys" :key="index" class="draggable-item-container">
						<div class="draggable-form-item">
							<Drag class="drag-handle" :size="24" />
							<NcTextArea
								:value.sync="item.apiKey"
								:label="t('openconnector', 'API key')"
								resize="none"
								class="apiKeyTextArea" />
							<NcSelect
								v-model="item.user"
								:options="apiKeyUsers"
								:user-select="true"
								:clearable="true"
								:placeholder="t('openconnector', 'Select allowed user')"
								class="apiKeyUserSelect" />
						</div>
					</div>
				</VueDraggable>
			</template>
			<template v-else>
				<div class="cn-form-dialog__select-wrapper">
					<label class="cn-form-dialog__label">{{ t('openconnector', 'Allowed users') }}</label>
					<NcSelect
						v-model="authUsers"
						:options="usersList"
						:user-select="true"
						:multiple="true"
						:clearable="true"
						:placeholder="t('openconnector', 'Select users who can access')" />
				</div>
				<div class="cn-form-dialog__select-wrapper">
					<label class="cn-form-dialog__label">{{ t('openconnector', 'Allowed groups') }}</label>
					<NcSelect
						v-model="authGroups"
						:options="groupsList"
						:multiple="true"
						:clearable="true"
						:placeholder="t('openconnector', 'Select groups who can access')" />
				</div>
			</template>
		</template>

		<!-- Extend input: repeatable {property, extends[]} rows -->
		<template #field-extendInputItems>
			<label class="cn-form-dialog__label">{{ t('openconnector', 'Extend input items') }}</label>
			<div class="extendList">
				<div v-for="(item, idx) in extendInputItems" :key="idx" class="extendItem">
					<div class="extendItemProperty">
						<label>{{ t('openconnector', 'Property (dot path)') }}</label>
						<NcTextField
							:value.sync="item.property"
							placeholder="a.b" />
					</div>
					<div class="extendItemProperty">
						<label>{{ t('openconnector', 'Extends (dot array)') }}</label>
						<NcSelect
							v-model="item.extends"
							:taggable="true"
							:multiple="true"
							:clearable="true"
							:options="[]">
							<template #no-options>
								{{ t('openconnector', 'Type to add path to extend') }}
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

		<!-- Extend external input: repeatable {property, schema} rows -->
		<template #field-extendExternalInputItems>
			<label class="cn-form-dialog__label">{{ t('openconnector', 'External properties') }}</label>
			<div class="extendList">
				<div v-for="(item, idx) in extendExternalItems" :key="idx" class="extendItem">
					<div class="extendItemProperty">
						<label>{{ t('openconnector', 'Property') }}</label>
						<NcTextField
							:value.sync="item.property"
							placeholder="path.to.url" />
					</div>
					<div class="extendItemProperty">
						<label>{{ t('openconnector', 'Schema ID') }}</label>
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

		<!-- Fetch file: source select -->
		<template #field-fetchFileSourceId="{ value, updateField }">
			<div class="cn-form-dialog__select-wrapper">
				<label class="cn-form-dialog__label">
					{{ t('openconnector', 'Source') }} *
				</label>
				<NcSelect
					:options="sourceSelectOptions"
					:value="resolvedSourceOption(value)"
					:loading="sourcesLoading"
					:clearable="false"
					@input="opt => updateField('fetchFileSourceId', opt ? opt.id : null)" />
			</div>
		</template>

		<!-- Fileparts create: schema select with custom option rendering -->
		<template #field-filepartsCreateSchemaId="{ value, updateField }">
			<div class="cn-form-dialog__select-wrapper">
				<label class="cn-form-dialog__label">
					{{ t('openconnector', 'Schema') }} *
				</label>
				<NcSelect
					:options="schemaSelectOptions"
					:value="resolvedSchemaOption(value)"
					:loading="schemasLoading"
					:disabled="!openRegister.isInstalled"
					:clearable="false"
					@input="opt => updateField('filepartsCreateSchemaId', opt ? opt.id : null)">
					<template #no-options>
						<p v-if="schemasLoading">
							{{ t('openconnector', 'Loading...') }}
						</p>
						<p v-else>
							{{ t('openconnector', 'No schemas available') }}
						</p>
					</template>
					<template #option="{ label, fullSchema }">
						<div class="schema-option">
							<FileTreeOutline :size="25" />
							<span>
								<h6 style="margin: 0">{{ label }}</h6>
								{{ fullSchema?.summary }}
							</span>
						</div>
					</template>
				</NcSelect>
			</div>
		</template>

		<!-- Fileparts create: mapping select -->
		<template #field-filepartsCreateMappingId="{ value, updateField }">
			<div class="cn-form-dialog__select-wrapper">
				<label class="cn-form-dialog__label">
					{{ t('openconnector', 'Mapping') }}
				</label>
				<NcSelect
					:options="mappingSelectOptions"
					:value="resolvedMappingOption(value)"
					:loading="mappingsLoading"
					@input="opt => updateField('filepartsCreateMappingId', opt ? opt.id : null)" />
			</div>
		</template>

		<!-- Filepart upload: mapping select (required) -->
		<template #field-filepartUploadMappingId="{ value, updateField }">
			<div class="cn-form-dialog__select-wrapper">
				<label class="cn-form-dialog__label">
					{{ t('openconnector', 'Mapping') }} *
				</label>
				<NcSelect
					:options="mappingSelectOptions"
					:value="resolvedMappingOption(value)"
					:loading="mappingsLoading"
					:clearable="false"
					@input="opt => updateField('filepartUploadMappingId', opt ? opt.id : null)" />
			</div>
		</template>
	</CnFormDialog>
</template>

<script>
import { NcSelect, NcTextField, NcTextArea, NcButton, NcNoteCard } from '@nextcloud/vue'
import { CnFormDialog, CnJsonViewer } from '@conduction/nextcloud-vue'
import { VueDraggable } from 'vue-draggable-plus'
import Drag from 'vue-material-design-icons/Drag.vue'
import Close from 'vue-material-design-icons/Close.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import CloudDownload from 'vue-material-design-icons/CloudDownload.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import openLink from '../../services/openLink.js'

const FETCH_FILE_METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH']

const TYPES_REQUIRING_OPENREGISTER = new Set(['fileparts_create', 'filepart_upload'])

export default {
	name: 'EditRule',
	components: {
		CnFormDialog,
		CnJsonViewer,
		NcSelect,
		NcTextField,
		NcTextArea,
		NcButton,
		NcNoteCard,
		VueDraggable,
		Drag,
		Close,
		OpenInNew,
		CloudDownload,
		FileTreeOutline,
		TrashCanOutline,
	},
	data() {
		return {
			selectedType: 'error',

			// Async-loaded option lists
			mappingsLoading: false,
			mappingSelectOptions: [],
			synchronizationsLoading: false,
			synchronizationSelectOptions: [],
			sourcesLoading: false,
			sourceSelectOptions: [],
			schemasLoading: false,
			schemaSelectOptions: [],
			usersList: [],
			groupsList: [],
			apiKeyUsers: [],

			// Local state for fields that don't fit cleanly into formData
			authTypeLocal: 'basic',
			authUsers: [],
			authGroups: [],
			apiKeys: [{ apiKey: '', user: null }],
			extendInputItems: [{ property: '', extends: [] }],
			extendExternalItems: [{ property: '', schema: '' }],

			openRegister: {
				isInstalled: true,
				isAvailable: true,
			},
			closeAlert: false,

			conditionsDraft: '{}',
			conditionsError: null,

			// Synthetic "row meta" values rendered via #field-_rowMeta slot.
			// Not in formData — merged in onConfirm. Same pattern as EditJob's extraFlags.
			meta: {
				action: 'post',
				type: 'error',
			},
		}
	},
	computed: {
		ruleStore() {
			return ruleStore
		},
		navigationStore() {
			return navigationStore
		},
		schemaForDialog() {
			return ruleSchema()
		},
		typeSelectOptions() {
			return RULE_TYPE_KEYS.map(id => ({ id, label: ruleTypeLabel(id) }))
		},
		actionSelectOptions() {
			return [
				{ id: 'post', label: t('openconnector', 'Post (Create)') },
				{ id: 'get', label: t('openconnector', 'Get (Read)') },
				{ id: 'put', label: t('openconnector', 'Put (Update)') },
				{ id: 'delete', label: t('openconnector', 'Delete (Delete)') },
			]
		},
		authTypeOptions() {
			return [
				{ label: t('openconnector', 'Basic Authentication'), value: 'basic' },
				{ label: t('openconnector', 'JWT'), value: 'jwt' },
				{ label: t('openconnector', 'JWT-ZGW'), value: 'jwt-zgw' },
				{ label: t('openconnector', 'OAuth'), value: 'oauth' },
				{ label: t('openconnector', 'Api-key'), value: 'api-key' },
			]
		},
		initialItem() {
			const item = ruleStore.item
			if (!item) {
				return {
					name: '',
					description: '',
					type: 'error',
					action: 'post',
					timing: 'before',
					order: 0,
					conditions: {},
				}
			}
			const cfg = item.configuration || {}
			const base = {
				...item,
				name: item.name || '',
				description: item.description || '',
				type: item.type || 'error',
				action: item.action || 'post',
				timing: item.timing || 'before',
				order: item.order ?? 0,
				conditions: item.conditions || {},
			}
			return { ...base, ...this.flattenConfig(item.type, cfg) }
		},
		formFields() {
			const labels = {
				name: t('openconnector', 'Name'),
				description: t('openconnector', 'Description'),
				type: t('openconnector', 'Type'),
				action: t('openconnector', 'Action'),
				timing: t('openconnector', 'Timing'),
				order: t('openconnector', 'Order'),
				conditions: t('openconnector', 'Conditions (JSON Logic)'),
			}
			const base = [
				{ key: 'name', label: labels.name, widget: 'text', required: true, validation: { maxLength: 255 } },
				{ key: 'description', label: labels.description, widget: 'textarea' },
				{ key: 'conditions', label: labels.conditions, widget: 'json', default: {} },
				{
					key: 'timing',
					label: labels.timing,
					widget: 'select',
					enum: ['before', 'after'],
					enumLabels: {
						before: t('openconnector', 'Before'),
						after: t('openconnector', 'After'),
					},
					required: true,
				},
				{ key: 'order', label: labels.order, widget: 'number', default: 0 },
				// Synthetic field — rendered via #field-_rowMeta slot as a 2-col grid
				// hosting action + type on one horizontal row.
				{ key: '_rowMeta', label: '', widget: 'custom' },
			]
			return [...base, ...this.typeSpecificFields(this.selectedType)]
		},
	},
	watch: {
		'navigationStore.modal': {
			immediate: true,
			handler(modal) {
				if (modal === 'editRule') this.onOpen()
			},
		},
		apiKeys: {
			deep: true,
			handler(newVal) {
				if (!newVal || !newVal.length) return
				if (newVal[newVal.length - 1]?.apiKey !== '') {
					this.apiKeys.push({ apiKey: '', user: null })
				}
				if (newVal.length > 1) {
					for (let i = newVal.length - 2; i >= 0; i--) {
						if (!newVal[i].apiKey || newVal[i].apiKey.trim() === '') {
							this.apiKeys.splice(i, 1)
						}
					}
				}
			},
		},
		extendInputItems: {
			deep: true,
			handler(newVal) {
				if (!newVal || !newVal.length) return
				const last = newVal[newVal.length - 1]
				if (last.property && last.property.trim() !== '') {
					this.extendInputItems.push({ property: '', extends: [] })
				}
				if (newVal.length > 1) {
					for (let i = newVal.length - 2; i >= 0; i--) {
						if (!newVal[i].property || newVal[i].property.trim() === '') {
							this.extendInputItems.splice(i, 1)
						}
					}
				}
			},
		},
		extendExternalItems: {
			deep: true,
			handler(newVal) {
				if (!newVal || !newVal.length) return
				const last = newVal[newVal.length - 1]
				if (last.property?.trim() && last.schema?.trim()) {
					this.extendExternalItems.push({ property: '', schema: '' })
				}
				if (newVal.length > 1) {
					for (let i = newVal.length - 2; i >= 0; i--) {
						const it = newVal[i]
						if ((!it.property || !it.property.trim()) && (!it.schema || !it.schema.trim())) {
							this.extendExternalItems.splice(i, 1)
						}
					}
				}
			},
		},
	},
	methods: {
		openLink,
		onOpen() {
			const item = ruleStore.item
			const cfg = item?.configuration || {}

			this.selectedType = item?.type || 'error'
			this.meta = {
				action: item?.action || 'post',
				type: item?.type || 'error',
			}
			this.closeAlert = false
			this.conditionsDraft = JSON.stringify(item?.conditions || {}, null, 2)
			this.conditionsError = null

			this.authTypeLocal = cfg.authentication?.type || 'basic'
			this.apiKeys = [{ apiKey: '', user: null }]
			this.authUsers = []
			this.authGroups = []
			this.extendInputItems = [{ property: '', extends: [] }]
			this.extendExternalItems = [{ property: '', schema: '' }]

			Promise.all([
				this.loadMappings(),
				this.loadSynchronizations(),
				this.loadSources(),
				this.loadSchemas(),
				this.loadUsers(),
				this.loadGroups(),
			]).catch(err => console.error('Failed to load rule edit data:', err))
		},
		typeSpecificFields(type) {
			switch (type) {
			case 'error':
				return [
					{ key: 'errorCode', label: t('openconnector', 'Error code'), widget: 'number', default: 500 },
					{ key: 'errorTitle', label: t('openconnector', 'Error title'), widget: 'text' },
					{ key: 'errorMessage', label: t('openconnector', 'Error message'), widget: 'textarea' },
					{ key: 'errorIncludeJsonLogicResult', label: t('openconnector', 'Include JSON Logic results in errors array'), widget: 'checkbox', default: false },
				]
			case 'mapping':
				return [
					{ key: 'mappingId', label: t('openconnector', 'Mapping'), widget: 'custom', required: true },
				]
			case 'synchronization':
				return [
					{ key: 'synchronizationId', label: t('openconnector', 'Synchronization'), widget: 'custom', required: true },
					{ key: 'synchronizationRetainResponse', label: t('openconnector', 'Retain original response'), widget: 'checkbox', default: false },
				]
			case 'javascript':
				return [
					{ key: 'javascriptCode', label: t('openconnector', 'JavaScript code'), widget: 'code', language: 'javascript' },
				]
			case 'authentication':
				return [
					{ key: 'authType', label: t('openconnector', 'Authentication type'), widget: 'custom', required: true },
					{ key: 'authUsersGroups', label: '', widget: 'custom' },
				]
			case 'download':
				return [
					{ key: 'downloadFileIdPosition', label: t('openconnector', 'File ID position'), widget: 'number', default: 0 },
				]
			case 'upload':
				return [
					{ key: 'uploadPath', label: t('openconnector', 'Upload path'), widget: 'text' },
					{ key: 'uploadAllowedTypes', label: t('openconnector', 'Allowed file types'), widget: 'text', description: t('openconnector', 'Comma-separated list, for example: jpg,png,pdf') },
					{ key: 'uploadMaxSize', label: t('openconnector', 'Max file size (MB)'), widget: 'number', default: 10 },
				]
			case 'locking':
				return [
					{
						key: 'lockingAction',
						label: t('openconnector', 'Lock action'),
						widget: 'select',
						enum: ['lock', 'unlock'],
						enumLabels: {
							lock: t('openconnector', 'Lock resource'),
							unlock: t('openconnector', 'Unlock resource'),
						},
						default: 'lock',
					},
					{ key: 'lockingTimeout', label: t('openconnector', 'Lock timeout (minutes)'), widget: 'number', default: 30 },
				]
			case 'fetch_file':
				return [
					{ key: 'fetchFileSourceId', label: t('openconnector', 'Source'), widget: 'custom', required: true },
					{ key: 'fetchFileMethod', label: t('openconnector', 'Method'), widget: 'select', enum: FETCH_FILE_METHODS },
					{ key: 'fetchFileTags', label: t('openconnector', 'Tags'), widget: 'tags' },
					{ key: 'fetchFileFilePath', label: t('openconnector', 'File path'), widget: 'text' },
					{ key: 'fetchFileSubObjectFilepath', label: t('openconnector', 'Sub-object file path (optional)'), widget: 'text' },
					{ key: 'fetchFileObjectIdPath', label: t('openconnector', 'Object ID path (optional)'), widget: 'text' },
					{ key: 'fetchFileAutoShare', label: t('openconnector', 'Auto share'), widget: 'checkbox', default: false },
					{ key: 'fetchFileSourceConfiguration', label: t('openconnector', 'Source configuration (JSON)'), widget: 'code', language: 'json' },
					{ key: 'fetchFileOriginIdPath', label: t('openconnector', 'Origin ID path (optional)'), widget: 'text' },
					{ key: 'fetchFileContentPath', label: t('openconnector', 'Content path (optional)'), widget: 'text' },
					{ key: 'fetchFileFilenamePath', label: t('openconnector', 'Filename path (optional)'), widget: 'text' },
					{ key: 'fetchFileFileExtension', label: t('openconnector', 'File extension (optional)'), widget: 'text' },
					{ key: 'fetchFileEndpoint', label: t('openconnector', 'Endpoint (optional)'), widget: 'text' },
				]
			case 'write_file':
				return [
					{ key: 'writeFileFilePath', label: t('openconnector', 'File path'), widget: 'text', required: true },
					{ key: 'writeFileFileNamePath', label: t('openconnector', 'File name path'), widget: 'text', required: true },
					{ key: 'writeFileTags', label: t('openconnector', 'Tags'), widget: 'tags' },
					{ key: 'writeFileAutoShare', label: t('openconnector', 'Auto share'), widget: 'checkbox', default: false },
				]
			case 'fileparts_create':
				return [
					{ key: 'filepartsCreateSizeLocation', label: t('openconnector', 'Size location'), widget: 'text', required: true },
					{ key: 'filepartsCreateSchemaId', label: t('openconnector', 'Schema'), widget: 'custom', required: true },
					{ key: 'filepartsCreateFilenameLocation', label: t('openconnector', 'Filename location'), widget: 'text' },
					{ key: 'filepartsCreateFilePartLocation', label: t('openconnector', 'Filepart location'), widget: 'text' },
					{ key: 'filepartsCreateMappingId', label: t('openconnector', 'Mapping'), widget: 'custom' },
				]
			case 'filepart_upload':
				return [
					{ key: 'filepartUploadMappingId', label: t('openconnector', 'Mapping'), widget: 'custom', required: true },
				]
			case 'save_object':
				return [
					{ key: 'saveObjectRegister', label: t('openconnector', 'Register'), widget: 'text', required: true },
					{ key: 'saveObjectSchema', label: t('openconnector', 'Schema'), widget: 'text', required: true },
					{ key: 'mappingId', label: t('openconnector', 'Mapping'), widget: 'custom' },
				]
			case 'extend_input':
				return [
					{ key: 'extendInputItems', label: '', widget: 'custom' },
				]
			case 'extend_external_input':
				return [
					{ key: 'extendExternalValidate', label: t('openconnector', 'Validate fetched object with schema'), widget: 'checkbox', default: true },
					{ key: 'extendExternalInputItems', label: '', widget: 'custom' },
				]
			default:
				return []
			}
		},
		flattenConfig(type, cfg) {
			switch (type) {
			case 'error':
				return {
					errorCode: cfg.error?.code ?? 500,
					errorTitle: cfg.error?.name ?? '',
					errorMessage: cfg.error?.message ?? '',
					errorIncludeJsonLogicResult: cfg.error?.includeJsonLogicResult ?? false,
				}
			case 'mapping':
				return { mappingId: cfg.mapping ?? null }
			case 'synchronization':
				return {
					synchronizationId: cfg.synchronization?.synchronization ?? null,
					synchronizationRetainResponse: cfg.synchronization?.retainResponse ?? false,
				}
			case 'javascript':
				return { javascriptCode: cfg.javascript ?? '' }
			case 'authentication':
				return { authType: cfg.authentication?.type ?? 'basic' }
			case 'download':
				return { downloadFileIdPosition: cfg.download?.fileIdPosition ?? 0 }
			case 'upload':
				return {
					uploadPath: cfg.upload?.path ?? '',
					uploadAllowedTypes: cfg.upload?.allowedTypes ?? '',
					uploadMaxSize: cfg.upload?.maxSize ?? 10,
				}
			case 'locking':
				return {
					lockingAction: cfg.locking?.action ?? 'lock',
					lockingTimeout: cfg.locking?.timeout ?? 30,
				}
			case 'fetch_file':
				return {
					fetchFileSourceId: cfg.fetch_file?.source ?? null,
					fetchFileMethod: cfg.fetch_file?.method ?? '',
					fetchFileTags: cfg.fetch_file?.tags ?? [],
					fetchFileFilePath: cfg.fetch_file?.filePath ?? '',
					fetchFileSubObjectFilepath: cfg.fetch_file?.subObjectFilepath ?? '',
					fetchFileObjectIdPath: cfg.fetch_file?.objectIdPath ?? '',
					fetchFileAutoShare: cfg.fetch_file?.autoShare ?? false,
					fetchFileSourceConfiguration: cfg.fetch_file?.sourceConfiguration
						? (typeof cfg.fetch_file.sourceConfiguration === 'string'
							? cfg.fetch_file.sourceConfiguration
							: JSON.stringify(cfg.fetch_file.sourceConfiguration, null, 2))
						: '[]',
					fetchFileOriginIdPath: cfg.fetch_file?.originIdPath ?? '',
					fetchFileContentPath: cfg.fetch_file?.contentPath ?? '',
					fetchFileFilenamePath: cfg.fetch_file?.filenamePath ?? '',
					fetchFileFileExtension: cfg.fetch_file?.fileExtension ?? '',
					fetchFileEndpoint: cfg.fetch_file?.endpoint ?? '',
				}
			case 'write_file':
				return {
					writeFileFilePath: cfg.write_file?.filePath ?? '',
					writeFileFileNamePath: cfg.write_file?.fileNamePath ?? '',
					writeFileTags: cfg.write_file?.tags ?? [],
					writeFileAutoShare: cfg.write_file?.autoShare ?? false,
				}
			case 'fileparts_create':
				return {
					filepartsCreateSizeLocation: cfg.fileparts_create?.sizeLocation ?? '',
					filepartsCreateSchemaId: cfg.fileparts_create?.schemaId ?? null,
					filepartsCreateFilenameLocation: cfg.fileparts_create?.filenameLocation ?? '',
					filepartsCreateFilePartLocation: cfg.fileparts_create?.filePartLocation ?? '',
					filepartsCreateMappingId: cfg.fileparts_create?.mappingId ?? null,
				}
			case 'filepart_upload':
				return { filepartUploadMappingId: cfg.filepart_upload?.mappingId ?? null }
			case 'save_object':
				return {
					saveObjectRegister: cfg.save_object?.register ?? '',
					saveObjectSchema: cfg.save_object?.schema ?? '',
					mappingId: cfg.save_object?.mapping ?? null,
				}
			case 'extend_input':
				return {}
			case 'extend_external_input':
				return { extendExternalValidate: cfg.extend_external_input?.validate ?? true }
			default:
				return {}
			}
		},
		packConfig(type, fd) {
			switch (type) {
			case 'error':
				return {
					error: {
						code: Number(fd.errorCode) || 500,
						name: fd.errorTitle || '',
						message: fd.errorMessage || '',
						includeJsonLogicResult: !!fd.errorIncludeJsonLogicResult,
					},
				}
			case 'mapping':
				return { mapping: fd.mappingId ?? null }
			case 'synchronization':
				return {
					synchronization: {
						synchronization: fd.synchronizationId ?? null,
						retainResponse: !!fd.synchronizationRetainResponse,
					},
				}
			case 'javascript':
				return { javascript: fd.javascriptCode || '' }
			case 'authentication':
				return {
					authentication: {
						type: this.authTypeLocal,
						users: this.authUsers.map(u => u.id),
						groups: this.authGroups.map(g => g.value ?? g.id),
						keys: this.apiKeys
							.filter(k => k.apiKey && k.user?.id)
							.map(k => ({ [k.apiKey]: k.user.id })),
					},
				}
			case 'download':
				return { download: { fileIdPosition: Number(fd.downloadFileIdPosition) || 0 } }
			case 'upload':
				return {
					upload: {
						path: fd.uploadPath || '',
						allowedTypes: fd.uploadAllowedTypes || '',
						maxSize: Number(fd.uploadMaxSize) || 10,
					},
				}
			case 'locking':
				return {
					locking: {
						action: fd.lockingAction || 'lock',
						timeout: Number(fd.lockingTimeout) || 30,
					},
				}
			case 'fetch_file': {
				let sourceConfiguration = []
				try {
					sourceConfiguration = fd.fetchFileSourceConfiguration ? JSON.parse(fd.fetchFileSourceConfiguration) : []
				} catch (e) {
					sourceConfiguration = []
				}
				return {
					fetch_file: {
						source: fd.fetchFileSourceId,
						method: fd.fetchFileMethod || '',
						tags: fd.fetchFileTags || [],
						filePath: fd.fetchFileFilePath || '',
						subObjectFilepath: fd.fetchFileSubObjectFilepath || '',
						objectIdPath: fd.fetchFileObjectIdPath || '',
						autoShare: !!fd.fetchFileAutoShare,
						sourceConfiguration,
						originIdPath: fd.fetchFileOriginIdPath || '',
						contentPath: fd.fetchFileContentPath || '',
						filenamePath: fd.fetchFileFilenamePath || '',
						fileExtension: fd.fetchFileFileExtension || '',
						endpoint: fd.fetchFileEndpoint || '',
					},
				}
			}
			case 'write_file':
				return {
					write_file: {
						filePath: fd.writeFileFilePath || '',
						fileNamePath: fd.writeFileFileNamePath || '',
						tags: fd.writeFileTags || [],
						autoShare: !!fd.writeFileAutoShare,
					},
				}
			case 'fileparts_create':
				return {
					fileparts_create: {
						sizeLocation: fd.filepartsCreateSizeLocation || '',
						schemaId: fd.filepartsCreateSchemaId ?? null,
						filenameLocation: fd.filepartsCreateFilenameLocation || '',
						filePartLocation: fd.filepartsCreateFilePartLocation || '',
						mappingId: fd.filepartsCreateMappingId ?? null,
					},
				}
			case 'filepart_upload':
				return { filepart_upload: { mappingId: fd.filepartUploadMappingId ?? null } }
			case 'save_object':
				return {
					save_object: {
						register: fd.saveObjectRegister || '',
						schema: fd.saveObjectSchema || '',
						mapping: fd.mappingId ?? null,
					},
				}
			case 'extend_input':
				return {
					extend_input: {
						properties: this.extendInputItems
							.filter(i => i.property && i.property.trim())
							.map(i => i.property),
						extends: this.extendInputItems
							.filter(i => i.property && i.property.trim())
							.reduce((acc, i) => {
								acc[i.property] = i.extends || []
								return acc
							}, {}),
					},
				}
			case 'extend_external_input':
				return {
					extend_external_input: {
						validate: fd.extendExternalValidate ?? true,
						properties: this.extendExternalItems
							.filter(p => p.property?.trim() && p.schema?.trim())
							.map(p => ({ property: p.property, schema: p.schema })),
					},
				}
			default:
				return {}
			}
		},
		resolvedTypeOption(value) {
			return this.typeSelectOptions.find(o => o.id === value) || this.typeSelectOptions[0]
		},
		isTypeSelectable(option) {
			if (TYPES_REQUIRING_OPENREGISTER.has(option.id)) return this.openRegister.isInstalled
			return true
		},
		onTypeChange(opt) {
			const id = opt?.id || 'error'
			this.selectedType = id
			this.meta.type = id
		},
		resolvedAuthTypeOption(value) {
			return this.authTypeOptions.find(o => o.value === value) || this.authTypeOptions[0]
		},
		onAuthTypeChange(opt, updateField) {
			const v = opt?.value || 'basic'
			this.authTypeLocal = v
			updateField('authType', v)
		},
		resolvedMappingOption(value) {
			if (value == null) return null
			return this.mappingSelectOptions.find(o => String(o.id) === String(value)) || null
		},
		resolvedSynchronizationOption(value) {
			if (value == null) return null
			return this.synchronizationSelectOptions.find(o => String(o.id) === String(value)) || null
		},
		resolvedSourceOption(value) {
			if (value == null) return null
			return this.sourceSelectOptions.find(o => String(o.id) === String(value)) || null
		},
		resolvedSchemaOption(value) {
			if (value == null) return null
			return this.schemaSelectOptions.find(o => String(o.id) === String(value)) || null
		},
		async loadMappings() {
			this.mappingsLoading = true
			try {
				await mappingStore.refreshList()
				this.mappingSelectOptions = (mappingStore.list || []).map(m => ({ id: m.id, label: m.name }))
			} catch (e) {
				console.error('Failed to fetch mappings:', e)
			} finally {
				this.mappingsLoading = false
			}
		},
		async loadSynchronizations() {
			this.synchronizationsLoading = true
			try {
				await synchronizationStore.refreshSynchronizationList()
				this.synchronizationSelectOptions = (synchronizationStore.synchronizationList || []).map(s => ({ id: s.id, label: s.name }))
			} catch (e) {
				console.error('Failed to fetch synchronizations:', e)
			} finally {
				this.synchronizationsLoading = false
			}
		},
		async loadSources() {
			this.sourcesLoading = true
			try {
				await sourceStore.refreshList()
				this.sourceSelectOptions = (sourceStore.list || []).map(s => ({ id: s.id, label: s.name }))
			} catch (e) {
				console.error('Failed to fetch sources:', e)
			} finally {
				this.sourcesLoading = false
			}
		},
		async loadSchemas() {
			this.schemasLoading = true
			try {
				const response = await fetch('/index.php/apps/openregister/api/schemas', {
					headers: { accept: '*/*', 'x-requested-with': 'XMLHttpRequest' },
					method: 'GET',
					credentials: 'include',
				})
				if (!response.ok) {
					this.openRegister.isInstalled = false
					return
				}
				const data = await response.json()
				this.schemaSelectOptions = (data.results || []).map(s => ({
					id: s.id,
					label: s.title,
					fullSchema: s,
				}))
			} catch (e) {
				this.openRegister.isInstalled = false
			} finally {
				this.schemasLoading = false
			}
		},
		async loadUsers() {
			try {
				const response = await fetch('/ocs/v1.php/cloud/users/details', {
					method: 'GET',
					headers: { Accept: 'application/json', 'OCS-APIRequest': 'true' },
				})
				if (!response.ok) return
				const data = await response.json()
				const userObjs = Object.values(data.ocs.data.users)
				this.usersList = userObjs.map(u => ({
					id: u.id,
					displayName: u.displayname,
					subname: u.email,
					user: u.id,
				}))
				this.apiKeyUsers = this.usersList.map(u => ({ ...u, name: u.displayName }))
				const item = ruleStore.item
				const auth = item?.configuration?.authentication
				if (auth) {
					this.authUsers = this.usersList.filter(u => (auth.users || []).includes(u.id))
					if (auth.keys?.length) {
						this.apiKeys = auth.keys.map(entry => {
							const [apiKey, userId] = Object.entries(entry)[0] || ['', '']
							const u = this.usersList.find(x => x.id === userId)
							return { apiKey, user: u ? { ...u, name: u.displayName } : null }
						})
						if (this.apiKeys[this.apiKeys.length - 1]?.apiKey !== '') {
							this.apiKeys.push({ apiKey: '', user: null })
						}
					}
				}
			} catch (e) {
				console.error('Failed to fetch users:', e)
			}
		},
		async loadGroups() {
			try {
				const response = await fetch('/ocs/v1.php/cloud/groups/details', {
					method: 'GET',
					headers: { Accept: 'application/json', 'OCS-APIRequest': 'true' },
				})
				if (!response.ok) return
				const data = await response.json()
				this.groupsList = data.ocs.data.groups.map(g => ({
					label: g.displayname,
					value: g.id,
				}))
				const item = ruleStore.item
				const auth = item?.configuration?.authentication
				if (auth) {
					this.authGroups = this.groupsList.filter(g => (auth.groups || []).includes(g.value))
				}
				const item2 = ruleStore.item
				const cfg = item2?.configuration || {}
				if (item2?.type === 'extend_input' && cfg.extend_input) {
					const props = cfg.extend_input.properties || []
					const ext = cfg.extend_input.extends || {}
					const items = props.map(p => ({ property: p, extends: ext[p] || [] }))
					if (!items.length || items[items.length - 1].property) items.push({ property: '', extends: [] })
					this.extendInputItems = items
				}
				if (item2?.type === 'extend_external_input' && cfg.extend_external_input) {
					const props = cfg.extend_external_input.properties || []
					const items = props.map(p => ({ property: p.property, schema: p.schema }))
					if (!items.length || (items[items.length - 1].property && items[items.length - 1].schema)) {
						items.push({ property: '', schema: '' })
					}
					this.extendExternalItems = items
				}
			} catch (e) {
				console.error('Failed to fetch groups:', e)
			}
		},
		async installOpenRegister() {
			try {
				const token = document.querySelector('head[data-requesttoken]').getAttribute('data-requesttoken')
				const response = await fetch('/index.php/settings/apps/enable', {
					headers: {
						accept: '*/*',
						'content-type': 'application/json',
						requesttoken: token,
						'x-requested-with': 'XMLHttpRequest',
					},
					body: '{"appIds":["openregister"],"groups":[]}',
					method: 'POST',
					credentials: 'include',
				})
				if (!response.ok) {
					this.openRegister.isAvailable = false
				} else {
					this.openRegister.isInstalled = true
					await this.loadSchemas()
				}
			} catch (e) {
				this.openRegister.isAvailable = false
			}
		},
		addExtendInputItem() {
			this.extendInputItems.push({ property: '', extends: [] })
		},
		removeExtendInputItem(index) {
			if (index === 0) return
			this.extendInputItems.splice(index, 1)
		},
		addExtendExternalItem() {
			this.extendExternalItems.push({ property: '', schema: '' })
		},
		removeExtendExternalItem(index) {
			if (index === 0) return
			this.extendExternalItems.splice(index, 1)
		},
		closeModal() {
			navigationStore.setModal(false)
		},
		onConditionsInput(value, updateField) {
			this.conditionsDraft = value
			const trimmed = (value || '').trim()
			if (!trimmed) {
				this.conditionsError = null
				updateField('conditions', {})
				return
			}
			try {
				const parsed = JSON.parse(trimmed)
				this.conditionsError = null
				updateField('conditions', parsed)
			} catch (e) {
				this.conditionsError = t('openconnector', 'Invalid JSON: {msg}', { msg: e.message })
			}
		},
		async onConfirm(formData) {
			if (this.conditionsError) {
				this.$refs.formDialog.setResult({ error: this.conditionsError })
				return
			}
			try {
				// Drop the synthetic placeholder; action/type live in `meta`.
				const { _rowMeta, ...payload } = formData
				const type = this.meta.type || 'error'
				const configuration = this.packConfig(type, payload)
				const rule = new Rule({
					...(ruleStore.item || {}),
					name: payload.name || '',
					description: payload.description || '',
					type,
					action: this.meta.action || 'post',
					timing: payload.timing || 'before',
					order: Number(payload.order) || 0,
					conditions: payload.conditions || {},
					configuration,
				})
				await ruleStore.save(rule)
				this.$refs.formDialog.setResult({ success: true })
			} catch (e) {
				this.$refs.formDialog.setResult({
					error: e.message || t('openconnector', 'An error occurred while saving the rule'),
				})
			}
		},
	},
}
</script>

<style scoped>
.install-buttons {
	display: flex;
	gap: 10px;
	margin-top: 10px;
	align-items: center;
}

.ruleMetaRow {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 12px;
	align-items: start;
}

.cn-form-dialog__select-wrapper {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.cn-form-dialog__label {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-main-text);
}

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
	align-items: stretch;
}

.schema-option {
	display: flex;
	align-items: center;
	gap: 10px;
}

.schema-option > h6 {
	line-height: 0.8;
}

.draggable-form-item {
	display: flex;
	align-items: center;
	gap: 3px;
	background-color: rgba(0, 0, 0, 0.05);
	padding: 4px;
	border-radius: 12px;
	margin-block: 8px;
}

.draggable-form-item :deep(.v-select) {
	min-width: 150px;
}

.apiKeyTextArea {
	flex: 1 0 0;
}

.apiKeyUserSelect {
	width: 45%;
	margin-left: 10px;
	margin-right: 8px;
}

.draggable-item-container:last-child .drag-handle {
	cursor: not-allowed;
}
</style>

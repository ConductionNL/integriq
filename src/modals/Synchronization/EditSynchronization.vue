<script setup>
import { synchronizationStore, navigationStore, sourceStore, mappingStore, ruleStore } from '../../store/store.js'
import { Synchronization } from '../../entities/index.js'
</script>

<template>
	<NcModal ref="modalRef"
		label-id="editSynchronization"
		size="large"
		:can-close="true"
		:width="1200"
		:name="synchronizationItem.id ? 'Edit Synchronization' : 'Create New Synchronization'"
		@close="closeModal">
		<div class="modalContent">
			<!-- ====================== -->
			<!-- Open Register notecard -->
			<!-- ====================== -->
			<div v-if="!openRegisterInstalled && !openRegisterCloseAlert" class="openregister-notecard">
				<NcNoteCard
					:type="openRegisterIsAvailable ? 'info' : 'error'"
					:heading="openRegisterIsAvailable ? 'Open Register is not installed' : 'Failed to install Open Register'">
					<p>
						{{ openRegisterIsAvailable
							? 'Some features require Open Register to be installed'
							: 'This either means that Open Register is not available on this server or you need to confirm your password' }}
					</p>

					<div class="install-buttons">
						<NcButton v-if="openRegisterIsAvailable"
							aria-label="Install OpenRegister"
							size="small"
							type="primary"
							:loading="openRegisterLoading"
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
							<NcActionButton close-after-click @click="openRegisterCloseAlert = true">
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
			<!-- Success/Error notecard -->
			<!-- ====================== -->
			<div v-if="success || error">
				<NcNoteCard v-if="success" type="success">
					<p>Synchronization successfully {{ synchronizationItem.id ? 'updated' : 'created' }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="error" type="error">
					<p>{{ error || 'An error occurred' }}</p>
				</NcNoteCard>
			</div>

			<!-- ====================== -->
			<!--    Three-Column Layout  -->
			<!-- ====================== -->
			<div v-if="!success" class="synchronization-layout">
				<!-- Source Section -->
				<div class="sync-section source-section">
					<div class="section-header">
						<DatabaseArrowRightOutline :size="24" />
						<h3>Source</h3>
					</div>
					<div class="section-content">
						<div class="info-card">
							<p class="section-description">
								Configure where data comes from
							</p>
						</div>

						<!-- Source Type -->
						<div class="form-group">
							<NcSelect v-bind="typeOptions"
								v-model="typeOptions.value"
								:selectable="(option) => {
									return option.id === 'register/schema' ? openRegisterInstalled : true
								}"
								input-label="Source Type" />
						</div>

						<!-- Source ID -->
						<div class="form-group">
							<NcSelect v-if="typeOptions.value?.id !== 'register/schema'"
								v-bind="sourceOptions"
								v-model="sourceOptions.sourceValue"
								required
								:loading="sourcesLoading"
								input-label="Source ID" />

							<div v-if="typeOptions.value?.id === 'register/schema'">
								<NcSelect v-bind="registerOptions"
									v-model="registerOptions.sourceValue"
									:disabled="!openRegisterInstalled"
									input-label="Register" />

								<NcSelect v-bind="selectedRegisterSourceValueSchemas"
									v-model="schemaOptions.sourceValue"
									:disabled="!openRegisterInstalled"
									input-label="Schema" />
							</div>
						</div>

						<!-- Source Configuration -->
						<div class="subsection">
							<h4>Source Configuration</h4>
							<div class="form-group">
								<NcTextField :value.sync="synchronizationItem.sourceConfig.idPosition"
									label="ID Position"
									placeholder="Position of id in source object" />

								<NcTextField :value.sync="synchronizationItem.sourceConfig.resultsPosition"
									label="Results Position"
									placeholder="Position of results in source object" />

								<NcTextField :value.sync="synchronizationItem.sourceConfig.endpoint"
									label="Endpoint"
									placeholder="Endpoint on which to fetch data" />

								<NcTextField :value.sync="synchronizationItem.sourceHashPosition"
									label="Source Hash Position"
									placeholder="Position of hash in source object" />
							</div>
						</div>
					</div>
				</div>

				<!-- General/Center Section -->
				<div class="sync-section general-section">
					<!-- General Card -->
					<div class="center-card">
						<div class="section-header">
							<CogOutline :size="24" />
							<h3>General</h3>
						</div>
						<div class="section-content">
							<form @submit.prevent="handleSubmit">
								<div class="form-group">
									<NcTextField :value.sync="synchronizationItem.name"
										label="Name"
										placeholder="Enter synchronization name"
										required />

									<NcTextArea
										resize="vertical"
										:value.sync="synchronizationItem.description"
										label="Description"
										placeholder="Describe what this synchronization does" />
								</div>
							</form>
						</div>
					</div>

					<!-- Data Flow Arrow -->
					<div class="data-flow">
						<div class="flow-step">
							<DatabaseArrowRightOutline :size="20" />
							<span>Source</span>
						</div>
						<ArrowRight :size="20" class="flow-arrow" />
						<div class="flow-step">
							<SwapHorizontal :size="20" />
							<span>Transform</span>
						</div>
						<ArrowRight :size="20" class="flow-arrow" />
						<div class="flow-step">
							<DatabaseArrowLeftOutline :size="20" />
							<span>Target</span>
						</div>
					</div>

					<!-- Transform Card -->
					<div class="center-card">
						<div class="section-header">
							<SwapHorizontal :size="24" />
							<h3>Transform</h3>
						</div>
						<div class="section-content">
							<div class="form-group">
								<NcTextArea
									resize="vertical"
									:value.sync="synchronizationItem.conditions"
									label="Conditions (JSON Logic)"
									placeholder="Enter JSON logic conditions" />

								<NcSelect v-bind="ruleOptions"
									v-model="ruleOptions.value"
									multiple
									:loading="rulesLoading"
									input-label="Rules" />
							</div>

							<!-- Mappings -->
							<div class="subsection">
								<h4>Mappings</h4>
								<div class="form-group">
									<NcSelect v-bind="sourceTargetMappingOptions"
										v-model="sourceTargetMappingOptions.sourceValue"
										:loading="sourceTargetMappingLoading"
										input-label="Source → Target Mapping" />

									<NcSelect v-bind="sourceTargetMappingOptions"
										v-model="sourceTargetMappingOptions.targetValue"
										:loading="sourceTargetMappingLoading"
										input-label="Target → Source Mapping" />

									<NcSelect v-bind="sourceTargetMappingOptions"
										v-model="sourceTargetMappingOptions.hashValue"
										:loading="sourceTargetMappingLoading"
										input-label="Source → Hash Mapping" />
								</div>
							</div>
						</div>
					</div>
				</div>

				<!-- Target Section -->
				<div class="sync-section target-section">
					<div class="section-header">
						<DatabaseArrowLeftOutline :size="24" />
						<h3>Target</h3>
					</div>
					<div class="section-content">
						<div class="info-card">
							<p class="section-description">
								Configure where data is written to
							</p>
						</div>

						<!-- Target Type -->
						<div class="form-group">
							<NcSelect v-bind="targetTypeOptions"
								v-model="targetTypeOptions.value"
								:selectable="(option) => {
									return option.id === 'register/schema' ? openRegisterInstalled : true
								}"
								input-label="Target Type" />
						</div>

						<!-- Target ID -->
						<div class="form-group">
							<NcSelect v-if="targetTypeOptions.value?.id === 'api'"
								v-bind="sourceOptions"
								v-model="sourceOptions.targetValue"
								:loading="sourcesLoading"
								input-label="Target ID" />

							<div v-if="targetTypeOptions.value?.id === 'register/schema'">
								<NcSelect v-bind="registerOptions"
									v-model="registerOptions.value"
									:disabled="!openRegisterInstalled"
									input-label="Register" />

								<NcSelect v-bind="selectedRegisterValueSchemas"
									v-model="schemaOptions.value"
									:disabled="!openRegisterInstalled"
									input-label="Schema" />
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Action Buttons -->
			<div v-if="!success" class="modal-actions">
				<NcButton v-if="!success"
					@click="closeModal">
					<template #icon>
						<CancelIcon size="20" />
					</template>
					Cancel
				</NcButton>
				<NcButton type="secondary" @click="testSynchronization">
					<template #icon>
						<PlayCircleOutline :size="20" />
					</template>
					Test
				</NcButton>
				<NcButton :disabled="loading
						|| !synchronizationItem.name
						|| (typeOptions.value?.id !== 'register/schema' && !sourceOptions.sourceValue?.id)
						// both register and schema need to be selected for register/schema target type
						|| (targetTypeOptions.value?.id === 'register/schema' && (!registerOptions.value?.id || !schemaOptions.value?.id))
						|| (typeOptions.value?.id === 'register/schema' && (!registerOptions.sourceValue?.id || !schemaOptions.sourceValue?.id))
						|| (targetTypeOptions.value?.id === 'api' && (!sourceOptions.targetValue))"
					type="primary"
					@click="editSynchronization()">
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
	NcActions,
	NcActionButton,
} from '@nextcloud/vue'
import openLink from '../../services/openLink.js'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import CloudDownload from 'vue-material-design-icons/CloudDownload.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Close from 'vue-material-design-icons/Close.vue'
import DatabaseArrowRightOutline from 'vue-material-design-icons/DatabaseArrowRightOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import DatabaseArrowLeftOutline from 'vue-material-design-icons/DatabaseArrowLeftOutline.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'

export default {
	name: 'EditSynchronization',
	components: {
		NcModal,
		NcButton,
		NcTextField,
		NcTextArea,
		NcSelect,
		NcLoadingIcon,
		NcNoteCard,
		NcActions,
		NcActionButton,
		DatabaseArrowRightOutline,
		CogOutline,
		DatabaseArrowLeftOutline,
		ArrowRight,
		SwapHorizontal,
		PlayCircleOutline,
		CancelIcon,
	},
	data() {
		return {
			/**
			 * Indicates if this is an edit modal or a create modal.
			 */
			IS_EDIT: !!synchronizationStore.synchronizationItem?.id,
			success: null, // Indicates if saving the synchronization was successful
			loading: false, // Indicates if saving the synchronization is in progress
			error: false,
			// synchronization item
			synchronizationItem: { // Initialize with empty fields
				name: '',
				description: '',
				conditions: '',
				sourceId: '',
				sourceType: '',
				sourceConfig: {
					idPosition: '',
					resultsPosition: '',
					endpoint: '',
					headers: {},
					query: {},
				},
				actions: [],
				sourceHashPosition: '',
				sourceHashMapping: '',
				sourceTargetMapping: '',
				targetId: '',
				targetType: 'register/schema',
				targetConfig: {},
				targetSourceMapping: '',
			},
			// ============================= //
			// source options
			// ============================= //
			typeOptions: {
				options: [
					{ label: 'Database', id: 'database' },
					{ label: 'API', id: 'api' },
					{ label: 'File', id: 'file' },
					{ label: 'Register/Schema', id: 'register/schema' },
				],
				value: { label: 'API', id: 'api' }, // Default source type
			},
			sourcesLoading: false, // Indicates if the sources are loading
			sourceOptions: { // This should be populated with available sources
				options: [],
				sourceValue: null,
				targetValue: null,
			},
			sourceTargetMappingLoading: false, // Indicates if the mappings are loading
			sourceTargetMappingOptions: { // A list of mappings
				options: [],
				hashValue: null,
				sourceValue: null,
				targetValue: null,
			},
			// ============================= //
			// target options
			// ============================= //
			targetTypeOptions: {
				options: [
					{ label: 'Register/Schema', id: 'register/schema' },
					{ label: 'API', id: 'api' },
					// { label: 'Database', id: 'database' },
				],
				value: { label: 'API', id: 'api' }, // Default target type
			},
			// registerOptions
			registerLoading: false, // Indicates if the registers are loading
			registerOptions: {
				options: [],
				value: null,
				sourceValue: null,
			},
			// schemaOptions
			schemaLoading: false, // Indicates if the schemas are loading
			schemaOptions: {
				options: [],
				value: null,
				sourceValue: null,
			},
			// ============================= //
			// rule options
			// ============================= //
			rulesLoading: false, // Indicates if the rules are loading
			ruleOptions: {
				options: [],
				value: null,
			},
			// ============================= //
			// OpenRegister
			// ============================= //
			openRegisterInstalled: true, // Indicates if OpenRegister is installed
			openRegisterLoading: true, // Indicates if installing OpenRegister is in progress
			openRegisterIsAvailable: true, // Indicates if OpenRegister is available
			openRegisterCloseAlert: false, // Indicates if the OpenRegister alert should be closed
			// ============================= //
			closeTimeoutFunc: null, // Function to close the modal after a timeout
		}
	},
	computed: {
		selectedRegisterSourceValueSchemas() {
			return this.registerOptions?.sourceValue?.schemas || []
		},
		selectedRegisterValueSchemas() {
			return this.registerOptions?.value?.schemas || []
		},
	},
	watch: {
		'registerOptions.value': {
			handler(newVal, oldVal) {
				// Only clear schema if register ID actually changed
				// Skip clearing during initialization (when oldVal is null in edit mode)
				if (oldVal === null && this.IS_EDIT) {
					return
				}
				if (oldVal?.id !== newVal?.id) {
					this.schemaOptions.value = null
				}
			},
			deep: true,
		},
		'registerOptions.sourceValue': {
			handler(newVal, oldVal) {
				// Only clear schema if register ID actually changed
				// Skip clearing during initialization (when oldVal is null in edit mode)
				if (oldVal === null && this.IS_EDIT) {
					return
				}
				if (oldVal?.id !== newVal?.id) {
					this.schemaOptions.sourceValue = null
				}
			},
			deep: true,
		},
	},
	mounted() {
		if (this.IS_EDIT) {
			// If there is a synchronization item in the store, use it
			this.synchronizationItem = {
				...synchronizationStore.synchronizationItem,
				conditions: JSON.stringify(synchronizationStore.synchronizationItem.conditions),
			}

			// update targetTypeOptions with the synchronization item target type
			this.targetTypeOptions.value = this.targetTypeOptions.options.find(option => option.id === this.synchronizationItem.targetType)
			this.typeOptions.value = this.typeOptions.options.find(option => option.id === this.synchronizationItem.sourceType)
		}

		// Fetch sources, mappings, register, and schema
		this.getSources()
		this.getSourceTargetMappings()
		this.getRegisterWithSchemas()
		this.getRules()
	},
	methods: {
		/**
		 * Fetches the list of available sources from the source store and updates the source options.
		 * Sets the loading state to true while fetching and updates the source options with the fetched data.
		 * If a source is already selected, it sets it as the active source.
		 * If the target type is 'api', it sets the active target source.
		 */
		getSources() {
			this.sourcesLoading = true

			sourceStore.refreshList()
				.then(() => {
					const entities = sourceStore.list
					const activeSourceSource = entities.find(source => source.id.toString() === this.synchronizationItem.sourceId.toString())

					let activeSourceTarget = null
					if (this.IS_EDIT && this.synchronizationItem.targetType === 'api') {
						activeSourceTarget = entities.find(source => source.id.toString() === this.synchronizationItem.targetId.toString())
					}

					this.sourceOptions = {
						options: entities.map(source => ({
							label: source.name,
							id: source.id,
						})),
						sourceValue: activeSourceSource
							? {
								label: activeSourceSource.name,
								id: activeSourceSource.id,
							}
							: null,
						targetValue: activeSourceTarget
							? {
								label: activeSourceTarget.name,
								id: activeSourceTarget.id,
							}
							: null,
					}
				})
				.finally(() => {
					this.sourcesLoading = false
				})
		},
		/**
		 * Fetches the list of source-target mappings from the mapping store and updates the mapping options.
		 * Sets the loading state to true while fetching and updates the mapping options with the fetched data.
		 * If a mapping is already selected, it sets it as the active source and target mapping.
		 */
		getSourceTargetMappings() {
			this.sourceTargetMappingLoading = true

			mappingStore.refreshList()
				.then(() => {
					const entities = mappingStore.list
					const activeSourceMapping = entities.find(mapping => mapping.id.toString() === this.synchronizationItem.sourceTargetMapping.toString())
					const activeTargetMapping = entities.find(mapping => mapping.id.toString() === this.synchronizationItem.targetSourceMapping.toString())
					const sourceHashMapping = entities.find(mapping => mapping.id.toString() === this.synchronizationItem.sourceHashMapping.toString())

					this.sourceTargetMappingOptions = {
						options: entities.map(mapping => ({
							label: mapping.name,
							id: mapping.id,
						})),
						hashValue: sourceHashMapping
							? {
								label: sourceHashMapping.name,
								id: sourceHashMapping.id,
							}
							: null,
						sourceValue: activeSourceMapping
							? {
								label: activeSourceMapping.name,
								id: activeSourceMapping.id,
							}
							: null,
						targetValue: activeTargetMapping
							? {
								label: activeTargetMapping.name,
								id: activeTargetMapping.id,
							}
							: null,
					}
				})
				.finally(() => {
					this.sourceTargetMappingLoading = false
				})
		},
		/**
		 * Fetches the list of registers from the mapping store and updates the register options.
		 * Sets the loading state to true while fetching and updates the register options with the fetched data.
		 * If a register is already selected, it sets it as the active register.
		 * If OpenRegister is not installed, it updates the state accordingly.
		 *
		 * additionally it adds the schemas of a register to its options data,
		 * which'll be used to populate the schema options when you select a register.
		 */
		getRegisterWithSchemas() {
			this.registerLoading = true

			mappingStore.getMappingObjects()
				.then(({ data }) => {
					if (!data.openRegisters) {
						this.registerLoading = false
						this.openRegisterInstalled = false
						return
					}

					// registers
					const registers = data.availableRegisters

					let activeRegister = null
					if (this.IS_EDIT && this.synchronizationItem.targetType === 'register/schema') {
						const registerId = this.synchronizationItem.targetId.split('/')[0]
						activeRegister = registers.find(object => object.id.toString() === registerId.toString())
					}

					let activeSourceRegister = null
					if (this.IS_EDIT && this.synchronizationItem.sourceType === 'register/schema') {
						const registerId = this.synchronizationItem.sourceId.split('/')[0]
						activeSourceRegister = registers.find(object => object.id.toString() === registerId.toString())
					}

					// schemas
					const schemas = registers.map(register => register.schemas).flat()
						.filter(schema => typeof schema === 'object')

					let activeSchema = null
					if (this.IS_EDIT && this.synchronizationItem.targetType === 'register/schema') {
						const schemaId = this.synchronizationItem.targetId.split('/')[1]
						activeSchema = schemas.find(schema => schema.id.toString() === schemaId.toString())
					}

					let activeSourceSchema = null
					if (this.IS_EDIT && this.synchronizationItem.sourceType === 'register/schema') {
						const schemaId = this.synchronizationItem.sourceId.split('/')[1]
						activeSourceSchema = schemas.find(schema => schema.id.toString() === schemaId.toString())
					}

					// load registers (with schema's in options)
					this.registerOptions = {
						options: registers.map(object => ({
							label: object.title || object.name,
							id: object.id,
							schemas: {
								options: object.schemas.filter(schema => typeof schema === 'object').map(schema => ({
									label: schema.title || schema.name,
									id: schema.id,
								})),
							},
						})),
						value: activeRegister
							? {
								label: activeRegister.title || activeRegister.name,
								id: activeRegister.id,
								schemas: {
									options: activeRegister.schemas.filter(schema => typeof schema === 'object').map(schema => ({
										label: schema.title || schema.name,
										id: schema.id,
									})),
								},
							}
							: null,
						sourceValue: activeSourceRegister
							? {
								label: activeSourceRegister.title || activeSourceRegister.name,
								id: activeSourceRegister.id,
								schemas: {
									options: activeSourceRegister.schemas.filter(schema => typeof schema === 'object').map(schema => ({
										label: schema.title || schema.name,
										id: schema.id,
									})),
								},
							}
							: null,
					}

					// set active schema
					this.schemaOptions = {
						value: activeSchema
							? {
								label: activeSchema.title || activeSchema.name,
								id: activeSchema.id,
							}
							: null,
						sourceValue: activeSourceSchema
							? {
								label: activeSourceSchema.title || activeSourceSchema.name,
								id: activeSourceSchema.id,
							}
							: null,
					}
				})
				.finally(() => {
					this.registerLoading = false
				})
		},
		/**
		 * Fetches the list of available rules from the rules store and updates the rules options.
		 * Sets the loading state to true while fetching and updates the rules options with the fetched data.
		 * If a rules is already selected, it sets it as the active rules.
		 * If the target type is 'api', it sets the active target rules.
		 */
		getRules() {
			this.rulesLoading = true

			ruleStore.refreshList()
				.then(() => {
					const rules = ruleStore.list
					const activeRule = rules.filter(rule => this.synchronizationItem.actions.includes(rule.id))

					this.ruleOptions = {
						options: rules.map(rule => ({
							label: rule.name,
							id: rule.id,
						})),
						value: activeRule.map(rule => ({
							label: rule.name,
							id: rule.id,
						})),
					}
				})
				.finally(() => {
					this.rulesLoading = false
				})
		},
		/**
		 * Installs OpenRegister by sending a request to the server.
		 * Sets the loading state to true while the installation is in progress.
		 * Updates the state based on the success or failure of the installation.
		 * If the installation is successful, it fetches the register and schema options.
		 */
		async installOpenRegister() {
			this.openRegisterLoading = true

			console.info('Installing Open Register')
			const requesttoken = document.querySelector('head[data-requesttoken]').getAttribute('data-requesttoken')

			const forceResponse = await fetch('/index.php/settings/apps/force', {
				headers: {
					accept: 'application/json, text/plain, */*',
					'accept-language': 'en-US,en;q=0.9,nl;q=0.8',
					'cache-control': 'no-cache',
					'content-type': 'application/json',
					pragma: 'no-cache',
					requesttoken,
					'x-requested-with': 'XMLHttpRequest, XMLHttpRequest',
				},
				referrerPolicy: 'no-referrer',
				body: '{"appId":"openregister"}',
				method: 'POST',
				mode: 'cors',
				credentials: 'include',
			})

			if (!forceResponse.ok) {
				console.info('Failed to install Open Register')
				this.openRegisterIsAvailable = false
				this.openRegisterLoading = false
				return
			}

			const response = await fetch('/index.php/settings/apps/enable', {
				headers: {
					accept: '*/*',
					'accept-language': 'en-US,en;q=0.9,nl;q=0.8',
					'cache-control': 'no-cache',
					'content-type': 'application/json',
					pragma: 'no-cache',
					requesttoken,
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
				this.openRegisterIsAvailable = false
			} else {
				console.info('Open Register installed')
				this.openRegisterInstalled = true
				this.getRegister()
				this.getSchema()
			}

			this.openRegisterLoading = false
		},
		/**
		 * Closes the modal and clears the timeout function.
		 */
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeTimeoutFunc)
		},
		/**
		 * Tests the synchronization configuration by running a test sync
		 */
		testSynchronization() {
			// TODO: Implement test synchronization functionality
		},
		/**
		 * Edits the synchronization by saving the synchronization item to the store.
		 * Sets the loading state to true while saving and updates the state based on the success or failure of the save operation.
		 * If the save operation is successful, it closes the modal after a timeout.
		 */
		editSynchronization() {
			this.loading = true

			let targetId = null
			if (this.targetTypeOptions.value?.id === 'register/schema') {
				targetId = `${this.registerOptions.value?.id}/${this.schemaOptions.value?.id}`
			} else if (this.targetTypeOptions.value?.id === 'api') {
				targetId = this.sourceOptions.targetValue?.id
			}

			let sourceId = null
			if (this.typeOptions.value?.id === 'register/schema') {
				sourceId = `${this.registerOptions.sourceValue?.id}/${this.schemaOptions.sourceValue?.id}`
			} else {
				sourceId = this.sourceOptions.sourceValue?.id
			}

			const synchronizationItem = new Synchronization({
				...this.synchronizationItem,
				sourceId: sourceId || null,
				sourceType: this.typeOptions.value?.id || null,
				sourceHashMapping: this.sourceTargetMappingOptions.hashValue?.id || null,
				sourceTargetMapping: this.sourceTargetMappingOptions.sourceValue?.id || null,
				conditions: this.synchronizationItem.conditions ? JSON.parse(this.synchronizationItem.conditions) : [],
				targetType: this.targetTypeOptions.value?.id || null,
				targetId: targetId || null,
				targetSourceMapping: this.sourceTargetMappingOptions.targetValue?.id || null,
				actions: this.ruleOptions.value ? this.ruleOptions.value.map(rule => rule.id) : [],
			})

			synchronizationStore.saveSynchronization(synchronizationItem)
				.then(({ response }) => {
					this.success = response.ok
					this.closeTimeoutFunc = setTimeout(this.closeModal, 2000)
				})
				.catch(error => {
					this.success = false
					this.error = error.message || 'Er is een fout opgetreden bij het opslaan van de synchronisatie'
				})
				.finally(() => {
					this.loading = false
				})
		},
	},
}
</script>

<style scoped>
/* Modal Content */
.modalContent {
	margin: 15px;
	text-align: left;
}

:deep(.modal-container) {
	width: unset !important;
	max-width: 1200px !important;
}

.modalContent h2 {
	margin-bottom: 20px;
	text-align: center;
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

.close-button {
	position: absolute;
	top: 5px;
	right: 5px;
}
.close-button :deep(.button-vue--vue-tertiary:hover:not(:disabled)) {
    background-color: rgba(var(--color-info-rgb), 0.1);
}

.css-fix-reg\/schema {
	width: 100%;
	display: grid;
	grid-template-columns: auto 1fr auto;
}

.css-fix-reg\/schema .v-select {
	width: 100%;
}

.css-fix-reg\/schema p {
	align-self: end;
	margin-block-end: 10px;
}

/* Three-Column Layout */
.synchronization-layout {
	display: flex !important;
	flex-direction: row !important;
	gap: 20px;
	margin: 15px 0;
	min-height: 500px;
	width: 100%;
	align-items: stretch;
}

.sync-section {
	flex: 1 1 33.333%;
	min-width: 300px;
	max-width: none;
	display: flex;
	flex-direction: column;
	gap: 15px;
}

/* Individual section cards */
.sync-section:not(.general-section) {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background-color: var(--color-main-background);
}

/* Center column cards */
.center-card {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background-color: var(--color-main-background);
	display: flex;
	flex-direction: column;
}

.section-header {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 12px;
	background-color: var(--color-background-hover);
	border-bottom: 1px solid var(--color-border);
	border-radius: 8px 8px 0 0;
}

.section-header h3 {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.section-content {
	flex: 1;
	padding: 12px;
}

.info-card {
	padding: 12px;
	background-color: var(--color-background-soft);
	border-radius: 6px;
	margin-bottom: 15px;
}

.section-description {
	margin: 0;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-bottom: 15px;
}

.subsection {
	margin-bottom: 15px;
}

.subsection h4 {
	margin: 0 0 10px 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-text-light);
}

/* Source Section Styling */
.source-section .section-header {
	background-color: var(--color-info);
}

/* General Section Styling */
.general-section .section-header {
	background-color: var(--color-warning);
}

/* Target Section Styling */
.target-section .section-header {
	background-color: var(--color-success);
}

/* Data Flow Visualization */
.data-flow {
	display: flex;
	justify-content: center;
	align-items: center;
	margin: 10px 0;
	padding: 15px;
	background-color: var(--color-background-soft);
	border-radius: 6px;
	border: 1px solid var(--color-border);
}

.flow-step {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 5px;
	padding: 10px;
}

.flow-step span {
	font-size: 12px;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.flow-arrow {
	margin: 0 10px;
	color: var(--color-text-maxcontrast);
}

/* Responsive Design */
@media (max-width: 1200px) {
	.synchronization-layout {
		flex-direction: column !important;
	}

	.sync-section {
		flex: none;
		min-width: auto;
		max-width: none;
	}
}

/* Install buttons styling */
.install-buttons {
	display: flex;
	gap: 10px;
	margin-top: 10px;
}
</style>

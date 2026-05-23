<script setup>
import { endpointStore, navigationStore } from '../../store/store.js'
import { Endpoint } from '../../entities/index.js'
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'editEndpoint'"
		ref="modalRef"
		label-id="editEndpoint"
		@close="closeModal">
		<div class="modalContent">
			<h2>{{ endpointItem.id ? t('openconnector', 'Edit endpoint') : t('openconnector', 'Add endpoint') }}</h2>

			<div v-if="success !== null">
				<NcNoteCard v-if="success" type="success">
					<p>{{ t('openconnector', 'Endpoint successfully saved') }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="error" type="error">
					<p>{{ error }}</p>
				</NcNoteCard>
			</div>

			<form v-if="success === null" @submit.prevent="handleSubmit">
				<div class="form-group">
					<NcTextField
						:label="t('openconnector', 'Name') + '*'"
						:value.sync="endpointItem.name" />

					<NcTextArea
						resize="vertical"
						:label="t('openconnector', 'Description')"
						:value.sync="endpointItem.description" />

					<NcTextField
						:label="t('openconnector', 'Endpoint')"
						:value.sync="endpointItem.endpoint" />

					<NcTextArea
						resize="vertical"
						:label="t('openconnector', 'Endpoint array (split on ,)')"
						:value.sync="endpointItem.endpointArray" />

					<NcTextField
						:label="t('openconnector', 'Endpoint regex')"
						:value.sync="endpointItem.endpointRegex" />

					<NcTextField
						:label="t('openconnector', 'Slug')"
						:value.sync="endpointItem.slug" />

					<div>
						<NcSelect v-bind="methodOptions"
							v-model="methodOptions.value" />
					</div>

					<div>
						<NcSelect v-bind="targetTypeOptions"
							v-model="targetTypeOptions.value" />
					</div>

					<div>
						<NcSelect v-bind="registerOptions"
							v-model="registerOptions.value"
							:input-label="t('openconnector', 'Register')"
							:disabled="registersLoading" />

						<NcSelect v-bind="schemaOptions"
							v-model="schemaOptions.value"
							:disabled="!registerOptions.value || schemasLoading"
							:input-label="t('openconnector', 'Schema')" />
					</div>

					<div>
						<NcSelect v-bind="configurationOptions"
							v-model="configurationOptions.value"
							:input-label="t('openconnector', 'Configurations')"
							:multiple="true"
							:disabled="configurationsLoading" />
					</div>
				</div>
			</form>

			<div class="modal-actions">
				<NcButton
					v-if="success === null"
					@click="closeModal">
					<template #icon>
						<CancelIcon size="20" />
					</template>
					{{ t('openconnector', 'Cancel') }}
				</NcButton>
				<NcButton
					v-if="success === null"
					:disabled="loading || !endpointItem.name || !registerOptions.value || !schemaOptions.value"
					type="primary"
					@click="editEndpoint()">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<ContentSaveOutline v-if="!loading" :size="20" />
					</template>
					{{ t('openconnector', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcModal,
	NcSelect,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
	NcTextArea,
} from '@nextcloud/vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import _ from 'lodash'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'

export default {
	name: 'EditEndpoint',
	components: {
		NcModal,
		NcButton,
		NcSelect,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		NcTextArea,
		CancelIcon,
	},
	data() {
		return {
			endpointItem: {
				name: '',
				description: '',
				endpoint: '',
				endpointArray: '',
				endpointRegex: '',
				method: '',
				targetType: '',
				targetId: '',
				slug: '',
				configurations: [],
			},
			success: null,
			loading: false,
			error: false,
			methodOptions: {
				inputLabel: 'Method',
				options: [
					{ label: 'GET' },
					{ label: 'POST' },
					{ label: 'PUT' },
					{ label: 'DELETE' },
					{ label: 'PATCH' },
				],
				value: {
					label: 'GET',
				},
			},
			targetTypeOptions: {
				inputLabel: 'Target Type',
				options: [
					{ label: 'register/schema' },
				],
				value: {
					label: 'register/schema',
				},
			},
			registerOptions: {
				options: [],
				value: null,
			},
			schemaOptions: {
				options: [],
				value: null,
			},
			configurationOptions: {
				options: [],
				value: [],
			},
			schemas: [],
			hasUpdated: false,
			closeTimeoutFunc: null,
			registersLoading: false,
			schemasLoading: false,
			configurationsLoading: false,
			initialSchemaSet: false,
		}
	},
	watch: {
		'registerOptions.value'(newVal) {
			if (this.initialSchemaSet) {
				this.schemaOptions.value = null
			}
			this.setSchemaOptions(newVal)
		},
	},
	mounted() {
		this.initializeEndpointItem()
		this.fetchRegisters()
		this.fetchSchemas()
		this.fetchConfigurations()
	},
	updated() {
		if (navigationStore.modal === 'editEndpoint' && !this.hasUpdated) {
			this.initializeEndpointItem()
			this.fetchRegisters()
			this.hasUpdated = true
		}
	},
	methods: {
		initializeEndpointItem() {
			if (endpointStore.endpointItem?.id) {
				this.endpointItem = {
					...endpointStore.endpointItem,
					name: endpointStore.endpointItem.name,
					description: endpointStore.endpointItem.description,
					endpoint: endpointStore.endpointItem.endpoint,
					endpointArray: endpointStore.endpointItem.endpointArray.join(', '),
					endpointRegex: endpointStore.endpointItem.endpointRegex,
					method: endpointStore.endpointItem.method,
					targetType: this.targetTypeOptions.options.find(i => i.label === endpointStore.endpointItem.targetType),
					targetId: endpointStore.endpointItem.targetId,
					slug: endpointStore.endpointItem.slug,
					configurations: endpointStore.endpointItem.configurations || [],
				}

				// If the method of the endpointItem exists on the methodOptions, apply it to the value
				// this is done for future proofing incase we were to change the method options
				if (this.methodOptions.options.map(i => i.label).indexOf(endpointStore.endpointItem.method) >= 0) {
					this.methodOptions.value = { label: endpointStore.endpointItem.method }
				}

				// Set the configurations value if there are any
				if (this.endpointItem.configurations?.length > 0) {
					this.configurationOptions.value = this.endpointItem.configurations.map(id => ({
						id,
						label: this.configurationOptions.options.find(opt => opt.id === id)?.label || id,
					}))
				}
			}
		},
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeTimeoutFunc)
			this.success = null
			this.loading = false
			this.error = false
			this.hasUpdated = false
			this.endpointItem = {
				name: '',
				description: '',
				endpoint: '',
				endpointArray: '',
				endpointRegex: '',
				method: '',
				targetType: '',
				targetId: '',
				slug: '',
				configurations: [],
			}
			this.methodOptions.value = { label: 'GET' }
			this.targetTypeOptions.value = { label: 'register/schema' }
			this.initialSchemaSet = false
		},
		async fetchRegisters() {
			this.registersLoading = true

			// checking if OpenRegister is installed
			console.info('Fetching registers from Open Register')
			const response = await fetch('/index.php/apps/openregister/api/registers', {
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
				this.$emit('open-register', {
					isInstalled: false,
				})
				return
			}

			const responseData = (await response.json()).results

			const registerId = endpointStore.endpointItem?.targetId?.split('/')[0]

			const selectedRegister = responseData.find(register => _.toString(register.id) === registerId)

			this.registerOptions = {
				options: responseData.map((register) => ({
					id: register.id,
					label: register.title,
					schemas: register.schemas,
				})),
				value: selectedRegister
					? {
						label: selectedRegister.title,
						id: selectedRegister.id,
						schemas: selectedRegister.schemas,
					}
					: null,
			}

			this.registersLoading = false
		},
		async fetchSchemas() {
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
				this.$emit('open-register', {
					isInstalled: false,
				})
				return
			}

			const responseData = (await response.json()).results

			this.schemas = responseData

			this.schemasLoading = false
		},
		async fetchConfigurations() {
			this.configurationsLoading = true

			try {
				console.info('Fetching configurations from Open Register')
				const response = await fetch('/index.php/apps/openregister/api/configurations', {
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
					console.info('Failed to fetch configurations')
					return
				}

				const responseData = (await response.json()).results

				this.configurationOptions = {
					options: responseData.map((config) => ({
						id: config.id,
						label: config.name,
					})),
					value: this.endpointItem.configurations?.map(id => ({
						id,
						label: responseData.find(c => c.id === id)?.name || id,
					})) || [],
				}
			} catch (error) {
				console.error('Error fetching configurations:', error)
			} finally {
				this.configurationsLoading = false
			}
		},
		setSchemaOptions(register) {
			const schemaId = endpointStore.endpointItem?.targetId.split('/')[1]

			const selectedSchema = this.schemas.find(schema => _.toString(schema.id) === schemaId)

			const selectableSchemas = this.schemas.filter(schema => register?.schemas?.includes(schema.id))

			const isSchemaInSelectableSchemas = selectableSchemas.includes(selectedSchema)

			this.schemaOptions = {
				options: selectableSchemas.map((schema) => ({
					id: schema.id,
					label: schema.title,
				})),
				value: !this.initialSchemaSet && isSchemaInSelectableSchemas && selectedSchema
					? {
						label: selectedSchema.title,
						id: selectedSchema.id,
					}
					: null,
			}

			this.initialSchemaSet = true

		},
		async editEndpoint() {
			this.loading = true

			const endpointItem = new Endpoint({
				...this.endpointItem,
				endpointArray: this.endpointItem.endpointArray.split(/ *, */g), // split on comma's, also take any spaces into consideration
				method: this.methodOptions.value.label,
				targetType: this.targetTypeOptions.value.label,
				targetId: `${this.registerOptions.value.id}/${this.schemaOptions.value.id}`,
				configurations: this.configurationOptions.value.map(v => v.id),
			})

			await endpointStore.saveEndpoint(endpointItem)
				.then(({ response }) => {
					this.success = response.ok
					this.closeTimeoutFunc = setTimeout(this.closeModal, 2000)
				}).catch((e) => {
					this.success = false
					this.error = e.message || 'An error occurred while saving the endpoint'
				}).finally(() => {
					this.loading = false
				})
		},
	},
}
</script>

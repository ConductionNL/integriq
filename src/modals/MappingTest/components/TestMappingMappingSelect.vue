<script setup>
import { translate as t } from '@nextcloud/l10n'
import { mappingStore } from '../../../store/store.js'
</script>

<template>
	<div>
		<h4>{{ t('openconnector', 'Test mapping') }}</h4>

		<div class="content">
			<div class="mapping-select">
				<NcSelect v-bind="mappings"
					v-model="mappings.value"
					:input-label="t('openconnector', 'Mapping')"
					:clearable="false"
					:loading="mappingsLoading || mappingTest.loading"
					required
					@input="emitMappingSelected">
					<!-- eslint-disable-next-line vue/no-unused-vars vue/no-template-shadow  -->
					<template #no-options="{ search, searching, loading }">
						<p v-if="loading">
							{{ t('openconnector', 'Loading...') }}
						</p>
						<p v-if="!loading && !mappings.options?.length">
							{{ t('openconnector', 'No mappings available') }}
						</p>
					</template>
					<!-- eslint-disable-next-line vue/no-unused-vars  -->
					<template #option="{ id, label, summary, removeStyle }">
						<div :class="removeStyle !== true && 'mapping-option'">
							<!-- custom style is enabled -->
							<SitemapOutline v-if="!removeStyle" :size="25" />
							<span v-if="!removeStyle">
								<h6 style="margin: 0">
									{{ label }}
								</h6>
								{{ summary }}
							</span>
							<!-- custom style is disabled -->
							<p v-if="removeStyle" class="truncate">
								{{ label }}
							</p>
						</div>
					</template>
				</NcSelect>

				<NcSelect v-bind="schemas"
					v-model="schemas.value"
					:input-label="t('openconnector', 'Schema')"
					:loading="schemasLoading"
					required
					@input="emitSchemaSelected">
					<!-- eslint-disable-next-line vue/no-unused-vars vue/no-template-shadow  -->
					<template #no-options="{ search, searching, loading }">
						<p v-if="loading">
							{{ t('openconnector', 'Loading...') }}
						</p>
						<p v-if="!loading && !schemas.options?.length">
							{{ t('openconnector', 'No schemas available') }}
						</p>
					</template>
					<!-- eslint-disable-next-line vue/no-unused-vars  -->
					<template #option="{ id, label, fullSchema, removeStyle }">
						<div :class="removeStyle !== true && 'mapping-option'">
							<!-- custom style is enabled -->
							<FileTreeOutline v-if="!removeStyle" :size="25" />
							<span v-if="!removeStyle">
								<h6 style="margin: 0">
									{{ label }}
								</h6>
								{{ fullSchema.summary }}
							</span>
							<!-- custom style is disabled -->
							<p v-if="removeStyle" class="truncate">
								{{ label }}
							</p>
						</div>
					</template>
				</NcSelect>
			</div>

			<div class="edit-mapping">
				<h4>{{ t('openconnector', 'Edit mapping') }}</h4>

				<NcTextField :value.sync="mappingItem.name"
					:label="t('openconnector', 'Name')" />

				<NcTextArea
					resize="vertical"
					:value.sync="mappingItem.description"
					:label="t('openconnector', 'Description')" />

				<NcTextArea
					resize="vertical"
					:value.sync="mappingItem.mapping"
					:label="t('openconnector', 'Mapping')"
					:error="!validJson(mappingItem.mapping)"
					:helper-text="!validJson(mappingItem.mapping) ? t('openconnector', 'Invalid JSON') : ''" />

				<NcTextArea
					resize="vertical"
					:value.sync="mappingItem.cast"
					:label="t('openconnector', 'Cast')"
					:error="!validJson(mappingItem.cast, true)"
					:helper-text="!validJson(mappingItem.cast, true) ? t('openconnector', 'Invalid JSON') : ''" />

				<NcTextArea
					resize="vertical"
					:value.sync="mappingItem.unset"
					:label="t('openconnector', 'Unset')"
					:helper-text="t('openconnector', 'Enter a comma-separated list of keys.')" />

				<div class="modal-actions">
					<NcButton v-if="!success"
						@click="closeModal">
						<template #icon>
							<CancelIcon size="20" />
						</template>
						{{ t('openconnector', 'Cancel') }}
					</NcButton>
					<NcButton class="reset-button"
						type="secondary"
						@click="setupEditFields(mappings.value?.id)">
						<template #icon>
							<Refresh :size="20" />
						</template>
						{{ t('openconnector', 'Reset') }}
					</NcButton>
					<NcButton :disabled="mappingTest.loading || !mappings.value || !inputObject.isValid || !validJson(mappingItem.mapping) || !validJson(mappingItem.cast, true)"
						class="test-button"
						type="success"
						@click="testMapping()">
						<template #icon>
							<NcLoadingIcon v-if="mappingTest.loading" :size="20" />
							<TestTube v-if="!mappingTest.loading" :size="20" />
						</template>
						{{ t('openconnector', 'Test') }}
					</NcButton>
					<NcButton class="save-button"
						type="primary"
						@click="saveMappingChanges()">
						<template #icon>
							<NcLoadingIcon v-if="savingMapping" :size="20" />
							<ContentSaveOutline v-if="!savingMapping" :size="20" />
						</template>
						{{ t('openconnector', 'Save') }}
					</NcButton>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import {
	NcSelect,
	NcTextField,
	NcTextArea,
	NcButton,
	NcLoadingIcon,
} from '@nextcloud/vue'

import CancelIcon from 'vue-material-design-icons/Cancel.vue'

import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import TestTube from 'vue-material-design-icons/TestTube.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

import { Mapping } from '../../../entities/index.js'

export default {
	name: 'TestMappingMappingSelect',
	components: {
		NcSelect,
		NcTextField,
		NcTextArea,
		NcButton,
		NcLoadingIcon,
		CancelIcon,
	},
	props: {
		inputObject: {
			type: Object,
			required: true,
		},
	},
	data() {
		return {
			mappings: [],
			mappingsLoading: false,
			mappingItem: {
				name: '',
				description: '',
				mapping: '{}',
				cast: '{}',
				unset: '', // array as string
			},
			// use uniqueMappingId as the "No mapping" option's ID to avoid any possible truthy comparisons
			uniqueMappingId: Symbol('No Mapping'), // Symbol creates a truly unique value, so unique making 2 of the same symbol will never be the same.
			savingMapping: false,
			savingMappingSuccess: null,
			// mapping test
			mappingTest: {
				result: {}, // result from the testMapping function
				success: null,
				loading: false,
				error: false,
			},
			schemas: [],
			schemasLoading: false,
		}
	},
	watch: {
		'mappings.value.id'(newVal) {
			this.setupEditFields(newVal)
		},
		// watch data and emit
		mappingTest: {
			handler(newVal) {
				this.$emit('mapping-test', {
					...newVal,
				})
			},
			deep: true,
		},
		mappingsLoading(newVal) {
			this.$emit('mapping-selected', {
				loading: newVal,
			})
		},
		schemasLoading(newVal) {
			this.$emit('schema-selected', {
				loading: newVal,
			})
		},
	},
	mounted() {
		this.fetchMappings()
		this.fetchSchemas()
	},
	methods: {
		closeModal() {
			this.$emit('close-modal')
		},
		emitMappingSelected(event) {
			this.$emit('mapping-selected', {
				selected: event,
			})
		},
		emitSchemaSelected(event) {
			this.$emit('schema-selected', {
				selected: event,
			})
		},
		setupEditFields(id) {
			if (id === this.uniqueMappingId) { // "No mapping" option selected (Symbol comparisons can only return true if its the same symbol from the same variable)
				this.mappingItem = {
					name: '',
					description: '',
					mapping: '{}',
					cast: '{}',
					unset: '',
				}
			} else {
				this.mappingItem.name = this.mappings.value.fullMapping.name
				this.mappingItem.description = this.mappings.value.fullMapping.description
				this.mappingItem.mapping = JSON.stringify(this.mappings.value.fullMapping.mapping, null, 2)
				this.mappingItem.cast = JSON.stringify(this.mappings.value.fullMapping.cast, null, 2)
				this.mappingItem.unset = this.mappings.value.fullMapping.unset.join(', ') // turn the array into a string
			}
		},
		async fetchMappings(currentMappingItem = null) {
			this.mappingsLoading = true

			return mappingStore.refreshList()
				.then(() => {
					if (!currentMappingItem) {
						currentMappingItem = mappingStore.item || null
					}

					const selectedMapping = mappingStore.list.find((mapping) => mapping.id === (currentMappingItem?.id || Symbol('mapping item id not found')))

					const fallbackMapping = mappingStore.list[0]
						? {
							id: mappingStore.list[0].id,
							label: mappingStore.list[0].name,
							summary: mappingStore.list[0].description,
							fullMapping: mappingStore.list[0],
						}
						: null

					this.mappings = {
						options: [
							{
								id: this.uniqueMappingId,
								label: this.t('openconnector', 'No mapping'),
								removeStyle: true,
							},
							...mappingStore.list.map((mapping) => ({
								id: mapping.id,
								label: mapping.name,
								summary: mapping.description,
								fullMapping: mapping,
							})),
						],
						value: selectedMapping
							? {
								id: selectedMapping.id,
								label: selectedMapping.name,
								summary: selectedMapping.description,
								fullMapping: selectedMapping,
							}
							: fallbackMapping,
					}

					// emit the current selected mapping after mappings initialization
					this.$emit('mapping-selected', {
						mappings: this.mappings,
						selected: this.mappings.value,
					})
				})
				.finally(() => {
					this.mappingsLoading = false
				})
		},
		async fetchSchemas() {
			this.schemasLoading = true

			const response = await fetch('/index.php/apps/openregister/api/schemas', {
				headers: {
					accept: '*/*',
					'x-requested-with': 'XMLHttpRequest',
				},
				method: 'GET',
				credentials: 'include',
			})

			if (!response.ok) {
				this.schemasLoading = false
				return
			}

			const responseData = (await response.json()).results

			this.schemas = {
				options: responseData.map((schema) => ({
					id: schema.id,
					label: schema.title,
					fullSchema: schema,
				})),
				value: null,
			}

			// emit the current selected mapping after mappings initialization
			this.$emit('schema-selected', {
				schemas: this.schemas,
				selected: this.schemas.value,
			})

			this.schemasLoading = false
		},
		async testMapping() {
			this.mappingTest.loading = true
			this.mappingTest.error = false
			this.mappingTest.success = null
			this.mappingTest.result = {}

			mappingStore.testMapping({
				mapping: {
					...this.mappings.value.fullMapping,
					name: this.mappingItem.name,
					description: this.mappingItem.description,
					mapping: JSON.parse(this.mappingItem.mapping),
					cast: this.mappingItem.cast ? JSON.parse(this.mappingItem.cast) : null,
					unset: this.mappingItem.unset.split(/ *, */g).filter(Boolean),
				},
				inputObject: JSON.parse(this.inputObject.value),
				schema: this.schemas.value?.id,
			})
				.then(({ response, data }) => {
					this.mappingTest.success = response.ok
					this.mappingTest.result = data
				})
				.catch((error) => {
					this.mappingTest.error = error.message || this.t('openconnector', 'An error occurred while testing the mapping')
				})
				.finally(() => {
					this.mappingTest.loading = false
				})
		},
		saveMappingChanges() {
			this.savingMapping = true

			const newMappingItem = new Mapping({
				...this.mappings.value?.fullMapping,
				name: this.mappingItem.name,
				description: this.mappingItem.description,
				mapping: JSON.parse(this.mappingItem.mapping),
				cast: JSON.parse(this.mappingItem.cast),
				unset: this.mappingItem.unset.split(/ *, */g).filter(Boolean),
			})

			mappingStore.save(newMappingItem)
				.then(({ response, entity }) => {
					this.savingMappingSuccess = response.ok
					response.ok && this.fetchMappings(entity)
						.then(() => {
							this.setupEditFields(entity.id)
						})
				})
				.catch((e) => {
					this.savingMappingSuccess = false
				})
				.finally(() => {
					setTimeout(() => (this.savingMappingSuccess = null), 2000)
					this.savingMapping = false
				})
		},
		validJson(object, optional = false) {
			if (optional && !object) {
				return true
			}

			try {
				JSON.parse(object)
				return true
			} catch (e) {
				return false
			}
		},
	},
}
</script>

<style scoped>
/* close button for notecard */
.openregister-notecard .notecard {
    position: relative;
}
.close-button {
    position: absolute;
    top: 5px;
    right: 5px;
}
.close-button .button-vue--vue-tertiary:hover:not(:disabled) {
    background-color: rgba(var(--color-info-rgb), 0.1);
}

.content {
    text-align: left;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.textarea :deep(textarea) {
    resize: vertical !important;
    height: 100%;
}

.mapping-select {
    display: grid;
	grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.mapping-select > .v-select {
    min-width: auto;
}

.mapping-select > .button-vue {
    margin-block-end: 4px !important;
}

.install-buttons {
    display: flex;
    gap: 0.5rem;
    margin-block-start: 1rem;
}

/* Mapping option */
.mapping-option {
    display: flex;
    align-items: center;
    gap: 10px;
    overflow: hidden;
}
.mapping-option > .material-design-icon {
    margin-block-start: 2px;
}
.mapping-option > h6 {
    line-height: 0.8;
}
/* truncate long labels and summaries */
.mapping-option > span {
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.mapping-option > span h6,
.mapping-option > span {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.truncate {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* select style */
/* remove box-shadow around search input */
.v-select :deep(.vs__search) {
    box-shadow: none !important;
}

.edit-mapping > h4 {
    margin-block-start: 2rem !important;
    margin-block-end: 1rem !important;
}

/* modal action buttons layout */
.modal-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 12px;
}
</style>

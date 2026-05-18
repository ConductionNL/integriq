<script setup>
import { translate as t } from '@nextcloud/l10n'
import { mappingStore, navigationStore } from '../../store/store.js'
import { Mapping } from '../../entities/index.js'
</script>

<template>
	<NcModal
		ref="modalRef"
		class="EditMappingMainModal"
		label-id="editMapping"
		size="large"
		:can-close="true"
		:width="1200"
		:name="mappingStore.item?.id ? t('openconnector', 'Edit Mapping') : t('openconnector', 'Create New Mapping')"
		@close="closeModal">
		<div class="modalContent">
			<!-- ====================== -->
			<!-- Success/Error notecard -->
			<!-- ====================== -->
			<div v-if="success || error">
				<NcNoteCard v-if="success" type="success">
					<p>{{ mappingStore.item?.id ? t('openconnector', 'Mapping successfully updated') : t('openconnector', 'Mapping successfully created') }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="error" type="error">
					<p>{{ error || t('openconnector', 'An error occurred') }}</p>
				</NcNoteCard>
			</div>

			<!-- ====================== -->
			<!--    Three-Column Layout  -->
			<!-- ====================== -->
			<div v-if="!success" class="mapping-layout">
				<!-- Input Section -->
				<div class="mapping-section input-section">
					<div class="section-header">
						<DatabaseArrowRightOutline :size="24" />
						<h3>{{ t('openconnector', 'Input') }}</h3>
					</div>
					<div class="section-content">
						<div class="info-card">
							<p class="section-description">
								{{ t('openconnector', 'Configure test input data for mapping validation') }}
							</p>
						</div>

						<!-- Test Input -->
						<div class="subsection">
							<h4>{{ t('openconnector', 'Test Input Object') }}</h4>
							<div class="form-group">
								<NcTextArea
									:value.sync="inputObject.value"
									resize="vertical"
									:label="t('openconnector', 'Test Input (JSON)')"
									:placeholder="t('openconnector', 'Enter JSON object to test mapping')"
									:error="!validJson(inputObject.value)"
									:helper-text="!validJson(inputObject.value) ? t('openconnector', 'Invalid JSON') : ''"
									@input="updateInputObject" />
							</div>
						</div>
					</div>
				</div>

				<!-- Transformation Section -->
				<div class="mapping-section transformation-section">
					<!-- General Card -->
					<div class="center-card">
						<div class="section-header">
							<CogOutline :size="24" />
							<h3>{{ t('openconnector', 'General') }}</h3>
						</div>
						<div class="section-content">
							<form @submit.prevent="handleSubmit">
								<div class="form-group">
									<NcTextField
										:value.sync="mappingItem.name"
										:label="t('openconnector', 'Name')"
										:placeholder="t('openconnector', 'Enter mapping name')"
										:error="!isValidName"
										:helper-text="!isValidName ? t('openconnector', 'Name must contain at least one letter or number') : ''"
										required />

									<NcTextArea
										resize="vertical"
										:value.sync="mappingItem.description"
										:label="t('openconnector', 'Description')"
										:placeholder="t('openconnector', 'Describe what this mapping does')" />
								</div>
							</form>
						</div>
					</div>

					<!-- Data Flow Arrow -->
					<div class="data-flow">
						<div class="flow-step">
							<DatabaseArrowRightOutline :size="20" />
							<span>{{ t('openconnector', 'Input') }}</span>
						</div>
						<ArrowRight :size="20" class="flow-arrow" />
						<div class="flow-step">
							<SwapHorizontal :size="20" />
							<span>{{ t('openconnector', 'Transform') }}</span>
						</div>
						<ArrowRight :size="20" class="flow-arrow" />
						<div class="flow-step">
							<DatabaseArrowLeftOutline :size="20" />
							<span>{{ t('openconnector', 'Output') }}</span>
						</div>
					</div>

					<!-- Transform Card -->
					<div class="center-card">
						<div class="section-header">
							<SwapHorizontal :size="24" />
							<h3>{{ t('openconnector', 'Transform') }}</h3>
						</div>
						<div class="section-content">
							<BTabs content-class="transform-tab-content"
								justified
								class="transform-tabs">
								<!-- Mapping Tab -->
								<BTab :title="t('openconnector', 'Mapping')">
									<div class="table-container mapping-property-table">
										<table v-if="Object.keys(mappingRules).length" class="statisticsTable">
											<thead>
												<tr>
													<th>{{ t('openconnector', 'Target') }}</th>
													<th>{{ t('openconnector', 'Source') }}</th>
													<th />
												</tr>
											</thead>
											<tbody>
												<tr v-for="(template, property) in mappingRules" :key="property">
													<td class="property-cell">
														<span v-tooltip="property" class="mapping-property">{{ property }}</span>
													</td>
													<td class="template-cell">
														<span v-tooltip="String(template)" class="mapping-template">{{ template }}</span>
													</td>
													<td class="actions-cell">
														<NcActions>
															<NcActionButton close-after-click @click="openEditMappingItemDialog('mapping', property)">
																<template #icon>
																	<Pencil :size="20" />
																</template>
																{{ t('openconnector', 'Edit') }}
															</NcActionButton>
															<NcActionButton close-after-click @click="openDeleteMappingItemDialog('mapping', property)">
																<template #icon>
																	<Delete :size="20" />
																</template>
																{{ t('openconnector', 'Delete') }}
															</NcActionButton>
														</NcActions>
													</td>
												</tr>
											</tbody>
										</table>
										<NcButton type="primary" @click="openEditMappingItemDialog('mapping', null)">
											<template #icon>
												<Plus :size="20" />
											</template>
											{{ t('openconnector', 'Add Mapping Rule') }}
										</NcButton>
									</div>
								</BTab>

								<!-- Cast Tab -->
								<BTab :title="t('openconnector', 'Cast')">
									<div class="table-container mapping-property-table">
										<table v-if="Object.keys(castRules).length" class="statisticsTable">
											<thead>
												<tr>
													<th>{{ t('openconnector', 'Property') }}</th>
													<th>{{ t('openconnector', 'Cast Type') }}</th>
													<th />
												</tr>
											</thead>
											<tbody>
												<tr v-for="(castType, property) in castRules" :key="property">
													<td class="property-cell">
														<span v-tooltip="property" class="mapping-property">{{ property }}</span>
													</td>
													<td>
														<span v-tooltip="String(castType)" class="mapping-template">{{ castType }}</span>
													</td>
													<td class="actions-cell">
														<NcActions>
															<NcActionButton close-after-click @click="openEditMappingItemDialog('cast', property)">
																<template #icon>
																	<Pencil :size="20" />
																</template>
																{{ t('openconnector', 'Edit') }}
															</NcActionButton>
															<NcActionButton close-after-click @click="openDeleteMappingItemDialog('cast', property)">
																<template #icon>
																	<Delete :size="20" />
																</template>
																{{ t('openconnector', 'Delete') }}
															</NcActionButton>
														</NcActions>
													</td>
												</tr>
											</tbody>
										</table>
										<NcButton type="primary" @click="openEditMappingItemDialog('cast', null)">
											<template #icon>
												<Plus :size="20" />
											</template>
											{{ t('openconnector', 'Add Cast Rule') }}
										</NcButton>
									</div>
								</BTab>

								<!-- Unset Tab -->
								<BTab :title="t('openconnector', 'Unset')">
									<div class="table-container mapping-property-table">
										<table v-if="unsetRules.length" class="statisticsTable">
											<thead>
												<tr>
													<th>{{ t('openconnector', 'Property') }}</th>
													<th />
												</tr>
											</thead>
											<tbody>
												<tr v-for="(property, index) in unsetRules" :key="index">
													<td class="property-cell">
														{{ property }}
													</td>
													<td class="actions-cell">
														<NcActions>
															<NcActionButton close-after-click @click="openEditMappingItemDialog('unset', property)">
																<template #icon>
																	<Pencil :size="20" />
																</template>
																{{ t('openconnector', 'Edit') }}
															</NcActionButton>
															<NcActionButton close-after-click @click="openDeleteMappingItemDialog('unset', property)">
																<template #icon>
																	<Delete :size="20" />
																</template>
																{{ t('openconnector', 'Delete') }}
															</NcActionButton>
														</NcActions>
													</td>
												</tr>
											</tbody>
										</table>
										<NcButton type="primary"
											:disabled="!mappingItem.passThrough"
											@click="openEditMappingItemDialog('unset', null)">
											<template #icon>
												<Plus :size="20" />
											</template>
											{{ t('openconnector', 'Add Unset Property') }}
										</NcButton>
										<p v-if="!mappingItem.passThrough" class="unset-disabled-note">
											{{ t('openconnector', 'Unset properties can only be configured when Pass Through is enabled.') }}
										</p>
									</div>
								</BTab>

								<!-- Options Tab -->
								<BTab :title="t('openconnector', 'Options')">
									<div class="options-container">
										<div class="form-group">
											<span class="flex-container">
												<NcCheckboxRadioSwitch
													:checked="passThroughLocal"
													@update:checked="onPassThroughChange">
													{{ t('openconnector', 'Pass Through') }}
												</NcCheckboxRadioSwitch>
												<a v-tooltip="t('openconnector', 'When turning passThrough on, all data from the original object is copied to the new object (passed through the mapper)')"
													href="https://commongateway.github.io/CoreBundle/pages/Features/Mappings"
													target="_blank">
													<HelpCircleOutline :size="20" />
												</a>
											</span>
										</div>
									</div>
								</BTab>
							</BTabs>
						</div>
					</div>

					<!-- Mapping Rule Dialog -->
					<NcModal v-if="showMappingDialog"
						:name="editingMappingRule ? t('openconnector', 'Edit Mapping Rule') : t('openconnector', 'Add Mapping Rule')"
						@close="closeMappingDialog">
						<div class="dialog-content">
							<h3>{{ editingMappingRule ? t('openconnector', 'Edit Mapping Rule') : t('openconnector', 'Add Mapping Rule') }}</h3>
							<div class="form-group">
								<NcTextField
									:value.sync="mappingDialogData.property"
									:label="t('openconnector', 'Target Property')"
									:placeholder="t('openconnector', 'Enter target property name')"
									required />
								<NcTextArea
									:value.sync="mappingDialogData.template"
									:label="t('openconnector', 'Source Property/Template')"
									:placeholder="t('openconnector', 'Enter Twig template (e.g., values[\'source.field\'] )')"
									resize="vertical"
									required />
							</div>
							<div class="dialog-actions">
								<NcButton type="secondary" @click="closeMappingDialog">
									{{ t('openconnector', 'Cancel') }}
								</NcButton>
								<NcButton type="primary" @click="saveMappingRule">
									{{ t('openconnector', 'Save') }}
								</NcButton>
							</div>
						</div>
					</NcModal>

					<!-- Cast Rule Dialog -->
					<NcModal v-if="showCastDialog"
						:name="editingCastRule ? t('openconnector', 'Edit Cast Rule') : t('openconnector', 'Add Cast Rule')"
						@close="closeCastDialog">
						<div class="dialog-content">
							<h3>{{ editingCastRule ? t('openconnector', 'Edit Cast Rule') : t('openconnector', 'Add Cast Rule') }}</h3>
							<div class="form-group">
								<NcTextField
									:value.sync="castDialogData.property"
									:label="t('openconnector', 'Property')"
									:placeholder="t('openconnector', 'Enter property name')"
									required />
								<NcSelect
									:value.sync="castDialogData.castType"
									:options="castTypeOptions"
									:label="t('openconnector', 'Cast Type')"
									required />
							</div>
							<div class="dialog-actions">
								<NcButton type="secondary" @click="closeCastDialog">
									{{ t('openconnector', 'Cancel') }}
								</NcButton>
								<NcButton type="primary" @click="saveCastRule">
									{{ t('openconnector', 'Save') }}
								</NcButton>
							</div>
						</div>
					</NcModal>

					<!-- Unset Rule Dialog -->
					<NcModal v-if="showUnsetDialog"
						:name="editingUnsetRule !== null ? t('openconnector', 'Edit Unset Property') : t('openconnector', 'Add Unset Property')"
						@close="closeUnsetDialog">
						<div class="dialog-content">
							<h3>{{ editingUnsetRule !== null ? t('openconnector', 'Edit Unset Property') : t('openconnector', 'Add Unset Property') }}</h3>
							<div class="form-group">
								<NcTextField
									:value.sync="unsetDialogData.property"
									:label="t('openconnector', 'Property')"
									:placeholder="t('openconnector', 'Enter property name to unset')"
									required />
							</div>
							<div class="dialog-actions">
								<NcButton type="secondary" @click="closeUnsetDialog">
									{{ t('openconnector', 'Cancel') }}
								</NcButton>
								<NcButton type="primary" @click="saveUnsetRule">
									{{ t('openconnector', 'Save') }}
								</NcButton>
							</div>
						</div>
					</NcModal>
				</div>

				<!-- Output Section -->
				<div class="mapping-section output-section">
					<div class="section-header">
						<DatabaseArrowLeftOutline :size="24" />
						<h3>{{ t('openconnector', 'Output') }}</h3>
					</div>
					<div class="section-content">
						<div class="info-card">
							<p class="section-description">
								{{ t('openconnector', 'View mapping test results and validation status') }}
							</p>
						</div>

						<!-- Schema Selection -->
						<div class="subsection">
							<h4>{{ t('openconnector', 'Validation Schema') }}</h4>
							<div class="form-group">
								<NcSelect v-bind="schemaOptions"
									v-model="schemaOptions.value"
									:input-label="t('openconnector', 'Schema (Optional)')"
									:loading="schemasLoading">
									<template #no-options="{ loading: isLoading }">
										<p v-if="isLoading">
											{{ t('openconnector', 'Loading...') }}
										</p>
										<p v-else-if="!schemaOptions.options?.length">
											{{ t('openconnector', 'No schemas available') }}
										</p>
									</template>
									<template #option="{ label, fullSchema }">
										<div class="schema-option">
											<FileTreeOutline :size="25" />
											<span>
												<h6>{{ label }}</h6>
												{{ fullSchema.summary || t('openconnector', 'No description') }}
											</span>
										</div>
									</template>
								</NcSelect>
							</div>
						</div>

						<!-- Test Status -->
						<div v-if="testResult.success !== null" class="test-status">
							<NcNoteCard v-if="testResult.success" type="success">
								<p>{{ t('openconnector', 'Mapping test completed successfully') }}</p>
							</NcNoteCard>
							<NcNoteCard v-if="testResult.success === false" type="error">
								<p>{{ t('openconnector', 'Mapping test failed') }}</p>
							</NcNoteCard>
							<NcNoteCard v-if="testResult.error" type="error">
								<p>{{ testResult.error }}</p>
							</NcNoteCard>
						</div>

						<!-- Validation Status -->
						<div v-if="testResult.result?.isValid !== undefined" class="validation-status">
							<p v-if="testResult.result.isValid" class="valid">
								<NcIconSvgWrapper inline :path="mdiCheckCircle" />
								{{ t('openconnector', 'Result is valid') }}
							</p>
							<p v-if="!testResult.result.isValid" class="invalid">
								<NcIconSvgWrapper inline :path="mdiCloseCircle" />
								{{ t('openconnector', 'Result is invalid') }}
							</p>
						</div>

						<!-- Validation Errors -->
						<div v-if="Object.keys(testResult.result?.validationErrors || {}).length" class="validation-errors">
							<h4>{{ t('openconnector', 'Validation Errors') }}</h4>
							<table>
								<thead>
									<tr>
										<th>{{ t('openconnector', 'Field') }}</th>
										<th>{{ t('openconnector', 'Errors') }}</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="(errors, field) in testResult.result.validationErrors" :key="field">
										<td>{{ field }}</td>
										<td>
											<ul>
												<li v-for="errorMessage in errors" :key="errorMessage">
													{{ errorMessage }}
												</li>
											</ul>
										</td>
									</tr>
								</tbody>
							</table>
						</div>

						<!-- Result Output -->
						<div v-if="testResult.result?.resultObject" class="result-output">
							<h4>{{ t('openconnector', 'Mapped Result') }}</h4>
							<div class="result-container">
								<pre>{{ JSON.stringify(testResult.result.resultObject, null, 2) }}</pre>
							</div>
						</div>

						<!-- Save Object Section -->
						<div v-if="testResult.result?.resultObject && !Object.keys(testResult.result?.validationErrors || {}).length" class="save-object">
							<h4>{{ t('openconnector', 'Save Result') }}</h4>
							<div class="form-group">
								<NcSelect v-bind="registerOptions"
									v-model="registerOptions.value"
									:input-label="t('openconnector', 'Register')"
									:loading="registersLoading"
									:disabled="saveObjectLoading">
									<template #no-options="{ loading: isLoading }">
										<p v-if="isLoading">
											{{ t('openconnector', 'Loading...') }}
										</p>
										<p v-else-if="!registerOptions.options?.length">
											{{ t('openconnector', 'No registers available') }}
										</p>
									</template>
									<template #option="{ label, fullRegister }">
										<div class="register-option">
											<DatabaseOutline :size="25" />
											<span>
												<h6>{{ label }}</h6>
												{{ fullRegister.description || t('openconnector', 'No description') }}
											</span>
										</div>
									</template>
								</NcSelect>

								<NcButton :disabled="saveObjectLoading || !testResult.result.isValid || !schemaOptions.value?.id || !registerOptions.value?.id || !testResult.result?.resultObject"
									type="primary"
									@click="saveObject">
									<template #icon>
										<NcLoadingIcon v-if="saveObjectLoading" :size="20" />
										<ContentSaveOutline v-if="!saveObjectLoading" :size="20" />
									</template>
									{{ t('openconnector', 'Save to Register') }}
								</NcButton>
							</div>

							<div v-if="saveObjectResult.success !== null" class="save-status">
								<NcNoteCard v-if="saveObjectResult.success" type="success">
									<p>{{ t('openconnector', 'Object saved successfully to register') }}</p>
								</NcNoteCard>
								<NcNoteCard v-if="saveObjectResult.success === false" type="error">
									<p>{{ saveObjectResult.error || t('openconnector', 'Failed to save object') }}</p>
								</NcNoteCard>
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
						<CancelIcon :size="20" />
					</template>
					{{ t('openconnector', 'Cancel') }}
				</NcButton>
				<NcButton :disabled="testLoading || !isValidName || !validJson(inputObject.value) || !validJson(mappingItem.mapping) || !validJson(mappingItem.cast, true)"
					type="secondary"
					@click="testMapping">
					<template #icon>
						<NcLoadingIcon v-if="testLoading" :size="20" />
						<TestTube v-if="!testLoading" :size="20" />
					</template>
					{{ t('openconnector', 'Test') }}
				</NcButton>
				<NcButton :disabled="loading || !isValidName || !validJson(mappingItem.mapping) || !validJson(mappingItem.cast, true)"
					type="primary"
					@click="editMapping">
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
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
	NcTextArea,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcActions,
	NcActionButton,
	NcIconSvgWrapper,
} from '@nextcloud/vue'

import { BTabs, BTab } from 'bootstrap-vue'

import { mdiCheckCircle, mdiCloseCircle } from '@mdi/js'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import TestTube from 'vue-material-design-icons/TestTube.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import DatabaseArrowRightOutline from 'vue-material-design-icons/DatabaseArrowRightOutline.vue'
import DatabaseArrowLeftOutline from 'vue-material-design-icons/DatabaseArrowLeftOutline.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'

export default {
	name: 'EditMapping',
	components: {
		NcModal,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		NcTextArea,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcActions,
		NcActionButton,
		NcIconSvgWrapper,
		BTabs,
		BTab,
		// Icons
		ContentSaveOutline,
		HelpCircleOutline,
		CogOutline,
		TestTube,
		FileTreeOutline,
		DatabaseOutline,
		DatabaseArrowRightOutline,
		DatabaseArrowLeftOutline,
		ArrowRight,
		SwapHorizontal,
		Pencil,
		Delete,
		Plus,
		CancelIcon,
	},
	setup() {
		return {
			mdiCheckCircle,
			mdiCloseCircle,
		}
	},
	data() {
		return {
			// Basic mapping data
			mappingItem: {
				name: '',
				description: '',
				mapping: '{}',
				cast: '{}',
				unset: '',
				passThrough: false,
			},
			passThroughLocal: false,
			success: null,
			loading: false,
			error: false,
			hasUpdated: false,
			closeTimeoutFunc: null,
			storeUnsubscribe: null,

			// Test functionality
			inputObject: {
				value: '',
				isValid: false,
			},
			testLoading: false,
			testResult: {
				result: {},
				success: null,
				error: false,
			},

			// Schema options
			schemasLoading: false,
			schemaOptions: {
				options: [],
				value: null,
			},

			// Register options for saving
			registersLoading: false,
			registerOptions: {
				options: [],
				value: null,
			},

			// Save object functionality
			saveObjectLoading: false,
			saveObjectResult: {
				success: null,
				error: '',
			},

			// Dialog states
			showMappingDialog: false,
			showCastDialog: false,
			showUnsetDialog: false,

			// Dialog data
			mappingDialogData: { property: '', template: '' },
			castDialogData: { property: '', castType: '' },
			unsetDialogData: { property: '' },

			// Editing states
			editingMappingRule: false,
			editingCastRule: false,
			editingUnsetRule: null,

			// Cast type options
			castTypeOptions: [
				{ id: 'string', label: 'String' },
				{ id: 'integer', label: 'Integer' },
				{ id: 'float', label: 'Float' },
				{ id: 'boolean', label: 'Boolean' },
				{ id: 'array', label: 'Array' },
				{ id: 'jsonToArray', label: 'JSON to Array' },
				{ id: 'htmlDecode', label: 'HTML Decode' },
			],
		}
	},
	computed: {
		isValidName() {
			const n = this.mappingItem?.name
			if (!n || typeof n !== 'string') return false
			return /[\p{L}\p{N}]/u.test(n)
		},
		/**
		 * Normalized mapping rules as an object regardless of source type
		 */
		mappingRules() {
			const raw = this.mappingItem?.mapping
			if (!raw) return {}
			if (typeof raw === 'string') {
				try {
					const parsed = JSON.parse(raw)
					return parsed && typeof parsed === 'object' ? parsed : {}
				} catch (e) {
					return {}
				}
			}
			if (typeof raw === 'object') return raw
			return {}
		},
		/**
		 * Normalized cast rules as an object regardless of source type
		 */
		castRules() {
			const raw = this.mappingItem?.cast
			if (!raw) return {}
			if (typeof raw === 'string') {
				try {
					const parsed = JSON.parse(raw)
					return parsed && typeof parsed === 'object' ? parsed : {}
				} catch (e) {
					return {}
				}
			}
			if (typeof raw === 'object') return raw
			return {}
		},
		/**
		 * Normalized unset rules as an array regardless of source type
		 */
		unsetRules() {
			const raw = this.mappingItem?.unset
			if (!raw) return []
			if (Array.isArray(raw)) return raw
			if (typeof raw === 'string') {
				return raw.split(/ *, */g).filter(Boolean)
			}
			return []
		},
	},
	watch: {
		// Keep local mapping fields in sync with store after edits/deletions from dialogs
		'mappingStore.item': {
			deep: true,
			handler(newItem) {
				// Only sync when the edit modal is open and a mapping exists
				if (navigationStore.modal !== 'editMapping' || !newItem) return

				// Update only the fields modified by item dialogs to avoid clobbering user input
				this.mappingItem = {
					...this.mappingItem,
					mapping: newItem.mapping ?? '{}',
					cast: newItem.cast ?? '{}',
					unset: newItem.unset ?? '',
					passThrough: !!newItem.passThrough,
				}
				this.passThroughLocal = !!newItem.passThrough
			},
		},
	},
	mounted() {
		this.initializeMappingItem()
		this.fetchSchemas()
		this.fetchRegisters()
		this.storeUnsubscribe = mappingStore.$subscribe(() => {
			if (navigationStore.modal !== 'editMapping' || !mappingStore.item) return
			this.mappingItem = {
				...this.mappingItem,
				mapping: mappingStore.item.mapping ?? '{}',
				cast: mappingStore.item.cast ?? '{}',
				unset: mappingStore.item.unset ?? '',
				passThrough: !!mappingStore.item.passThrough,
			}
			this.passThroughLocal = !!mappingStore.item.passThrough
		})
	},
	updated() {
		if (navigationStore.modal === 'editMapping' && !this.hasUpdated) {
			this.initializeMappingItem()
			this.hasUpdated = true
		}
	},
	unmounted() {
		this.storeUnsubscribe && this.storeUnsubscribe()
	},
	destroyed() {
		this.storeUnsubscribe && this.storeUnsubscribe()
	},
	methods: {
		/**
		 * Initialize the mapping item with data from the store or default values
		 */
		initializeMappingItem() {
			if (mappingStore.item?.id) {
				// Clone into a plain, mutable object to avoid readonly assignments from entity instances
				const src = mappingStore.item
				this.mappingItem = {
					name: src.name || '',
					description: src.description || '',
					mapping: src.mapping ?? '{}',
					cast: src.cast ?? '{}',
					unset: src.unset ?? '',
					passThrough: !!src.passThrough,
				}
				this.passThroughLocal = !!this.mappingItem.passThrough
			} else {
				this.mappingItem = {
					name: '',
					description: '',
					mapping: '{}',
					cast: '{}',
					unset: '',
					passThrough: false,
				}
				this.passThroughLocal = false
			}
		},

		/**
		 * Fetch available schemas from OpenRegister
		 */
		async fetchSchemas() {
			this.schemasLoading = true

			try {
				const response = await fetch('/index.php/apps/openregister/api/schemas', {
					headers: {
						accept: '*/*',
						'x-requested-with': 'XMLHttpRequest',
					},
					method: 'GET',
					credentials: 'include',
				})

				if (!response.ok) return

				const responseData = (await response.json()).results

				this.schemaOptions = {
					options: responseData.map((schema) => ({
						id: schema.id,
						label: schema.title,
						fullSchema: schema,
					})),
					value: null,
				}
			} catch (error) {
				console.error('Failed to fetch schemas:', error)
			} finally {
				this.schemasLoading = false
			}
		},

		/**
		 * Fetch available registers for saving objects
		 */
		async fetchRegisters() {
			this.registersLoading = true

			try {
				const { data } = await mappingStore.getMappingObjects()

				if (!data?.availableRegisters) return

				this.registerOptions = {
					options: data.availableRegisters.map((register) => ({
						id: register.id,
						label: register.title,
						fullRegister: register,
					})),
					value: null,
				}
			} catch (error) {
				console.error('Failed to fetch registers:', error)
			} finally {
				this.registersLoading = false
			}
		},

		/**
		 * Update input object validation status
		 * @param {Event} event - The input event object
		 */
		updateInputObject(event) {
			this.inputObject = {
				value: event.target.value,
				isValid: this.validJson(event.target.value),
			}
		},

		/**
		 * Test the mapping with the provided input
		 */
		async testMapping() {
			this.testLoading = true
			this.testResult = {
				result: {},
				success: null,
				error: false,
			}

			try {
				const mappingData = {
					...mappingStore.item,
					name: this.mappingItem.name,
					description: this.mappingItem.description,
					mapping: this.mappingRules,
					cast: Object.keys(this.castRules).length ? this.castRules : null,
					unset: this.unsetRules,
					passThrough: this.mappingItem.passThrough,
				}

				const { response, data } = await mappingStore.testMapping({
					mapping: mappingData,
					inputObject: JSON.parse(this.inputObject.value),
					schema: this.schemaOptions.value?.id,
				})

				this.testResult.success = response.ok
				this.testResult.result = data
			} catch (error) {
				this.testResult.error = error.message || this.t('openconnector', 'An error occurred while testing the mapping')
			} finally {
				this.testLoading = false
			}
		},

		/**
		 * Save the test result object to a register
		 */
		async saveObject() {
			this.saveObjectLoading = true
			this.saveObjectResult = {
				success: null,
				error: '',
			}

			try {
				const { response } = await mappingStore.saveMappingObject({
					object: this.testResult.result.resultObject,
					register: this.registerOptions.value.id,
					schema: this.schemaOptions.value.id,
				})

				this.saveObjectResult.success = response.ok
			} catch (error) {
				console.error('Failed to save object:', error)
				this.saveObjectResult.success = false
				this.saveObjectResult.error = error.message || this.t('openconnector', 'An error occurred')
			} finally {
				this.saveObjectLoading = false

				// Clear status after 3 seconds
				setTimeout(() => {
					this.saveObjectResult = {
						success: null,
						error: '',
					}
				}, 3000)
			}
		},

		/**
		 * Reset mapping to original values
		 */
		resetMapping() {
			this.initializeMappingItem()
			this.testResult = {
				result: {},
				success: null,
				error: false,
			}
			this.inputObject = {
				value: '',
				isValid: false,
			}
		},

		/**
		 * Close the modal and reset state
		 */
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeTimeoutFunc)
			this.success = null
			this.loading = false
			this.error = false
			this.hasUpdated = false
			this.resetMapping()
			// also clear any editing context for dialogs
			mappingStore.clearEditingContext && mappingStore.clearEditingContext()
		},

		/**
		 * Save the mapping
		 */
		async editMapping() {
			this.loading = true

			try {
				const mappingItem = new Mapping({
					...mappingStore.item,
					...this.mappingItem,
					mapping: this.mappingRules,
					cast: this.castRules,
					unset: this.unsetRules,
				})

				const { response } = await mappingStore.save(mappingItem)

				this.success = response.ok
				if (this.success) {
					this.closeTimeoutFunc = setTimeout(this.closeModal, 2000)
				}
			} catch (error) {
				this.error = error.message || this.t('openconnector', 'An error occurred while saving the mapping')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Validate JSON string
		 * @param {string|object} jsonString - The JSON value to validate
		 * @param {boolean} optional - Whether the field is optional
		 * @return {boolean} - Whether the JSON is valid
		 */
		validJson(jsonString, optional = false) {
			if (optional && !jsonString) {
				return true
			}

			// Treat non-strings (already objects/arrays) as valid
			if (typeof jsonString !== 'string') {
				return true
			}

			try {
				JSON.parse(jsonString)
				return true
			} catch (e) {
				return false
			}
		},

		// Mapping Rule Dialog Methods
		/**
		 * Open dialog to add a new mapping rule
		 */
		addMappingRule() {
			mappingStore.setEditingMode('mapping')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingMappingKey(null)
			navigationStore.setDialog('editMappingItem')
		},

		/**
		 * Open dialog to edit an existing mapping rule
		 * @param {string} property - The property name
		 * @param {string} template - The template value
		 */
		editMappingRule(property, template) {
			this.mappingDialogData = { property, template }
			this.editingMappingRule = true
			this.showMappingDialog = true
		},

		/**
		 * Save mapping rule and update the mapping JSON
		 */
		saveMappingRule() {
			if (!this.mappingDialogData.property || !this.mappingDialogData.template) {
				return
			}

			const mappingRules = { ...this.mappingRules }
			mappingRules[this.mappingDialogData.property] = this.mappingDialogData.template
			this.mappingItem.mapping = JSON.stringify(mappingRules, null, 2)
			this.closeMappingDialog()
		},

		/**
		 * Delete a mapping rule
		 * @param {string} property - The property to delete
		 */
		deleteMappingRule(property) {
			const mappingRules = { ...this.mappingRules }
			delete mappingRules[property]
			this.mappingItem.mapping = JSON.stringify(mappingRules, null, 2)
		},

		/**
		 * Close mapping rule dialog
		 */
		closeMappingDialog() {
			this.showMappingDialog = false
			this.mappingDialogData = { property: '', template: '' }
			this.editingMappingRule = false
		},

		// Cast Rule Dialog Methods
		/**
		 * Open dialog to add a new cast rule
		 */
		addCastRule() {
			this.castDialogData = { property: '', castType: '' }
			this.editingCastRule = false
			mappingStore.setEditingMode('cast')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingCastKey(null)
			navigationStore.setDialog('editMappingItem')
		},

		/**
		 * Open dialog to edit an existing cast rule
		 * @param {string} property - The property name
		 * @param {string} castType - The cast type
		 */
		editCastRule(property, castType) {
			this.castDialogData = { property, castType }
			this.editingCastRule = true
			this.showCastDialog = true
		},

		/**
		 * Save cast rule and update the cast JSON
		 */
		saveCastRule() {
			if (!this.castDialogData.property || !this.castDialogData.castType) {
				return
			}

			const castRules = { ...this.castRules }
			castRules[this.castDialogData.property] = this.castDialogData.castType
			this.mappingItem.cast = JSON.stringify(castRules, null, 2)
			this.closeCastDialog()
		},

		/**
		 * Delete a cast rule
		 * @param {string} property - The property to delete
		 */
		deleteCastRule(property) {
			const castRules = { ...this.castRules }
			delete castRules[property]
			this.mappingItem.cast = JSON.stringify(castRules, null, 2)
		},

		/**
		 * Close cast rule dialog
		 */
		closeCastDialog() {
			this.showCastDialog = false
			this.castDialogData = { property: '', castType: '' }
			this.editingCastRule = false
		},

		onPassThroughChange(val) {
			// update local and backing object; mappingItem is a plain object now
			this.passThroughLocal = !!val
			this.mappingItem = {
				...this.mappingItem,
				passThrough: !!val,
			}
		},

		// Unset Rule Dialog Methods
		/**
		 * Open dialog to add a new unset property
		 */
		addUnsetRule() {
			this.unsetDialogData = { property: '' }
			this.editingUnsetRule = null
			mappingStore.setEditingMode('unset')
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			mappingStore.setMappingUnsetKey(null)
			navigationStore.setDialog('editMappingItem')
		},

		/**
		 * Open dialog to edit an existing unset property
		 * @param {number} index - The index of the property
		 * @param {string} property - The property name
		 */
		editUnsetRule(index, property) {
			this.unsetDialogData = { property }
			this.editingUnsetRule = index
			this.showUnsetDialog = true
		},

		/**
		 * Save unset rule and update the unset string
		 */
		saveUnsetRule() {
			if (!this.unsetDialogData.property) {
				return
			}

			const unsetRules = [...this.unsetRules]
			if (this.editingUnsetRule !== null) {
				unsetRules[this.editingUnsetRule] = this.unsetDialogData.property
			} else {
				unsetRules.push(this.unsetDialogData.property)
			}
			this.mappingItem.unset = unsetRules.join(', ')
			this.closeUnsetDialog()
		},

		/**
		 * Delete an unset property
		 * @param {number} index - The index to delete
		 */
		deleteUnsetRule(index) {
			const unsetRules = [...this.unsetRules]
			unsetRules.splice(index, 1)
			this.mappingItem.unset = unsetRules.join(', ')
		},

		/**
		 * Close unset rule dialog
		 */
		closeUnsetDialog() {
			this.showUnsetDialog = false
			this.unsetDialogData = { property: '' }
			this.editingUnsetRule = null
		},

		openEditMappingItemDialog(mode, key) {
			mappingStore.setEditingMode(mode)
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			if (mode === 'mapping') {
				mappingStore.setMappingMappingKey(key)
			} else if (mode === 'cast') {
				mappingStore.setMappingCastKey(key)
			} else if (mode === 'unset') {
				mappingStore.setMappingUnsetKey(key)
			}
			navigationStore.setDialog('editMappingItem')
		},

		openDeleteMappingItemDialog(mode, key) {
			mappingStore.setEditingMode(mode)
			mappingStore.setEditingMappingId(mappingStore.item?.id)
			if (mode === 'mapping') {
				mappingStore.setMappingMappingKey(key)
			} else if (mode === 'cast') {
				mappingStore.setMappingCastKey(key)
			} else if (mode === 'unset') {
				mappingStore.setMappingUnsetKey(key)
			}
			navigationStore.setDialog('deleteMappingItem')
		},
	},
}
</script>

<!-- All CSS is provided by main.css -->
<style scoped>
:deep(.modal-container) {
	width: unset !important;
	max-width: 1200px !important;
}

/* Transform tabs styling and spacing */
:deep(.transform-tabs .nav) {
	display: flex;
	justify-content: center;
	gap: 8px;
	margin-bottom: 12px;
	padding-bottom: 12px;
	padding-top: 12px;
	border-bottom: 1px solid var(--color-border);
}

:deep(.transform-tabs .nav-link) {
	padding: 8px;
	font-weight: 700;
	transition: .2s;
}

:deep(.transform-tabs .nav-link.active) {
	background: var(--color-background-hover);
	border-bottom: 2px solid var(--color-primary);
	color: var(--color-primary);
}

/* Nice table styling similar to a log/source view */
.mapping-property-table {
	margin-top: 8px;
}
.statisticsTable {
	width: 100%;
	table-layout: fixed;
	margin-bottom: 12px;
}
.statisticsTable thead tr th {
	text-align: left;
	font-weight: 700;
	padding: 10px 12px;
	background-color: var(--color-background-hover);
}
.statisticsTable tbody tr td {
	padding: 10px 12px;
	background-color: var(--color-background);
	vertical-align: middle;
}

.statisticsTable tbody tr:nth-child(even) td {
	background-color: var(--color-background-hover);
}
.statisticsTable tbody tr {
	transition: background 0.15s ease-in-out;
}
.statisticsTable tbody tr:hover td {
	background-color: var(--color-background-hover);
}

/* Cells */
.property-cell {
	align-items: center;
	gap: 6px;
	text-overflow: ellipsis;
	overflow: hidden;
	white-space: nowrap;
}
.mapping-property {
	flex: 1 1 auto;
	min-width: 0;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.template-cell {
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.actions-cell {
	width: 120px;
	text-align: right;
	white-space: nowrap;
}

/* Inline icon button for quick edit */
.icon-inline {
	background: transparent;
	border: none;
	padding: 2px;
	cursor: pointer;
	border-radius: 6px;
	color: inherit;
	transition: background 0.15s ease-in-out, opacity 0.15s ease-in-out;
}
.icon-inline:hover {
	background: rgba(255, 255, 255, 0.12);
	opacity: 0.95;
}

/* Add button spacing */
.table-container .nc-button {
	margin-top: 8px;
}

.unset-disabled-note {
	margin-top: 6px;
	opacity: 0.8;
}

/* ====================== */
/* Mapping Modal Styles   */
/* ====================== */

/* Three-Column Layout for Mapping Modal */
.mapping-layout {
	display: flex !important;
	flex-direction: row !important;
	gap: 20px;
	margin: 15px 0;
	min-height: 600px;
	width: 100%;
	align-items: stretch;
}

.mapping-section {
	flex: 1 1 33.333%;
	min-width: 350px;
	max-width: none;
	display: flex;
	flex-direction: column;
	gap: 15px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background-color: var(--color-main-background);
}

.mapping-section .section-header {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 12px;
	background-color: var(--color-background-hover);
	border-bottom: 1px solid var(--color-border);
	border-radius: 8px 8px 0 0;
}

.mapping-section .section-header h3 {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.mapping-section .section-content {
	flex: 1;
	padding: 12px;
	overflow-y: auto;
}

.mapping-section .info-card {
	padding: 12px;
	background-color: var(--color-background-soft);
	border-radius: 6px;
	margin-bottom: 15px;
}

.mapping-section .section-description {
	margin: 0;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.mapping-section .subsection {
	margin-bottom: 15px;
}

.mapping-section .subsection h4 {
	margin: 0 0 10px 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-text-light);
}

.mapping-section .form-group {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-bottom: 15px;
}

/* Section-specific styling */
.input-section .section-header {
	background-color: rgba(var(--color-primary-rgb), 0.1);
}

.transformation-section .section-header {
	background-color: rgba(var(--color-warning-rgb), 0.1);
}

.output-section .section-header {
	background-color: rgba(var(--color-success-rgb), 0.1);
}

/* Center column cards for transformation section */
.transformation-section {
	border: none !important;
	background-color: transparent !important;
}

.center-card {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background-color: var(--color-main-background);
	display: flex;
	flex-direction: column;
}

.center-card .section-header {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 12px;
	background-color: var(--color-background-hover);
	border-bottom: 1px solid var(--color-border);
	border-radius: 8px 8px 0 0;
}

.center-card .section-header h3 {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.center-card .section-content {
	flex: 1;
	padding: 12px;
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

/* Responsive Design for Mapping Modal */
@media (max-width: 1400px) {
	.mapping-layout {
		flex-direction: column !important;
	}

	.mapping-section {
		flex: none;
		min-width: auto;
		max-width: none;
	}
}
</style>

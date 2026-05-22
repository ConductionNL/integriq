<script setup>
import { mappingStore } from '../../../store/store.js'
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<div>
		<h4>{{ t('openconnector', 'Test result') }}</h4>

		<div class="content">
			<NcNoteCard v-if="mappingTest.success" type="success">
				<p>{{ t('openconnector', 'Mapping tested successfully') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="mappingTest.success === false" type="error">
				<p>{{ t('openconnector', 'Mapping failed to test') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="mappingTest.error" type="error">
				<p>{{ mappingTest.error }}</p>
			</NcNoteCard>

			<div class="result">
				<pre><!-- do NOT remove this comment
					-->{{ JSON.stringify(mappingTest.result?.resultObject, null, 2) }}
				</pre>
			</div>

			<div v-if="mappingTest.result?.isValid !== undefined">
				<p v-if="mappingTest.result.isValid" class="valid">
					<NcIconSvgWrapper inline :path="mdiCheckCircle" /> {{ t('openconnector', 'input object is valid') }}
				</p>
				<p v-if="!mappingTest.result.isValid" class="invalid">
					<NcIconSvgWrapper inline :path="mdiCloseCircle" /> {{ t('openconnector', 'input object is invalid') }}
				</p>
			</div>

			<div v-if="Object.keys(mappingTest.result?.validationErrors || {}).length" class="validation-errors">
				<table>
					<thead>
						<tr>
							<th>{{ t('openconnector', 'Field') }}</th>
							<th>{{ t('openconnector', 'Errors') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(errors, field) in mappingTest.result.validationErrors" :key="field">
							<td>{{ field }}</td>
							<td>
								<ul>
									<li v-for="error in errors" :key="error">
										{{ error }}
									</li>
								</ul>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div v-if="!Object.keys(mappingTest.result?.validationErrors || {}).length">
				<h4>{{ t('openconnector', 'Save object') }}</h4>

				<NcSelect v-bind="registers"
					v-model="registers.value"
					:input-label="t('openconnector', 'Register')"
					:loading="fetchRegistersLoading"
					:disabled="!openRegisterInstalled || saveObjectLoading"
					required>
					<!-- eslint-disable-next-line vue/no-unused-vars vue/no-template-shadow  -->
					<template #no-options="{ search, searching, loading }">
						<p v-if="loading">
							{{ t('openconnector', 'Loading...') }}
						</p>
						<p v-if="!loading && !registers.options?.length">
							{{ t('openconnector', 'No registers available') }}
						</p>
					</template>
					<!-- eslint-disable-next-line vue/no-unused-vars  -->
					<template #option="{ id, label, fullRegister }">
						<div class="custom-select-option">
							<DatabaseOutline :size="25" />
							<span>
								<h6 style="margin: 0">
									{{ label }}
								</h6>
								{{ fullRegister.description || t('openconnector', 'No description') }}
							</span>
						</div>
					</template>
				</NcSelect>

				<NcButton :disabled="saveObjectLoading // loading state for saving object
						|| !mappingTest.result.isValid // result is invalid
						|| !schema.selected?.id // no schema selected
						|| !registers.value?.id // no register selected
						|| !mappingTest.result?.resultObject /* result object does not exist */"
					type="primary"
					class="single-modal-action"
					@click="saveObject()">
					<template #icon>
						<NcLoadingIcon v-if="saveObjectLoading" :size="20" />
						<ContentSaveOutline v-if="!saveObjectLoading" :size="20" />
					</template>
					{{ t('openconnector', 'Save') }}
				</NcButton>

				<NcNoteCard v-if="saveObjectSuccess === true" type="success">
					<p>{{ t('openconnector', 'Object saved successfully') }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="saveObjectSuccess === false" type="error">
					<p>{{ saveObjectError || t('openconnector', 'An error occurred') }}</p>
				</NcNoteCard>
			</div>
		</div>
	</div>
</template>

<script>
import {
	NcNoteCard,
	NcIconSvgWrapper,
	NcSelect,
	NcButton,
	NcLoadingIcon,
} from '@nextcloud/vue'

import { mdiCheckCircle, mdiCloseCircle } from '@mdi/js'

import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'

export default {
	name: 'TestMappingResult',
	components: {
		NcNoteCard,
		NcIconSvgWrapper,
		NcSelect,
		NcButton,
		NcLoadingIcon,
		// icons
		DatabaseOutline,
		ContentSaveOutline,
	},
	props: {
		mappingTest: {
			type: Object,
			required: true,
		},
		schema: {
			type: Object,
			required: true,
		},
	},
	setup() {
		return {
			mdiCheckCircle,
			mdiCloseCircle,
		}
	},
	data() {
		return {
			openRegisterInstalled: false,
			fetchRegistersLoading: false,
			rawRegisters: [],
			registers: {
				options: [],
				value: null,
			},
			saveObjectLoading: false,
			saveObjectSuccess: null,
			saveObjectError: '',
		}
	},
	mounted() {
		this.fetchRegisters()
	},
	methods: {
		fetchRegisters() {
			this.fetchRegistersLoading = true

			mappingStore.getMappingObjects()
				.then(({ response, data }) => {
					this.openRegisterInstalled = data.openRegisters
					if (!data.openRegisters) return // if open register is not installed, we don't need the rest of this code

					this.rawRegisters = data.availableRegisters

					this.registers.options = data.availableRegisters.map((register) => ({
						id: register.id,
						label: register.title,
						fullRegister: register,
					}))
				})
				.catch((error) => {
					console.error(error)
				})
				.finally(() => {
					this.fetchRegistersLoading = false
				})
		},
		saveObject() {
			this.saveObjectLoading = true
			this.saveObjectSuccess = null
			this.saveObjectError = ''

			mappingStore.saveMappingObject({
				object: this.mappingTest.result.resultObject,
				register: this.registers.value.id,
				schema: this.schema.selected.id,
			})
				.then(({ response }) => {
					this.saveObjectSuccess = response.ok
				})
				.catch((error) => {
					console.error(error)
					this.saveObjectSuccess = false
					this.saveObjectError = error
				})
				.finally(() => {
					this.saveObjectLoading = false

					// cleanup after 3 seconds
					setTimeout(() => {
						this.saveObjectSuccess = null
						this.saveObjectError = ''
					}, 3000)
				})
		},
	},
}
</script>

<style scoped>
.content {
	text-align: left;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.result {
	color: var(--color-main-text);
	background-color: var(--color-main-background);
	min-width: 0;
	border-radius: var(--border-radius-large);
	box-shadow: 0 0 10px var(--color-box-shadow);
	height: fit-content;
	padding: 15px;
	margin: 20px 0.5rem;
}

.result pre {
	white-space: pre-wrap;
	word-break: break-word;
	overflow-wrap: anywhere;
}

.valid {
	color: var(--color-success);
}

.invalid {
	color: var(--color-error);
}

.validation-errors {
	margin-block-start: 0.5rem;
	overflow-x: auto;
	width: 100%;
}

.validation-errors table {
	border: 1px solid grey;
	border-collapse: collapse;
}

.validation-errors th, .validation-errors td {
	border: 1px solid grey;
	padding: 8px;
}

.v-select {
	min-width: auto;
	width: 100%;
}

/* custom select option */
.custom-select-option {
	display: flex;
	align-items: center;
	gap: 10px;
}

.custom-select-option > .material-design-icon {
	margin-block-start: 2px;
}

.custom-select-option > h6 {
	line-height: 0.8;
}
/* truncate long option labels/descriptions */
.custom-select-option > span {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.custom-select-option > span h6,
.custom-select-option > span {
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
</style>

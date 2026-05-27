<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Test mapping modal — manifest-driven replacement for the legacy
  src/modals/MappingTest/TestMapping.vue (142 LoC + 3 sub-widgets
  ~1200 LoC). Trimmed to one stacked layout: input JSON textarea, the
  pre-selected mapping (passed in by the row-action handler), an optional
  schema picker for validation, and a side-by-side result panel.

  Closes #835.

  Backend contract is unchanged: POST /api/mappings/test accepts
  `inputObject` (string JSON or object), `mapping` (object), optional
  `schema` (id) + `validation` (bool). Response shape:
    { resultObject, isValid, validationErrors }
-->
<template>
	<NcModal v-if="open"
		label-id="testMappingModal"
		size="large"
		@close="onClose">
		<div class="cn-test-mapping-modal">
			<h2>{{ t('openconnector', 'Test mapping') }}</h2>

			<NcNoteCard v-if="mappingName" type="info">
				<p>{{ t('openconnector', 'Testing mapping: {name}', { name: mappingName }) }}</p>
			</NcNoteCard>

			<div class="cn-test-mapping-modal__panes">
				<!-- Left: input -->
				<section class="cn-test-mapping-modal__pane">
					<h3>{{ t('openconnector', 'Input') }}</h3>
					<label for="cn-test-mapping-input">
						{{ t('openconnector', 'Input object (JSON)') }}
					</label>
					<textarea id="cn-test-mapping-input"
						v-model="inputJson"
						class="cn-test-mapping-modal__textarea"
						rows="14"
						spellcheck="false"
						:placeholder="placeholder" />
					<p v-if="inputError" class="cn-test-mapping-modal__error">
						{{ inputError }}
					</p>

					<label for="cn-test-mapping-schema">
						{{ t('openconnector', 'Validate against schema (optional)') }}
					</label>
					<NcSelect id="cn-test-mapping-schema"
						:aria-label-combobox="t('openconnector', 'Validate against schema (optional)')"
						v-model="selectedSchema"
						:options="schemaOptions"
						:loading="schemasLoading"
						:placeholder="t('openconnector', 'No validation')"
						:clearable="true"
						input-id="cn-test-mapping-schema" />

					<div class="cn-test-mapping-modal__actions">
						<NcButton @click="onClose">
							{{ t('openconnector', 'Close') }}
						</NcButton>
						<NcButton type="primary"
							:disabled="running || !canRun"
							@click="runTest">
							<template #icon>
								<NcLoadingIcon v-if="running" :size="20" />
								<PlayOutlineIcon v-else :size="20" />
							</template>
							{{ t('openconnector', 'Run test') }}
						</NcButton>
					</div>
				</section>

				<!-- Right: result -->
				<section class="cn-test-mapping-modal__pane">
					<h3>{{ t('openconnector', 'Result') }}</h3>
					<div v-if="!hasResult && !runError" class="cn-test-mapping-modal__empty">
						{{ t('openconnector', 'Run the test to see the result here.') }}
					</div>
					<NcNoteCard v-if="runError" type="error">
						<p>{{ runError }}</p>
					</NcNoteCard>
					<template v-if="hasResult">
						<NcNoteCard v-if="selectedSchema && validation.isValid" type="success">
							<p>{{ t('openconnector', 'Schema validation passed.') }}</p>
						</NcNoteCard>
						<NcNoteCard v-else-if="selectedSchema && !validation.isValid" type="warning">
							<p>{{ t('openconnector', 'Schema validation failed.') }}</p>
							<ul v-if="validation.errors.length">
								<li v-for="(err, idx) in validation.errors" :key="idx">
									{{ formatValidationError(err) }}
								</li>
							</ul>
						</NcNoteCard>
						<pre class="cn-test-mapping-modal__pre">{{ resultJson }}</pre>
					</template>
				</section>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcModal,
	NcButton,
	NcSelect,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import PlayOutlineIcon from 'vue-material-design-icons/PlayOutline.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'TestMappingModal',

	components: {
		NcModal,
		NcButton,
		NcSelect,
		NcLoadingIcon,
		NcNoteCard,
		PlayOutlineIcon,
	},

	props: {
		/** Whether the modal is mounted/visible. */
		open: { type: Boolean, default: false },
		/** Pre-selected mapping row to test (from the row-action context). */
		mapping: { type: Object, default: null },
	},

	data() {
		return {
			inputJson: '{\n  "example": "value"\n}',
			selectedSchema: null,
			schemaOptions: [],
			schemasLoading: false,
			running: false,
			runError: '',
			inputError: '',
			result: null,
			validation: { isValid: true, errors: [] },
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-4 */
		mappingName() {
			return this.mapping?.name || ''
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-4 */
		placeholder() {
			return '{\n  "key": "value"\n}'
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-4 */
		canRun() {
			return !!this.mapping && this.inputJson.trim().length > 0
		},
		hasResult() {
			return this.result !== null
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-4 */
		resultJson() {
			try {
				return JSON.stringify(this.result, null, 2)
			} catch (_e) {
				return String(this.result)
			}
		},
	},

	watch: {
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-4 */
		open(value) {
			if (value) {
				this.resetState()
				this.fetchSchemas()
			}
		},
	},

	methods: {
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-4 */
		onClose() {
			this.$emit('close')
		},

		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-4 */
		resetState() {
			this.runError = ''
			this.inputError = ''
			this.result = null
			this.validation = { isValid: true, errors: [] }
		},

		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-4 */
		async fetchSchemas() {
			this.schemasLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/schemas'),
				)
				const list = Array.isArray(response.data?.results)
					? response.data.results
					: Array.isArray(response.data)
						? response.data
						: []
				this.schemaOptions = list.map((schema) => ({
					id: schema.id || schema.uuid,
					label: schema.title || schema.name || schema.slug,
				}))
			} catch (err) {
				// Silent fallback — schema picker just stays empty. Mapping
				// test still runs without validation.
				// eslint-disable-next-line no-console
				console.warn('[TestMappingModal] schema fetch failed', err)
				this.schemaOptions = []
			} finally {
				this.schemasLoading = false
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-4 */
		async runTest() {
			this.resetState()
			let parsedInput = null
			const raw = this.inputJson.trim()
			try {
				parsedInput = raw.length > 0 ? JSON.parse(raw) : {}
			} catch (parseErr) {
				this.inputError = t(
					'openconnector',
					'Input is not valid JSON: {message}',
					{ message: parseErr.message },
				)
				return
			}

			this.running = true
			try {
				const payload = {
					inputObject: parsedInput,
					mapping: this.mapping,
				}
				if (this.selectedSchema?.id) {
					payload.schema = this.selectedSchema.id
					payload.validation = true
				}
				const response = await axios.post(
					generateUrl('/apps/openconnector/api/mappings/test'),
					payload,
				)
				this.result = response.data?.resultObject ?? response.data
				this.validation = {
					isValid: response.data?.isValid !== false,
					errors: response.data?.validationErrors ?? [],
				}
				showSuccess(t('openconnector', 'Mapping test completed.'))
			} catch (err) {
				const message = err?.response?.data?.message || err?.message || ''
				this.runError = t('openconnector', 'Mapping test failed')
					+ (message ? `: ${message}` : '')
				showError(this.runError)
			} finally {
				this.running = false
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-4 */
		formatValidationError(err) {
			if (typeof err === 'string') return err
			return err?.message || err?.error || JSON.stringify(err)
		},
	},
}
</script>

<style scoped>
.cn-test-mapping-modal {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 20px;
	min-width: 720px;
	max-width: 1100px;
}

.cn-test-mapping-modal__panes {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
	gap: 16px;
}

.cn-test-mapping-modal__pane {
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-width: 0;
}

.cn-test-mapping-modal__textarea {
	width: 100%;
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
	resize: vertical;
	padding: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.cn-test-mapping-modal__error {
	color: var(--color-error);
	font-size: 12px;
	margin: 0;
}

.cn-test-mapping-modal__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.cn-test-mapping-modal__pre {
	background: var(--color-background-dark);
	color: var(--color-main-text);
	padding: 12px;
	border-radius: var(--border-radius);
	overflow: auto;
	max-height: 360px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 12px;
	white-space: pre-wrap;
	word-break: break-word;
}

.cn-test-mapping-modal__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>

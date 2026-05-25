<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  FetchFileForm — SynchronizationService::processFetchFileRule reads
  `source`, `filePath`, `endpoint`, `objectIdPath`, `originIdPath`,
  `contentPath`, `filenamePath`, `fileExtension`, `subObjectFilepath`,
  `tags`, `autoShare`, `sourceConfiguration` (JSON blob).

  Source picker resolves OpenConnector sources via OpenRegister.
-->
<template>
	<div class="action-form">
		<label class="action-form__label">{{ t('openconnector', 'Source') }}</label>
		<NcSelect
			data-testid="action-form-fetch-source"
			:value="selectedSource"
			:options="sourceOptions"
			:loading="sourcesLoading"
			:placeholder="t('openconnector', 'Select a source')"
			@input="onSourcePick" />

		<NcTextField
			:label="t('openconnector', 'File path (dot path)')"
			:value="value.filePath || ''"
			placeholder="body.attachment.url"
			@update:value="(next) => patch('filePath', next)" />
		<NcTextField
			:label="t('openconnector', 'Endpoint (optional)')"
			:value="value.endpoint || ''"
			placeholder="https://upstream/file/123"
			@update:value="(next) => patch('endpoint', next)" />
		<NcTextField
			:label="t('openconnector', 'Object ID path (optional)')"
			:value="value.objectIdPath || ''"
			placeholder="body.id"
			@update:value="(next) => patch('objectIdPath', next)" />
		<NcTextField
			:label="t('openconnector', 'Origin ID path (optional)')"
			:value="value.originIdPath || ''"
			placeholder="body.origin.id"
			@update:value="(next) => patch('originIdPath', next)" />
		<NcTextField
			:label="t('openconnector', 'Content path (optional)')"
			:value="value.contentPath || ''"
			placeholder="body.attachment.content"
			@update:value="(next) => patch('contentPath', next)" />
		<NcTextField
			:label="t('openconnector', 'Filename path (optional)')"
			:value="value.filenamePath || ''"
			placeholder="body.attachment.name"
			@update:value="(next) => patch('filenamePath', next)" />
		<NcTextField
			:label="t('openconnector', 'File extension (optional)')"
			:value="value.fileExtension || ''"
			placeholder="pdf"
			@update:value="(next) => patch('fileExtension', next)" />
		<NcTextField
			:label="t('openconnector', 'Sub-object filepath (optional)')"
			:value="value.subObjectFilepath || ''"
			placeholder="body.objects.0.url"
			@update:value="(next) => patch('subObjectFilepath', next)" />
		<NcTextField
			:label="t('openconnector', 'Tags (comma-separated)')"
			:value="csv(value.tags)"
			placeholder="invoice,inbox"
			@update:value="(next) => patch('tags', toArray(next))" />
		<NcCheckboxRadioSwitch
			type="switch"
			:checked="!!value.autoShare"
			@update:checked="(next) => patch('autoShare', !!next)">
			{{ t('openconnector', 'Auto-share fetched files') }}
		</NcCheckboxRadioSwitch>

		<label class="action-form__label" :for="'rule-fetch-source-config-' + uid">
			{{ t('openconnector', 'Source configuration (JSON, optional)') }}
		</label>
		<textarea
			:id="'rule-fetch-source-config-' + uid"
			class="action-form__textarea action-form__textarea--code"
			:value="sourceConfigDraft"
			spellcheck="false"
			rows="5"
			placeholder="[]"
			@input="onSourceConfigInput" />
		<span v-if="sourceConfigError" class="action-form__helper action-form__helper--error">
			{{ sourceConfigError }}
		</span>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import { fetchOpenRegisterCollection, patchMethod, valueProp } from './shared.js'

let uidCounter = 0

export default {
	name: 'FetchFileForm',
	components: { NcCheckboxRadioSwitch, NcSelect, NcTextField },
	props: { ...valueProp },
	data() {
		return {
			uid: ++uidCounter,
			sourceOptions: [],
			sourcesLoading: false,
			sourceConfigDraft: '',
			sourceConfigError: '',
		}
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3 */
		selectedSource() {
			const id = String(this.value?.source || '')
			if (!id) return null
			return this.sourceOptions.find((opt) => opt.id === id) ?? { id, label: id }
		},
	},
	watch: {
		// Keep textarea in sync if parent feeds in a value from a remote
		// fetch. Drift is rare because the rule loads once.
		'value.sourceConfiguration'(next) {
			const serialized = this.serialiseSourceConfig(next)
			if (serialized !== this.sourceConfigDraft) {
				this.sourceConfigDraft = serialized
			}
		},
	},
	/** @spec openspec/changes/retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3 */
	async mounted() {
		this.sourcesLoading = true
		this.sourceOptions = await fetchOpenRegisterCollection('source')
		this.sourcesLoading = false
		this.sourceConfigDraft = this.serialiseSourceConfig(this.value?.sourceConfiguration)
	},
	methods: {
		patch: patchMethod(),
		/** @spec openspec/changes/retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3 */
		onSourcePick(option) {
			this.patch('source', option?.id ? String(option.id) : '')
		},
		/** @spec openspec/changes/retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3 */
		csv(value) {
			return Array.isArray(value) ? value.join(',') : (value || '')
		},
		/** @spec openspec/changes/retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3 */
		toArray(text) {
			return (text || '').split(',').map((entry) => entry.trim()).filter(Boolean)
		},
		/** @spec openspec/changes/retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3 */
		serialiseSourceConfig(value) {
			if (value === undefined || value === null) return ''
			if (typeof value === 'string') return value
			try { return JSON.stringify(value, null, 2) } catch (_e) { return String(value) }
		},
		/** @spec openspec/changes/retrofit-2026-05-25-rule-editor-ui/tasks.md#task-3 */
		onSourceConfigInput(event) {
			const raw = event.target.value
			this.sourceConfigDraft = raw
			if (raw.trim().length === 0) {
				this.sourceConfigError = ''
				const next = { ...(this.value || {}) }
				delete next.sourceConfiguration
				this.$emit('update:value', next)
				return
			}
			try {
				const parsed = JSON.parse(raw)
				this.sourceConfigError = ''
				this.patch('sourceConfiguration', parsed)
			} catch (parseErr) {
				this.sourceConfigError = this.t('openconnector', 'Invalid JSON: {message}', { message: parseErr.message })
			}
		},
	},
}
</script>

<style scoped>
.action-form { display: flex; flex-direction: column; gap: 10px; }

.action-form__label { font-weight: bold; }

.action-form__helper { color: var(--color-text-maxcontrast); font-size: 12px; }

.action-form__helper--error { color: var(--color-error); }

.action-form__textarea {
	width: 100%; padding: 8px; font-family: var(--font-face, sans-serif);
	font-size: 14px; background: var(--color-main-background); color: var(--color-main-text);
	border: 1px solid var(--color-border); border-radius: var(--border-radius); resize: vertical;
}

.action-form__textarea--code { font-family: var(--font-face-monospace, monospace); font-size: 12px; }
</style>

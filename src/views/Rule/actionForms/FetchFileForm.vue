<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  FetchFileForm — SynchronizationService::processFetchFileRule reads
  `source`, `filePath`, `endpoint`, `objectIdPath`, `originIdPath`,
  `contentPath`, `filenamePath`, `fileExtension`, `subObjectFilepath`,
  `tags`, `autoShare`, `sourceConfiguration` (JSON blob).

  Source picker resolves Integriq sources via OpenRegister.
-->
<template>
	<div class="action-form">
		<label class="action-form__label">{{ t('integriq', 'Source') }}</label>
		<NcSelect
			data-testid="action-form-fetch-source"
			:aria-label-combobox="t('integriq', 'Source')"
			:modelValue="selectedSource"
			:options="sourceOptions"
			:loading="sourcesLoading"
			:placeholder="t('integriq', 'Select a source')"
			@update:modelValue="onSourcePick" />

		<NcTextField
			:label="t('integriq', 'File path (dot path)')"
			:modelValue="value.filePath || ''"
			placeholder="body.attachment.url"
			@update:modelValue="(next) => patch('filePath', next)" />
		<NcTextField
			:label="t('integriq', 'Endpoint (optional)')"
			:modelValue="value.endpoint || ''"
			placeholder="https://upstream/file/123"
			@update:modelValue="(next) => patch('endpoint', next)" />
		<NcTextField
			:label="t('integriq', 'Object ID path (optional)')"
			:modelValue="value.objectIdPath || ''"
			placeholder="body.id"
			@update:modelValue="(next) => patch('objectIdPath', next)" />
		<NcTextField
			:label="t('integriq', 'Origin ID path (optional)')"
			:modelValue="value.originIdPath || ''"
			placeholder="body.origin.id"
			@update:modelValue="(next) => patch('originIdPath', next)" />
		<NcTextField
			:label="t('integriq', 'Content path (optional)')"
			:modelValue="value.contentPath || ''"
			placeholder="body.attachment.content"
			@update:modelValue="(next) => patch('contentPath', next)" />
		<NcTextField
			:label="t('integriq', 'Filename path (optional)')"
			:modelValue="value.filenamePath || ''"
			placeholder="body.attachment.name"
			@update:modelValue="(next) => patch('filenamePath', next)" />
		<NcTextField
			:label="t('integriq', 'File extension (optional)')"
			:modelValue="value.fileExtension || ''"
			placeholder="pdf"
			@update:modelValue="(next) => patch('fileExtension', next)" />
		<NcTextField
			:label="t('integriq', 'Sub-object filepath (optional)')"
			:modelValue="value.subObjectFilepath || ''"
			placeholder="body.objects.0.url"
			@update:modelValue="(next) => patch('subObjectFilepath', next)" />
		<NcTextField
			:label="t('integriq', 'Tags (comma-separated)')"
			:modelValue="csv(value.tags)"
			placeholder="invoice,inbox"
			@update:modelValue="(next) => patch('tags', toArray(next))" />
		<NcCheckboxRadioSwitch
			type="switch"
			:modelValue="!!value.autoShare"
			@update:modelValue="(next) => patch('autoShare', !!next)">
			{{ t('integriq', 'Auto-share fetched files') }}
		</NcCheckboxRadioSwitch>

		<label class="action-form__label" :for="'rule-fetch-source-config-' + uid">
			{{ t('integriq', 'Source configuration (JSON, optional)') }}
		</label>
		<textarea
			:id="'rule-fetch-source-config-' + uid"
			class="action-form__textarea action-form__textarea--code"
			:value="sourceConfigDraft"
			spellcheck="false"
			rows="5"
			placeholder="[]"
			@input="onSourceConfigInput" />
		<span
			v-if="sourceConfigError"
			class="action-form__helper action-form__helper--error">
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
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		selectedSource() {
			const id = String(this.value?.source || '')
			if (!id) return null
			return (
				this.sourceOptions.find((opt) => opt.id === id) ?? { id, label: id }
			)
		},
	},

	watch: {
		// Keep textarea in sync if parent feeds in a value from a remote
		// fetch. Drift is rare because the rule loads once.
		'value.sourceConfiguration': function (next) {
			const serialized = this.serialiseSourceConfig(next)
			if (serialized !== this.sourceConfigDraft) {
				this.sourceConfigDraft = serialized
			}
		},
	},

	/** @spec openspec/specs/rule-editor-ui/spec.md */
	async mounted() {
		this.sourcesLoading = true
		this.sourceOptions = await fetchOpenRegisterCollection('source')
		this.sourcesLoading = false
		this.sourceConfigDraft = this.serialiseSourceConfig(
			this.value?.sourceConfiguration,
		)
	},

	methods: {
		patch: patchMethod(),
		/**
		 * Store the picked Integriq source on the action config. Clearing
		 * the picker writes an empty string rather than dropping the key.
		 *
		 * @param {?{id: string, label: string, raw: object}} option The option
		 *   chosen in NcSelect, or null/undefined when the selection is cleared.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onSourcePick(option) {
			this.patch('source', option?.id ? String(option.id) : '')
		},

		/**
		 * Render the stored `tags` list for the comma-separated text field.
		 *
		 * @param {string[]|string|undefined} value The persisted tags — an array
		 *   in the canonical shape, but tolerated as a bare string or missing.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		csv(value) {
			return Array.isArray(value) ? value.join(',') : value || ''
		},

		/**
		 * Parse the tags text field back into the stored array, trimming each
		 * entry and dropping empties so trailing commas are harmless.
		 *
		 * @param {string} text Comma-separated tags as typed (e.g. `invoice,inbox`).
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		toArray(text) {
			return (text || '')
				.split(',')
				.map((entry) => entry.trim())
				.filter(Boolean)
		},

		/**
		 * Render the persisted `sourceConfiguration` as pretty-printed JSON for
		 * the textarea. Strings pass through untouched (the user's own text) and
		 * anything unserialisable falls back to its string coercion.
		 *
		 * @param {*} value The stored source configuration — normally an object
		 *   or array, but also handled when absent or already raw text.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		serialiseSourceConfig(value) {
			if (value === undefined || value === null) return ''
			if (typeof value === 'string') return value
			try {
				return JSON.stringify(value, null, 2)
			} catch (_e) {
				return String(value)
			}
		},

		/**
		 * Handle typing in the source-configuration textarea: keep the draft
		 * text verbatim, drop the key entirely when the field is emptied, and
		 * only commit parsed JSON. A parse failure surfaces in
		 * `sourceConfigError` and leaves the last valid value stored.
		 *
		 * @param {InputEvent} event The native textarea `input` event; its
		 *   `target.value` holds the raw JSON text.
		 *
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
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
				this.sourceConfigError = this.t(
					'integriq',
					'Invalid JSON: {message}',
					{ message: parseErr.message },
				)
			}
		},
	},
}
</script>

<style scoped>
.action-form {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.action-form__label {
	font-weight: bold;
}

.action-form__helper {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.action-form__helper--error {
	color: var(--color-error);
}

.action-form__textarea {
	width: 100%;
	padding: 8px;
	font-family: var(--font-face, sans-serif);
	font-size: 14px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.action-form__textarea--code {
	font-family: var(--font-face-monospace, monospace);
	font-size: 12px;
}
</style>

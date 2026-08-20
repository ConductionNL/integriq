<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  WriteFileForm — EndpointService::processWriteFileRule reads
  `filePath`, `fileNamePath`, `tags`, plus the auto-share toggle.
-->
<template>
	<div class="action-form">
		<NcTextField
			:label="t('openconnector', 'File path (dot path on data, required)')"
			:modelValue="value.filePath || ''"
			placeholder="body.attachment"
			@update:modelValue="(next) => patch('filePath', next)" />
		<NcTextField
			:label="t('openconnector', 'Filename path (dot path on data, required)')"
			:modelValue="value.fileNamePath || ''"
			placeholder="body.filename"
			@update:modelValue="(next) => patch('fileNamePath', next)" />
		<NcTextField
			:label="t('openconnector', 'Tags (comma-separated)')"
			:modelValue="csv(value.tags)"
			placeholder="invoice,outbox"
			@update:modelValue="(next) => patch('tags', toArray(next))" />
		<NcCheckboxRadioSwitch
			type="switch"
			:modelValue="!!value.autoShare"
			@update:modelValue="(next) => patch('autoShare', !!next)">
			{{ t('openconnector', 'Auto-share written files') }}
		</NcCheckboxRadioSwitch>
		<span class="action-form__helper">
			{{
				t(
					'openconnector',
					'The dot path under the rule data tells the engine where to find the file bytes; the filename path supplies the name.',
				)
			}}
		</span>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'
import { patchMethod, valueProp } from './shared.js'

export default {
	name: 'WriteFileForm',
	components: { NcCheckboxRadioSwitch, NcTextField },
	props: { ...valueProp },
	methods: {
		patch: patchMethod(),
		/**
		 * Render the stored `tags` list as the comma-separated text the
		 * NcTextField displays.
		 *
		 * @param {Array<string>|string|undefined} value The stored tags, either
		 *   an array of entries or an already-flat string.
		 * @return {string} Comma-separated tags, or '' when unset.
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		csv(value) {
			return Array.isArray(value) ? value.join(',') : value || ''
		},

		/**
		 * Parse comma-separated tag input back into the array shape the backend
		 * expects, trimming entries and dropping empty ones.
		 *
		 * @param {string} text Raw comma-separated text typed into the field.
		 * @return {Array<string>} The cleaned list of tags.
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		toArray(text) {
			return (text || '')
				.split(',')
				.map((entry) => entry.trim())
				.filter(Boolean)
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

.action-form__helper {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}
</style>

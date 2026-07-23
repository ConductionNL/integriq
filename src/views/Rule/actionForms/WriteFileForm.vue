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
			:value="value.filePath || ''"
			placeholder="body.attachment"
			@update:value="(next) => patch('filePath', next)" />
		<NcTextField
			:label="t('openconnector', 'Filename path (dot path on data, required)')"
			:value="value.fileNamePath || ''"
			placeholder="body.filename"
			@update:value="(next) => patch('fileNamePath', next)" />
		<NcTextField
			:label="t('openconnector', 'Tags (comma-separated)')"
			:value="csv(value.tags)"
			placeholder="invoice,outbox"
			@update:value="(next) => patch('tags', toArray(next))" />
		<NcCheckboxRadioSwitch
			type="switch"
			:checked="!!value.autoShare"
			@update:checked="(next) => patch('autoShare', !!next)">
			{{ t('openconnector', 'Auto-share written files') }}
		</NcCheckboxRadioSwitch>
		<span class="action-form__helper">
			{{ t('openconnector', 'The dot path under the rule data tells the engine where to find the file bytes; the filename path supplies the name.') }}
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
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		csv(value) {
			return Array.isArray(value) ? value.join(',') : (value || '')
		},
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		toArray(text) {
			return (text || '').split(',').map((entry) => entry.trim()).filter(Boolean)
		},
	},
}
</script>

<style scoped>
.action-form { display: flex; flex-direction: column; gap: 10px; }

.action-form__helper { color: var(--color-text-maxcontrast); font-size: 12px; }
</style>

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  UploadForm — `upload` is not yet dispatched by the rule engine
  (no `case 'upload'` in EndpointService) but the legacy modal
  persisted `path`, `allowedTypes`, `maxSize`. We surface the same
  fields so existing data round-trips and new rules can be authored
  in anticipation of the backend handler landing.
-->
<template>
	<div class="action-form">
		<NcTextField
			:label="t('openconnector', 'Upload path')"
			:model-value="value.path || ''"
			placeholder="/path/to/upload/directory"
			@update:model-value="(next) => patch('path', next)" />
		<NcTextField
			:label="t('openconnector', 'Allowed file types (comma-separated)')"
			:model-value="value.allowedTypes || ''"
			placeholder="jpg,png,pdf"
			@update:model-value="(next) => patch('allowedTypes', next)" />
		<NcTextField
			:label="t('openconnector', 'Max file size (MB)')"
			type="number"
			:model-value="value.maxSize != null ? String(value.maxSize) : ''"
			placeholder="10"
			@update:model-value="onMaxSizeInput" />
		<span class="action-form__helper">
			{{ t('openconnector', 'Note: the backend handler for upload is still pending. Configuration is persisted so it activates once the dispatcher case lands.') }}
		</span>
	</div>
</template>

<script>
import { NcTextField } from '@nextcloud/vue'
import { patchMethod, valueProp } from './shared.js'

export default {
	name: 'UploadForm',
	components: { NcTextField },
	props: { ...valueProp },
	methods: {
		patch: patchMethod(),
		/**
		 * Coerce the max-file-size field (in MB): an empty input removes the
		 * key entirely, non-numeric input is ignored, anything else is stored
		 * as a number.
		 * @param {string|null} raw The raw text emitted by the number NcTextField.
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onMaxSizeInput(raw) {
			if (raw === '' || raw == null) {
				const next = { ...(this.value || {}) }
				delete next.maxSize
				this.$emit('update:value', next)
				return
			}
			const num = Number(raw)
			if (Number.isNaN(num)) return
			this.patch('maxSize', num)
		},
	},
}
</script>

<style scoped>
.action-form { display: flex; flex-direction: column; gap: 10px; }

.action-form__helper { color: var(--color-text-maxcontrast); font-size: 12px; }
</style>

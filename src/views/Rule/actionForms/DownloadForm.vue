<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  DownloadForm — EndpointService::processDownloadRule reads
  `configuration.filenamePosition` (dotted path on the object) to
  resolve the filename. The legacy modal also collected
  `fileIdPosition` (path-index of the file id in the URL) which the
  current backend ignores, but we keep it so existing data round-trips.
-->
<template>
	<div class="action-form">
		<NcTextField
			:label="t('openconnector', 'Filename position (dotted path on object)')"
			:model-value="value.filenamePosition || ''"
			placeholder="attachments.0.filename"
			@update:model-value="(next) => patch('filenamePosition', next)" />
		<NcTextField
			:label="t('openconnector', 'File ID position in URL path (legacy)')"
			type="number"
			:model-value="value.fileIdPosition != null ? String(value.fileIdPosition) : ''"
			placeholder="2"
			@update:model-value="onFileIdInput" />
		<span class="action-form__helper">
			{{ t('openconnector', 'The endpoint checks if the authenticated user is allowed to read the resolved file before sending bytes back.') }}
		</span>
	</div>
</template>

<script>
import { NcTextField } from '@nextcloud/vue'
import { patchMethod, valueProp } from './shared.js'

export default {
	name: 'DownloadForm',
	components: { NcTextField },
	props: { ...valueProp },
	methods: {
		patch: patchMethod(),
		/** @spec openspec/specs/rule-editor-ui/spec.md */
		onFileIdInput(raw) {
			if (raw === '' || raw == null) {
				const next = { ...(this.value || {}) }
				delete next.fileIdPosition
				this.$emit('update:value', next)
				return
			}
			const num = Number(raw)
			if (Number.isNaN(num)) return
			this.patch('fileIdPosition', num)
		},
	},
}
</script>

<style scoped>
.action-form { display: flex; flex-direction: column; gap: 10px; }

.action-form__helper { color: var(--color-text-maxcontrast); font-size: 12px; }
</style>

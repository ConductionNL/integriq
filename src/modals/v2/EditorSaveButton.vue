<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<!--
  EditorSaveButton: the primary button of an editor dialog. It reads Create
  for a new record and Save otherwise, and shows a spinner while saving.

  @spec openspec/specs/mapping-editor-ui/spec.md
-->
<template>
	<NcButton variant="primary" :disabled="disabled" @click="$emit('click')">
		<template #icon>
			<NcLoadingIcon v-if="saving" :size="20" />
			<PlusIcon v-else-if="isCreate" :size="20" />
			<ContentSaveOutlineIcon v-else :size="20" />
		</template>
		{{ isCreate ? t('integriq', 'Create') : t('integriq', 'Save') }}
	</NcButton>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import ContentSaveOutlineIcon from 'vue-material-design-icons/ContentSaveOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'EditorSaveButton',

	components: { ContentSaveOutlineIcon, NcButton, NcLoadingIcon, PlusIcon },

	props: {
		/** Whether the button is disabled. */
		disabled: { type: Boolean, default: false },
		/** Whether a save is in flight. */
		saving: { type: Boolean, default: false },
		/** Whether the record is new. */
		isCreate: { type: Boolean, default: false },
	},

	emits: ['click'],

	methods: { t },
}
</script>

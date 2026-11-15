<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<!--
  EditorIdentityFields: the name and description of a record in an editor
  dialog, stacked above the work of the dialog. The host passes its BEM
  block for the class, the name label and validation text, and stores the
  typed values itself.

  @spec openspec/specs/mapping-editor-ui/spec.md
-->
<template>
	<div :class="`${block}__identity`">
		<NcTextField
			:modelValue="name"
			:label="nameLabel"
			:error="!!nameError"
			:helperText="nameError"
			:disabled="disabled"
			v-bind="requiredAttr"
			@update:modelValue="$emit('update:name', $event)"
			@blur="$emit('blur')" />
		<NcTextArea
			:modelValue="description"
			:label="t('integriq', 'Description')"
			:disabled="disabled"
			rows="1"
			resize="vertical"
			@update:modelValue="$emit('update:description', $event)" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcTextArea, NcTextField } from '@nextcloud/vue'

export default {
	name: 'EditorIdentityFields',

	components: { NcTextArea, NcTextField },

	props: {
		/** BEM block of the host, used for the class. */
		block: { type: String, required: true },
		/** The record name. */
		name: { type: String, default: '' },
		/** The record description. */
		description: { type: String, default: '' },
		/** The name label, with its required marker. */
		nameLabel: { type: String, required: true },
		/** The name validation text, or ''. */
		nameError: { type: String, default: '' },
		/** Whether the fields are disabled while saving. */
		disabled: { type: Boolean, default: false },
		/** Whether the name input carries the required attribute. */
		required: { type: Boolean, default: false },
	},

	emits: ['update:name', 'update:description', 'blur'],

	computed: {
		/**
		 * @return {object} The bare required attribute when asked for, else nothing.
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		requiredAttr() {
			return this.required ? { required: '' } : {}
		},
	},

	methods: { t },
}
</script>

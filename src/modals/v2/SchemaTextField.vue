<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<!--
  SchemaTextField: one schema field of a form-fields override as a text
  input with its helper line underneath. The stored value shows as text,
  null as empty; a required field gets the asterisk on its label. Typed
  text is emitted as is; the form decides how to store it.

  @spec openspec/specs/endpoint-job-editor-ui/spec.md
-->
<template>
	<NcTextField
		:label="field.label + (field.required ? ' *' : '')"
		:modelValue="value != null ? String(value) : ''"
		:error="!!error"
		:type="type"
		:disabled="field.readOnly"
		:placeholder="placeholder"
		@update:modelValue="$emit('update', $event)" />
	<CnFieldHelper
		:text="field.description"
		:more="field.descriptionLong"
		:error="error" />
</template>

<script>
import { CnFieldHelper } from '@conduction/nextcloud-vue'
import { NcTextField } from '@nextcloud/vue'

export default {
	name: 'SchemaTextField',

	components: { CnFieldHelper, NcTextField },

	props: {
		/** The resolved field descriptor from CnFormDialog. */
		field: { type: Object, required: true },
		/** The stored value of the field. */
		value: { type: [Boolean, String, Number, Object, Array], default: null },
		/** The error message of the field, if any. */
		error: { type: String, default: undefined },
		/** The input type; the text input default when unset. */
		type: { type: String, default: undefined },
		/** The placeholder text. */
		placeholder: { type: String, default: undefined },
	},

	emits: ['update'],
}
</script>

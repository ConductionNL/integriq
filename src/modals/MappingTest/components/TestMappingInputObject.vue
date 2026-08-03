<script setup>
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<div class="input-object-container">
		<h4>{{ t('openconnector', 'Input object') }}</h4>

		<NcTextArea v-model="inputObject"
			resize="vertical"
			class="textarea"
			:error="!validJson(inputObject)"
			:helper-text="!validJson(inputObject) ? t('openconnector', 'Invalid JSON') : ''"
			@update:model-value="emitInputObjectChanged" />
	</div>
</template>

<script>
import {
	NcTextArea,
} from '@nextcloud/vue'

export default {
	name: 'TestMappingInputObject',
	components: {
		NcTextArea,
	},
	props: {
		// none
	},
	data() {
		return {
			inputObject: '',
		}
	},
	methods: {
		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		emitInputObjectChanged(value) {
			// Receives the VALUE, not a DOM event: NcTextArea is a v9 component
			// emitting update:modelValue. The former `$event.target.value` read
			// relied on the listener falling through to the inner <textarea>.
			const data = {
				value,
				isValid: this.validJson(value),
			}
			this.$emit('input-object-changed', data)
		},
		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		validJson(object) {
			try {
				JSON.parse(object)
				return true
			} catch (e) {
				return false
			}
		},
	},
}
</script>

<style scoped>
.input-object-container {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-right: 12px !important;
}

.input-object-container .textarea {
	display: flex;
	flex-direction: column;
	flex-grow: 1;
}

.input-object-container .textarea :deep(.textarea__main-wrapper) {
	flex-grow: 0.986; /* why this value? idk, it just works */
}

.textarea :deep(textarea) {
	resize: vertical !important;
	height: 100%;
}
</style>

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  EditMappingRuleDialog — single-purpose modal that the rules editor pops
  to add or edit one row of one rule kind.

  The component is intentionally generic over the three rule kinds so the
  parent (`MappingRulesEditor`) keeps one dialog component for all three
  tabs. The form layout swaps based on `kind`:

    - mapping : property (string) + template (multi-line, monospace) — the
      template is a Twig expression and may contain `{{ }}` markers.
    - cast    : property (string) + castType (NcSelect with the legacy
      cast type vocabulary: string|integer|float|boolean|array|jsonToArray|htmlDecode).
    - unset   : property (string only).

  Submitting emits `submit { kind, property, value }` to the parent and the
  parent decides how to merge the new row into the collection. We don't
  touch the parent's data here — pure form-state.
-->
<template>
	<NcModal :name="dialogTitle" size="normal" @close="$emit('cancel')">
		<div class="cn-rule-dialog">
			<h2 class="cn-rule-dialog__title">
				{{ dialogTitle }}
			</h2>

			<form class="cn-rule-dialog__form" @submit.prevent="onSubmit">
				<label class="cn-rule-dialog__field">
					<span class="cn-rule-dialog__label">{{ propertyLabel }}</span>
					<NcTextField
						v-model="propertyDraft"
						:placeholder="propertyPlaceholder"
						:error="!!propertyError"
						:helperText="propertyError"
						:label="propertyLabel" />
				</label>

				<label v-if="kind === 'mapping'" class="cn-rule-dialog__field">
					<span class="cn-rule-dialog__label">
						{{ t('openconnector', 'Twig template') }}
					</span>
					<textarea
						v-model="valueDraft"
						class="cn-rule-dialog__textarea"
						rows="6"
						spellcheck="false"
						:placeholder="templatePlaceholder" />
					<p class="cn-rule-dialog__hint">
						{{ templateHelp }}
					</p>
				</label>

				<label v-else-if="kind === 'cast'" class="cn-rule-dialog__field">
					<span class="cn-rule-dialog__label">
						{{ t('openconnector', 'Cast type') }}
					</span>
					<NcSelect
						:modelValue="castSelectValue"
						:options="castTypeOptions"
						:clearable="false"
						:aria-label-combobox="t('openconnector', 'Cast type')"
						inputId="cn-rule-dialog-cast-type"
						@update:modelValue="onCastTypeInput" />
				</label>

				<div class="cn-rule-dialog__actions">
					<NcButton variant="tertiary" @click="$emit('cancel')">
						{{ t('openconnector', 'Cancel') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="!canSubmit"
						@click="onSubmit">
						{{ submitLabel }}
					</NcButton>
				</div>
			</form>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcSelect, NcTextField } from '@nextcloud/vue'

/**
 * Cast type vocabulary, mirrored from the legacy
 * `src/modals/Mapping/EditMapping.vue` so backend semantics stay
 * unchanged. Server-side `RuleHandler`/`MappingService` consumes these
 * strings verbatim.
 */
const CAST_TYPE_OPTIONS = [
	{ id: 'string', label: 'String' },
	{ id: 'integer', label: 'Integer' },
	{ id: 'float', label: 'Float' },
	{ id: 'boolean', label: 'Boolean' },
	{ id: 'array', label: 'Array' },
	{ id: 'jsonToArray', label: 'JSON to Array' },
	{ id: 'htmlDecode', label: 'HTML Decode' },
]

export default {
	name: 'EditMappingRuleDialog',

	components: {
		NcButton,
		NcModal,
		NcSelect,
		NcTextField,
	},

	props: {
		/** One of 'mapping' | 'cast' | 'unset'. */
		kind: {
			type: String,
			required: true,
			validator: (value) => ['mapping', 'cast', 'unset'].includes(value),
		},

		/**
		 * Property/key being edited. `null` indicates a new row, in which
		 * case the property input is empty and editable.
		 */
		property: {
			type: String,
			default: null,
		},

		/**
		 * Initial value: a Twig template string (`kind: 'mapping'`), a
		 * cast type id (`kind: 'cast'`), or the property name itself
		 * (`kind: 'unset'`). Optional — falls back to safe defaults.
		 */
		value: {
			type: [String, Number, Boolean, Object],
			default: '',
		},

		/**
		 * Keys already present in the collection (excluding the row being
		 * edited). Used to surface a "duplicate property" inline error
		 * before the user submits.
		 */
		existingKeys: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			propertyDraft:
				this.kind === 'unset'
					? (this.property
						?? (typeof this.value === 'string' ? this.value : ''))
					: (this.property ?? ''),

			valueDraft:
				typeof this.value === 'string'
					? this.value
					: this.value != null
						? String(this.value)
						: '',
		}
	},

	computed: {
		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		castTypeOptions() {
			return CAST_TYPE_OPTIONS
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		templatePlaceholder() {
			// Built outside the template so the embedded `{{ … }}` Twig
			// markers don't collide with Vue's mustache parser.
			const openBrace = '{{'
			const closeBrace = '}}'
			return `${openBrace} originalProperty ${closeBrace} or ${openBrace} source.field|default('-') ${closeBrace}`
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		templateHelp() {
			// See `templatePlaceholder`: avoid embedding `{{` directly in
			// the template literal that becomes Vue parser input.
			const openBrace = '{{'
			const closeBrace = '}}'
			return this.t(
				'openconnector',
				'A Twig template evaluated against the input object. Use {open} field {close} to reference source values.',
				{ open: openBrace, close: closeBrace },
			)
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		castSelectValue() {
			return (
				this.castTypeOptions.find((option) => option.id === this.valueDraft)
				|| this.castTypeOptions[0]
			)
		},

		isNew() {
			return this.property == null
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		dialogTitle() {
			if (this.kind === 'mapping') {
				return this.isNew
					? this.t('openconnector', 'Add mapping rule')
					: this.t('openconnector', 'Edit mapping rule')
			}
			if (this.kind === 'cast') {
				return this.isNew
					? this.t('openconnector', 'Add cast rule')
					: this.t('openconnector', 'Edit cast rule')
			}
			return this.isNew
				? this.t('openconnector', 'Add unset rule')
				: this.t('openconnector', 'Edit unset rule')
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		submitLabel() {
			return this.isNew
				? this.t('openconnector', 'Add rule')
				: this.t('openconnector', 'Save rule')
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		propertyLabel() {
			if (this.kind === 'mapping')
				return this.t('openconnector', 'Target property')
			return this.t('openconnector', 'Property')
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		propertyPlaceholder() {
			if (this.kind === 'mapping') {
				return this.t('openconnector', 'e.g. firstName')
			}
			return this.t('openconnector', 'JSON path or property name')
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		propertyError() {
			const trimmed = this.propertyDraft.trim()
			if (!trimmed) return ''
			if (this.existingKeys.includes(trimmed)) {
				return this.t(
					'openconnector',
					'Another rule already targets this property.',
				)
			}
			return ''
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		canSubmit() {
			const property = this.propertyDraft.trim()
			if (!property || this.propertyError) return false
			if (this.kind === 'mapping') {
				return this.valueDraft.length > 0
			}
			if (this.kind === 'cast') {
				return this.castTypeOptions.some(
					(option) => option.id === this.valueDraft,
				)
			}
			return true
		},
	},

	methods: {
		/**
		 * Store the picked cast type as the rule's value draft.
		 *
		 * @param {{id: string, label: string}|null} selected The cast-type option
		 *   chosen in the NcSelect, or null when the selection is cleared.
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		onCastTypeInput(selected) {
			if (!selected) return
			this.valueDraft = selected.id
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		onSubmit() {
			if (!this.canSubmit) return
			const property = this.propertyDraft.trim()
			if (this.kind === 'mapping') {
				this.$emit('submit', {
					kind: 'mapping',
					property,
					value: this.valueDraft,
				})
				return
			}
			if (this.kind === 'cast') {
				this.$emit('submit', {
					kind: 'cast',
					property,
					value: this.valueDraft,
				})
				return
			}
			this.$emit('submit', {
				kind: 'unset',
				property,
				value: property,
			})
		},
	},
}
</script>

<style scoped>
.cn-rule-dialog {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 20px;
	min-width: 480px;
	max-width: 640px;
}

.cn-rule-dialog__title {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.cn-rule-dialog__form {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.cn-rule-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.cn-rule-dialog__label {
	font-weight: 500;
}

.cn-rule-dialog__textarea {
	width: 100%;
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
	padding: 8px;
	resize: vertical;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.cn-rule-dialog__hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.cn-rule-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>

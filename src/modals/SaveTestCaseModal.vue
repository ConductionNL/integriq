<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  SaveTestCaseModal — "Save as test case" on the trace detail page.

  Copies one mapping or synchronization step of a trace into a new
  integration_test_case: the step's input becomes the case input and its
  output the expected output. The trace snapshots are redacted before they
  are stored, so the dialog names every field that still carries the
  redaction marker and lets the engineer replace it with a test value before
  saving (POST /api/execution-traces/{id}/test-case).

  @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
-->
<template>
	<NcModal
		v-if="open"
		labelId="saveTestCaseModal"
		size="normal"
		data-testid="save-test-case-modal"
		@close="onClose">
		<div class="save-test-case">
			<h2 id="saveTestCaseModal">
				{{ t('integriq', 'Save as test case') }}
			</h2>

			<NcSelect
				:modelValue="selectedStep"
				:options="stepOptions"
				:inputLabel="t('integriq', 'Step')"
				:clearable="false"
				label="label"
				data-testid="save-test-case-step"
				@update:modelValue="onSelectStep" />

			<NcTextField
				:modelValue="name"
				:label="t('integriq', 'Name')"
				data-testid="save-test-case-name"
				@update:modelValue="name = $event" />

			<NcNoteCard
				v-if="redacted.length > 0"
				type="warning"
				data-testid="save-test-case-redacted">
				<p>
					{{ t('integriq', 'These fields were redacted when the trace was stored. Replace them with test values, or the case will not pass.') }}
				</p>
			</NcNoteCard>

			<NcTextField
				v-for="path in redacted"
				:key="path"
				:modelValue="replacements[path] || ''"
				:label="path"
				:data-testid="`save-test-case-redacted-${path}`"
				@update:modelValue="setReplacement(path, $event)" />

			<NcTextField
				:modelValue="ignoredPathsText"
				:label="t('integriq', 'Ignored paths')"
				:helperText="t('integriq', 'Dot paths left out of the comparison, separated by commas.')"
				data-testid="save-test-case-ignored"
				@update:modelValue="ignoredPathsText = $event" />

			<NcNoteCard v-if="errorMessage" type="error">
				{{ errorMessage }}
			</NcNoteCard>

			<div class="save-test-case__buttons">
				<NcButton @click="onClose">
					{{ t('integriq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="saving || !selectedStep"
					data-testid="save-test-case-confirm"
					@click="save">
					{{ t('integriq', 'Save test case') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcModal, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import {
	parseIgnoredPaths,
	recordableSteps,
	redactedPaths,
	withValueAt,
} from '../components/regression/regressionCases.js'

export default {
	name: 'SaveTestCaseModal',

	components: {
		NcButton,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** Whether the dialog is shown. */
		open: { type: Boolean, default: false },
		/** The trace's id. */
		traceId: { type: String, required: true },
		/** The trace's ordered steps. */
		steps: { type: Array, default: () => [] },
	},

	emits: ['close', 'saved'],

	data() {
		return {
			selectedStep: null,
			name: '',
			replacements: {},
			ignoredPathsText: '',
			saving: false,
			errorMessage: '',
		}
	},

	computed: {
		/** @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002 */
		stepOptions() {
			return recordableSteps(this.steps).map((step) => ({
				id: step.order,
				label: `${step.order}. ${step.name || step.type} (${step.type})`,
				step,
			}))
		},

		/**
		 * Every redacted field of the chosen step, prefixed by `input` or `expectedOutput`.
		 *
		 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
		 */
		redacted() {
			const step = this.selectedStep?.step
			if (!step) return []
			return [
				...redactedPaths(step.input || {}, 'input'),
				...redactedPaths(step.output || {}, 'expectedOutput'),
			]
		},
	},

	watch: {
		open: {
			immediate: true,
			/**
			 * Start over each time the dialog opens.
			 *
			 * @param {boolean} isOpen Whether the dialog is now open.
			 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
			 */
			handler(isOpen) {
				if (isOpen) this.reset()
			},
		},
	},

	methods: {
		t,

		/**
		 * Start from the first recordable step with nothing replaced.
		 *
		 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
		 */
		reset() {
			this.errorMessage = ''
			this.ignoredPathsText = ''
			this.onSelectStep(this.stepOptions[0] || null)
		},

		/**
		 * Choose the step; its name becomes the default case name.
		 *
		 * @param {object|null} option The step option.
		 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
		 */
		onSelectStep(option) {
			this.selectedStep = option
			this.replacements = {}
			this.name = option ? (option.step.name || option.step.type) : ''
		},

		/**
		 * Remember the test value typed for a redacted field.
		 *
		 * @param {string} path The field's path.
		 * @param {string} value The test value.
		 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
		 */
		setReplacement(path, value) {
			this.replacements = { ...this.replacements, [path]: value }
		},

		/**
		 * The body the record route receives: the step, the name, and the
		 * snapshots with every replaced field filled in.
		 *
		 * @return {object} The request body.
		 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
		 */
		requestBody() {
			const step = this.selectedStep.step
			let input = step.input || {}
			let expectedOutput = step.output || {}
			for (const [path, value] of Object.entries(this.replacements)) {
				if (value === '') continue
				if (path.startsWith('input.')) {
					input = withValueAt(input, path.slice('input.'.length), value)
				} else if (path.startsWith('expectedOutput.')) {
					expectedOutput = withValueAt(expectedOutput, path.slice('expectedOutput.'.length), value)
				}
			}
			return {
				step: step.order,
				name: this.name.trim(),
				input,
				expectedOutput,
				ignoredPaths: parseIgnoredPaths(this.ignoredPathsText),
			}
		},

		/**
		 * Save the case.
		 *
		 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
		 */
		async save() {
			this.saving = true
			this.errorMessage = ''
			try {
				const res = await axios.post(
					generateUrl(`/apps/integriq/api/execution-traces/${encodeURIComponent(this.traceId)}/test-case`),
					this.requestBody(),
				)
				showSuccess(t('integriq', 'Test case saved'))
				this.$emit('saved', res.data)
				this.$emit('close')
			} catch (err) {
				this.errorMessage = err?.response?.data?.error || t('integriq', 'The test case could not be saved.')
			} finally {
				this.saving = false
			}
		},

		/** @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002 */
		onClose() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.save-test-case {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.save-test-case__buttons {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>

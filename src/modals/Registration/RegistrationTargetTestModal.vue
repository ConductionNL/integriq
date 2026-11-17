<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  RegistrationTargetTestModal — the "Test" row action of the registration
  targets page (form-submission-registration-targets, design D6).

  Mounted by ModalHost on EVENT_OPEN_REGISTRATION_TEST. The administrator may
  paste a hand-over; left empty, the server maps its own sample. "Run test"
  POSTs to /api/registration-targets/{slug}/test, which maps the hand-over and
  stops before the first write, and the mapped body or the refusal is shown.
-->
<template>
	<NcModal v-if="open" labelId="registrationTargetTestModal" size="large" @close="onClose">
		<div class="cn-registration-test">
			<h2 id="registrationTargetTestModal">
				{{ t('integriq', 'Test registration target') }}
			</h2>
			<p v-if="title" class="cn-registration-test__subject">
				{{ title }}
			</p>
			<NcNoteCard type="info">
				<p>{{ t('integriq', 'The test maps the hand-over and stops before anything is sent or saved.') }}</p>
			</NcNoteCard>

			<label for="cn-registration-test-input">
				{{ t('integriq', 'Hand-over (JSON, leave empty for the sample)') }}
			</label>
			<textarea
				id="cn-registration-test-input"
				v-model="handoverJson"
				class="cn-registration-test__textarea"
				rows="10"
				spellcheck="false" />
			<p v-if="inputError" class="cn-registration-test__error" role="alert">
				{{ inputError }}
			</p>

			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="refusal" type="warning">
				<p>{{ refusal }}</p>
			</NcNoteCard>
			<section v-if="mappedJson" class="cn-registration-test__result">
				<h3>{{ t('integriq', 'Mapped body') }}</h3>
				<pre class="cn-registration-test__pre">{{ mappedJson }}</pre>
			</section>

			<div class="cn-registration-test__actions">
				<NcButton @click="onClose">
					{{ t('integriq', 'Close') }}
				</NcButton>
				<NcButton variant="primary" :disabled="busy || !slug" @click="run">
					<template #icon>
						<NcLoadingIcon v-if="busy" :size="20" />
					</template>
					{{ t('integriq', 'Run test') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'RegistrationTargetTestModal',

	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
	},

	props: {
		/** Whether the modal is shown. */
		open: { type: Boolean, default: false },
		/** The registration target row the test runs for. */
		target: { type: Object, default: null },
	},

	emits: ['close'],

	data() {
		return {
			handoverJson: '',
			inputError: '',
			busy: false,
			error: '',
			answer: null,
		}
	},

	computed: {
		/**
		 * The target's slug, the key of the test endpoint.
		 *
		 * @return {string} The slug, or empty.
		 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
		 */
		slug() {
			return typeof this.target?.slug === 'string' ? this.target.slug : ''
		},

		/**
		 * The target's title, shown under the heading.
		 *
		 * @return {string} The title, or empty.
		 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
		 */
		title() {
			return this.target?.title || this.slug
		},

		/**
		 * The refusal of the last run, as code and message.
		 *
		 * @return {string} The refusal text, or empty when the run mapped.
		 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
		 */
		refusal() {
			if (!this.answer || this.answer.ok === true) {
				return ''
			}
			return t('integriq', 'Refused ({code}): {message}', {
				code: this.answer.code || '',
				message: this.answer.message || '',
			})
		},

		/**
		 * The mapped body of the last run, pretty-printed.
		 *
		 * @return {string} The JSON, or empty.
		 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
		 */
		mappedJson() {
			if (this.answer?.ok !== true) {
				return ''
			}
			return JSON.stringify(this.answer.mapped ?? {}, null, 2)
		},
	},

	watch: {
		/**
		 * Start clean each time the modal opens for a row.
		 *
		 * @param {boolean} value Whether the modal is now open.
		 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
		 */
		open(value) {
			if (value) {
				this.handoverJson = ''
				this.inputError = ''
				this.error = ''
				this.answer = null
			}
		},
	},

	methods: {
		t,

		/**
		 * The pasted hand-over, or an empty object for the server's sample.
		 *
		 * @return {object|null} The hand-over, or null when it is not a JSON object.
		 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
		 */
		parsedHandover() {
			if (this.handoverJson.trim() === '') {
				return {}
			}
			try {
				const value = JSON.parse(this.handoverJson)
				if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
					return value
				}
			} catch {
				// Reported below as not a JSON object.
			}
			return null
		},

		/**
		 * Send the dry run and keep its answer.
		 *
		 * @return {Promise<void>} Resolves once the answer or the failure is shown.
		 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
		 */
		async run() {
			if (!this.slug) {
				return
			}
			const handover = this.parsedHandover()
			if (handover === null) {
				this.inputError = t('integriq', 'The hand-over must be a JSON object.')
				return
			}

			this.inputError = ''
			this.error = ''
			this.answer = null
			this.busy = true
			try {
				const response = await axios.post(
					generateUrl(`/apps/integriq/api/registration-targets/${encodeURIComponent(this.slug)}/test`),
					{ handover },
				)
				this.answer = response.data
			} catch {
				this.error = t('integriq', 'The test could not be run.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Close the modal.
		 *
		 * @return {void}
		 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
		 */
		onClose() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.cn-registration-test {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.cn-registration-test__subject {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.cn-registration-test__textarea,
.cn-registration-test__pre {
	width: 100%;
	box-sizing: border-box;
	font-family: var(--font-face-monospace, monospace);
	padding: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.cn-registration-test__textarea {
	resize: vertical;
}

.cn-registration-test__pre {
	margin: 0;
	max-height: 320px;
	overflow: auto;
	white-space: pre-wrap;
}

.cn-registration-test__error {
	margin: 0;
	color: var(--color-error);
}

.cn-registration-test__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>

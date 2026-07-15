<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  FormsFieldMapping — read-only field reference list for a `nextcloud-form`
  source (sync-editor-ui REQ-SYNCUI-009).

  Fetches the selected form's questions via the forms-bridge discovery
  endpoint and renders one row per question: id, text, and type, so the user
  sees the exact question-id/text references available to a `Mapping` or an
  outbound `action.kind: 'mapping'` configuration (nextcloud-forms-connector
  REQ-003) before writing mapping expressions by hand. `multiple`/
  `multiple_unique`-type questions are visually flagged as array-valued;
  question texts shared by two-or-more questions are visually flagged as
  ambiguous (steering the user toward referencing by id instead).

  Unlike TablesColumnMapping.vue (which writes `targetConfig.columnMapping`),
  there is no `nextcloud-form` TARGET (nextcloud-forms-connector REQ-002 is
  source-only) — this component never emits an `update:config`; it is purely
  informational labelling.

  All DOM-free logic lives in ./formsBridge.js (unit-tested in the repo's
  node-env vitest harness).
-->

<template>
	<div class="forms-field-mapping">
		<header class="forms-field-mapping__header">
			<h4 class="forms-field-mapping__title">
				{{ t('openconnector', 'Form questions') }}
			</h4>
			<span class="forms-field-mapping__hint">
				{{ t('openconnector', 'Reference a question by id or exact text in your Mapping or outbound action configuration.') }}
			</span>
		</header>

		<NcLoadingIcon v-if="loading" :size="24" />

		<span v-else-if="questionsError" class="forms-field-mapping__error">
			{{ questionsError }}
		</span>

		<span v-else-if="questions.length === 0" class="forms-field-mapping__empty">
			{{ t('openconnector', 'This form has no questions to reference.') }}
		</span>

		<ul v-else class="forms-field-mapping__list">
			<li
				v-for="question in questions"
				:key="question.id"
				class="forms-field-mapping__row">
				<span class="forms-field-mapping__question-text">{{ question.text }}</span>
				<span class="forms-field-mapping__question-id">#{{ question.id }}</span>
				<span class="forms-field-mapping__question-type">{{ question.type }}</span>
				<span v-if="isArrayValued(question)" class="forms-field-mapping__badge forms-field-mapping__badge--array">
					{{ t('openconnector', 'array') }}
				</span>
				<span v-if="isAmbiguous(question)" class="forms-field-mapping__badge forms-field-mapping__badge--ambiguous">
					{{ t('openconnector', 'ambiguous text — reference by id') }}
				</span>
			</li>
		</ul>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

import {
	extractResults,
	mapQuestionDescriptors,
	isArrayValuedQuestion,
	ambiguousQuestionTexts,
} from './formsBridge.js'

export default {
	name: 'FormsFieldMapping',

	components: {
		NcLoadingIcon,
	},

	props: {
		/** The Source UUID whose credentials list the questions. */
		sourceId: { type: [String, Number], default: '' },
		/** The selected Forms form id. */
		formId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			questions: [],
			loading: false,
			questionsError: '',
		}
	},

	computed: {
		/** @spec openspec/specs/sync-editor-ui/spec.md#requirement-field-mapping-helper-prefilled-from-form-questions-req-syncui-009 */
		ambiguousTexts() {
			return ambiguousQuestionTexts(this.questions)
		},
	},

	watch: {
		formId: {
			immediate: true,
			/** @spec openspec/specs/sync-editor-ui/spec.md#requirement-field-mapping-helper-prefilled-from-form-questions-req-syncui-009 */
			handler() {
				this.fetchQuestions()
			},
		},
		/** @spec openspec/specs/sync-editor-ui/spec.md#requirement-field-mapping-helper-prefilled-from-form-questions-req-syncui-009 */
		sourceId() {
			this.fetchQuestions()
		},
	},

	methods: {
		/** @spec openspec/specs/sync-editor-ui/spec.md#requirement-field-mapping-helper-prefilled-from-form-questions-req-syncui-009 */
		isArrayValued(question) {
			return isArrayValuedQuestion(question)
		},
		/** @spec openspec/specs/sync-editor-ui/spec.md#requirement-field-mapping-helper-prefilled-from-form-questions-req-syncui-009 */
		isAmbiguous(question) {
			return this.ambiguousTexts.has(question.text)
		},
		/**
		 * Fetch the form's questions via the forms-bridge discovery endpoint.
		 * Soft-fails to an empty list with an inline error so the helper
		 * degrades gracefully.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-field-mapping-helper-prefilled-from-form-questions-req-syncui-009
		 */
		async fetchQuestions() {
			if (!this.sourceId || !this.formId) {
				this.questions = []
				return
			}
			this.loading = true
			this.questionsError = ''
			try {
				const response = await axios.get(
					generateUrl('/apps/openconnector/api/synchronizations/forms-bridge/forms/{formId}/questions', { formId: this.formId }),
					{ params: { sourceId: this.sourceId } },
				)
				this.questions = mapQuestionDescriptors(extractResults(response.data))
			} catch (err) {
				this.questions = []
				this.questionsError = err?.response?.data?.error
					|| t('openconnector', 'Could not load questions for this form.')
				// eslint-disable-next-line no-console
				console.warn('[FormsFieldMapping] questions fetch failed', err)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.forms-field-mapping {
	display: flex;
	flex-direction: column;
	gap: 10px;
	margin-top: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.forms-field-mapping__header {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.forms-field-mapping__title {
	margin: 0;
	font-size: 14px;
	font-weight: bold;
}

.forms-field-mapping__hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.forms-field-mapping__list {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.forms-field-mapping__row {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 8px;
	font-size: 13px;
}

.forms-field-mapping__question-text {
	font-weight: bold;
}

.forms-field-mapping__question-id,
.forms-field-mapping__question-type {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.forms-field-mapping__badge {
	font-size: 11px;
	padding: 1px 6px;
	border-radius: var(--border-radius-pill, 10px);
}

.forms-field-mapping__badge--array {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.forms-field-mapping__badge--ambiguous {
	background-color: var(--color-error);
	color: var(--color-main-background);
}

.forms-field-mapping__error {
	font-size: 12px;
	color: var(--color-error);
}

.forms-field-mapping__empty {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  Test service fetch modal: "Testen" on Beheer > Service fetches
  (forms-service-fetch-for-apps, REQ-SF-001, design D5).

  Mounted by ModalHost on EVENT_OPEN_TEST_SERVICE_FETCH from the Service
  fetches row action. One field per declared input; "Run test" posts the sample
  inputs to the admin-only POST /api/service-fetches/{slug}/test and shows the
  source's raw answer next to the mapped outputs. A refusal is named, with the
  raw answer when the source did answer. Nothing runs until the button is
  pressed.
-->
<template>
	<NcModal v-if="open" labelId="testServiceFetchModal" size="large" @close="onClose">
		<div class="cn-test-service-fetch" data-testid="test-service-fetch-modal">
			<h2 id="testServiceFetchModal">
				{{ t('integriq', 'Test service fetch') }}
			</h2>
			<NcNoteCard v-if="fetchName" type="info">
				<p>{{ t('integriq', 'Testing service fetch: {name}', { name: fetchName }) }}</p>
			</NcNoteCard>

			<section class="cn-test-service-fetch__inputs">
				<h3>{{ t('integriq', 'Sample inputs') }}</h3>
				<p v-if="inputs.length === 0" class="cn-test-service-fetch__muted">
					{{ t('integriq', 'This fetch declares no inputs.') }}
				</p>
				<NcTextField
					v-for="input in inputs"
					:key="input.name"
					:modelValue="values[input.name] || ''"
					:label="input.required ? t('integriq', '{name} (required)', { name: input.name }) : input.name"
					:data-testid="`test-service-fetch-input-${input.name}`"
					@update:modelValue="setValue(input.name, $event)" />
				<div class="cn-test-service-fetch__actions">
					<NcButton @click="onClose">
						{{ t('integriq', 'Close') }}
					</NcButton>
					<NcButton
						variant="primary"
						data-testid="test-service-fetch-run"
						:disabled="running || !slug"
						@click="runTest">
						<template #icon>
							<NcLoadingIcon v-if="running" :size="20" />
						</template>
						{{ t('integriq', 'Run test') }}
					</NcButton>
				</div>
			</section>

			<p v-if="error" class="cn-test-service-fetch__error" role="alert" data-testid="test-service-fetch-error">
				{{ error }}
			</p>
			<NcNoteCard v-if="result && result.refusal" type="warning">
				<p data-testid="test-service-fetch-refusal">
					{{ refusalText }}
				</p>
			</NcNoteCard>

			<div v-if="result" class="cn-test-service-fetch__panes">
				<section class="cn-test-service-fetch__pane">
					<h3>{{ t('integriq', 'Answer from the source') }}</h3>
					<pre v-if="result.raw" data-testid="test-service-fetch-raw">{{ pretty(result.raw) }}</pre>
					<p v-else class="cn-test-service-fetch__muted">
						{{ t('integriq', 'The source was not called.') }}
					</p>
				</section>
				<section class="cn-test-service-fetch__pane">
					<h3>{{ t('integriq', 'Mapped outputs') }}</h3>
					<pre v-if="result.outputs" data-testid="test-service-fetch-outputs">{{ pretty(result.outputs) }}</pre>
					<p v-else class="cn-test-service-fetch__muted">
						{{ t('integriq', 'No outputs.') }}
					</p>
				</section>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard, NcTextField } from '@nextcloud/vue'

export default {
	name: 'TestServiceFetchModal',

	components: { NcButton, NcLoadingIcon, NcModal, NcNoteCard, NcTextField },

	props: {
		/** Whether the modal is shown. */
		open: {
			type: Boolean,
			default: false,
		},

		/** The serviceFetch row: its slug, title and declared inputs. */
		fetch: {
			type: Object,
			default: null,
		},
	},

	emits: ['close'],

	data() {
		return {
			values: {},
			running: false,
			result: null,
			error: '',
		}
	},

	computed: {
		/**
		 * The fetch's slug, the key of the test route.
		 *
		 * @return {string}
		 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-administrator-defines-a-service-fetch-with-inputs-and-mapped-outputs-req-sf-001
		 */
		slug() {
			return String(this.fetch?.slug || '')
		},

		/**
		 * The name shown above the fields.
		 *
		 * @return {string}
		 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-administrator-defines-a-service-fetch-with-inputs-and-mapped-outputs-req-sf-001
		 */
		fetchName() {
			return String(this.fetch?.title || this.slug)
		},

		/**
		 * The declared inputs that have a name.
		 *
		 * @return {Array<{name: string, required: boolean}>}
		 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-administrator-defines-a-service-fetch-with-inputs-and-mapped-outputs-req-sf-001
		 */
		inputs() {
			const declared = Array.isArray(this.fetch?.inputs) ? this.fetch.inputs : []
			return declared
				.filter((input) => input && typeof input.name === 'string' && input.name !== '')
				.map((input) => ({ name: input.name, required: input.required === true }))
		},

		/**
		 * The refusal, named for the administrator.
		 *
		 * @return {string}
		 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-administrator-defines-a-service-fetch-with-inputs-and-mapped-outputs-req-sf-001
		 */
		refusalText() {
			const refusal = this.result?.refusal || ''
			const detail = this.result?.detail || ''
			return detail
				? t('integriq', 'Refused: {refusal} ({detail})', { refusal, detail })
				: t('integriq', 'Refused: {refusal}', { refusal })
		},
	},

	watch: {
		/**
		 * Start empty each time the modal opens, or another fetch is tested.
		 *
		 * @return {void}
		 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-administrator-defines-a-service-fetch-with-inputs-and-mapped-outputs-req-sf-001
		 */
		open() {
			this.reset()
		},

		/**
		 * Start empty when another fetch is tested.
		 *
		 * @return {void}
		 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-administrator-defines-a-service-fetch-with-inputs-and-mapped-outputs-req-sf-001
		 */
		slug() {
			this.reset()
		},
	},

	methods: {
		t,

		/**
		 * Clear the fields and the last result.
		 *
		 * @return {void}
		 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-administrator-defines-a-service-fetch-with-inputs-and-mapped-outputs-req-sf-001
		 */
		reset() {
			this.values = {}
			this.result = null
			this.error = ''
		},

		/**
		 * Set one sample input.
		 *
		 * @param {string} name The input.
		 * @param {string} value Its value.
		 * @return {void}
		 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-administrator-defines-a-service-fetch-with-inputs-and-mapped-outputs-req-sf-001
		 */
		setValue(name, value) {
			this.values = { ...this.values, [name]: value ?? '' }
		},

		/**
		 * Run the fetch with the filled-in inputs; an empty field is not sent.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-administrator-defines-a-service-fetch-with-inputs-and-mapped-outputs-req-sf-001
		 */
		async runTest() {
			if (!this.slug || this.running) {
				return
			}
			const inputs = {}
			for (const input of this.inputs) {
				const value = String(this.values[input.name] ?? '')
				if (value !== '') {
					inputs[input.name] = value
				}
			}
			this.running = true
			this.error = ''
			this.result = null
			try {
				const { data } = await axios.post(
					generateUrl(`/apps/integriq/api/service-fetches/${encodeURIComponent(this.slug)}/test`),
					{ inputs },
				)
				this.result = {
					raw: data?.raw ?? null,
					outputs: data?.outputs ?? null,
					refusal: data?.refusal ?? null,
					detail: data?.detail ?? null,
				}
			} catch (e) {
				this.error = e?.response?.data?.error || t('integriq', 'The service fetch could not be tested.')
			} finally {
				this.running = false
			}
		},

		/**
		 * A value as indented JSON.
		 *
		 * @param {*} value The value.
		 * @return {string}
		 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-administrator-defines-a-service-fetch-with-inputs-and-mapped-outputs-req-sf-001
		 */
		pretty(value) {
			return JSON.stringify(value, null, 2)
		},

		/**
		 * Close the modal.
		 *
		 * @return {void}
		 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-administrator-defines-a-service-fetch-with-inputs-and-mapped-outputs-req-sf-001
		 */
		onClose() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.cn-test-service-fetch {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 4);
}

.cn-test-service-fetch__inputs {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.cn-test-service-fetch__actions {
	display: flex;
	justify-content: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}

.cn-test-service-fetch__panes {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
	gap: calc(var(--default-grid-baseline) * 3);
}

.cn-test-service-fetch__pane pre {
	white-space: pre-wrap;
	word-break: break-word;
	background-color: var(--color-background-dark);
	padding: calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius);
}

.cn-test-service-fetch__muted {
	color: var(--color-text-maxcontrast);
}

.cn-test-service-fetch__error {
	color: var(--color-error-text);
}
</style>

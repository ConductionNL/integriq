<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Test mapping modal — mounted by ModalHost on EVENT_OPEN_TEST_MAPPING from
  the Mappings row action, with the mapping pre-selected.

  Two panes: the input JSON textarea this component owns, and
  MappingResultPanel, which owns everything downstream of the run — the
  validation-schema picker, the request itself, the validation display and
  saving the result into a register. `:auto="false"` keeps it explicit:
  nothing hits the server until "Run test" is pressed.

  Closes #835.
-->
<template>
	<NcModal v-if="open" labelId="testMappingModal" size="large" @close="onClose">
		<div class="cn-test-mapping-modal">
			<h2>{{ t('integriq', 'Test mapping') }}</h2>

			<NcNoteCard v-if="mappingName" type="info">
				<p>
					{{
						t('integriq', 'Testing mapping: {name}', {
							name: mappingName,
						})
					}}
				</p>
			</NcNoteCard>

			<div class="cn-test-mapping-modal__panes">
				<!-- Left: input -->
				<section class="cn-test-mapping-modal__pane">
					<h3>{{ t('integriq', 'Input') }}</h3>
					<label for="cn-test-mapping-input">
						{{ t('integriq', 'Input object (JSON)') }}
					</label>
					<textarea
						id="cn-test-mapping-input"
						v-model="inputJson"
						class="cn-test-mapping-modal__textarea"
						rows="14"
						spellcheck="false"
						:placeholder="placeholder" />
					<p v-if="inputError" class="cn-test-mapping-modal__error">
						{{ inputError }}
					</p>

					<div class="cn-test-mapping-modal__actions">
						<NcButton @click="onClose">
							{{ t('integriq', 'Close') }}
						</NcButton>
						<NcButton
							variant="primary"
							:disabled="!canRun"
							@click="runTest">
							<template #icon>
								<PlayOutlineIcon :size="20" />
							</template>
							{{ t('integriq', 'Run test') }}
						</NcButton>
					</div>
				</section>

				<!-- Right: result -->
				<section class="cn-test-mapping-modal__pane">
					<h3>{{ t('integriq', 'Result') }}</h3>
					<MappingResultPanel
						ref="result"
						:mapping="mapping"
						:inputObject="inputJson"
						:auto="false"
						@inputError="inputError = $event" />
				</section>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcNoteCard } from '@nextcloud/vue'
import PlayOutlineIcon from 'vue-material-design-icons/PlayOutline.vue'
import MappingResultPanel from '../../components/mapping/MappingResultPanel.vue'

export default {
	name: 'TestMappingModal',

	components: {
		NcModal,
		NcButton,
		NcNoteCard,
		MappingResultPanel,
		PlayOutlineIcon,
	},

	props: {
		/** Whether the modal is mounted/visible. */
		open: { type: Boolean, default: false },
		/** Pre-selected mapping row to test (from the row-action context). */
		mapping: { type: Object, default: null },
	},

	data() {
		return {
			inputJson: '{\n  "example": "value"\n}',
			inputError: '',
		}
	},

	computed: {
		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		mappingName() {
			return this.mapping?.name || ''
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		placeholder() {
			return '{\n  "key": "value"\n}'
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		canRun() {
			return !!this.mapping && this.inputJson.trim().length > 0
		},
	},

	watch: {
		/**
		 * Clear the previous run when the modal is re-opened for another row.
		 *
		 * @param {boolean} value Whether the modal is now open.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		open(value) {
			if (value) {
				this.inputError = ''
				this.$refs.result?.reset()
			}
		},
	},

	methods: {
		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		onClose() {
			this.$emit('close')
		},

		/**
		 * Run the test. The request, the schema picker, the validation
		 * display and the save-to-register block all live in
		 * MappingResultPanel — this modal only owns the input textarea.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		runTest() {
			this.$refs.result?.run()
		},
	},
}
</script>

<style scoped>
.cn-test-mapping-modal {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 20px;
	min-width: 720px;
	max-width: 1100px;
}

.cn-test-mapping-modal__panes {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
	gap: 16px;
}

.cn-test-mapping-modal__pane {
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-width: 0;
}

.cn-test-mapping-modal__textarea {
	width: 100%;
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
	resize: vertical;
	padding: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.cn-test-mapping-modal__error {
	color: var(--color-error);
	font-size: 12px;
	margin: 0;
}

.cn-test-mapping-modal__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>

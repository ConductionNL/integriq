<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Run-action modal — the informative surface for the "Run now" / "Test" row
  actions on the Synchronizations and Jobs indexes, replacing the fire-and-forget
  POST-plus-toast those handlers used to be.

  It solves two problems beyond "show the result":

    1. The run is GATED. Both backends accept flags no UI could previously set —
       `force` / `forceDeletion` on a synchronization run, `forceRun` on a job —
       and `forceDeletion` in particular is documented as an "explicit,
       human-in-the-loop override" of the deletion-ratio guard. The options step
       is that human and that loop. It also means a misclick in the row menu can
       no longer write to a target system.
    2. The returned run log is RENDERED. Both endpoints return a full log —
       object counters and contract references for a synchronization, level and
       stack trace for a job — which the toast threw away.

  Three states in one dialog: options → running → result. All entity-specific
  knowledge (endpoints, switches, result shape, log link) lives in runTargets.js;
  this component only knows how to render a descriptor.

  Modelled on TestSourceModal.vue, the closest sibling — same open/close contract,
  same reset-on-open watcher, same styling vocabulary.
-->
<template>
	<NcModal v-if="open && descriptor"
		label-id="runActionModal"
		size="normal"
		@close="onClose">
		<div class="cn-run-action">
			<h2>{{ descriptor.title }}</h2>
			<p v-if="subjectName" class="cn-run-action__subject">
				{{ subjectName }}
			</p>

			<NcNoteCard v-if="descriptor.intro" type="info">
				<p>{{ descriptor.intro }}</p>
			</NcNoteCard>

			<!-- Step 1: options. Nothing has been sent yet. -->
			<template v-if="step === 'options'">
				<div v-for="option in options" :key="option.key" class="cn-run-action__option">
					<NcNoteCard :type="noteTypeFor(option)">
						<p>{{ noteFor(option) }}</p>
					</NcNoteCard>
					<NcCheckboxRadioSwitch type="switch"
						:model-value="values[option.key] === true"
						:disabled="isOptionDisabled(option)"
						@update:model-value="setOption(option, $event)">
						{{ option.label }}
					</NcCheckboxRadioSwitch>
				</div>

				<NcNoteCard v-if="!subjectId" type="error">
					<p>{{ t('openconnector', 'This row has no id, so it cannot be run.') }}</p>
				</NcNoteCard>

				<div class="cn-run-action__actions">
					<NcButton @click="onClose">
						{{ t('openconnector', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" :disabled="!subjectId" @click="fire">
						<template #icon>
							<PlayOutlineIcon :size="20" />
						</template>
						{{ descriptor.runLabel }}
					</NcButton>
				</div>
			</template>

			<!-- Step 2: in flight. -->
			<div v-else-if="step === 'running'" class="cn-run-action__running">
				<NcLoadingIcon :size="44" :name="descriptor.title" />
				<p>{{ t('openconnector', 'Running… this can take a while for a large source.') }}</p>
			</div>

			<!-- Step 3: outcome. -->
			<template v-else>
				<NcNoteCard :type="status.type">
					<p>{{ status.text }}</p>
				</NcNoteCard>

				<section v-for="section in sections" :key="section.id" class="cn-run-action__section">
					<h3>{{ section.title }}</h3>

					<!-- Prose explanation with optional supporting figures —
					     a tripped deletion guard. -->
					<template v-if="section.kind === 'note'">
						<NcNoteCard :type="section.noteType || 'info'">
							<p>{{ section.value }}</p>
						</NcNoteCard>
						<dl v-if="section.rows && section.rows.length" class="cn-run-action__meta">
							<template v-for="row in section.rows" :key="row.label">
								<dt>{{ row.label }}</dt>
								<dd>{{ row.value }}</dd>
							</template>
						</dl>
					</template>

					<!-- Counter grid — the synchronization object tallies. -->
					<div v-else-if="section.kind === 'counters'" class="cn-run-action__counters">
						<div v-for="cell in section.value" :key="cell.label" class="cn-run-action__counter">
							<span class="cn-run-action__counter-value">{{ cell.value }}</span>
							<span class="cn-run-action__counter-label">{{ cell.label }}</span>
						</div>
					</div>

					<!-- Label/value pairs. -->
					<dl v-else-if="section.kind === 'meta'" class="cn-run-action__meta">
						<template v-for="row in section.value" :key="row.label">
							<dt>{{ row.label }}</dt>
							<dd>{{ row.value }}</dd>
						</template>
					</dl>

					<!-- Ordered lines — a job's stack-trace frames. -->
					<ol v-else-if="section.kind === 'list'" class="cn-run-action__list">
						<li v-for="(line, index) in section.value" :key="index">
							{{ line }}
						</li>
					</ol>

					<pre v-else class="cn-run-action__pre">{{ section.value }}</pre>
				</section>

				<details v-if="payload !== null" class="cn-run-action__raw">
					<summary>{{ t('openconnector', 'Raw payload') }}</summary>
					<pre class="cn-run-action__pre">{{ formattedPayload }}</pre>
				</details>

				<div class="cn-run-action__actions">
					<NcButton v-if="logsLink" @click="openLogs">
						<template #icon>
							<TextBoxOutlineIcon :size="20" />
						</template>
						{{ t('openconnector', 'View full log') }}
					</NcButton>
					<NcButton v-if="retryAction" type="secondary" @click="runAgain(retryAction.values)">
						<template #icon>
							<RestartIcon :size="20" />
						</template>
						{{ retryAction.label }}
					</NcButton>
					<NcButton @click="runAgain()">
						<template #icon>
							<RestartIcon :size="20" />
						</template>
						{{ t('openconnector', 'Run again') }}
					</NcButton>
					<NcButton type="primary" @click="onClose">
						{{ t('openconnector', 'Close') }}
					</NcButton>
				</div>
			</template>
		</div>
	</NcModal>
</template>

<script>
import {
	NcModal,
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import PlayOutlineIcon from 'vue-material-design-icons/PlayOutline.vue'
import RestartIcon from 'vue-material-design-icons/Restart.vue'
import TextBoxOutlineIcon from 'vue-material-design-icons/TextBoxOutline.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	getRunDescriptor,
	initialOptionValues,
	visibleOptions,
	rowId,
} from './runTargets.js'

export default {
	name: 'RunActionModal',

	components: {
		NcModal,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		PlayOutlineIcon,
		RestartIcon,
		TextBoxOutlineIcon,
	},

	props: {
		/** Whether the modal is mounted/visible. */
		open: { type: Boolean, default: false },
		/** Which entity is being run — `synchronization` or `job`. */
		target: { type: String, default: '' },
		/** Which action — `run` or `test`. */
		mode: { type: String, default: '' },
		/** The row the action was invoked on. */
		item: { type: Object, default: null },
	},

	data() {
		return {
			/** 'options' | 'running' | 'result' */
			step: 'options',
			values: {},
			payload: null,
			/** Set when the request itself failed, rather than the run. */
			requestError: '',
		}
	},

	computed: {
		/** @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004 */
		descriptor() {
			return getRunDescriptor(this.target, this.mode)
		},

		/** @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004 */
		subjectId() {
			return rowId(this.item)
		},

		/** @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004 */
		subjectName() {
			return (this.item?.name || this.item?.title || '')
		},

		/** @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004 */
		options() {
			return visibleOptions(this.descriptor, this.values)
		},

		/**
		 * A transport failure outranks the descriptor's own reading of the
		 * payload — there is no payload to read in that case.
		 *
		 * @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004
		 */
		status() {
			if (this.requestError) {
				return { type: 'error', text: this.requestError }
			}

			return (this.descriptor?.status(this.payload) ?? { type: 'info', text: '' })
		},

		/** @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004 */
		sections() {
			if (this.requestError) {
				return []
			}

			return (this.descriptor?.sections(this.payload) ?? [])
		},

		/** @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004 */
		logsLink() {
			if (this.requestError) {
				return null
			}

			return (this.descriptor?.logsLink(this.item) ?? null)
		},

		/**
		 * A one-click re-run with the switch that would have changed the
		 * outcome already flipped, when the run identified one. Only the
		 * deletion-ratio guard does today.
		 *
		 * @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004
		 */
		retryAction() {
			if (this.requestError || typeof this.descriptor?.retry !== 'function') {
				return null
			}

			return this.descriptor.retry(this.payload)
		},

		/** @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004 */
		formattedPayload() {
			try {
				return JSON.stringify(this.payload, null, 2)
			} catch (_e) {
				return String(this.payload)
			}
		},
	},

	watch: {
		/** @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004 */
		open(value) {
			if (value) {
				this.resetState()
			}
		},
	},

	methods: {
		/** @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004 */
		onClose() {
			this.$emit('close')
		},

		/** @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004 */
		resetState() {
			this.step = 'options'
			this.values = initialOptionValues(this.descriptor)
			this.payload = null
			this.requestError = ''
		},

		/**
		 * Keep the flags but go back to the options step, so a second run can be
		 * fired with the same or adjusted settings without reopening the modal.
		 *
		 * Returns to the options step rather than firing immediately even when
		 * `overrides` are supplied: a re-run that overrides a guard is exactly
		 * the kind of thing that should still be confirmed, and it lets the user
		 * see which switch was flipped on their behalf.
		 *
		 * @param {object} [overrides] Switch values to pre-set, e.g. from a guard's retry hint.
		 *
		 * @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004
		 */
		runAgain(overrides) {
			if (overrides) {
				this.values = { ...this.values, ...overrides }
			}
			this.step = 'options'
			this.payload = null
			this.requestError = ''
		},

		/** @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004 */
		isOptionDisabled(option) {
			if (option.locked === true) {
				return true
			}

			if (typeof option.disabledWhen === 'function') {
				return option.disabledWhen(this.item) === true
			}

			return false
		},

		/**
		 * A disabled switch explains why it is disabled rather than what it would
		 * have done — the latter is useless when it cannot be turned on.
		 *
		 * @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004
		 */
		noteFor(option) {
			if (option.disabledNote && typeof option.disabledWhen === 'function'
				&& option.disabledWhen(this.item) === true) {
				return option.disabledNote
			}

			return option.note
		},

		/** @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004 */
		noteTypeFor(option) {
			if (option.disabledNote && typeof option.disabledWhen === 'function'
				&& option.disabledWhen(this.item) === true) {
				return 'info'
			}

			return (option.noteType || 'info')
		},

		/**
		 * Set one switch. Reassigns `values` wholesale so a `hiddenWhen` keyed on
		 * another switch re-evaluates.
		 *
		 * @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004
		 */
		setOption(option, value) {
			if (this.isOptionDisabled(option)) {
				return
			}

			this.values = { ...this.values, [option.key]: (value === true) }
		},

		/**
		 * POST the descriptor's request and move to the result step.
		 *
		 * Note the deliberate absence of toasts: the modal is the surface, and a
		 * toast on top of an open dialog reporting the same thing is noise.
		 *
		 * @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004
		 */
		async fire() {
			if (!this.subjectId) {
				return
			}

			if (!this.descriptor) {
				return
			}

			const { url, body } = this.descriptor.request(this.item, this.values)

			this.step = 'running'
			this.payload = null
			this.requestError = ''

			try {
				const response = await axios.post(generateUrl(url), body)
				// A literal `null` body is a real, documented outcome (a job that was
				// not due) — `?? null` normalises `undefined` without swallowing it.
				this.payload = (response.data ?? null)
			} catch (err) {
				this.requestError = this.extractError(err)
			} finally {
				this.step = 'result'
			}
		},

		/**
		 * Surface the server's own message where there is one — both controllers
		 * return `{ error, message }` on failure — rather than a bare
		 * "request failed".
		 *
		 * @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004
		 */
		extractError(err) {
			const data = (err?.response?.data || {})
			const detail = (data.message || data.error || err?.message || '')

			if (detail) {
				return detail
			}

			return t('openconnector', 'The request failed.')
		},

		/**
		 * Leave for the filtered logs page. Closing first matters: a route change
		 * under an open NcModal leaves the dialog orphaned over the new page.
		 *
		 * @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004
		 */
		openLogs() {
			const link = this.logsLink
			if (!link) {
				return
			}

			this.onClose()

			this.$router.push(link).catch((err) => {
				// vue-router throws NavigationDuplicated when pushing the same route
				// twice; swallow that specific case, surface anything else.
				if (err && err.name !== 'NavigationDuplicated') {
					// eslint-disable-next-line no-console
					console.warn('[openconnector] RunActionModal log navigation failed', err)
				}
			})
		},
	},
}
</script>

<style scoped>
.cn-run-action {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
	min-width: 0;
}

.cn-run-action__subject {
	margin: -8px 0 0;
	color: var(--color-text-maxcontrast);
}

.cn-run-action__option {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.cn-run-action__running {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 32px 0;
	color: var(--color-text-maxcontrast);
}

.cn-run-action__section h3 {
	font-size: 15px;
	margin: 0 0 6px;
}

.cn-run-action__counters {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: 8px;
}

.cn-run-action__counter {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 2px;
	padding: 8px 4px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.cn-run-action__counter-value {
	font-size: 20px;
	font-weight: bold;
}

.cn-run-action__counter-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.cn-run-action__meta {
	display: grid;
	grid-template-columns: max-content minmax(0, 1fr);
	gap: 2px 12px;
	margin: 0;
}

.cn-run-action__meta dt {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.cn-run-action__meta dd {
	margin: 0;
	overflow-wrap: anywhere;
}

.cn-run-action__list {
	margin: 0;
	padding-inline-start: 20px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 12px;
	max-height: 220px;
	overflow: auto;
}

.cn-run-action__pre {
	background: var(--color-background-dark);
	color: var(--color-main-text);
	padding: 12px;
	border-radius: var(--border-radius);
	overflow: auto;
	max-height: 260px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 12px;
	white-space: pre-wrap;
	word-break: break-word;
}

.cn-run-action__raw summary {
	cursor: pointer;
	color: var(--color-text-maxcontrast);
}

.cn-run-action__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
	flex-wrap: wrap;
}
</style>

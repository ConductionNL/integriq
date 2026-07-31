<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  SyncMappingPreview — inline side-by-side preview of `inputObject →
  mappingResult` for the mapping picked in a Synchronization. Mirrors the
  surface in TestMappingModal but lives directly under the mapping picker
  so the user doesn't have to context-switch into a modal to verify a
  transformation while wiring a sync.

  Wiring contract:
    - prop `mappingId` is the slug (or id) stored in
      `synchronization.sourceTargetMapping`. The component looks the full
      mapping record up via `/api/objects/openconnector/mapping/{id}` so
      it can POST the object (not just the slug) to the test endpoint —
      that endpoint expects the full mapping payload, not a reference.
    - The sample input defaults to `{}` but can be edited freely. Changes
      to either the mapping id or the input debounce-fire the preview
      request (300ms) so typing doesn't fill the network tab.
    - Errors render inline (parse error vs network error styled the same).

  Closes #878 part 2. Open follow-ups (intentional non-goals):
    - Pulling a real sample record from the source side. The brief
      mentioned auto-populating from `/api/objects/openconnector/{schema}/{uuid}`
      when sourceType=register; punted because the picker only owns the
      mapping slug, not the source identity. The user can paste a sample
      object directly into the input field — same UX as the test modal.
-->
<template>
	<div class="sync-mapping-preview">
		<div class="sync-mapping-preview__header" :class="{ 'sync-mapping-preview__header--collapsed': !expanded }">
			<NcButton
				type="tertiary-no-background"
				:aria-label="expanded ? t('openconnector', 'Hide mapping preview') : t('openconnector', 'Show mapping preview')"
				@click="expanded = !expanded">
				<template #icon>
					<ChevronDown v-if="expanded" :size="18" />
					<ChevronRight v-else :size="18" />
				</template>
				{{ t('openconnector', 'Preview') }}
			</NcButton>
			<span class="sync-mapping-preview__hint">
				{{ t('openconnector', 'Run the picked mapping against a sample object to see the transformed output.') }}
			</span>
			<div class="sync-mapping-preview__spacer" />
			<NcLoadingIcon v-if="running" :size="18" />
		</div>

		<div v-if="expanded" class="sync-mapping-preview__body">
			<div v-if="!mappingId" class="sync-mapping-preview__empty">
				{{ t('openconnector', 'Pick a Source → Target mapping above to enable the preview.') }}
			</div>
			<template v-else>
				<div class="sync-mapping-preview__panes">
					<section class="sync-mapping-preview__pane">
						<label :for="inputId" class="sync-mapping-preview__label">
							{{ t('openconnector', 'Sample input (JSON)') }}
						</label>
						<textarea
							:id="inputId"
							class="sync-mapping-preview__textarea"
							:value="inputJson"
							rows="10"
							spellcheck="false"
							@input="onInput($event.target.value)" />
						<p v-if="inputError" class="sync-mapping-preview__error">
							{{ inputError }}
						</p>
					</section>

					<section class="sync-mapping-preview__pane">
						<label class="sync-mapping-preview__label">
							{{ t('openconnector', 'Mapping output') }}
						</label>
						<div v-if="loadError" class="sync-mapping-preview__error">
							{{ loadError }}
						</div>
						<div v-else-if="runError" class="sync-mapping-preview__error">
							{{ runError }}
						</div>
						<pre v-else-if="resultJson" class="sync-mapping-preview__pre">{{ resultJson }}</pre>
						<div v-else class="sync-mapping-preview__placeholder">
							{{ t('openconnector', 'Type in the input pane to see the transformed output here.') }}
						</div>
					</section>
				</div>
			</template>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

let previewSeq = 0

/**
 * Debounce window for preview requests. Keeps typing-driven re-runs from
 * hammering the test endpoint while remaining short enough that the user
 * perceives the panel as "live". Matches the value used by the equivalent
 * preview surface in the Mapping editor (#876).
 */
const DEBOUNCE_MS = 300

export default {
	name: 'SyncMappingPreview',

	components: {
		NcButton,
		NcLoadingIcon,
		ChevronDown,
		ChevronRight,
	},

	props: {
		/**
		 * The slug (or id) of the picked mapping. The component fetches
		 * the full mapping object so the test endpoint receives the object
		 * payload it requires — passing just the slug is not enough.
		 */
		mappingId: { type: String, default: '' },
	},

	data() {
		const seq = ++previewSeq
		return {
			previewUid: seq,
			expanded: false,
			inputJson: '{\n  "example": "value"\n}',
			inputError: '',
			loadError: '',
			runError: '',
			result: null,
			running: false,
			mapping: null,
			debounceTimer: null,
		}
	},

	computed: {
		/** @spec openspec/specs/sync-editor-ui/spec.md */
		inputId() {
			return `sync-mapping-preview-${this.previewUid}-input`
		},
		/** @spec openspec/specs/sync-editor-ui/spec.md */
		resultJson() {
			if (this.result === null) return ''
			try {
				return JSON.stringify(this.result, null, 2)
			} catch (_e) {
				return String(this.result)
			}
		},
	},

	watch: {
		mappingId: {
			immediate: true,
			/**
			 * Drop the result and any errors left over from the previously
			 * previewed mapping, then re-run only when the panel is open (a
			 * collapsed panel defers loading to the `expanded` watcher).
			 *
			 * @param {string} newId The newly picked mapping slug (or id); ''
			 *   when the picker was cleared.
			 * @param {string} oldId The previously previewed mapping slug — used
			 *   to skip the no-op re-notification the immediate watcher fires.
			 *
			 * @spec openspec/specs/sync-editor-ui/spec.md
			 */
			handler(newId, oldId) {
				if (newId === oldId) return
				// Clear stale state before loading the new mapping.
				this.result = null
				this.runError = ''
				this.loadError = ''
				if (!newId) {
					this.mapping = null
					return
				}
				if (this.expanded) {
					this.loadAndRun()
				}
			},
		},
		/**
		 * Lazy-load on first open: the mapping is only fetched (and the preview
		 * only run) once the user actually expands the panel.
		 *
		 * @param {boolean} value The new panel state — true when just opened.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		expanded(value) {
			if (value && this.mappingId && !this.mapping) {
				this.loadAndRun()
			}
		},
	},

	/** @spec openspec/specs/sync-editor-ui/spec.md */
	beforeUnmount() {
		if (this.debounceTimer) {
			window.clearTimeout(this.debounceTimer)
		}
	},

	methods: {
		/**
		 * Track the sample-input textarea and debounce a preview run, so the
		 * test endpoint is not hit on every keystroke.
		 *
		 * @param {string} value The current raw textarea contents (parsed as
		 *   JSON later, in `runPreview`).
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		onInput(value) {
			this.inputJson = value
			this.scheduleRun()
		},
		/** @spec openspec/specs/sync-editor-ui/spec.md */
		scheduleRun() {
			if (this.debounceTimer) {
				window.clearTimeout(this.debounceTimer)
			}
			this.debounceTimer = window.setTimeout(() => {
				this.debounceTimer = null
				this.runPreview()
			}, DEBOUNCE_MS)
		},
		/** @spec openspec/specs/sync-editor-ui/spec.md */
		async loadAndRun() {
			this.loadError = ''
			try {
				// Mappings are keyed by slug in the picker, but OR's object
				// endpoint resolves either slug or uuid against the lookup
				// key — so the same URL works for legacy id-keyed rows too.
				const response = await axios.get(
					generateUrl('/apps/openregister/api/objects/openconnector/mapping/{id}', {
						id: this.mappingId,
					}),
				)
				this.mapping = response.data?.object || response.data || null
				if (!this.mapping) {
					this.loadError = t('openconnector', 'Mapping not found.')
					return
				}
				this.runPreview()
			} catch (err) {
				// eslint-disable-next-line no-console
				console.warn('[SyncMappingPreview] mapping fetch failed', err)
				this.loadError = err?.response?.data?.message
					|| err?.message
					|| t('openconnector', 'Failed to load mapping.')
			}
		},
		/** @spec openspec/specs/sync-editor-ui/spec.md */
		async runPreview() {
			if (!this.mapping) {
				// If the panel was just expanded without a cached mapping,
				// load first — runPreview will fire again from loadAndRun.
				if (this.mappingId) return this.loadAndRun()
				return
			}
			this.inputError = ''
			this.runError = ''
			let parsedInput = null
			const raw = (this.inputJson || '').trim()
			try {
				parsedInput = raw.length > 0 ? JSON.parse(raw) : {}
			} catch (parseErr) {
				this.inputError = t(
					'openconnector',
					'Input is not valid JSON: {message}',
					{ message: parseErr.message },
				)
				this.result = null
				return
			}
			this.running = true
			try {
				const response = await axios.post(
					generateUrl('/apps/openconnector/api/mappings/test'),
					{
						inputObject: parsedInput,
						mapping: this.mapping,
					},
				)
				this.result = response.data?.resultObject ?? response.data
			} catch (err) {
				this.runError = err?.response?.data?.message
					|| err?.message
					|| t('openconnector', 'Mapping preview failed.')
				this.result = null
			} finally {
				this.running = false
			}
		},
	},
}
</script>

<style scoped>
.sync-mapping-preview {
	border-top: 1px solid var(--color-border);
	padding-top: 10px;
	margin-top: 4px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.sync-mapping-preview__header {
	display: flex;
	align-items: center;
	gap: 8px;
}

.sync-mapping-preview__hint {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.sync-mapping-preview__spacer {
	flex: 1;
}

.sync-mapping-preview__body {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.sync-mapping-preview__panes {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
	gap: 12px;
}

@media (max-width: 800px) {
	.sync-mapping-preview__panes {
		grid-template-columns: 1fr;
	}
}

.sync-mapping-preview__pane {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 0;
}

.sync-mapping-preview__label {
	font-weight: bold;
	font-size: 12px;
}

.sync-mapping-preview__textarea {
	width: 100%;
	font-family: var(--font-face-monospace, monospace);
	font-size: 12px;
	resize: vertical;
	padding: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.sync-mapping-preview__pre {
	margin: 0;
	background: var(--color-background-dark);
	color: var(--color-main-text);
	padding: 8px;
	border-radius: var(--border-radius);
	overflow: auto;
	max-height: 240px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 12px;
	white-space: pre-wrap;
	word-break: break-word;
}

.sync-mapping-preview__placeholder,
.sync-mapping-preview__empty {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	font-style: italic;
	padding: 8px;
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
	text-align: center;
}

.sync-mapping-preview__error {
	color: var(--color-error);
	font-size: 12px;
	margin: 0;
}
</style>

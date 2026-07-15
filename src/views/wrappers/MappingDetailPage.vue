<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  MappingDetailPage — bespoke detail view for a Mapping object.

  Why a custom page (manifest `type: custom`, `component: MappingDetailPage`):
  The standard `CnDetailPage` is enough for the name/description headline,
  but the substance of a Mapping lives in three rule collections that the
  generic JSON-property editor (CnFormDialog `widget: 'json'`) cannot
  comfortably surface:

    - `mapping` rules : { targetProperty → twig-template-string }
    - `cast`    rules : { property        → castType (string|integer|…) }
    - `unset`   rules : [ propertyToRemove, … ]

  The legacy `src/modals/Mapping/EditMapping.vue` (1537 LoC) carried this
  with a Transform pane that paginated by sub-rule kind through a tabular
  add/edit/delete interface. This component reproduces that UX semantics
  on top of `CnDetailPage` — the rules editor is delegated to
  `MappingRulesEditor`, which handles the 3-tab table + the per-row dialog.

  Persistence goes through `useObjectStore` on the parent mapping object:
  every rule mutation diffs the local state into a `{ mapping, cast, unset }`
  patch and calls `objectStore.saveObject('mapping', { id, …patch })`. The
  store re-fetches automatically, so the rules editor reacts to the
  fresh values without a manual reload.

  Right of the rules editor sits a **live preview pane** (closes #876):
  a JSON textarea for a sample input plus a read-only pretty-printed
  output of `POST /api/mappings/test`. Debounced at 400 ms; re-fires on
  every rule edit or sample-input change. Server-side errors render
  inline; a "Reset preview" button clears the sample and result.

  The "Test mapping" header action re-uses the existing v2 modal pattern
  from #867: emit `EVENT_OPEN_TEST_MAPPING` on `modalBus`, the App.vue
  `ModalHost` picks it up and mounts `TestMappingModal` with the current
  mapping pre-selected.

  Closes ConductionNL/openconnector#832 and #876.
-->
<template>
	<CnDetailPage
		:title="title"
		:description="description"
		:loading="loading"
		:error="!!loadError"
		:error-message="errorMessage"
		:on-retry="reload"
		:object-type="objectType"
		:object-id="resolvedId"
		:sidebar="sidebarConfig"
		:subscribe="true">
		<template #actions>
			<NcButton :disabled="!hasMapping" @click="openTestModal">
				<template #icon>
					<PlayOutlineIcon :size="20" />
				</template>
				{{ t('openconnector', 'Test mapping') }}
			</NcButton>
		</template>

		<div v-if="hasMapping" class="cn-mapping-detail">
			<!-- General info card -->
			<section class="cn-mapping-detail__card">
				<h3 class="cn-mapping-detail__section-title">
					{{ t('openconnector', 'General') }}
				</h3>
				<dl class="cn-mapping-detail__meta">
					<dt>{{ t('openconnector', 'Name') }}</dt>
					<dd>{{ mapping.name || '-' }}</dd>
					<dt>{{ t('openconnector', 'Description') }}</dt>
					<dd>{{ mapping.description || '-' }}</dd>
					<dt>{{ t('openconnector', 'Pass through') }}</dt>
					<dd>
						<NcCheckboxRadioSwitch :checked="!!mapping.passThrough"
							:disabled="saving"
							@update:checked="onTogglePassThrough">
							{{ passThroughLabel }}
						</NcCheckboxRadioSwitch>
						<p class="cn-mapping-detail__hint">
							{{ t('openconnector', 'When enabled, fields from the input object are copied through unless explicitly unset.') }}
						</p>
					</dd>
				</dl>
			</section>

			<!-- Rules + preview, side by side -->
			<div class="cn-mapping-detail__split">
				<section class="cn-mapping-detail__card cn-mapping-detail__rules">
					<h3 class="cn-mapping-detail__section-title">
						{{ t('openconnector', 'Transformation rules') }}
					</h3>
					<MappingRulesEditor
						:mapping-rules="mappingRules"
						:cast-rules="castRules"
						:unset-rules="unsetRules"
						:pass-through="!!mapping.passThrough"
						:saving="saving"
						@update-mapping="onUpdateMapping"
						@update-cast="onUpdateCast"
						@update-unset="onUpdateUnset" />
				</section>

				<section class="cn-mapping-detail__card cn-mapping-detail__preview">
					<div class="cn-mapping-detail__preview-header">
						<h3 class="cn-mapping-detail__section-title">
							{{ t('openconnector', 'Live preview') }}
						</h3>
						<NcButton type="tertiary"
							:disabled="previewLoading"
							@click="resetPreview">
							<template #icon>
								<RestoreIcon :size="18" />
							</template>
							{{ t('openconnector', 'Reset preview') }}
						</NcButton>
					</div>
					<p class="cn-mapping-detail__hint">
						{{ t('openconnector', 'Edit the sample input below; the output re-renders as you type and on every rule change.') }}
					</p>

					<label for="cn-mapping-detail__sample-input" class="cn-mapping-detail__field-label">
						{{ t('openconnector', 'Sample input (JSON)') }}
					</label>
					<textarea id="cn-mapping-detail__sample-input"
						v-model="sampleInput"
						class="cn-mapping-detail__textarea"
						rows="8"
						spellcheck="false"
						:placeholder="samplePlaceholder" />
					<p v-if="sampleParseError" class="cn-mapping-detail__error">
						{{ sampleParseError }}
					</p>

					<div class="cn-mapping-detail__preview-output">
						<div class="cn-mapping-detail__field-label cn-mapping-detail__preview-output-label">
							<span>{{ t('openconnector', 'Output') }}</span>
							<NcLoadingIcon v-if="previewLoading" :size="16" />
						</div>
						<NcNoteCard v-if="previewError" type="error">
							<p>{{ previewError }}</p>
						</NcNoteCard>
						<pre v-else-if="previewOutput !== null"
							class="cn-mapping-detail__pre"><!--
							-->{{ formattedPreview }}</pre>
						<p v-else class="cn-mapping-detail__preview-empty">
							{{ t('openconnector', 'Output will appear here once a sample input and rules produce a result.') }}
						</p>
					</div>
				</section>
			</div>
		</div>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/objectStore.js'
import liveObjectSubscription from '../../mixins/liveObjectSubscription.js'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import PlayOutlineIcon from 'vue-material-design-icons/PlayOutline.vue'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import debounce from 'lodash/debounce.js'

import MappingRulesEditor from './MappingRulesEditor.vue'
import { modalBus, EVENT_OPEN_TEST_MAPPING } from '../../handlers/modalBus.js'

/** Default sample input shown until the user edits the textarea. */
const DEFAULT_SAMPLE = '{}'

/** Debounce delay for preview requests, in milliseconds. */
const PREVIEW_DEBOUNCE_MS = 400

/**
 * Normalise the `mapping` field (Twig-template map) of a Mapping object to
 * a plain JS object regardless of the storage shape (string or object).
 *
 * @param {*} raw The raw value from the Mapping record.
 * @return {object} Object with `{ targetProperty: template }` shape.
 */
function asObjectMap(raw) {
	if (!raw) return {}
	if (typeof raw === 'string') {
		try {
			const parsed = JSON.parse(raw)
			return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
				? parsed
				: {}
		} catch (_e) {
			return {}
		}
	}
	if (typeof raw === 'object' && !Array.isArray(raw)) return raw
	return {}
}

/**
 * Normalise the `unset` field of a Mapping object to a plain string array.
 *
 * The legacy storage shape was a comma-separated string; current OR shape
 * is a JSON array. Either is accepted here.
 *
 * @param {*} raw The raw value from the Mapping record.
 * @return {Array<string>} List of property names to unset.
 */
function asUnsetList(raw) {
	if (!raw) return []
	if (Array.isArray(raw)) {
		return raw.filter((entry) => typeof entry === 'string' && entry.length > 0)
	}
	if (typeof raw === 'string') {
		return raw.split(/ *, */g).filter(Boolean)
	}
	return []
}

export default {
	name: 'MappingDetailPage',

	components: {
		CnDetailPage,
		MappingRulesEditor,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		PlayOutlineIcon,
		RestoreIcon,
	},

	mixins: [liveObjectSubscription],

	props: {
		/**
		 * Mapping ID from the URL (`/mappings/:id`). The route is declared
		 * with `props: true` so the value is forwarded here by CnPageRenderer.
		 */
		id: {
			type: [String, Number],
			default: '',
		},
		/**
		 * Manifest-config: the OR register slug. Forwarded by
		 * CnPageRenderer from `pages[].config.register`.
		 */
		register: {
			type: String,
			default: 'openconnector',
		},
		/**
		 * Manifest-config: the OR schema slug. Forwarded by
		 * CnPageRenderer from `pages[].config.schema`.
		 */
		schema: {
			type: String,
			default: 'mapping',
		},
	},

	data() {
		return {
			objectType: 'mapping',
			saving: false,
			sampleInput: DEFAULT_SAMPLE,
			sampleParseError: '',
			previewLoading: false,
			previewError: '',
			previewOutput: null,
		}
	},

	computed: {
		/**
		 * Pinia store instance.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-1
		 */
		store() {
			return useObjectStore()
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-1 */
		resolvedId() {
			return this.id != null ? String(this.id) : ''
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-1 */
		mapping() {
			if (!this.resolvedId) return {}
			return this.store.getObject(this.objectType, this.resolvedId) || {}
		},
		hasMapping() {
			return !!this.mapping && Object.keys(this.mapping).length > 0
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-1 */
		loading() {
			return !!this.store.loading?.[this.objectType] && !this.hasMapping
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-1 */
		loadError() {
			return this.store.errors?.[this.objectType] || null
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-1 */
		errorMessage() {
			const err = this.loadError
			if (!err) return ''
			return err.message
				|| this.t('openconnector', 'Failed to load mapping')
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-1 */
		title() {
			return this.mapping?.name || this.t('openconnector', 'Mapping')
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-1 */
		description() {
			return this.mapping?.description || ''
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-1 */
		passThroughLabel() {
			return this.mapping?.passThrough
				? this.t('openconnector', 'Pass through enabled')
				: this.t('openconnector', 'Pass through disabled')
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-1 */
		sidebarConfig() {
			return {
				enabled: true,
				show: true,
				register: this.register,
				schema: this.schema,
				showMetadata: true,
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-2 */
		mappingRules() {
			return asObjectMap(this.mapping?.mapping)
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-2 */
		castRules() {
			return asObjectMap(this.mapping?.cast)
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-2 */
		unsetRules() {
			return asUnsetList(this.mapping?.unset)
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-4 */
		samplePlaceholder() {
			return '{\n  "name": "hello"\n}'
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-4 */
		formattedPreview() {
			try {
				return JSON.stringify(this.previewOutput, null, 2)
			} catch (_e) {
				return String(this.previewOutput)
			}
		},
		/**
		 * Combined reactivity key for the preview watcher. Recomputing this
		 * forces the debounced preview to refire whenever the rule shape or
		 * sample input changes.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-1
		 */
		previewSignal() {
			return JSON.stringify({
				mapping: this.mappingRules,
				cast: this.castRules,
				unset: this.unsetRules,
				passThrough: !!this.mapping?.passThrough,
				input: this.sampleInput,
			})
		},
	},

	watch: {
		previewSignal: {
			immediate: false,
			/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-1 */
			handler() {
				this.schedulePreview()
			},
		},
	},

	/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-1 */
	mounted() {
		this.ensureRegistered()
		const fetch = this.reload()
		// Trigger the first preview render once data lands.
		Promise.resolve(fetch).finally(() => this.schedulePreview())
		// Live updates: or-object-{uuid} events refetch this mapping into the
		// store cache; the `mapping` computed reads the cache directly, so no
		// bridge callback is needed — reactivity re-renders the page.
		if (this.resolvedId) {
			this.syncLiveSubscription(this.objectType, this.resolvedId)
		}
	},

	/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-1 */
	beforeDestroy() {
		if (this.schedulePreview && this.schedulePreview.cancel) {
			this.schedulePreview.cancel()
		}
	},

	/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-1 */
	created() {
		// Bind the debounced function on the instance so each component gets
		// its own timer and `.cancel()` works on teardown.
		this.schedulePreview = debounce(this.runPreview, PREVIEW_DEBOUNCE_MS)
	},

	methods: {
		/**
		 * Register the `mapping` type on the shared object store the first
		 * time this page mounts. Idempotent — registering twice with the
		 * same arguments is a no-op modulo Vue reactivity churn, but we
		 * gate on `objectTypeRegistry` anyway.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-1
		 */
		ensureRegistered() {
			const registry = this.store.objectTypeRegistry || {}
			if (registry[this.objectType]) return
			if (typeof this.store.registerObjectType !== 'function') return
			this.store.registerObjectType(
				this.objectType,
				this.schema,
				this.register,
				{
					registerSlug: this.register,
					schemaSlug: this.schema,
				},
			)
		},

		/**
		 * Force a server-side re-fetch of the current mapping.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-1
		 */
		reload() {
			if (!this.resolvedId) return Promise.resolve(null)
			return this.store.fetchObject(this.objectType, this.resolvedId)
		},

		/**
		 * Emit the bus event picked up by ModalHost (`src/modals/v2/ModalHost.vue`)
		 * to mount TestMappingModal with the current mapping pre-loaded.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-4
		 */
		openTestModal() {
			if (!this.hasMapping) return
			modalBus.$emit(EVENT_OPEN_TEST_MAPPING, { mapping: this.mapping })
		},

		/**
		 * Persist a patch through the object store. Replaces the full
		 * mapping object (`PUT /api/objects/<register>/<schema>/<id>`) so
		 * the existing values for unchanged fields stay intact.
		 *
		 * @param {object} patch Fields to merge over the current mapping.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-1
		 */
		async persistPatch(patch) {
			if (!this.hasMapping) return
			this.saving = true
			try {
				const merged = { ...this.mapping, ...patch }
				const result = await this.store.saveObject(this.objectType, merged)
				if (!result) {
					const message = this.store.errors?.[this.objectType]?.message
						|| this.t('openconnector', 'Failed to save mapping')
					showError(message)
					return
				}
				showSuccess(this.t('openconnector', 'Mapping saved'))
			} finally {
				this.saving = false
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-2 */
		onUpdateMapping(nextRules) {
			return this.persistPatch({ mapping: nextRules })
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-2 */
		onUpdateCast(nextRules) {
			return this.persistPatch({ cast: nextRules })
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-2 */
		onUpdateUnset(nextList) {
			return this.persistPatch({ unset: nextList })
		},
		/** @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-1 */
		onTogglePassThrough(value) {
			return this.persistPatch({ passThrough: !!value })
		},

		/**
		 * Issue the live-preview request against `/api/mappings/test`. The
		 * inflight indicator is shown next to the Output label so it
		 * doesn't block edits in the rules table on the left.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-4
		 */
		async runPreview() {
			if (!this.hasMapping) return
			let parsed
			const raw = (this.sampleInput || '').trim()
			try {
				parsed = raw.length > 0 ? JSON.parse(raw) : {}
			} catch (parseErr) {
				this.sampleParseError = this.t(
					'openconnector',
					'Sample input is not valid JSON: {message}',
					{ message: parseErr.message },
				)
				this.previewLoading = false
				return
			}
			this.sampleParseError = ''
			this.previewLoading = true
			this.previewError = ''
			try {
				const response = await axios.post(
					generateUrl('/apps/openconnector/api/mappings/test'),
					{
						inputObject: parsed,
						mapping: this.mapping,
					},
				)
				this.previewOutput = response.data?.resultObject ?? response.data ?? null
			} catch (err) {
				const status = err?.response?.status
				const message = err?.response?.data?.message
					|| err?.response?.data?.error
					|| err?.message
					|| ''
				this.previewError = this.t('openconnector', 'Preview failed')
					+ (status ? ` (${status})` : '')
					+ (message ? `: ${message}` : '')
			} finally {
				this.previewLoading = false
			}
		},

		/**
		 * Clear sample input + output and cancel any pending request.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-mapping-editor-ui/tasks.md#task-4
		 */
		resetPreview() {
			if (this.schedulePreview && this.schedulePreview.cancel) {
				this.schedulePreview.cancel()
			}
			this.sampleInput = DEFAULT_SAMPLE
			this.sampleParseError = ''
			this.previewOutput = null
			this.previewError = ''
			this.previewLoading = false
		},
	},
}
</script>

<style scoped>
.cn-mapping-detail {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.cn-mapping-detail__card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 20px;
}

.cn-mapping-detail__section-title {
	margin: 0 0 16px 0;
	font-size: 16px;
	font-weight: 600;
}

.cn-mapping-detail__meta {
	display: grid;
	grid-template-columns: minmax(140px, 200px) 1fr;
	row-gap: 12px;
	column-gap: 16px;
	margin: 0;
}

.cn-mapping-detail__meta dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.cn-mapping-detail__meta dd {
	margin: 0;
	min-width: 0;
}

.cn-mapping-detail__hint {
	margin: 4px 0 0 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.cn-mapping-detail__split {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
	gap: 24px;
}

@media (max-width: 1100px) {
	.cn-mapping-detail__split {
		grid-template-columns: minmax(0, 1fr);
	}
}

.cn-mapping-detail__rules,
.cn-mapping-detail__preview {
	min-width: 0;
}

.cn-mapping-detail__preview {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.cn-mapping-detail__preview-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 4px;
}

.cn-mapping-detail__preview-header .cn-mapping-detail__section-title {
	margin: 0;
}

.cn-mapping-detail__field-label {
	display: block;
	font-weight: 500;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-top: 8px;
}

.cn-mapping-detail__preview-output-label {
	display: flex;
	align-items: center;
	gap: 6px;
}

.cn-mapping-detail__textarea {
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

.cn-mapping-detail__error {
	color: var(--color-error);
	font-size: 12px;
	margin: 4px 0 0 0;
}

.cn-mapping-detail__preview-output {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 4px;
}

.cn-mapping-detail__pre {
	background: var(--color-background-dark);
	color: var(--color-main-text);
	padding: 12px;
	border-radius: var(--border-radius);
	overflow: auto;
	max-height: 360px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 12px;
	white-space: pre-wrap;
	word-break: break-word;
	margin: 0;
}

.cn-mapping-detail__preview-empty {
	margin: 0;
	padding: 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
	font-style: italic;
	font-size: 13px;
}
</style>

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
		:errorMessage="errorMessage"
		:onRetry="reload"
		:objectType="objectType"
		:objectId="resolvedId"
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
						<NcCheckboxRadioSwitch
							:modelValue="!!mapping.passThrough"
							:disabled="saving"
							@update:modelValue="onTogglePassThrough">
							{{ passThroughLabel }}
						</NcCheckboxRadioSwitch>
						<p class="cn-mapping-detail__hint">
							{{
								t(
									'openconnector',
									'When enabled, fields from the input object are copied through unless explicitly unset.',
								)
							}}
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
						:mappingRules="mappingRules"
						:castRules="castRules"
						:unsetRules="unsetRules"
						:passThrough="!!mapping.passThrough"
						:saving="saving"
						@updateMapping="onUpdateMapping"
						@updateCast="onUpdateCast"
						@updateUnset="onUpdateUnset" />
				</section>

				<section class="cn-mapping-detail__card cn-mapping-detail__preview">
					<div class="cn-mapping-detail__preview-header">
						<h3 class="cn-mapping-detail__section-title">
							{{ t('openconnector', 'Live preview') }}
						</h3>
						<NcButton variant="tertiary" @click="resetPreview">
							<template #icon>
								<RestoreIcon :size="18" />
							</template>
							{{ t('openconnector', 'Reset preview') }}
						</NcButton>
					</div>
					<p class="cn-mapping-detail__hint">
						{{
							t(
								'openconnector',
								'Edit the sample input below; the output re-renders as you type and on every rule change.',
							)
						}}
					</p>

					<label
						for="cn-mapping-detail__sample-input"
						class="cn-mapping-detail__field-label">
						{{ t('openconnector', 'Sample input (JSON)') }}
					</label>
					<textarea
						id="cn-mapping-detail__sample-input"
						v-model="sampleInput"
						class="cn-mapping-detail__textarea"
						rows="8"
						spellcheck="false"
						:placeholder="samplePlaceholder" />
					<p v-if="sampleParseError" class="cn-mapping-detail__error">
						{{ sampleParseError }}
					</p>

					<MappingResultPanel
						ref="result"
						:mapping="mapping"
						:inputObject="sampleInput"
						@inputError="sampleParseError = $event" />
				</section>
			</div>
		</div>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import PlayOutlineIcon from 'vue-material-design-icons/PlayOutline.vue'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'
import MappingResultPanel from '../../components/mapping/MappingResultPanel.vue'
import MappingRulesEditor from './MappingRulesEditor.vue'
import { asObjectMap, asUnsetList } from '../../components/mapping/mappingShape.js'
import { EVENT_OPEN_TEST_MAPPING, modalBus } from '../../handlers/modalBus.js'
import liveObjectSubscription from '../../mixins/liveObjectSubscription.js'
import { useObjectStore } from '../../store/objectStore.js'

/** Default sample input shown until the user edits the textarea. */
const DEFAULT_SAMPLE = '{}'

export default {
	name: 'MappingDetailPage',

	components: {
		CnDetailPage,
		MappingRulesEditor,
		MappingResultPanel,
		NcButton,
		NcCheckboxRadioSwitch,
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
		}
	},

	computed: {
		/**
		 * Pinia store instance.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		store() {
			return useObjectStore()
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		resolvedId() {
			return this.id != null ? String(this.id) : ''
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		mapping() {
			if (!this.resolvedId) return {}
			return this.store.getObject(this.objectType, this.resolvedId) || {}
		},

		hasMapping() {
			return !!this.mapping && Object.keys(this.mapping).length > 0
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		loading() {
			return !!this.store.loading?.[this.objectType] && !this.hasMapping
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		loadError() {
			return this.store.errors?.[this.objectType] || null
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		errorMessage() {
			const err = this.loadError
			if (!err) return ''
			return err.message || this.t('openconnector', 'Failed to load mapping')
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		title() {
			return this.mapping?.name || this.t('openconnector', 'Mapping')
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		description() {
			return this.mapping?.description || ''
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		passThroughLabel() {
			return this.mapping?.passThrough
				? this.t('openconnector', 'Pass through enabled')
				: this.t('openconnector', 'Pass through disabled')
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		sidebarConfig() {
			return {
				enabled: true,
				show: true,
				register: this.register,
				schema: this.schema,
				showMetadata: true,
			}
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		mappingRules() {
			return asObjectMap(this.mapping?.mapping)
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		castRules() {
			return asObjectMap(this.mapping?.cast)
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		unsetRules() {
			return asUnsetList(this.mapping?.unset)
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		samplePlaceholder() {
			return '{\n  "name": "hello"\n}'
		},
	},

	/** @spec openspec/specs/mapping-editor-ui/spec.md */
	mounted() {
		this.ensureRegistered()
		this.reload()
		// Live updates: or-object-{uuid} events refetch this mapping into the
		// store cache; the `mapping` computed reads the cache directly, so no
		// bridge callback is needed — reactivity re-renders the page.
		if (this.resolvedId) {
			this.syncLiveSubscription(this.objectType, this.resolvedId)
		}
	},

	methods: {
		/**
		 * Register the `mapping` type on the shared object store the first
		 * time this page mounts. Idempotent — registering twice with the
		 * same arguments is a no-op modulo Vue reactivity churn, but we
		 * gate on `objectTypeRegistry` anyway.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
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
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		reload() {
			if (!this.resolvedId) return Promise.resolve(null)
			return this.store.fetchObject(this.objectType, this.resolvedId)
		},

		/**
		 * Emit the bus event picked up by ModalHost (`src/modals/v2/ModalHost.vue`)
		 * to mount TestMappingModal with the current mapping pre-loaded.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		openTestModal() {
			if (!this.hasMapping) return
			modalBus.emit(EVENT_OPEN_TEST_MAPPING, { mapping: this.mapping })
		},

		/**
		 * Persist a patch through the object store. Replaces the full
		 * mapping object (`PUT /api/objects/<register>/<schema>/<id>`) so
		 * the existing values for unchanged fields stay intact.
		 *
		 * @param {object} patch Fields to merge over the current mapping.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		async persistPatch(patch) {
			if (!this.hasMapping) return
			this.saving = true
			try {
				const merged = { ...this.mapping, ...patch }
				const result = await this.store.saveObject(this.objectType, merged)
				if (!result) {
					const message =
						this.store.errors?.[this.objectType]?.message
						|| this.t('openconnector', 'Failed to save mapping')
					showError(message)
					return
				}
				showSuccess(this.t('openconnector', 'Mapping saved'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Persist the transformation rules after an edit in MappingRulesEditor.
		 *
		 * @param {object} nextRules The complete replacement `mapping` object —
		 *   target field path → source field path / Twig expression.
		 * @return {Promise<void>} Resolves once the patch has been persisted.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		onUpdateMapping(nextRules) {
			return this.persistPatch({ mapping: nextRules })
		},

		/**
		 * Persist the cast rules after an edit in MappingRulesEditor.
		 *
		 * @param {object} nextRules The complete replacement `cast` object —
		 *   field path → cast type applied after the mapping runs.
		 * @return {Promise<void>} Resolves once the patch has been persisted.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		onUpdateCast(nextRules) {
			return this.persistPatch({ cast: nextRules })
		},

		/**
		 * Persist the unset list after an edit in MappingRulesEditor.
		 *
		 * @param {string[]} nextList The complete replacement `unset` list — the
		 *   field paths stripped from the output, already de-duplicated.
		 * @return {Promise<void>} Resolves once the patch has been persisted.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		onUpdateUnset(nextList) {
			return this.persistPatch({ unset: nextList })
		},

		/**
		 * Persist the pass-through switch, which decides whether unmapped input
		 * fields are copied into the output. Coerced to a strict boolean so the
		 * stored mapping never carries a truthy non-boolean.
		 *
		 * @param {boolean} value The new switch state from NcCheckboxRadioSwitch.
		 * @return {Promise<void>} Resolves once the patch has been persisted.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		onTogglePassThrough(value) {
			return this.persistPatch({ passThrough: !!value })
		},

		/**
		 * Clear sample input and result, and cancel any pending request. The
		 * request lifecycle itself belongs to MappingResultPanel, so this
		 * only resets the input this page owns and asks the panel to clear.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		resetPreview() {
			this.sampleInput = DEFAULT_SAMPLE
			this.sampleParseError = ''
			this.$refs.result?.reset()
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
</style>

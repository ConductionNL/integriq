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

  The "Test mapping" header action re-uses the existing v2 modal pattern
  from #867: emit `EVENT_OPEN_TEST_MAPPING` on `modalBus`, the App.vue
  `ModalHost` picks it up and mounts `TestMappingModal` with the current
  mapping pre-selected.

  Closes ConductionNL/openconnector#832.
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

			<!-- Rules editor -->
			<section class="cn-mapping-detail__card">
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
		</div>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage, useObjectStore } from '@conduction/nextcloud-vue'
import {
	NcButton,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'
import PlayOutlineIcon from 'vue-material-design-icons/PlayOutline.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'

import MappingRulesEditor from './MappingRulesEditor.vue'
import { modalBus, EVENT_OPEN_TEST_MAPPING } from '../../handlers/modalBus.js'

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
		PlayOutlineIcon,
	},

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
		}
	},

	computed: {
		/** Pinia store instance. */
		store() {
			return useObjectStore()
		},
		resolvedId() {
			return this.id != null ? String(this.id) : ''
		},
		mapping() {
			if (!this.resolvedId) return {}
			return this.store.getObject(this.objectType, this.resolvedId) || {}
		},
		hasMapping() {
			return !!this.mapping && Object.keys(this.mapping).length > 0
		},
		loading() {
			return !!this.store.loading?.[this.objectType] && !this.hasMapping
		},
		loadError() {
			return this.store.errors?.[this.objectType] || null
		},
		errorMessage() {
			const err = this.loadError
			if (!err) return ''
			return err.message
				|| this.t('openconnector', 'Failed to load mapping')
		},
		title() {
			return this.mapping?.name || this.t('openconnector', 'Mapping')
		},
		description() {
			return this.mapping?.description || ''
		},
		passThroughLabel() {
			return this.mapping?.passThrough
				? this.t('openconnector', 'Pass through enabled')
				: this.t('openconnector', 'Pass through disabled')
		},
		sidebarConfig() {
			return {
				enabled: true,
				show: true,
				register: this.register,
				schema: this.schema,
				showMetadata: true,
			}
		},
		mappingRules() {
			return asObjectMap(this.mapping?.mapping)
		},
		castRules() {
			return asObjectMap(this.mapping?.cast)
		},
		unsetRules() {
			return asUnsetList(this.mapping?.unset)
		},
	},

	mounted() {
		this.ensureRegistered()
		this.reload()
	},

	methods: {
		/**
		 * Register the `mapping` type on the shared object store the first
		 * time this page mounts. Idempotent — registering twice with the
		 * same arguments is a no-op modulo Vue reactivity churn, but we
		 * gate on `objectTypeRegistry` anyway.
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

		/** Force a server-side re-fetch of the current mapping. */
		reload() {
			if (!this.resolvedId) return Promise.resolve(null)
			return this.store.fetchObject(this.objectType, this.resolvedId)
		},

		/**
		 * Emit the bus event picked up by ModalHost (`src/modals/v2/ModalHost.vue`)
		 * to mount TestMappingModal with the current mapping pre-loaded.
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

		onUpdateMapping(nextRules) {
			return this.persistPatch({ mapping: nextRules })
		},
		onUpdateCast(nextRules) {
			return this.persistPatch({ cast: nextRules })
		},
		onUpdateUnset(nextList) {
			return this.persistPatch({ unset: nextList })
		},
		onTogglePassThrough(value) {
			return this.persistPatch({ passThrough: !!value })
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
</style>

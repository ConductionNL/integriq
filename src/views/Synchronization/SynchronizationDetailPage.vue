<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  SynchronizationDetailPage — bespoke detail page for one Synchronization.

  Wraps `CnDetailPage` and lays out five dedicated config widgets that the
  generic schema-driven detail page cannot express cleanly:

    1. #source-config-widget   — source-type-specific config blob editor
       (api / register/schema / file)
    2. #target-config-widget   — target-type-specific config blob editor
    3. #mapping-picker-widget  — `sourceTargetMapping` Mapping picker
       (slug-based NcSelect, populated from OR's `/api/objects/openconnector/mapping`)
    4. #actions-list-widget    — `actions[]` rule references (multi-select Rules)
    5. #followups-list-widget  — `followUps[]` synchronization references
       (multi-select sibling Syncs)

  Wired in via:
    - `src/manifest.json` → page `SynchronizationDetail` with
      `"type": "custom", "component": "SynchronizationDetailPage"`
    - `src/registry.js`   → maps `SynchronizationDetailPage` to this component
    - `src/manifest.json` → Synchronizations index gets a row "Edit" action
      that navigates to the new route, plus the auto-row-click path.

  Reuses the #867 picker pattern: NcSelect populated from OR's
  `/api/objects/openregister/api/objects/openconnector/{schema}` endpoint.

  Persistence: `useObjectStore` (the same store CnDetailPage subscribes to).
  Mutations go through `objectStore.saveObject('synchronization', {...})`
  which writes back via PUT to OR. The page tracks a `dirty` flag and
  exposes a Save button in the header actions slot — no auto-save on
  every keystroke, mirroring the legacy modal's two-phase flow.

  Closes #834. Open follow-ups:
    - Conditions array editor (legacy JSON-Logic textarea) — re-add in a
      future bespoke widget if/when the visual condition builder lands.
    - Mapping preview (legacy "test mapping" surface) — out of scope here;
      tracked separately under the test-mapping modal work.
-->

<template>
	<CnDetailPage
		:title="title"
		:description="description"
		icon="SyncOutline"
		:loading="loading"
		:error="hasError"
		:error-message="errorMessage"
		:on-retry="hasError ? loadObject : null"
		:object-type="schemaSlug"
		:object-id="objectIdString"
		:sidebar-props="{ register: registerSlug, schema: schemaSlug }">
		<template #actions>
			<NcButton
				v-if="dirty"
				type="primary"
				:disabled="saving || !canSave"
				@click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<ContentSaveOutline v-else :size="20" />
				</template>
				{{ t('openconnector', 'Save changes') }}
			</NcButton>
			<NcButton
				v-if="dirty"
				:disabled="saving"
				@click="resetEdits">
				<template #icon>
					<UndoIcon :size="20" />
				</template>
				{{ t('openconnector', 'Discard') }}
			</NcButton>
		</template>

		<div v-if="!loading && draft" class="sync-detail">
			<!-- Source / General / Target row -->
			<div class="sync-detail__row">
				<section class="sync-detail__card sync-detail__source">
					<header class="sync-detail__card-header">
						<DatabaseArrowRightOutline :size="22" />
						<h3>{{ t('openconnector', 'Source') }}</h3>
					</header>
					<p class="sync-detail__hint">
						{{ t('openconnector', 'Configure where data comes from.') }}
					</p>

					<!-- Source type discriminator -->
					<div class="sync-detail__field">
						<label :for="'sync-source-type'" class="sync-detail__label">
							{{ t('openconnector', 'Source type') }}
						</label>
						<NcSelect
							:input-id="'sync-source-type'"
							:value="selectedSourceType"
							:options="typeOptions"
							:clearable="false"
							@input="onSourceTypeChange" />
					</div>

					<!-- #source-config-widget -->
					<SyncConfigWidget
						kind="source"
						:type="draft.sourceType"
						:source-id="draft.sourceId"
						:config="draft.sourceConfig"
						@update:sourceId="(value) => updateDraft('sourceId', value)"
						@update:config="(value) => updateDraft('sourceConfig', value)" />
				</section>

				<section class="sync-detail__card sync-detail__general">
					<header class="sync-detail__card-header">
						<CogOutline :size="22" />
						<h3>{{ t('openconnector', 'General') }}</h3>
					</header>

					<div class="sync-detail__field">
						<NcTextField
							:label="t('openconnector', 'Name')"
							:value="draft.name || ''"
							required
							@update:value="(value) => updateDraft('name', value)" />
					</div>

					<div class="sync-detail__field">
						<label :for="'sync-description'" class="sync-detail__label">
							{{ t('openconnector', 'Description') }}
						</label>
						<textarea
							:id="'sync-description'"
							class="sync-detail__textarea"
							:value="draft.description || ''"
							rows="3"
							@input="updateDraft('description', $event.target.value)" />
					</div>

					<!-- Flow indicator -->
					<div class="sync-detail__flow">
						<span class="sync-detail__flow-step">
							<DatabaseArrowRightOutline :size="18" />
							{{ t('openconnector', 'Source') }}
						</span>
						<ArrowRight :size="18" />
						<span class="sync-detail__flow-step">
							<SwapHorizontal :size="18" />
							{{ t('openconnector', 'Transform') }}
						</span>
						<ArrowRight :size="18" />
						<span class="sync-detail__flow-step">
							<DatabaseArrowLeftOutline :size="18" />
							{{ t('openconnector', 'Target') }}
						</span>
					</div>
				</section>

				<section class="sync-detail__card sync-detail__target">
					<header class="sync-detail__card-header">
						<DatabaseArrowLeftOutline :size="22" />
						<h3>{{ t('openconnector', 'Target') }}</h3>
					</header>
					<p class="sync-detail__hint">
						{{ t('openconnector', 'Configure where data is written to.') }}
					</p>

					<!-- Target type discriminator -->
					<div class="sync-detail__field">
						<label :for="'sync-target-type'" class="sync-detail__label">
							{{ t('openconnector', 'Target type') }}
						</label>
						<NcSelect
							:input-id="'sync-target-type'"
							:value="selectedTargetType"
							:options="typeOptions"
							:clearable="false"
							@input="onTargetTypeChange" />
					</div>

					<!-- #target-config-widget -->
					<SyncConfigWidget
						kind="target"
						:type="draft.targetType"
						:source-id="draft.targetId"
						:config="draft.targetConfig"
						@update:sourceId="(value) => updateDraft('targetId', value)"
						@update:config="(value) => updateDraft('targetConfig', value)" />
				</section>
			</div>

			<!-- Mapping + Actions + FollowUps row -->
			<div class="sync-detail__row">
				<section class="sync-detail__card sync-detail__mapping">
					<header class="sync-detail__card-header">
						<SwapHorizontal :size="22" />
						<h3>{{ t('openconnector', 'Mapping') }}</h3>
					</header>

					<!-- #mapping-picker-widget -->
					<SyncMappingPicker
						:value="draft.sourceTargetMapping"
						:hash-value="draft.sourceHashMapping"
						:target-source-value="draft.targetSourceMapping"
						@update:value="(value) => updateDraft('sourceTargetMapping', value)"
						@update:hashValue="(value) => updateDraft('sourceHashMapping', value)"
						@update:targetSourceValue="(value) => updateDraft('targetSourceMapping', value)" />
				</section>

				<section class="sync-detail__card sync-detail__actions">
					<header class="sync-detail__card-header">
						<PlayCircleOutline :size="22" />
						<h3>{{ t('openconnector', 'Actions') }}</h3>
					</header>
					<p class="sync-detail__hint">
						{{ t('openconnector', 'Rules applied during each sync pass.') }}
					</p>

					<!-- #actions-list-widget -->
					<SyncReferenceList
						schema="rule"
						label-key="name"
						:value="draft.actions"
						:placeholder="t('openconnector', 'Pick rules to run during sync')"
						:empty-label="t('openconnector', 'No rules linked yet.')"
						@input="(value) => updateDraft('actions', value)" />
				</section>

				<section class="sync-detail__card sync-detail__followups">
					<header class="sync-detail__card-header">
						<CallSplit :size="22" />
						<h3>{{ t('openconnector', 'Follow-ups') }}</h3>
					</header>
					<p class="sync-detail__hint">
						{{ t('openconnector', 'Synchronizations to trigger when this one completes.') }}
					</p>

					<!-- #followups-list-widget -->
					<SyncReferenceList
						schema="synchronization"
						label-key="name"
						:value="draft.followUps"
						:exclude-id="objectIdString"
						:placeholder="t('openconnector', 'Pick follow-up synchronizations')"
						:empty-label="t('openconnector', 'No follow-ups linked yet.')"
						@input="(value) => updateDraft('followUps', value)" />
				</section>
			</div>

			<NcNoteCard v-if="saveError" type="error">
				<p>{{ saveError }}</p>
			</NcNoteCard>
		</div>
	</CnDetailPage>
</template>

<script>
import {
	NcButton,
	NcSelect,
	NcTextField,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import {
	CnDetailPage,
	useObjectStore,
} from '@conduction/nextcloud-vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import CallSplit from 'vue-material-design-icons/CallSplit.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import DatabaseArrowLeftOutline from 'vue-material-design-icons/DatabaseArrowLeftOutline.vue'
import DatabaseArrowRightOutline from 'vue-material-design-icons/DatabaseArrowRightOutline.vue'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import UndoIcon from 'vue-material-design-icons/Undo.vue'

import SyncConfigWidget from './SyncConfigWidget.vue'
import SyncMappingPicker from './SyncMappingPicker.vue'
import SyncReferenceList from './SyncReferenceList.vue'

const SCHEMA_SLUG = 'synchronization'
const REGISTER_SLUG = 'openconnector'

/**
 * Polymorphic type discriminator options shared between source and
 * target. Keep in sync with `synchronization.sourceType` /
 * `targetType` description in `lib/Settings/openconnector_register.json`.
 */
const TYPE_OPTIONS = [
	{ id: 'api', label: 'API' },
	{ id: 'register/schema', label: 'Register/Schema' },
	{ id: 'file', label: 'File' },
]

/**
 * Build an empty draft for the create case. The legacy modal defaulted
 * sourceType:'api', targetType:'register/schema' — preserve that here so
 * a newly-routed-to record without a stored type still renders a sensible
 * config form.
 */
function emptyDraft() {
	return {
		name: '',
		description: '',
		sourceType: 'api',
		sourceId: '',
		sourceConfig: {},
		sourceTargetMapping: '',
		sourceHashMapping: '',
		targetType: 'register/schema',
		targetId: '',
		targetConfig: {},
		targetSourceMapping: '',
		actions: [],
		followUps: [],
	}
}

export default {
	name: 'SynchronizationDetailPage',

	components: {
		CnDetailPage,
		NcButton,
		NcSelect,
		NcTextField,
		NcLoadingIcon,
		NcNoteCard,
		ArrowRight,
		CallSplit,
		CogOutline,
		ContentSaveOutline,
		DatabaseArrowLeftOutline,
		DatabaseArrowRightOutline,
		PlayCircleOutline,
		SwapHorizontal,
		UndoIcon,
		SyncConfigWidget,
		SyncMappingPicker,
		SyncReferenceList,
	},

	props: {
		/**
		 * The route param. Forwarded by CnPageRenderer from the
		 * `/synchronizations/:id` URL — required for fetch + save.
		 */
		id: { type: [String, Number], default: '' },
		// CnPageRenderer also forwards page `config` keys; we accept and
		// ignore them — the schema/register are hardcoded for this page.
		register: { type: String, default: REGISTER_SLUG },
		schema: { type: String, default: SCHEMA_SLUG },
	},

	setup() {
		const objectStore = useObjectStore()
		return { objectStore }
	},

	data() {
		return {
			loading: false,
			saving: false,
			saveError: '',
			loadError: '',
			draft: null,
			original: null,
		}
	},

	computed: {
		objectIdString() {
			return this.id != null ? String(this.id) : ''
		},
		registerSlug() {
			return this.register || REGISTER_SLUG
		},
		schemaSlug() {
			return this.schema || SCHEMA_SLUG
		},
		title() {
			if (this.draft?.name) return this.draft.name
			return this.original?.name || t('openconnector', 'Synchronization')
		},
		description() {
			return this.original?.description || ''
		},
		hasError() {
			return Boolean(this.loadError) && !this.draft
		},
		errorMessage() {
			return this.loadError || t('openconnector', 'Failed to load synchronization')
		},
		typeOptions() {
			return TYPE_OPTIONS
		},
		selectedSourceType() {
			return TYPE_OPTIONS.find((opt) => opt.id === this.draft?.sourceType) || TYPE_OPTIONS[0]
		},
		selectedTargetType() {
			return TYPE_OPTIONS.find((opt) => opt.id === this.draft?.targetType) || TYPE_OPTIONS[1]
		},
		dirty() {
			if (!this.draft || !this.original) return false
			return JSON.stringify(this.draft) !== JSON.stringify(this.normalizeForDiff(this.original))
		},
		canSave() {
			return Boolean(this.draft?.name && this.draft.name.trim().length > 0)
		},
	},

	watch: {
		id: {
			immediate: true,
			handler() {
				this.loadObject()
			},
		},
	},

	methods: {
		async loadObject() {
			if (!this.objectIdString) {
				// New-create surface — start with empty draft.
				this.draft = emptyDraft()
				this.original = emptyDraft()
				return
			}
			this.loading = true
			this.loadError = ''
			try {
				const data = await this.objectStore.fetchObject(this.schemaSlug, this.objectIdString)
				if (!data) {
					this.loadError = this.objectStore.errors?.[this.schemaSlug] || t('openconnector', 'Failed to load synchronization')
					this.draft = null
					this.original = null
					return
				}
				this.original = data
				this.draft = this.normalizeForDiff(data)
			} catch (err) {
				this.loadError = err?.message || t('openconnector', 'Failed to load synchronization')
				this.draft = null
				this.original = null
			} finally {
				this.loading = false
			}
		},
		/**
		 * Clone an object into the shape the form mutates. Ensures all
		 * fields exist (so v-model doesn't trip on `undefined`) and that
		 * objects/arrays are independent copies — pinia replaces the
		 * cached object on save, so we must not hold a reference to it.
		 *
		 * @param {object} obj Raw object from the store.
		 * @return {object} Plain draft with all editable fields filled.
		 */
		normalizeForDiff(obj) {
			const base = emptyDraft()
			const out = { ...base }
			for (const key of Object.keys(base)) {
				if (obj && obj[key] !== undefined && obj[key] !== null) {
					if (Array.isArray(base[key])) {
						out[key] = Array.isArray(obj[key]) ? [...obj[key]] : []
					} else if (typeof base[key] === 'object') {
						out[key] = (typeof obj[key] === 'object' && !Array.isArray(obj[key]))
							? { ...obj[key] }
							: {}
					} else {
						out[key] = obj[key]
					}
				}
			}
			return out
		},
		updateDraft(key, value) {
			if (!this.draft) return
			this.$set(this.draft, key, value)
		},
		onSourceTypeChange(option) {
			if (!option?.id || !this.draft) return
			// Type changed — clear the kind-specific blob + id so we don't
			// carry stale `endpoint:'/foo'` over into a register/schema mode.
			this.$set(this.draft, 'sourceType', option.id)
			this.$set(this.draft, 'sourceId', '')
			this.$set(this.draft, 'sourceConfig', {})
		},
		onTargetTypeChange(option) {
			if (!option?.id || !this.draft) return
			this.$set(this.draft, 'targetType', option.id)
			this.$set(this.draft, 'targetId', '')
			this.$set(this.draft, 'targetConfig', {})
		},
		async save() {
			if (!this.draft || this.saving) return
			this.saving = true
			this.saveError = ''
			try {
				const payload = {
					...this.original,
					...this.draft,
				}
				const saved = await this.objectStore.saveObject(this.schemaSlug, payload)
				if (!saved) {
					this.saveError = this.objectStore.errors?.[this.schemaSlug] || t('openconnector', 'Save failed')
					return
				}
				this.original = saved
				this.draft = this.normalizeForDiff(saved)
			} catch (err) {
				this.saveError = err?.message || t('openconnector', 'Save failed')
			} finally {
				this.saving = false
			}
		},
		resetEdits() {
			if (!this.original) return
			this.draft = this.normalizeForDiff(this.original)
			this.saveError = ''
		},
	},
}
</script>

<style scoped>
.sync-detail {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.sync-detail__row {
	display: grid;
	grid-template-columns: 1fr 1fr 1fr;
	gap: 16px;
}

@media (max-width: 1100px) {
	.sync-detail__row {
		grid-template-columns: 1fr;
	}
}

.sync-detail__card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.sync-detail__card-header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0;
}

.sync-detail__card-header h3 {
	margin: 0;
	font-size: 16px;
	font-weight: bold;
}

.sync-detail__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.sync-detail__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.sync-detail__label {
	font-weight: bold;
	font-size: 13px;
}

.sync-detail__textarea {
	width: 100%;
	padding: 8px;
	font-family: var(--font-face, sans-serif);
	font-size: 14px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.sync-detail__flow {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	padding: 8px;
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.sync-detail__flow-step {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}
</style>

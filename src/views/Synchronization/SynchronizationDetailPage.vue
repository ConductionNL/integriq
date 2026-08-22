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

  Closes #834. Follow-ups landed in #878:
    - Visual JsonLogic condition builder + raw-JSON toggle (reuses
      RuleConditionGroup from the Rule editor).
    - Inline mapping preview pane on the picker (debounce-fires
      `/api/mappings/test`).
    - NcFilePicker swap for `sourceType: 'file'` (in SyncConfigWidget).
-->

<template>
	<CnDetailPage
		:title="title"
		:description="description"
		icon="SyncOutline"
		:loading="loading"
		:error="hasError"
		:errorMessage="errorMessage"
		:onRetry="hasError ? loadObject : null"
		:objectType="schemaSlug"
		:objectId="objectIdString"
		:sidebarProps="{ register: registerSlug, schema: schemaSlug }">
		<template #actions>
			<NcButton
				v-if="dirty"
				variant="primary"
				:disabled="saving || !canSave"
				@click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<ContentSaveOutline v-else :size="20" />
				</template>
				{{ t('integriq', 'Save changes') }}
			</NcButton>
			<NcButton v-if="dirty" :disabled="saving" @click="resetEdits">
				<template #icon>
					<UndoIcon :size="20" />
				</template>
				{{ t('integriq', 'Discard') }}
			</NcButton>
		</template>

		<!-- Deprecation context, first in the body so it reads as page-level
		     framing rather than an error against the synchronization being
		     edited. flow-native-synchronization task 3.2: the Synchronizations
		     page is replaced by the Flows list of generated flows; every
		     synchronization service and the contract tables stay. Rendered
		     outside the `draft` guard so the notice is present while the
		     object loads. -->
		<AutomationDeprecationNotice />

		<div v-if="!loading && draft" class="sync-detail">
			<!-- Source / General / Target row -->
			<div class="sync-detail__row">
				<section class="sync-detail__card sync-detail__source">
					<header class="sync-detail__card-header">
						<DatabaseArrowRightOutline :size="22" />
						<h3>{{ t('integriq', 'Source') }}</h3>
					</header>
					<p class="sync-detail__hint">
						{{ t('integriq', 'Configure where data comes from.') }}
					</p>

					<!-- Source type discriminator -->
					<div class="sync-detail__field">
						<label for="sync-source-type" class="sync-detail__label">
							{{ t('integriq', 'Source type') }}
						</label>
						<NcSelect
							inputId="sync-source-type"
							:aria-label-combobox="t('integriq', 'Source type')"
							:modelValue="selectedSourceType"
							:options="sourceTypeOptions"
							:clearable="false"
							@update:modelValue="onSourceTypeChange" />
					</div>

					<!-- #source-config-widget -->
					<SyncConfigWidget
						kind="source"
						:type="draft.sourceType"
						:sourceId="draft.sourceId"
						:config="draft.sourceConfig"
						@update:sourceId="(value) => updateDraft('sourceId', value)"
						@update:config="
							(value) => updateDraft('sourceConfig', value)
						" />

					<!-- Incremental sync mode (REQ-016/REQ-017/REQ-019, change cdc-incremental-sync) -->
					<div class="sync-detail__field">
						<label for="sync-mode" class="sync-detail__label">
							{{ t('integriq', 'Sync mode') }}
						</label>
						<NcSelect
							inputId="sync-mode"
							:aria-label-combobox="t('integriq', 'Sync mode')"
							:modelValue="selectedSyncMode"
							:options="syncModeOptions"
							:clearable="false"
							@update:modelValue="onSyncModeChange" />
						<span class="sync-detail__helper">
							{{
								t(
									'integriq',
									'Incremental mode fetches only records changed since the stored cursor and never deletes target objects no longer present in the source. Switch back to Full to restore deletion detection.',
								)
							}}
						</span>
					</div>

					<template v-if="isIncremental">
						<div class="sync-detail__field">
							<NcTextField
								:label="t('integriq', 'Cursor field')"
								:modelValue="draft.sourceConfig.cursorField || ''"
								:placeholder="t('integriq', 'e.g. updatedAt')"
								@update:modelValue="
									(value) =>
										updateSourceConfigField('cursorField', value)
								" />
						</div>

						<div class="sync-detail__field">
							<label
								for="sync-cursor-comparator"
								class="sync-detail__label">
								{{ t('integriq', 'Cursor comparator') }}
							</label>
							<NcSelect
								inputId="sync-cursor-comparator"
								:aria-label-combobox="
									t('integriq', 'Cursor comparator')
								"
								:modelValue="selectedCursorComparator"
								:options="cursorComparatorOptions"
								@update:modelValue="onCursorComparatorChange" />
						</div>

						<div class="sync-detail__field">
							<span class="sync-detail__label">{{
								t('integriq', 'Cursor watermark')
							}}</span>
							<span class="sync-detail__helper">
								{{
									original && original.cursorWatermark
										? original.cursorWatermark
										: t(
												'integriq',
												'(not set — the next run requests an unfiltered fetch)',
											)
								}}
							</span>
							<NcButton
								variant="secondary"
								:disabled="
									resettingCursor
									|| !objectIdString
									|| !(original && original.cursorWatermark)
								"
								:title="
									t(
										'integriq',
										'Clears the stored cursor only. Does not delete data and does not restore deletion detection — switch Sync mode to Full for that.',
									)
								"
								@click="resetCursor">
								<template #icon>
									<NcLoadingIcon
										v-if="resettingCursor"
										:size="20" />
									<RestoreIcon v-else :size="20" />
								</template>
								{{ t('integriq', 'Reset cursor') }}
							</NcButton>
						</div>
					</template>
				</section>

				<section class="sync-detail__card sync-detail__general">
					<header class="sync-detail__card-header">
						<CogOutline :size="22" />
						<h3>{{ t('integriq', 'General') }}</h3>
					</header>

					<div class="sync-detail__field">
						<NcTextField
							:label="t('integriq', 'Name')"
							:modelValue="draft.name || ''"
							required
							@update:modelValue="
								(value) => updateDraft('name', value)
							" />
					</div>

					<div class="sync-detail__field">
						<label for="sync-description" class="sync-detail__label">
							{{ t('integriq', 'Description') }}
						</label>
						<textarea
							id="sync-description"
							class="sync-detail__textarea"
							:value="draft.description || ''"
							rows="3"
							@input="
								updateDraft('description', $event.target.value)
							" />
					</div>

					<!-- Flow indicator -->
					<div class="sync-detail__flow">
						<span class="sync-detail__flow-step">
							<DatabaseArrowRightOutline :size="18" />
							{{ t('integriq', 'Source') }}
						</span>
						<ArrowRight :size="18" />
						<span class="sync-detail__flow-step">
							<SwapHorizontal :size="18" />
							{{ t('integriq', 'Transform') }}
						</span>
						<ArrowRight :size="18" />
						<span class="sync-detail__flow-step">
							<DatabaseArrowLeftOutline :size="18" />
							{{ t('integriq', 'Target') }}
						</span>
					</div>
				</section>

				<section class="sync-detail__card sync-detail__target">
					<header class="sync-detail__card-header">
						<DatabaseArrowLeftOutline :size="22" />
						<h3>{{ t('integriq', 'Target') }}</h3>
					</header>
					<p class="sync-detail__hint">
						{{
							t('integriq', 'Configure where data is written to.')
						}}
					</p>

					<!-- Target type discriminator -->
					<div class="sync-detail__field">
						<label for="sync-target-type" class="sync-detail__label">
							{{ t('integriq', 'Target type') }}
						</label>
						<NcSelect
							inputId="sync-target-type"
							:aria-label-combobox="t('integriq', 'Target type')"
							:modelValue="selectedTargetType"
							:options="typeOptions"
							:clearable="false"
							@update:modelValue="onTargetTypeChange" />
					</div>

					<!-- #target-config-widget -->
					<SyncConfigWidget
						kind="target"
						:type="draft.targetType"
						:sourceId="draft.targetId"
						:config="draft.targetConfig"
						@update:sourceId="(value) => updateDraft('targetId', value)"
						@update:config="
							(value) => updateDraft('targetConfig', value)
						" />
				</section>
			</div>

			<!-- Mapping + Actions + FollowUps row -->
			<div class="sync-detail__row">
				<section class="sync-detail__card sync-detail__mapping">
					<header class="sync-detail__card-header">
						<SwapHorizontal :size="22" />
						<h3>{{ t('integriq', 'Mapping') }}</h3>
					</header>

					<!-- #mapping-picker-widget -->
					<SyncMappingPicker
						:value="draft.sourceTargetMapping"
						:hashValue="draft.sourceHashMapping"
						:targetSourceValue="draft.targetSourceMapping"
						@update:value="
							(value) => updateDraft('sourceTargetMapping', value)
						"
						@update:hashValue="
							(value) => updateDraft('sourceHashMapping', value)
						"
						@update:targetSourceValue="
							(value) => updateDraft('targetSourceMapping', value)
						" />
				</section>

				<section class="sync-detail__card sync-detail__actions">
					<header class="sync-detail__card-header">
						<PlayCircleOutline :size="22" />
						<h3>{{ t('integriq', 'Actions') }}</h3>
					</header>
					<p class="sync-detail__hint">
						{{
							t(
								'integriq',
								'Rules applied during each sync pass.',
							)
						}}
					</p>

					<!-- #actions-list-widget -->
					<SyncReferenceList
						schema="rule"
						labelKey="name"
						:value="draft.actions"
						:placeholder="
							t('integriq', 'Pick rules to run during sync')
						"
						:emptyLabel="t('integriq', 'No rules linked yet.')"
						@input="(value) => updateDraft('actions', value)" />
				</section>

				<section class="sync-detail__card sync-detail__followups">
					<header class="sync-detail__card-header">
						<CallSplit :size="22" />
						<h3>{{ t('integriq', 'Follow-ups') }}</h3>
					</header>
					<p class="sync-detail__hint">
						{{
							t(
								'integriq',
								'Synchronizations to trigger when this one completes.',
							)
						}}
					</p>

					<!-- #followups-list-widget -->
					<SyncReferenceList
						schema="synchronization"
						labelKey="name"
						:value="draft.followUps"
						:excludeId="objectIdString"
						:placeholder="
							t('integriq', 'Pick follow-up synchronizations')
						"
						:emptyLabel="t('integriq', 'No follow-ups linked yet.')"
						@input="(value) => updateDraft('followUps', value)" />
				</section>
			</div>

			<!-- Conditions row -->
			<div class="sync-detail__row sync-detail__row--single">
				<section class="sync-detail__card sync-detail__conditions">
					<header class="sync-detail__card-header">
						<FilterVariant :size="22" />
						<h3>{{ t('integriq', 'Conditions') }}</h3>
						<div class="sync-detail__card-header-spacer" />
						<NcButton
							variant="tertiary"
							:aria-label="
								rawConditions
									? t(
											'integriq',
											'Switch back to visual builder',
										)
									: t(
											'integriq',
											'Edit conditions as raw JSON',
										)
							"
							@click="toggleRawConditions">
							<template #icon>
								<CodeJson :size="18" />
							</template>
							{{
								rawConditions
									? t('integriq', 'Visual builder')
									: t('integriq', 'Raw JSON')
							}}
						</NcButton>
					</header>
					<p class="sync-detail__hint">
						{{
							t(
								'integriq',
								'JSON Logic predicates that gate which source records are synchronised. Leave empty to sync everything.',
							)
						}}
					</p>
					<RuleConditionGroup
						v-if="!rawConditions"
						:node="rootConditionGroup"
						:removable="false"
						@update="onConditionsUpdate" />
					<div v-else class="sync-detail__raw-conditions">
						<label class="sync-detail__label" for="sync-raw-conditions">
							{{ t('integriq', 'Conditions (JSON Logic)') }}
						</label>
						<textarea
							id="sync-raw-conditions"
							class="sync-detail__textarea sync-detail__textarea--code"
							:value="rawConditionsDraft"
							spellcheck="false"
							rows="10"
							@input="onRawConditionsInput($event.target.value)" />
						<span
							class="sync-detail__helper"
							:class="{
								'sync-detail__helper--error': rawConditionsError,
							}">
							{{
								rawConditionsError
								|| t(
									'integriq',
									'Edit the JSON Logic directly. Saved into the synchronization conditions field exactly as typed.',
								)
							}}
						</span>
					</div>
				</section>
			</div>

			<NcNoteCard v-if="saveError" type="error">
				<p>{{ saveError }}</p>
			</NcNoteCard>
		</div>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import CallSplit from 'vue-material-design-icons/CallSplit.vue'
import CodeJson from 'vue-material-design-icons/CodeJson.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import DatabaseArrowLeftOutline from 'vue-material-design-icons/DatabaseArrowLeftOutline.vue'
import DatabaseArrowRightOutline from 'vue-material-design-icons/DatabaseArrowRightOutline.vue'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import UndoIcon from 'vue-material-design-icons/Undo.vue'
import AutomationDeprecationNotice from '../../components/AutomationDeprecationNotice.vue'
import RuleConditionGroup from '../Rule/RuleConditionGroup.vue'
import SyncConfigWidget from './SyncConfigWidget.vue'
import SyncMappingPicker from './SyncMappingPicker.vue'
import SyncReferenceList from './SyncReferenceList.vue'
import liveObjectSubscription from '../../mixins/liveObjectSubscription.js'
import { useObjectStore } from '../../store/objectStore.js'
import { NEXTCLOUD_FORM_KIND } from './formsBridge.js'
// Shared with modals/v2/SynchronizationEditorModal.vue — see syncDraft.js.
import {
	CURSOR_COMPARATOR_OPTIONS,
	emptyDraft,
	emptyRootGroup,
	fetchBridgeStatus,
	NEXTCLOUD_FORM_OPTION,
	NEXTCLOUD_TABLE_OPTION,
	normaliseConditions,
	serializeConditions,
	SYNC_MODE_OPTIONS,
	TYPE_OPTIONS,
} from './syncDraft.js'
import { NEXTCLOUD_TABLE_KIND } from './tablesBridge.js'

const SCHEMA_SLUG = 'synchronization'
const REGISTER_SLUG = 'openconnector'

export default {
	name: 'SynchronizationDetailPage',

	components: {
		AutomationDeprecationNotice,
		CnDetailPage,
		NcButton,
		NcSelect,
		NcTextField,
		NcLoadingIcon,
		NcNoteCard,
		ArrowRight,
		CallSplit,
		CodeJson,
		CogOutline,
		ContentSaveOutline,
		DatabaseArrowLeftOutline,
		DatabaseArrowRightOutline,
		FilterVariant,
		PlayCircleOutline,
		RestoreIcon,
		SwapHorizontal,
		UndoIcon,
		RuleConditionGroup,
		SyncConfigWidget,
		SyncMappingPicker,
		SyncReferenceList,
	},

	mixins: [liveObjectSubscription],

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

	/**
	 * Register the `synchronization` object type with the store before the
	 * component fetches, so `fetchObject`/`saveObject` can resolve the
	 * schema → register pair when building URLs.
	 *
	 * @param {{ id: string|number, register: string, schema: string }} props Resolved
	 *   component props; `register`/`schema` are the slugs forwarded by
	 *   CnPageRenderer and fall back to this page's hardcoded defaults.
	 * @return {{ objectStore: object }} Bindings exposed to the options API.
	 *
	 * @spec openspec/specs/sync-editor-ui/spec.md
	 */
	setup(props) {
		const objectStore = useObjectStore()
		// Register the type so objectStore.fetchObject/saveObject can resolve
		// the schema → register pair for URL building. Mirrors the pattern in
		// RuleDetailPage / MappingDetailPage; without this, fetchObject throws
		// "Object type 'synchronization' is not registered in the store".
		if (typeof objectStore.registerObjectType === 'function') {
			objectStore.registerObjectType(
				SCHEMA_SLUG,
				props.schema || SCHEMA_SLUG,
				props.register || REGISTER_SLUG,
			)
		}
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
			/** Toggles the conditions editor between visual builder and raw JSON. */
			rawConditions: false,
			rawConditionsDraft: '',
			rawConditionsError: '',
			/** Whether the backend reports the Tables app is enabled (REQ-004). */
			tablesEnabled: false,
			/** Whether the backend reports the Forms app is enabled (nextcloud-forms-connector REQ-001). */
			formsEnabled: false,
			/** In-flight guard for the "Reset cursor" action (REQ-019). */
			resettingCursor: false,
		}
	},

	computed: {
		/** @spec openspec/specs/sync-editor-ui/spec.md */
		objectIdString() {
			return this.id != null ? String(this.id) : ''
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		registerSlug() {
			return this.register || REGISTER_SLUG
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		schemaSlug() {
			return this.schema || SCHEMA_SLUG
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		title() {
			if (this.draft?.name) return this.draft.name
			return this.original?.name || t('integriq', 'Synchronization')
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		description() {
			return this.original?.description || ''
		},

		hasError() {
			return Boolean(this.loadError) && !this.draft
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		errorMessage() {
			return (
				this.loadError
				|| t('integriq', 'Failed to load synchronization')
			)
		},

		/**
		 * Kind options offered in the source/target selectors. `nextcloud-table`
		 * is only present when the backend reports the Tables app is enabled
		 * (tables-bridge REQ-004 / sync-editor-ui REQ-SYNCUI-006) — but an
		 * already-configured `nextcloud-table` sync keeps the option so its
		 * type still renders a label when Tables is later disabled.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-table-picker-for-the-nextcloud-table-sourcetarget-kind-req-syncui-006
		 */
		typeOptions() {
			const usesTable =
				this.draft?.sourceType === NEXTCLOUD_TABLE_KIND
				|| this.draft?.targetType === NEXTCLOUD_TABLE_KIND
			if (this.tablesEnabled || usesTable) {
				return [...TYPE_OPTIONS, NEXTCLOUD_TABLE_OPTION]
			}
			return TYPE_OPTIONS
		},

		/**
		 * Kind options offered in the SOURCE selector only. `nextcloud-form`
		 * is appended here (never to the shared `typeOptions` the target
		 * selector uses) — nextcloud-forms-connector REQ-002 is source-only,
		 * so `nextcloud-form` must never appear as a target-kind option,
		 * regardless of whether the Forms app is enabled (sync-editor-ui
		 * REQ-SYNCUI-008). Mirrors `typeOptions`' "keep an already-configured
		 * type visible even if the app is later disabled" behaviour.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-form-picker-for-the-nextcloud-form-source-kind-req-syncui-008
		 */
		sourceTypeOptions() {
			const usesForm = this.draft?.sourceType === NEXTCLOUD_FORM_KIND
			if (this.formsEnabled || usesForm) {
				return [...this.typeOptions, NEXTCLOUD_FORM_OPTION]
			}
			return this.typeOptions
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		selectedSourceType() {
			return (
				this.sourceTypeOptions.find(
					(opt) => opt.id === this.draft?.sourceType,
				) || TYPE_OPTIONS[0]
			)
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		selectedTargetType() {
			return (
				this.typeOptions.find((opt) => opt.id === this.draft?.targetType)
				|| TYPE_OPTIONS[1]
			)
		},

		/** @spec openspec/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016 */
		syncModeOptions() {
			return SYNC_MODE_OPTIONS
		},

		/** @spec openspec/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016 */
		selectedSyncMode() {
			return (
				SYNC_MODE_OPTIONS.find((opt) => opt.id === this.draft?.syncMode)
				|| SYNC_MODE_OPTIONS[0]
			)
		},

		/** @spec openspec/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016 */
		isIncremental() {
			return this.draft?.syncMode === 'incremental'
		},

		/** @spec openspec/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016 */
		cursorComparatorOptions() {
			return CURSOR_COMPARATOR_OPTIONS
		},

		/** @spec openspec/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016 */
		selectedCursorComparator() {
			const value = this.draft?.sourceConfig?.cursorComparator
			return CURSOR_COMPARATOR_OPTIONS.find((opt) => opt.id === value) || null
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		dirty() {
			if (!this.draft || !this.original) return false
			// `normalizeForDiff` shapes both sides identically (conditions
			// always rendered as the group-node object), so a JSON.stringify
			// diff compares apples-to-apples even though the wire-format
			// stores conditions as `array<object>`.
			return (
				JSON.stringify(this.draft)
				!== JSON.stringify(this.normalizeForDiff(this.original))
			)
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		canSave() {
			return Boolean(this.draft?.name && this.draft.name.trim().length > 0)
		},

		/**
		 * Coerce persisted `conditions` into a JsonLogic group node so the
		 * visual RuleConditionGroup always renders. Mirrors the
		 * `normaliseConditions` helper in RuleDetailPage — same legacy shapes
		 * (string, array, single leaf, group) end up as `{and:[...]}` /
		 * `{or:[...]}`.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		rootConditionGroup() {
			return normaliseConditions(this.draft?.conditions)
		},
	},

	watch: {
		id: {
			immediate: true,
			/** @spec openspec/specs/sync-editor-ui/spec.md */
			handler() {
				this.loadObject()
			},
		},

		/**
		 * Seed the raw JSON textarea from the visual builder's current group
		 * whenever the editor is switched into raw mode, so the two views
		 * start out showing the same conditions.
		 *
		 * @param {boolean} value True when the raw JSON editor has just been enabled.
		 * @return {void}
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		rawConditions(value) {
			if (value) {
				try {
					this.rawConditionsDraft = JSON.stringify(
						this.rootConditionGroup,
						null,
						2,
					)
					this.rawConditionsError = ''
				} catch (_e) {
					this.rawConditionsDraft = ''
				}
			}
		},
	},

	/**
	 * Feature-detects the Tables and Forms bridges so the sync-kind selector
	 * only offers `nextcloud-table` / `nextcloud-form` when the backing app is
	 * actually available to the acting user.
	 *
	 * @return {void}
	 * @spec openspec/specs/sync-editor-ui/spec.md
	 */
	mounted() {
		this.fetchTablesStatus()
		this.fetchFormsStatus()
	},

	methods: {
		/**
		 * Ask the backend whether the Tables app is enabled for the acting
		 * user; only then is `nextcloud-table` offered in the kind selectors
		 * (tables-bridge REQ-004). Soft-fails to "disabled" so a backend
		 * without the endpoint simply never offers the type.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-table-picker-for-the-nextcloud-table-sourcetarget-kind-req-syncui-006
		 */
		async fetchTablesStatus() {
			this.tablesEnabled = await fetchBridgeStatus('tables')
		},

		/**
		 * Ask the backend whether the Forms app is enabled for the acting
		 * user; only then is `nextcloud-form` offered in the SOURCE kind
		 * selector (nextcloud-forms-connector REQ-001, sync-editor-ui
		 * REQ-SYNCUI-008). Soft-fails to "disabled" so a backend without the
		 * endpoint simply never offers the type.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-form-picker-for-the-nextcloud-form-source-kind-req-syncui-008
		 */
		async fetchFormsStatus() {
			this.formsEnabled = await fetchBridgeStatus('forms')
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
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
				const data = await this.objectStore.fetchObject(
					this.schemaSlug,
					this.objectIdString,
				)
				if (!data) {
					this.loadError =
						this.objectStore.errors?.[this.schemaSlug]
						|| t('integriq', 'Failed to load synchronization')
					this.draft = null
					this.original = null
					return
				}
				this.original = data
				this.draft = this.normalizeForDiff(data)
				// Live updates: or-object-{uuid} events refetch this sync and
				// applyLiveObject (dirty-guarded) refreshes the working copy.
				this.syncLiveSubscription(this.schemaSlug, this.objectIdString)
			} catch (err) {
				this.loadError =
					err?.message
					|| t('integriq', 'Failed to load synchronization')
				this.draft = null
				this.original = null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Live-update bridge (liveObjectSubscription mixin): apply a fresh
		 * server-side version of the synchronization to the local working
		 * copy — but NEVER over unsaved edits. When the draft is dirty the
		 * refetched object stays in the store cache and the user's edits
		 * win; the next save persists them.
		 *
		 * @param {object} fresh The refetched synchronization from the store
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 */
		applyLiveObject(fresh) {
			if (this.dirty || this.saving) return
			this.original = fresh
			this.draft = this.normalizeForDiff(fresh)
		},

		/**
		 * Clone an object into the shape the form mutates. Ensures all
		 * fields exist (so v-model doesn't trip on `undefined`) and that
		 * objects/arrays are independent copies — pinia replaces the
		 * cached object on save, so we must not hold a reference to it.
		 *
		 * @param {object} obj Raw object from the store.
		 * @return {object} Plain draft with all editable fields filled.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		normalizeForDiff(obj) {
			const base = emptyDraft()
			const out = { ...base }
			for (const key of Object.keys(base)) {
				if (obj && obj[key] !== undefined && obj[key] !== null) {
					if (Array.isArray(base[key])) {
						out[key] = Array.isArray(obj[key]) ? [...obj[key]] : []
					} else if (key === 'conditions') {
						// Conditions has three valid storage shapes (string,
						// array, object) thanks to legacy rows — funnel them
						// all through the JsonLogic coercer so the visual
						// builder sees a consistent group node.
						out[key] = normaliseConditions(obj[key])
					} else if (typeof base[key] === 'object') {
						out[key] =
							typeof obj[key] === 'object' && !Array.isArray(obj[key])
								? { ...obj[key] }
								: {}
					} else {
						out[key] = obj[key]
					}
				}
			}
			return out
		},

		/**
		 * Write the condition tree back onto the draft. Shared entry point
		 * for both editors — the visual builder emits it on every edit and
		 * `onRawConditionsInput` routes parsed JSON through it too.
		 *
		 * @param {object} node Root JsonLogic group node, e.g. `{ and: [...] }`.
		 * @return {void}
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		onConditionsUpdate(node) {
			if (!this.draft) return
			this.draft.conditions = node
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		toggleRawConditions() {
			this.rawConditions = !this.rawConditions
		},

		/**
		 * Handle typing in the raw JSON conditions textarea. Empty input
		 * resets the draft to the empty AND group; invalid JSON only sets
		 * `rawConditionsError` so a half-typed tree never reaches the draft.
		 *
		 * @param {string} value Raw textarea contents, expected to parse as a JsonLogic node.
		 * @return {void}
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		onRawConditionsInput(value) {
			this.rawConditionsDraft = value
			const trimmed = value.trim()
			if (trimmed.length === 0) {
				this.rawConditionsError = ''
				this.onConditionsUpdate(emptyRootGroup())
				return
			}
			try {
				const parsed = JSON.parse(trimmed)
				this.rawConditionsError = ''
				this.onConditionsUpdate(parsed)
			} catch (parseErr) {
				this.rawConditionsError = t(
					'integriq',
					'Invalid JSON: {message}',
					{ message: parseErr.message },
				)
			}
		},

		/**
		 * Generic field writer used by the simple inputs (name, description,
		 * ids, mappings, actions, follow-ups) so each one does not need its
		 * own handler. No-ops until the draft has been loaded.
		 *
		 * @param {string} key Draft property to overwrite, e.g. `name` or `sourceConfig`.
		 * @param {*} value New value; type follows the property — string for text
		 *   fields, object for the config blobs, array for actions/follow-ups.
		 * @return {void}
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		updateDraft(key, value) {
			if (!this.draft) return
			this.draft[key] = value
		},

		/**
		 * Handle a pick in the source type selector. Switching the type
		 * discards `sourceId` and `sourceConfig` so kind-specific settings
		 * (e.g. an API endpoint) cannot leak into another kind's form.
		 *
		 * @param {{ id: string, label: string }|null} option Chosen entry from
		 *   `sourceTypeOptions`; ignored when null or without an `id`.
		 * @return {void}
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		onSourceTypeChange(option) {
			if (!option?.id || !this.draft) return
			// Type changed — clear the kind-specific blob + id so we don't
			// carry stale `endpoint:'/foo'` over into a register/schema mode.
			this.draft.sourceType = option.id
			this.draft.sourceId = ''
			this.draft.sourceConfig = {}
		},

		/**
		 * Handle a pick in the target type selector — same reset semantics as
		 * `onSourceTypeChange`, applied to the target side.
		 *
		 * @param {{ id: string, label: string }|null} option Chosen entry from
		 *   `typeOptions`; ignored when null or without an `id`.
		 * @return {void}
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		onTargetTypeChange(option) {
			if (!option?.id || !this.draft) return
			this.draft.targetType = option.id
			this.draft.targetId = ''
			this.draft.targetConfig = {}
		},

		/**
		 * Handle a pick in the sync mode selector. Selecting `incremental`
		 * is what reveals the cursor field / comparator inputs.
		 *
		 * @param {{ id: string, label: string }|null} option Chosen entry from
		 *   `SYNC_MODE_OPTIONS` (`full` or `incremental`); ignored when null.
		 * @return {void}
		 *
		 * @spec openspec/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016
		 */
		onSyncModeChange(option) {
			if (!option?.id || !this.draft) return
			this.draft.syncMode = option.id
		},

		/**
		 * Merge a single key into `draft.sourceConfig` without clobbering the
		 * other keys SyncConfigWidget independently manages on the same
		 * object — mirrors SyncConfigWidget's own read-current/spread/emit
		 * pattern so both write paths stay non-destructive of each other.
		 *
		 * @param {string} key   The sourceConfig key to set.
		 * @param {string} value The new value; an empty/null value removes the key.
		 *
		 * @spec openspec/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016
		 */
		updateSourceConfigField(key, value) {
			if (!this.draft) return
			const next = { ...(this.draft.sourceConfig || {}) }
			if (value === '' || value === null || value === undefined) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.draft.sourceConfig = next
		},

		/**
		 * Handle a pick in the cursor comparator selector, storing it under
		 * `sourceConfig.cursorComparator`. Clearing the select writes an
		 * empty value, which removes the key from the config blob.
		 *
		 * @param {{ id: string, label: string }|null} option Chosen entry from
		 *   `CURSOR_COMPARATOR_OPTIONS` (`gt` or `gte`), or null when cleared.
		 * @return {void}
		 *
		 * @spec openspec/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016
		 */
		onCursorComparatorChange(option) {
			this.updateSourceConfigField('cursorComparator', option?.id || '')
		},

		/**
		 * Clear the stored cursor watermark via `POST .../reset-cursor`
		 * (REQ-019). Clears the watermark only — does not change `syncMode`
		 * and does not delete any data or re-enable deletion detection
		 * (REQ-018 stays keyed on `syncMode`, unaffected by this action).
		 *
		 * @spec openspec/specs/synchronization-engine/spec.md#requirement-reset-cursor-action-clears-the-stored-watermark-req-019
		 */
		async resetCursor() {
			if (!this.objectIdString || this.resettingCursor) return
			this.resettingCursor = true
			try {
				const response = await axios.post(
					generateUrl(
						`/apps/integriq/api/synchronizations/${this.objectIdString}/reset-cursor`,
					),
				)
				const cleared = response.data?.cursorWatermark ?? ''
				if (this.original) this.original.cursorWatermark = cleared
				showSuccess(
					t(
						'integriq',
						'Cursor watermark cleared. The next run will request an unfiltered fetch — this does not delete data or restore deletion detection.',
					),
				)
			} catch (err) {
				showError(
					err?.response?.data?.error
						|| err?.message
						|| t('integriq', 'Failed to reset cursor'),
				)
			} finally {
				this.resettingCursor = false
			}
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		async save() {
			if (!this.draft || this.saving) return
			this.saving = true
			this.saveError = ''
			try {
				const payload = {
					...this.original,
					...this.draft,
					// The register schema declares `conditions` as
					// `array<object>` — serialise the builder's group node
					// into a single-element array so OR validation passes.
					conditions: serializeConditions(this.draft.conditions),
				}
				const saved = await this.objectStore.saveObject(
					this.schemaSlug,
					payload,
				)
				if (!saved) {
					this.saveError =
						this.objectStore.errors?.[this.schemaSlug]
						|| t('integriq', 'Save failed')
					return
				}
				this.original = saved
				this.draft = this.normalizeForDiff(saved)
			} catch (err) {
				this.saveError = err?.message || t('integriq', 'Save failed')
			} finally {
				this.saving = false
			}
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
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

.sync-detail__row--single {
	grid-template-columns: 1fr;
}

.sync-detail__card-header-spacer {
	flex: 1;
}

.sync-detail__textarea--code {
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
}

.sync-detail__raw-conditions {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.sync-detail__helper {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.sync-detail__helper--error {
	color: var(--color-error);
}
</style>

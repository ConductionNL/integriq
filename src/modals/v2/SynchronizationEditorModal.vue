<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  SynchronizationEditorModal — the wide create/edit surface for a Synchronization.

  Restores the three-column workflow the pre-manifest modal had — configure
  where data comes from, how it is transformed, and where it goes, on one
  screen:

      name │ description        (metadata, not a stage)

      ┌───────────────────┐  →  ┌───────────────────┐
      │ Source            │     │ Target            │
      │ kind + config     │     │ kind + config     │
      │ sync mode, cursor │     │ dry-run result    │
      └───────────────────┘     └───────────────────┘

      ┌─────────────────────────────────────────────┐
      │ Transform                                   │
      │ [Conditions][Rules][Mappings][Follow-ups]   │
      └─────────────────────────────────────────────┘

  Source and Target are peers of similar size and sit side by side with the
  flow arrow between them. Transform takes the full width underneath rather
  than a third column: its controls are much wider than theirs — a condition
  row is field + operator + value, and the operator select alone carries
  `min-width: 220px` — so in a ~440px column every control collapsed to its
  minimum and the placeholders clipped to "F"/"V". Tabs stop the four sections
  stacking into something several times taller than its neighbours, which is
  what made the three-column version look lopsided. The tabbed Transform also
  mirrors MappingEditorModal, so the two editors read as one family.

  It replaces the generic four-field CnFormDialog the Synchronizations index
  got during the manifest migration, where "+ Add" produced a stub that had to
  be finished on a second screen.

  ## Not a port of the old modal

  `src/modals/Synchronization/EditSynchronization.vue` was deleted alongside
  this component landing. Everything it did is already implemented, better, by
  the components `SynchronizationDetailPage` uses — `SyncConfigWidget` (the
  polymorphic source/target config, incl. the file picker and the Tables/Forms
  bridges), `SyncMappingPicker`, `SyncReferenceList` and `RuleConditionGroup`
  (a visual JsonLogic builder, where the old modal had a raw textarea). This
  modal is a second HOST for those components, not a second implementation;
  the shared draft/option/conditions logic lives in `syncDraft.js`.

  ## How it is mounted

  Through CnIndexPage's `form-dialog` slot, declared in the manifest as
  `pages[Synchronizations].slots["form-dialog"]`. Not `form-fields`: CnIndexPage
  does not forward `size` to CnFormDialog, so an inner-content override can
  never be wider than NcDialog's `normal`.

  Note the template is gated on `show` — unlike the default CnFormDialog, slot
  content always renders, so the gate has to be ours.

  ## Draft semantics

  Every edit lands in a local `draft`, seeded from `item` when the dialog
  opens; nothing is persisted until Save, which goes through the slot's
  `confirm` binding so the index's own save path (and its list refresh) runs.

  ## Why Test is edit-only

  `POST /api/synchronizations/{id}/test` loads the saved record by id, so
  there is nothing to test before the first save and a dirty draft would be
  tested as its last-saved self. The button is therefore absent in create mode
  and disabled while dirty, rather than quietly lying. (Contrast the mapping
  editor, whose `/api/mappings/test` accepts the mapping inline and can
  preview an unsaved draft.)

  @spec openspec/specs/sync-editor-ui/spec.md
-->
<template>
	<NcDialog
		v-if="show"
		:name="dialogTitle"
		size="large"
		class="cn-sync-editor-modal"
		:noClose="saving"
		@closing="onCancel">
		<div class="cn-sync-editor">
			<NcNoteCard v-if="saveError" type="error">
				<p>{{ saveError }}</p>
			</NcNoteCard>

			<!-- Identity fields — the synchronization's own metadata, so they sit
			     above the source → transform → target columns rather than inside
			     one, stacked and capped short of the modal width. -->
			<div class="cn-sync-editor__identity">
				<NcTextField
					v-model="draft.name"
					:label="nameLabel"
					:error="!!nameError"
					:helperText="nameError"
					:disabled="saving"
					required
					@blur="nameTouched = true" />
				<NcTextArea
					v-model="draft.description"
					:label="t('openconnector', 'Description')"
					:disabled="saving"
					rows="1"
					resize="vertical" />
			</div>

			<div class="cn-sync-editor__columns">
				<!-- ── Source ──────────────────────────────────────────── -->
				<section
					class="cn-sync-editor__column cn-sync-editor__column--source">
					<header class="cn-sync-editor__column-header">
						<DatabaseArrowRightOutlineIcon :size="20" />
						<h3>{{ t('openconnector', 'Source') }}</h3>
					</header>
					<div class="cn-sync-editor__column-body">
						<label class="cn-sync-editor__label">{{
							t('openconnector', 'Source type')
						}}</label>
						<NcSelect
							:modelValue="selectedSourceType"
							:options="sourceTypeOptions"
							:clearable="false"
							:disabled="saving"
							:aria-label-combobox="t('openconnector', 'Source type')"
							@update:modelValue="onSourceTypeChange" />

						<SyncConfigWidget
							kind="source"
							:type="draft.sourceType"
							:sourceId="draft.sourceId"
							:config="draft.sourceConfig"
							@update:sourceId="
								(value) => updateDraft('sourceId', value)
							"
							@update:config="
								(value) => updateDraft('sourceConfig', value)
							" />

						<label class="cn-sync-editor__label">{{
							t('openconnector', 'Sync mode')
						}}</label>
						<NcSelect
							:modelValue="selectedSyncMode"
							:options="syncModeOptions"
							:clearable="false"
							:disabled="saving"
							:aria-label-combobox="t('openconnector', 'Sync mode')"
							@update:modelValue="onSyncModeChange" />

						<template v-if="draft.syncMode === 'incremental'">
							<NcTextField
								:modelValue="draft.sourceConfig?.cursorField || ''"
								:label="t('openconnector', 'Cursor field')"
								:disabled="saving"
								@update:modelValue="
									(value) =>
										updateSourceConfigField('cursorField', value)
								" />
							<label class="cn-sync-editor__label">{{
								t('openconnector', 'Cursor comparator')
							}}</label>
							<NcSelect
								:modelValue="selectedCursorComparator"
								:options="cursorComparatorOptions"
								:clearable="false"
								:disabled="saving"
								:aria-label-combobox="
									t('openconnector', 'Cursor comparator')
								"
								@update:modelValue="onCursorComparatorChange" />
						</template>
					</div>
				</section>

				<ArrowRightIcon
					class="cn-sync-editor__arrow"
					:size="20"
					aria-hidden="true" />

				<!-- ── Target ──────────────────────────────────────────── -->
				<section
					class="cn-sync-editor__column cn-sync-editor__column--target">
					<header class="cn-sync-editor__column-header">
						<DatabaseArrowLeftOutlineIcon :size="20" />
						<h3>{{ t('openconnector', 'Target') }}</h3>
					</header>
					<div class="cn-sync-editor__column-body">
						<label class="cn-sync-editor__label">{{
							t('openconnector', 'Target type')
						}}</label>
						<NcSelect
							:modelValue="selectedTargetType"
							:options="typeOptions"
							:clearable="false"
							:disabled="saving"
							:aria-label-combobox="t('openconnector', 'Target type')"
							@update:modelValue="onTargetTypeChange" />

						<SyncConfigWidget
							kind="target"
							:type="draft.targetType"
							:sourceId="draft.targetId"
							:config="draft.targetConfig"
							@update:sourceId="
								(value) => updateDraft('targetId', value)
							"
							@update:config="
								(value) => updateDraft('targetConfig', value)
							" />

						<!-- Dry-run result. The row action discards this payload for a
						     bare toast; here it is the point of pressing Test. -->
						<template v-if="testError || testResult !== null">
							<h4 class="cn-sync-editor__group-title">
								{{ t('openconnector', 'Dry run result') }}
							</h4>
							<NcNoteCard v-if="testError" type="error">
								<p>{{ testError }}</p>
							</NcNoteCard>
							<pre v-else class="cn-sync-editor__pre"><!--
								-->{{ formattedTestResult }}</pre>
						</template>
					</div>
				</section>
			</div>

			<!--
			  Transform gets the full width rather than a third column. Its
			  controls are far wider than the source/target ones — a condition
			  row is field + operator + value and the operator select alone has
			  `min-width: 220px` — so in a ~440px column every control collapsed
			  to its minimum and the placeholders clipped to "F"/"V". Tabs keep
			  the four sections from stacking into a column several times taller
			  than its neighbours, which is what made the modal look lopsided.
			-->
			<section class="cn-sync-editor__transform">
				<header class="cn-sync-editor__column-header">
					<SwapHorizontalIcon :size="20" />
					<h3>{{ t('openconnector', 'Transform') }}</h3>
				</header>
				<div class="cn-sync-editor__transform-body">
					<CnTabs :aria-label="t('openconnector', 'Transform')">
						<CnTab :title="t('openconnector', 'Conditions')">
							<div class="cn-sync-editor__panel">
								<p class="cn-sync-editor__hint">
									{{
										t(
											'openconnector',
											'Gate which source records are synchronised. Leave empty to sync everything — raw JSON editing lives in the full editor.',
										)
									}}
								</p>
								<!-- Emits `update` (not `update:node`) — matches the detail page. -->
								<RuleConditionGroup
									:node="rootConditionGroup"
									:removable="false"
									@update="onConditionsChange" />
							</div>
						</CnTab>

						<CnTab>
							<template #title>
								<span class="cn-sync-editor__tab-label">
									{{ t('openconnector', 'Rules') }}
									<span class="cn-sync-editor__tab-count">{{
										draft.actions.length
									}}</span>
								</span>
							</template>
							<div class="cn-sync-editor__panel">
								<p class="cn-sync-editor__hint">
									{{
										t(
											'openconnector',
											'Rules applied during each sync pass.',
										)
									}}
								</p>
								<SyncReferenceList
									schema="rule"
									labelKey="name"
									:value="draft.actions"
									:inputLabel="t('openconnector', 'Rules')"
									:placeholder="
										t(
											'openconnector',
											'Pick rules to run during sync',
										)
									"
									:emptyLabel="
										t('openconnector', 'No rules linked yet.')
									"
									@input="
										(value) => updateDraft('actions', value)
									" />
							</div>
						</CnTab>

						<CnTab :title="t('openconnector', 'Mappings')">
							<div class="cn-sync-editor__panel">
								<SyncMappingPicker
									:value="draft.sourceTargetMapping"
									:hashValue="draft.sourceHashMapping"
									:targetSourceValue="draft.targetSourceMapping"
									@update:value="
										(value) =>
											updateDraft('sourceTargetMapping', value)
									"
									@update:hashValue="
										(value) =>
											updateDraft('sourceHashMapping', value)
									"
									@update:targetSourceValue="
										(value) =>
											updateDraft('targetSourceMapping', value)
									" />
							</div>
						</CnTab>

						<CnTab>
							<template #title>
								<span class="cn-sync-editor__tab-label">
									{{ t('openconnector', 'Follow-ups') }}
									<span class="cn-sync-editor__tab-count">{{
										draft.followUps.length
									}}</span>
								</span>
							</template>
							<div class="cn-sync-editor__panel">
								<p class="cn-sync-editor__hint">
									{{
										t(
											'openconnector',
											'Synchronizations to trigger when this one completes.',
										)
									}}
								</p>
								<SyncReferenceList
									schema="synchronization"
									labelKey="name"
									:value="draft.followUps"
									:excludeId="itemIdString"
									:inputLabel="t('openconnector', 'Follow-ups')"
									:placeholder="
										t(
											'openconnector',
											'Pick follow-up synchronizations',
										)
									"
									:emptyLabel="
										t(
											'openconnector',
											'No follow-ups linked yet.',
										)
									"
									@input="
										(value) => updateDraft('followUps', value)
									" />
							</div>
						</CnTab>
					</CnTabs>
				</div>
			</section>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="onCancel">
				{{ t('openconnector', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!isCreate"
				:disabled="saving || testing || dirty"
				:title="
					dirty
						? t(
								'openconnector',
								'Save first — the dry run tests the saved version',
							)
						: ''
				"
				@click="onTest">
				<template #icon>
					<NcLoadingIcon v-if="testing" :size="20" />
					<PlayCircleOutlineIcon v-else :size="20" />
				</template>
				{{ t('openconnector', 'Test') }}
			</NcButton>
			<NcButton type="primary" :disabled="!canSave" @click="onSave">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<PlusIcon v-else-if="isCreate" :size="20" />
					<ContentSaveOutlineIcon v-else :size="20" />
				</template>
				{{
					isCreate
						? t('openconnector', 'Create')
						: t('openconnector', 'Save')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { CnTab, CnTabs } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import ContentSaveOutlineIcon from 'vue-material-design-icons/ContentSaveOutline.vue'
import DatabaseArrowLeftOutlineIcon from 'vue-material-design-icons/DatabaseArrowLeftOutline.vue'
import DatabaseArrowRightOutlineIcon from 'vue-material-design-icons/DatabaseArrowRightOutline.vue'
import PlayCircleOutlineIcon from 'vue-material-design-icons/PlayCircleOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import SwapHorizontalIcon from 'vue-material-design-icons/SwapHorizontal.vue'
import RuleConditionGroup from '../../views/Rule/RuleConditionGroup.vue'
import SyncConfigWidget from '../../views/Synchronization/SyncConfigWidget.vue'
import SyncMappingPicker from '../../views/Synchronization/SyncMappingPicker.vue'
import SyncReferenceList from '../../views/Synchronization/SyncReferenceList.vue'
import { NEXTCLOUD_FORM_KIND } from '../../views/Synchronization/formsBridge.js'
import {
	CURSOR_COMPARATOR_OPTIONS,
	emptyDraft,
	fetchBridgeStatus,
	NEXTCLOUD_FORM_OPTION,
	NEXTCLOUD_TABLE_OPTION,
	normaliseConditions,
	serializeConditions,
	SYNC_MODE_OPTIONS,
	TYPE_OPTIONS,
} from '../../views/Synchronization/syncDraft.js'
import { NEXTCLOUD_TABLE_KIND } from '../../views/Synchronization/tablesBridge.js'

/** A name has to carry at least one letter or digit — punctuation alone is not a name. */
const NAME_PATTERN = /[\p{L}\p{N}]/u

export default {
	name: 'SynchronizationEditorModal',

	components: {
		CnTab,
		CnTabs,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
		RuleConditionGroup,
		SyncConfigWidget,
		SyncMappingPicker,
		SyncReferenceList,
		ArrowRightIcon,
		ContentSaveOutlineIcon,
		DatabaseArrowLeftOutlineIcon,
		DatabaseArrowRightOutlineIcon,
		PlayCircleOutlineIcon,
		PlusIcon,
		SwapHorizontalIcon,
	},

	props: {
		/** Slot scope: whether CnIndexPage wants the form dialog open. */
		show: {
			type: Boolean,
			default: false,
		},

		/** Slot scope: the row being edited, or `null` in create mode. */
		item: {
			type: Object,
			default: null,
		},

		/** Slot scope: the effective JSON schema. Unused — the fields are bespoke. */
		schema: {
			type: Object,
			default: null,
		},

		/**
		 * Slot scope: persists the object through CnIndexPage's own save path
		 * and refreshes the list. Saving here instead of calling this would
		 * leave the index stale until a reload — the built-in refresh only runs
		 * inside that handler — and would write to a different store than the
		 * one the list reads from.
		 */
		confirm: {
			type: Function,
			default: null,
		},

		/** Slot scope: closes the form dialog on CnIndexPage. */
		close: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			draft: emptyDraft(),
			/** Serialised snapshot of the seeded draft, for the dirty check. */
			originalSignature: '',
			/**
			 * Whether the name field has been left at least once. The "required"
			 * message waits for this so a freshly-opened Create dialog does not
			 * greet the user with a red empty field.
			 */
			nameTouched: false,
			tablesEnabled: false,
			formsEnabled: false,
			saving: false,
			saveError: '',
			testing: false,
			testError: '',
			testResult: null,
		}
	},

	computed: {
		isCreate() {
			return !this.item
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		itemIdString() {
			return this.item?.id != null ? String(this.item.id) : ''
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		dialogTitle() {
			return this.isCreate
				? this.t('openconnector', 'Create synchronization')
				: this.t('openconnector', 'Edit synchronization')
		},

		/**
		 * Required marker on the label. NcTextField/NcInputField has no
		 * `required` prop and renders no marker of its own, so the ` *` suffix
		 * is appended here — the same convention CnFormDialog uses for
		 * schema-required fields. `name` is the schema's only required property.
		 *
		 * @return {string} Label text.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		nameLabel() {
			return this.t('openconnector', 'Name') + ' *'
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		nameError() {
			if (!this.draft.name) {
				return this.nameTouched
					? this.t('openconnector', 'Name is required')
					: ''
			}
			return NAME_PATTERN.test(this.draft.name)
				? ''
				: this.t(
						'openconnector',
						'Name must contain at least one letter or number',
					)
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		dirty() {
			return JSON.stringify(this.draft) !== this.originalSignature
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		canSave() {
			return (
				!this.saving
				&& !!this.draft.name
				&& !this.nameError
				// No `confirm` means the host did not bind the slot scope, so
				// there is nothing to save through.
				&& typeof this.confirm === 'function'
			)
		},

		/**
		 * Kind options shared by both selectors. An already-configured
		 * `nextcloud-table` stays visible even if the Tables app is later
		 * disabled, so an existing record never silently loses its type.
		 *
		 * @return {Array<object>} Type options.
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
		 * Kind options offered in the SOURCE selector only. `nextcloud-form` is
		 * appended here and never to the shared list the target selector uses —
		 * it is a source-only type (nextcloud-forms-connector REQ-002).
		 *
		 * @return {Array<object>} Source type options.
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

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		syncModeOptions() {
			return SYNC_MODE_OPTIONS
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		selectedSyncMode() {
			return (
				SYNC_MODE_OPTIONS.find((opt) => opt.id === this.draft?.syncMode)
				|| SYNC_MODE_OPTIONS[0]
			)
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		cursorComparatorOptions() {
			return CURSOR_COMPARATOR_OPTIONS
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		selectedCursorComparator() {
			const current = this.draft?.sourceConfig?.cursorComparator
			return (
				CURSOR_COMPARATOR_OPTIONS.find((opt) => opt.id === current)
				|| CURSOR_COMPARATOR_OPTIONS[0]
			)
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		rootConditionGroup() {
			return normaliseConditions(this.draft?.conditions)
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		formattedTestResult() {
			try {
				return JSON.stringify(this.testResult, null, 2)
			} catch (_e) {
				return String(this.testResult)
			}
		},
	},

	watch: {
		show: {
			immediate: true,
			/**
			 * Re-seed the draft every time the dialog opens, so a cancelled edit
			 * leaves nothing behind for the next one.
			 *
			 * @param {boolean} value Whether the dialog is now open.
			 *
			 * @spec openspec/specs/sync-editor-ui/spec.md
			 */
			handler(value) {
				if (value) this.seedDraft()
			},
		},
	},

	/** @spec openspec/specs/sync-editor-ui/spec.md */
	async mounted() {
		// Probe both bridges once: their kinds are only offered when the
		// companion app is enabled for the acting user.
		this.tablesEnabled = await fetchBridgeStatus('tables')
		this.formsEnabled = await fetchBridgeStatus('forms')
	},

	methods: {
		/**
		 * Copy the row being edited into the local draft, normalising the
		 * conditions tree — persisted records carry it as `array<object>` while
		 * the visual builder works with a group node.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		seedDraft() {
			this.saveError = ''
			this.testError = ''
			this.testResult = null
			this.nameTouched = false
			const base = emptyDraft()
			if (this.item) {
				for (const key of Object.keys(base)) {
					if (this.item[key] !== undefined && this.item[key] !== null) {
						base[key] = this.item[key]
					}
				}
				base.conditions = normaliseConditions(this.item.conditions)
			}
			this.draft = base
			this.originalSignature = JSON.stringify(base)
		},

		/**
		 * Replace one draft field. Assigning a fresh object keeps the dirty
		 * signature honest for the nested config bags.
		 *
		 * @param {string} key   Draft field name.
		 * @param {*}      value New value.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		updateDraft(key, value) {
			this.draft = { ...this.draft, [key]: value }
		},

		/**
		 * Replace one key inside `sourceConfig` without dropping its siblings.
		 *
		 * @param {string} key   Config key.
		 * @param {*}      value New value.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		updateSourceConfigField(key, value) {
			this.updateDraft('sourceConfig', {
				...(this.draft.sourceConfig || {}),
				[key]: value,
			})
		},

		/**
		 * @param {?object} option Selected source-kind option.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		onSourceTypeChange(option) {
			this.updateDraft('sourceType', option?.id || TYPE_OPTIONS[0].id)
		},

		/**
		 * @param {?object} option Selected target-kind option.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		onTargetTypeChange(option) {
			this.updateDraft('targetType', option?.id || TYPE_OPTIONS[1].id)
		},

		/**
		 * @param {?object} option Selected sync-mode option.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		onSyncModeChange(option) {
			this.updateDraft('syncMode', option?.id || SYNC_MODE_OPTIONS[0].id)
		},

		/**
		 * @param {?object} option Selected cursor-comparator option.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		onCursorComparatorChange(option) {
			this.updateSourceConfigField(
				'cursorComparator',
				option?.id || CURSOR_COMPARATOR_OPTIONS[0].id,
			)
		},

		/**
		 * @param {object} node The JsonLogic group node from the visual builder.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		onConditionsChange(node) {
			this.updateDraft('conditions', node)
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		onCancel() {
			if (this.saving) return
			this.close?.()
		},

		/**
		 * Run the dry run against the SAVED record. Guarded to edit mode and a
		 * clean draft by the button itself; the endpoint takes only an id.
		 *
		 * @return {Promise<void>} Resolves once the run has settled.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		async onTest() {
			if (this.isCreate || !this.itemIdString) return
			this.testing = true
			this.testError = ''
			this.testResult = null
			try {
				const response = await axios.post(
					generateUrl(
						`/apps/openconnector/api/synchronizations/${this.itemIdString}/test`,
					),
				)
				this.testResult = response.data ?? null
			} catch (err) {
				const status = err?.response?.status
				const message =
					err?.response?.data?.message
					|| err?.response?.data?.error
					|| err?.message
					|| ''
				this.testError =
					this.t('openconnector', 'Synchronization test failed')
					+ (status ? ` (${status})` : '')
					+ (message ? `: ${message}` : '')
			} finally {
				this.testing = false
			}
		},

		/**
		 * Persist the draft through CnIndexPage's `confirm` binding rather than
		 * saving here directly — that is what runs the index's list refresh and
		 * keeps the write in the store the list reads from.
		 *
		 * The draft is merged over `item` so fields this dialog does not edit
		 * (slug, version, status, the engine-managed hashes and timestamps)
		 * survive; no `id` means create. `conditions` is re-serialised to the
		 * schema's `array<object>` wire shape on the way out.
		 *
		 * @return {Promise<void>} Resolves once the save has settled.
		 *
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		async onSave() {
			if (!this.canSave) return
			this.saving = true
			this.saveError = ''
			try {
				await this.confirm({
					...(this.item || {}),
					...this.draft,
					conditions: serializeConditions(this.draft.conditions),
				})
				showSuccess(
					this.isCreate
						? this.t('openconnector', 'Synchronization created')
						: this.t('openconnector', 'Synchronization saved'),
				)
				this.close?.()
			} catch (err) {
				this.saveError =
					err?.message
					|| this.t('openconnector', 'Failed to save synchronization')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
/*
 * NcModal sizes `large` at a fixed 900px, which is too tight for three
 * columns; the original synchronization modal widened its container to 1200px
 * the same way. `class` on NcDialog lands on NcModal's `.modal-mask` root,
 * which carries this component's scope id even though NcModal teleports to
 * <body>, so `:deep()` from here reaches the container.
 *
 * Specificity matters: NcModal's own rule is
 * `.modal-wrapper--large > .modal-container[data-v-…]` at (0,2,0). Keeping
 * `.modal-wrapper` in the selector puts this at (0,4,0), so it wins outright
 * rather than tying and depending on stylesheet order.
 */
.cn-sync-editor-modal :deep(.modal-wrapper > .modal-container) {
	width: 1200px;
	max-width: 90%;
}

.cn-sync-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	width: 100%;
}

/* Metadata, not a stage — stacked and capped so the differing control heights
   of an input next to a textarea cannot leave a gap beside the shorter one. */
.cn-sync-editor__identity {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 480px;
}

/*
 * Source and Target are peers of similar size, so they sit side by side with
 * the flow arrow in the `auto` gutter between them. Rows stretch (the grid
 * default) so the two cards share one height rather than reading as two
 * floating panels. Transform is NOT a third column here — see the section
 * comment in the template.
 */
.cn-sync-editor__columns {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
	gap: 12px;
}

.cn-sync-editor__arrow {
	/* Line the arrow up with the middle of the column header band
	   (12px padding + 20px icon + 12px padding = 44px tall). */
	align-self: start;
	padding-top: 12px;
	color: var(--color-text-maxcontrast);
}

/* The modal is capped at 90% of the viewport, so below roughly 1024px the
   three columns get too narrow to work in — stack them, and drop the
   left-to-right arrows since the flow is now top-to-bottom. */
@media (max-width: 1024px) {
	.cn-sync-editor__columns {
		grid-template-columns: minmax(0, 1fr);
	}

	.cn-sync-editor__arrow {
		display: none;
	}
}

.cn-sync-editor__column,
.cn-sync-editor__transform {
	display: flex;
	flex-direction: column;
	min-width: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background);
}

.cn-sync-editor__transform-body {
	padding: 12px;
	min-width: 0;
}

/* Panel content inside a tab. */
.cn-sync-editor__panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

/* Rendered into CnTabs' nav button via the `#title` slot. Slot content is
   compiled in this component's scope, so these scoped rules still match. */
.cn-sync-editor__tab-label {
	display: inline-flex;
	gap: 6px;
	align-items: center;
}

.cn-sync-editor__tab-count {
	font-size: 12px;
	font-weight: 400;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
	padding: 0 6px;
	border-radius: 10px;
}

.cn-sync-editor__column-header {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px) var(--border-radius-large, 8px) 0
		0;
	background: var(--color-background-hover);
}

.cn-sync-editor__column-header h3 {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

/* Column tints, carried over from the original modal — they are what made the
   source → transform → target direction readable at a glance. */
.cn-sync-editor__column--source > .cn-sync-editor__column-header {
	background: rgba(var(--color-primary-rgb), 0.1);
}

.cn-sync-editor__transform > .cn-sync-editor__column-header {
	background: rgba(var(--color-warning-rgb), 0.1);
}

.cn-sync-editor__column--target > .cn-sync-editor__column-header {
	background: rgba(var(--color-success-rgb), 0.1);
}

.cn-sync-editor__column-body {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	min-width: 0;
	flex: 1;
}

.cn-sync-editor__label {
	font-weight: 500;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.cn-sync-editor__group-title {
	margin: 8px 0 0 0;
	font-size: 14px;
	font-weight: 600;
}

.cn-sync-editor__group-title:first-child {
	margin-top: 0;
}

.cn-sync-editor__hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.cn-sync-editor__pre {
	background: var(--color-background-dark);
	color: var(--color-main-text);
	padding: 12px;
	border-radius: var(--border-radius);
	overflow: auto;
	max-height: 280px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 12px;
	white-space: pre-wrap;
	word-break: break-word;
	margin: 0;
}
</style>

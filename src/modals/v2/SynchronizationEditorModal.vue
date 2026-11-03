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

			<!-- Identity fields — the synchronization metadata itself, so they sit
			     above the source → transform → target columns rather than inside
			     one, stacked and capped short of the modal width. -->
			<EditorIdentityFields
				block="cn-sync-editor"
				:name="draft.name"
				:description="draft.description"
				:nameLabel="nameLabel"
				:nameError="nameError"
				:disabled="saving"
				required
				@update:name="draft.name = $event"
				@update:description="draft.description = $event"
				@blur="nameTouched = true" />

			<div class="cn-sync-editor__columns">
				<!-- ── Source ──────────────────────────────────────────── -->
				<section
					class="cn-sync-editor__column cn-sync-editor__column--source">
					<header class="cn-sync-editor__column-header">
						<DatabaseArrowRightOutlineIcon :size="20" />
						<h3>{{ t('integriq', 'Source') }}</h3>
					</header>
					<div class="cn-sync-editor__column-body">
						<label class="cn-sync-editor__label">{{
							t('integriq', 'Source type')
						}}</label>
						<NcSelect
							:modelValue="selectedSourceType"
							:options="sourceTypeOptions"
							:clearable="false"
							:disabled="saving"
							:aria-label-combobox="t('integriq', 'Source type')"
							@update:modelValue="onSourceTypeChange" />

						<SyncConfigWidget
							kind="source"
							:type="draft.sourceType"
							:sourceId="draft.sourceId"
							:config="draft.sourceConfig"
							@update:sourceId="updateDraft('sourceId', $event)"
							@update:config="updateDraft('sourceConfig', $event)" />

						<label class="cn-sync-editor__label">{{
							t('integriq', 'Sync mode')
						}}</label>
						<NcSelect
							:modelValue="selectedSyncMode"
							:options="syncModeOptions"
							:clearable="false"
							:disabled="saving"
							:aria-label-combobox="t('integriq', 'Sync mode')"
							@update:modelValue="onSyncModeChange" />

						<template v-if="draft.syncMode === 'incremental'">
							<NcTextField
								:modelValue="draft.sourceConfig?.cursorField || ''"
								:label="t('integriq', 'Cursor field')"
								:disabled="saving"
								@update:modelValue="
									updateSourceConfigField('cursorField', $event)
								" />
							<label class="cn-sync-editor__label">{{
								t('integriq', 'Cursor comparator')
							}}</label>
							<NcSelect
								:modelValue="selectedCursorComparator"
								:options="cursorComparatorOptions"
								:clearable="false"
								:disabled="saving"
								:aria-label-combobox="
									t('integriq', 'Cursor comparator')
								"
								@update:modelValue="onCursorComparatorChange" />
						</template>

						<NcSelect
							inputId="cn-sync-editor-ownership-mode"
							:inputLabel="t('integriq', 'Who owns these records')"
							:modelValue="selectedOwnershipMode"
							:options="ownershipModeOptions"
							:clearable="false"
							:disabled="saving"
							data-testid="sync-editor-ownership-mode"
							@update:modelValue="
								updateSourceConfigField(
									'ownershipMode',
									$event?.id || 'local',
								)
							" />
						<NcSelect
							inputId="cn-sync-editor-disappearance-policy"
							:inputLabel="
								t(
									'integriq',
									'When the source stops sending a record',
								)
							"
							:modelValue="selectedDisappearancePolicy"
							:options="disappearancePolicyOptions"
							:clearable="false"
							:disabled="saving"
							data-testid="sync-editor-disappearance-policy"
							@update:modelValue="
								updateSourceConfigField(
									'disappearancePolicy',
									$event?.id || 'delete',
								)
							" />
						<NcSelect
							inputId="cn-sync-editor-source-destroyed"
							:inputLabel="
								t(
									'integriq',
									'When the source says it destroyed a record',
								)
							"
							:modelValue="selectedSourceDestroyed"
							:options="sourceDestroyedOptions"
							:clearable="false"
							:disabled="saving"
							data-testid="sync-editor-source-destroyed"
							@update:modelValue="onSourceDestroyedChange" />
						<NcNoteCard
							v-if="purgeSelected"
							type="warning"
							data-testid="sync-editor-purge-warning">
							{{
								t(
									'integriq',
									'A purge deletes the record and its files permanently. Purged files cannot be restored.',
								)
							}}
						</NcNoteCard>
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
						<h3>{{ t('integriq', 'Target') }}</h3>
					</header>
					<div class="cn-sync-editor__column-body">
						<label class="cn-sync-editor__label">{{
							t('integriq', 'Target type')
						}}</label>
						<NcSelect
							:modelValue="selectedTargetType"
							:options="typeOptions"
							:clearable="false"
							:disabled="saving"
							:aria-label-combobox="t('integriq', 'Target type')"
							@update:modelValue="onTargetTypeChange" />

						<SyncConfigWidget
							kind="target"
							:type="draft.targetType"
							:sourceId="draft.targetId"
							:config="draft.targetConfig"
							@update:sourceId="updateDraft('targetId', $event)"
							@update:config="updateDraft('targetConfig', $event)" />

						<!-- REQ-CSD-001: only a register/schema source has an object
						     to write the push's outcome back onto. -->
						<SyncWriteBackFields
							v-if="draft.sourceType === 'register/schema'"
							:value="draft.writeBack"
							:disabled="saving"
							@update:value="updateDraft('writeBack', $event)" />

						<!-- Dry-run result. The row action discards this payload for a
						     bare toast; here it is the point of pressing Test. -->
						<template v-if="testError || testResult !== null">
							<h4 class="cn-sync-editor__group-title">
								{{ t('integriq', 'Dry run result') }}
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
					<h3>{{ t('integriq', 'Transform') }}</h3>
				</header>
				<div class="cn-sync-editor__transform-body">
					<CnTabs :aria-label="t('integriq', 'Transform')">
						<CnTab :title="t('integriq', 'Conditions')">
							<div class="cn-sync-editor__panel">
								<p class="cn-sync-editor__hint">
									{{
										t(
											'integriq',
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
									{{ t('integriq', 'Rules') }}
									<span class="cn-sync-editor__tab-count">{{
										draft.actions.length
									}}</span>
								</span>
							</template>
							<div class="cn-sync-editor__panel">
								<p class="cn-sync-editor__hint">
									{{
										t(
											'integriq',
											'Rules applied during each sync pass.',
										)
									}}
								</p>
								<SyncReferenceList
									schema="rule"
									labelKey="name"
									:value="draft.actions"
									:inputLabel="t('integriq', 'Rules')"
									:placeholder="
										t(
											'integriq',
											'Pick rules to run during sync',
										)
									"
									:emptyLabel="
										t('integriq', 'No rules linked yet.')
									"
									@input="updateDraft('actions', $event)" />
							</div>
						</CnTab>

						<CnTab :title="t('integriq', 'Mappings')">
							<div class="cn-sync-editor__panel">
								<SyncMappingPicker
									:value="draft.sourceTargetMapping"
									:hashValue="draft.sourceHashMapping"
									:targetSourceValue="draft.targetSourceMapping"
									@update:value="
										updateDraft('sourceTargetMapping', $event)
									"
									@update:hashValue="
										updateDraft('sourceHashMapping', $event)
									"
									@update:targetSourceValue="
										updateDraft('targetSourceMapping', $event)
									" />
							</div>
						</CnTab>

						<CnTab>
							<template #title>
								<span class="cn-sync-editor__tab-label">
									{{ t('integriq', 'Follow-ups') }}
									<span class="cn-sync-editor__tab-count">{{
										draft.followUps.length
									}}</span>
								</span>
							</template>
							<div class="cn-sync-editor__panel">
								<p class="cn-sync-editor__hint">
									{{
										t(
											'integriq',
											'Synchronizations to trigger when this one completes.',
										)
									}}
								</p>
								<SyncReferenceList
									schema="synchronization"
									labelKey="name"
									:value="draft.followUps"
									:excludeId="itemIdString"
									:inputLabel="t('integriq', 'Follow-ups')"
									:placeholder="
										t(
											'integriq',
											'Pick follow-up synchronizations',
										)
									"
									:emptyLabel="
										t('integriq', 'No follow-ups linked yet.')
									"
									@input="updateDraft('followUps', $event)" />
							</div>
						</CnTab>
					</CnTabs>
				</div>
			</section>

			<!--
			  Extra content a HOST appends below the three stages. Empty for
			  the Synchronizations index; the flow editor's node adapter fills
			  it with the step-level options (force, output, maxItems,
			  onError) so one dialog edits both the synchronization and the
			  step that runs it.
			-->
			<slot name="hostExtras" />
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="onCancel">
				{{ t('integriq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!isCreate"
				:disabled="saving || testing || dirty"
				:title="
					dirty
						? t(
								'integriq',
								'Save first — the dry run tests the saved version',
							)
						: ''
				"
				@click="onTest">
				<template #icon>
					<NcLoadingIcon v-if="testing" :size="20" />
					<PlayCircleOutlineIcon v-else :size="20" />
				</template>
				{{ t('integriq', 'Test') }}
			</NcButton>
			<EditorSaveButton
				:disabled="!canSave"
				:saving="saving"
				:isCreate="isCreate"
				@click="onSave" />
		</template>
	</NcDialog>
</template>

<script>
import { CnTab, CnTabs } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import DatabaseArrowLeftOutlineIcon from 'vue-material-design-icons/DatabaseArrowLeftOutline.vue'
import DatabaseArrowRightOutlineIcon from 'vue-material-design-icons/DatabaseArrowRightOutline.vue'
import PlayCircleOutlineIcon from 'vue-material-design-icons/PlayCircleOutline.vue'
import SwapHorizontalIcon from 'vue-material-design-icons/SwapHorizontal.vue'
import RuleConditionGroup from '../../views/Rule/RuleConditionGroup.vue'
import SyncConfigWidget from '../../views/Synchronization/SyncConfigWidget.vue'
import SyncMappingPicker from '../../views/Synchronization/SyncMappingPicker.vue'
import SyncReferenceList from '../../views/Synchronization/SyncReferenceList.vue'
import SyncWriteBackFields from '../../views/Synchronization/SyncWriteBackFields.vue'
import EditorIdentityFields from './EditorIdentityFields.vue'
import EditorSaveButton from './EditorSaveButton.vue'
import confirmSave from '../../mixins/confirmSave.js'
import namedEditorDialog from '../../mixins/namedEditorDialog.js'
import syncKindOptions from '../../mixins/syncKindOptions.js'
import {
	disappearancePolicyError,
	disappearancePolicyOptions,
	ownershipModeOptions,
	purges,
	sourceDestroyedOptions,
} from '../../views/Synchronization/ownershipOptions.js'
import {
	CURSOR_COMPARATOR_OPTIONS,
	emptyDraft,
	fetchBridgeStatus,
	normaliseConditions,
	serializeConditions,
	SYNC_MODE_OPTIONS,
	TYPE_OPTIONS,
} from '../../views/Synchronization/syncDraft.js'
import { editorSlotScopeProps } from '../editorSlotScope.js'

export default {
	name: 'SynchronizationEditorModal',

	components: {
		EditorSaveButton,
		EditorIdentityFields,
		CnTab,
		CnTabs,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		RuleConditionGroup,
		SyncConfigWidget,
		SyncMappingPicker,
		SyncReferenceList,
		SyncWriteBackFields,
		ArrowRightIcon,
		DatabaseArrowLeftOutlineIcon,
		DatabaseArrowRightOutlineIcon,
		PlayCircleOutlineIcon,
		SwapHorizontalIcon,
	},

	mixins: [namedEditorDialog, confirmSave, syncKindOptions],

	props: { ...editorSlotScopeProps },

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
		/** @spec openspec/specs/sync-editor-ui/spec.md */
		itemIdString() {
			return this.item?.id != null ? String(this.item.id) : ''
		},

		/** @spec openspec/specs/sync-editor-ui/spec.md */
		dialogTitle() {
			return this.isCreate
				? this.t('integriq', 'Create synchronization')
				: this.t('integriq', 'Edit synchronization')
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

		/**
		 * The ownership mode options (REQ-SOR-001).
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/specs/source-owned-records/spec.md#requirement-a-record-maintained-from-a-source-says-who-owns-it-req-sor-001
		 */
		ownershipModeOptions() {
			return ownershipModeOptions()
		},

		/**
		 * The declared ownership mode, `local` when none is declared.
		 *
		 * @return {{id: string, label: string}}
		 * @spec openspec/specs/source-owned-records/spec.md#requirement-a-record-maintained-from-a-source-says-who-owns-it-req-sor-001
		 */
		selectedOwnershipMode() {
			const options = this.ownershipModeOptions
			const current = this.draft?.sourceConfig?.ownershipMode
			return options.find((opt) => opt.id === current) || options[0]
		},

		/**
		 * The disappearance policy options (REQ-SOR-002).
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/specs/source-owned-records/spec.md#requirement-what-happens-when-a-record-disappears-is-declared-not-hardcoded-req-sor-002
		 */
		disappearancePolicyOptions() {
			return disappearancePolicyOptions()
		},

		/**
		 * The declared disappearance policy. A value the engine does not know
		 * is shown as itself rather than as the default, so an administrator
		 * sees the typo instead of a policy that is not in force.
		 *
		 * @return {{id: string, label: string}}
		 * @spec openspec/specs/source-owned-records/spec.md#requirement-what-happens-when-a-record-disappears-is-declared-not-hardcoded-req-sor-002
		 */
		selectedDisappearancePolicy() {
			const options = this.disappearancePolicyOptions
			const current = this.draft?.sourceConfig?.disappearancePolicy
			if (current === undefined || current === null || current === '') {
				return options[0]
			}
			return (
				options.find((opt) => opt.id === current) || {
					id: current,
					label: String(current),
				}
			)
		},

		/**
		 * What a destruction notice does (REQ-SDP-002).
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
		 */
		sourceDestroyedOptions() {
			return sourceDestroyedOptions()
		},

		/**
		 * The declared destruction notice choice, the policy when none is declared.
		 *
		 * @return {{id: string, label: string}}
		 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
		 */
		selectedSourceDestroyed() {
			const options = this.sourceDestroyedOptions
			const current = this.draft?.sourceConfig?.onSourceDestroyed || ''
			return (
				options.find((opt) => opt.id === current) || {
					id: current,
					label: String(current),
				}
			)
		},

		/**
		 * Whether this synchronization purges, so the form can say a purge is final.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-synchronization-can-purge-a-vanished-record-and-its-files-req-sdp-001
		 */
		purgeSelected() {
			return purges(this.draft?.sourceConfig)
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
			// writeBack is not in emptyDraft: the detail page shares that and
			// does not edit it. Null means the record declares none.
			base.writeBack = this.item?.writeBack ?? null
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
		 * Write the destruction notice choice; the default leaves the key out.
		 *
		 * @param {?object} option the picked option
		 *
		 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
		 */
		onSourceDestroyedChange(option) {
			const rest = { ...(this.draft.sourceConfig || {}) }
			delete rest.onSourceDestroyed
			if (option?.id) {
				rest.onSourceDestroyed = option.id
			}
			this.updateDraft('sourceConfig', rest)
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
						`/apps/integriq/api/synchronizations/${this.itemIdString}/test`,
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
					this.t('integriq', 'Synchronization test failed')
					+ (status ? ` (${status})` : '')
					+ (message ? `: ${message}` : '')
			} finally {
				this.testing = false
			}
		},

		/**
		 * The object to save: the draft merged over `item` so fields this
		 * dialog does not edit survive, `conditions` in the schema's wire shape.
		 * A write-back is sent when declared; one the user emptied is sent as
		 * `{}` so the stored one does not survive the merge (REQ-CSD-001); a
		 * record that never had one gets no `writeBack` key at all.
		 *
		 * @return {object} the payload for `confirm`
		 *
		 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
		 */
		savePayload() {
			const { writeBack, ...draft } = this.draft
			const payload = {
				...(this.item || {}),
				...draft,
				conditions: serializeConditions(this.draft.conditions),
			}
			delete payload.writeBack
			if (writeBack) {
				payload.writeBack = writeBack
			} else if (this.item?.writeBack) {
				payload.writeBack = {}
			}
			return payload
		},

		/**
		 * Refuse an unknown disappearance policy at save (REQ-SOR-002), rather
		 * than discovering it at the first run that deletes nothing.
		 *
		 * @return {Promise<boolean>} True when the policy was refused.
		 * @spec openspec/specs/source-owned-records/spec.md#requirement-what-happens-when-a-record-disappears-is-declared-not-hardcoded-req-sor-002
		 */
		async saveRefused() {
			this.saveError = await disappearancePolicyError(this.draft.sourceConfig)
			return this.saveError !== ''
		},

		/**
		 * The toast after a save.
		 *
		 * @return {string} The translated text.
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		savedText() {
			return this.isCreate
				? this.t('integriq', 'Synchronization created')
				: this.t('integriq', 'Synchronization saved')
		},

		/**
		 * The reason shown when a save fails without one.
		 *
		 * @return {string} The translated text.
		 * @spec openspec/specs/sync-editor-ui/spec.md
		 */
		saveFailedText() {
			return this.t('integriq', 'Failed to save synchronization')
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
	overflow-wrap: break-word;
	margin: 0;
}
</style>

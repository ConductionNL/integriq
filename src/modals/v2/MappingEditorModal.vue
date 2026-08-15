<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  MappingEditorModal — the wide create/edit surface for a Mapping.

  Restores the three-column workflow the pre-manifest modal had — see your
  test input, shape it, and see the output, all on one screen:

      name           (metadata, not a stage — stacked and capped ~480px so
      description     the differing control heights cannot leave a gap)

      ┌───────────┐    ┌──────────────────┐    ┌──────────────────┐
      │ Input     │ →  │ Transform        │ →  │ Output           │
      │ test JSON │    │ mapping / cast / │    │ validation schema│
      │           │    │ unset / options  │    │ live result      │
      │           │    │ tabs             │    │ validation errors│
      └───────────┘    └──────────────────┘    │ save to register │
                                               └──────────────────┘

  The three cards share one row height (grid rows stretch) so they read as a
  single workspace; the input textarea and the result block flex into whatever
  height the tallest column sets, so no column needs its own scrollbar. The
  flow arrows sit in the grid gutters rather than in a separate strip above —
  a full-width band of icons only restated the column headers while eating
  working height.

  It replaces the generic two-field CnFormDialog that the Mappings index
  got during the manifest migration, where the only way to build an actual
  mapping was to create an empty named shell and then navigate to a second
  screen.

  ## How it is mounted

  Through the `form-dialog` slot of CnIndexPage, declared in the manifest as
  `pages[Mappings].slots["form-dialog"] = "MappingEditorModal"`. CnPageRenderer
  mounts `page.slots` entries as named slots and v-binds the slot scope as
  props, so `show` / `item` / `schema` / `close` arrive as props below.

  Not `form-fields`: CnIndexPage does not forward `size` to CnFormDialog, so
  an inner-content override can never be wider than NcDialog's `normal`.
  Overriding `form-dialog` hands us the dialog itself.

  Note the whole template is gated on `v-if="show"` — unlike the default
  CnFormDialog, slot content always renders, so the gate has to be ours.

  ## Draft semantics

  Every edit lands in a local `draft`, seeded from `item` when the dialog
  opens; nothing is persisted until Save. That is deliberate and differs
  from both predecessors: the legacy modal saved rules mid-edit through a
  sibling dialog, and MappingDetailPage still persists on every individual
  rule change. Holding a draft is what makes create mode work at all — you
  build the whole mapping, including its rules, then save once.

  @spec openspec/specs/mapping-editor-ui/spec.md
-->
<template>
	<NcDialog
		v-if="show"
		:name="dialogTitle"
		size="large"
		class="cn-mapping-editor-modal"
		:no-close="saving"
		@closing="onCancel">
		<div class="cn-mapping-editor">
			<NcNoteCard v-if="saveError" type="error">
				<p>{{ saveError }}</p>
			</NcNoteCard>

			<!-- Identity fields — the mapping's own metadata, so they sit above
			     the input → transform → output columns rather than inside one,
			     stacked and capped short of the modal width: they are not the
			     work, and stacking sidesteps the input/textarea height gap. -->
			<div class="cn-mapping-editor__identity">
				<NcTextField
					v-model="draft.name"
					:label="nameLabel"
					:error="!!nameError"
					:helper-text="nameError"
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

			<!-- The pipeline. Arrows live in the grid gutters rather than in a
			     separate strip: they carry the input → transform → output
			     reading without a band of empty space restating the headers. -->
			<div class="cn-mapping-editor__columns">
				<!-- ── Input ───────────────────────────────────────────── -->
				<section
					class="cn-mapping-editor__column cn-mapping-editor__column--input">
					<header class="cn-mapping-editor__column-header">
						<DatabaseArrowRightOutlineIcon :size="20" />
						<h3>{{ t('openconnector', 'Input') }}</h3>
						<span class="cn-mapping-editor__column-note">
							{{ t('openconnector', 'not stored') }}
						</span>
					</header>
					<div class="cn-mapping-editor__column-body">
						<label
							class="cn-mapping-editor__label"
							for="cn-mapping-editor-input">
							{{ t('openconnector', 'Test input (JSON)') }}
						</label>
						<textarea
							id="cn-mapping-editor-input"
							v-model="inputJson"
							class="cn-mapping-editor__textarea"
							rows="8"
							spellcheck="false"
							:placeholder="inputPlaceholder" />
						<p v-if="inputError" class="cn-mapping-editor__error">
							{{ inputError }}
						</p>
					</div>
				</section>

				<ArrowRightIcon
					class="cn-mapping-editor__arrow"
					:size="20"
					aria-hidden="true" />

				<!-- ── Transform ───────────────────────────────────────── -->
				<section
					class="cn-mapping-editor__column cn-mapping-editor__column--transform">
					<header class="cn-mapping-editor__column-header">
						<SwapHorizontalIcon :size="20" />
						<h3>{{ t('openconnector', 'Transform') }}</h3>
					</header>
					<div class="cn-mapping-editor__column-body">
						<MappingRulesEditor
							:mapping-rules="draft.mapping"
							:cast-rules="draft.cast"
							:unset-rules="draft.unset"
							:pass-through="draft.passThrough"
							:saving="saving"
							show-options-tab
							@update-mapping="draft.mapping = $event"
							@update-cast="draft.cast = $event"
							@update-unset="draft.unset = $event"
							@update-pass-through="draft.passThrough = $event" />
					</div>
				</section>

				<ArrowRightIcon
					class="cn-mapping-editor__arrow"
					:size="20"
					aria-hidden="true" />

				<!-- ── Output ──────────────────────────────────────────── -->
				<section
					class="cn-mapping-editor__column cn-mapping-editor__column--output">
					<header class="cn-mapping-editor__column-header">
						<DatabaseArrowLeftOutlineIcon :size="20" />
						<h3>{{ t('openconnector', 'Output') }}</h3>
						<span class="cn-mapping-editor__column-note">
							{{ t('openconnector', 'live') }}
						</span>
					</header>
					<div class="cn-mapping-editor__column-body">
						<MappingResultPanel
							ref="result"
							:mapping="testableMapping"
							:input-object="inputJson"
							@input-error="inputError = $event" />
					</div>
				</section>
			</div>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="onCancel">
				{{ t('openconnector', 'Cancel') }}
			</NcButton>
			<NcButton :disabled="saving || !!inputError" @click="onTest">
				<template #icon>
					<TestTubeIcon :size="20" />
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
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import ContentSaveOutlineIcon from 'vue-material-design-icons/ContentSaveOutline.vue'
import DatabaseArrowLeftOutlineIcon from 'vue-material-design-icons/DatabaseArrowLeftOutline.vue'
import DatabaseArrowRightOutlineIcon from 'vue-material-design-icons/DatabaseArrowRightOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import SwapHorizontalIcon from 'vue-material-design-icons/SwapHorizontal.vue'
import TestTubeIcon from 'vue-material-design-icons/TestTube.vue'
import { showSuccess } from '@nextcloud/dialogs'

import MappingRulesEditor from '../../views/wrappers/MappingRulesEditor.vue'
import MappingResultPanel from '../../components/mapping/MappingResultPanel.vue'
import { asObjectMap, asUnsetList } from '../../components/mapping/mappingShape.js'

/** A name has to carry at least one letter or digit — punctuation alone is not a name. */
const NAME_PATTERN = /[\p{L}\p{N}]/u

export default {
	name: 'MappingEditorModal',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcTextArea,
		NcTextField,
		MappingRulesEditor,
		MappingResultPanel,
		ArrowRightIcon,
		ContentSaveOutlineIcon,
		DatabaseArrowLeftOutlineIcon,
		DatabaseArrowRightOutlineIcon,
		PlusIcon,
		SwapHorizontalIcon,
		TestTubeIcon,
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
		 * leave the index stale until a reload — the built-in refresh only
		 * runs inside this handler — and would write to a different store
		 * than the one the list reads from.
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
			draft: this.emptyDraft(),
			/**
			 * Whether the name field has been left at least once. The
			 * "required" message waits for this so a freshly-opened Create
			 * dialog does not greet the user with a red empty field — which is
			 * exactly what the generic CnFormDialog did here.
			 */
			nameTouched: false,
			inputJson: '{}',
			inputError: '',
			saving: false,
			saveError: '',
		}
	},

	computed: {
		isCreate() {
			return !this.item
		},
		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		dialogTitle() {
			return this.isCreate
				? this.t('openconnector', 'Create mapping')
				: this.t('openconnector', 'Edit mapping')
		},
		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		inputPlaceholder() {
			return '{\n  "name": "hello"\n}'
		},
		/**
		 * Required marker on the label. NcTextField/NcInputField has no
		 * `required` prop and renders no marker of its own, so the ` *`
		 * suffix is appended here — the same convention CnFormDialog uses for
		 * schema-required fields, so both dialogs read alike.
		 *
		 * @return {string} Label text.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		nameLabel() {
			return this.t('openconnector', 'Name') + ' *'
		},
		/** @spec openspec/specs/mapping-editor-ui/spec.md */
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
		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		canSave() {
			return (
				!this.saving
				&& !!this.draft.name
				&& !this.nameError
				// No `confirm` means the host did not bind the slot scope, so
				// there is nothing to save through — better a disabled button
				// than a click that silently does nothing.
				&& typeof this.confirm === 'function'
			)
		},
		/**
		 * The draft merged over the persisted record, which is what the test
		 * endpoint evaluates. Sending the draft rather than `item` is the
		 * whole point of the live preview: it shows the rules on screen, not
		 * the ones last saved.
		 *
		 * @return {object} Mapping payload for `/api/mappings/test`.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		testableMapping() {
			return { ...(this.item || {}), ...this.draft }
		},
	},

	watch: {
		show: {
			immediate: true,
			/**
			 * Re-seed the draft every time the dialog opens, so a cancelled
			 * edit leaves nothing behind for the next one.
			 *
			 * @param {boolean} value Whether the dialog is now open.
			 *
			 * @spec openspec/specs/mapping-editor-ui/spec.md
			 */
			handler(value) {
				if (value) this.seedDraft()
			},
		},
	},

	methods: {
		/**
		 * A blank mapping draft. Pass-through defaults to on, matching the
		 * `mapping` schema's own default in the OpenRegister register.
		 *
		 * @return {object} Empty draft.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		emptyDraft() {
			return {
				name: '',
				description: '',
				mapping: {},
				cast: {},
				unset: [],
				passThrough: true,
			}
		},

		/**
		 * Copy the row being edited into the local draft, normalising the
		 * rule collections — an imported or legacy mapping can carry them as
		 * JSON strings rather than objects.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		seedDraft() {
			this.saveError = ''
			this.inputError = ''
			this.nameTouched = false
			this.$refs.result?.reset()
			if (!this.item) {
				this.draft = this.emptyDraft()
				return
			}
			this.draft = {
				name: this.item.name || '',
				description: this.item.description || '',
				mapping: asObjectMap(this.item.mapping),
				cast: asObjectMap(this.item.cast),
				unset: asUnsetList(this.item.unset),
				passThrough: this.item.passThrough !== false,
			}
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		onTest() {
			this.$refs.result?.run()
		},

		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		onCancel() {
			if (this.saving) return
			this.close?.()
		},

		/**
		 * Persist the draft through CnIndexPage's `confirm` binding rather
		 * than saving here directly.
		 *
		 * That is load-bearing, not stylistic: the index's list refresh runs
		 * inside its own save handler, so a dialog that persists on its own
		 * leaves the table stale until a reload (the `or-collection-*` live
		 * update only covers it where server push is actually delivered).
		 * Going through `confirm` also keeps the write in the same store the
		 * list reads from instead of a second cache of the same objects.
		 *
		 * The draft is merged over `item` so fields this dialog does not edit
		 * (slug, version, configurations, …) survive; no `id` means create.
		 *
		 * @return {Promise<void>} Resolves once the save has settled.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		async onSave() {
			if (!this.canSave) return
			this.saving = true
			this.saveError = ''
			try {
				await this.confirm({ ...(this.item || {}), ...this.draft })
				showSuccess(
					this.isCreate
						? this.t('openconnector', 'Mapping created')
						: this.t('openconnector', 'Mapping saved'),
				)
				this.close?.()
			} catch (err) {
				this.saveError =
					err?.message || this.t('openconnector', 'Failed to save mapping')
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
 * columns; the original mapping modal widened its container to 1200px the
 * same way. `class` on NcDialog lands on NcModal's `.modal-mask` root, which
 * carries this component's scope id even though NcModal teleports to <body>,
 * so `:deep()` from here reaches the container.
 *
 * Specificity matters: NcModal's own rule is
 * `.modal-wrapper--large > .modal-container[data-v-…]` at (0,2,0). Keeping
 * `.modal-wrapper` in the selector puts this at (0,4,0), so it wins outright
 * rather than tying and depending on stylesheet order.
 */
.cn-mapping-editor-modal :deep(.modal-wrapper > .modal-container) {
	width: 1200px;
	max-width: 90%;
}

.cn-mapping-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	width: 100%;
}

/*
 * Name and description belong to the mapping itself rather than to any one
 * stage, so they sit above the columns — but they are metadata, not the work,
 * so the block is capped well short of the modal width instead of stretching
 * a one-line description across 1200px.
 *
 * Stacked rather than side by side: the two controls are different heights (a
 * single-line input next to a textarea), and side by side that difference
 * always shows up as leftover space beside the shorter one, whichever way it
 * is aligned. Stacking removes the mismatch instead of arranging around it.
 */
.cn-mapping-editor__identity {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 480px;
}

/*
 * `auto` tracks between the columns hold the flow arrows. Rows stretch (the
 * grid default) so all three cards share one height — ragged card bottoms
 * were what made this read as three floating panels instead of one
 * workspace. The tallest column sets the height and the input textarea
 * absorbs the slack, so no column needs its own scrollbar.
 */
.cn-mapping-editor__columns {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto minmax(0, 1.3fr) auto minmax(0, 1fr);
	gap: 12px;
}

.cn-mapping-editor__arrow {
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
	.cn-mapping-editor__columns {
		grid-template-columns: minmax(0, 1fr);
	}

	.cn-mapping-editor__arrow {
		display: none;
	}
}

.cn-mapping-editor__column {
	display: flex;
	flex-direction: column;
	min-width: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background);
}

.cn-mapping-editor__column-header {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px) var(--border-radius-large, 8px) 0
		0;
	background: var(--color-background-hover);
}

.cn-mapping-editor__column-header h3 {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

/* Replaces the per-column explanatory paragraphs: same information, one word,
   no vertical space taken from the working area. */
.cn-mapping-editor__column-note {
	margin-inline-start: auto;
	font-size: 11px;
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

/* Column tints, carried over from the original modal — they are what made
   the input → transform → output direction readable at a glance. */
.cn-mapping-editor__column--input > .cn-mapping-editor__column-header {
	background: rgba(var(--color-primary-rgb), 0.1);
}

.cn-mapping-editor__column--transform > .cn-mapping-editor__column-header {
	background: rgba(var(--color-warning-rgb), 0.1);
}

.cn-mapping-editor__column--output > .cn-mapping-editor__column-header {
	background: rgba(var(--color-success-rgb), 0.1);
}

.cn-mapping-editor__column-body {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	min-width: 0;
	/* Fill the shared row height so the input textarea and the result pane can
	   grow into it rather than leaving dead space under short content. */
	flex: 1;
}

.cn-mapping-editor__label {
	font-weight: 500;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.cn-mapping-editor__error {
	margin: 0;
	font-size: 12px;
	color: var(--color-error);
}

.cn-mapping-editor__textarea {
	width: 100%;
	/* Grows into whatever height the three-column row settles at. */
	flex: 1;
	min-height: 200px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
	resize: vertical;
	padding: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}
</style>

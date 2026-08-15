<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  MappingRulesEditor — tabbed table editor for the three rule collections
  on a Mapping object. Used by `MappingDetailPage` and `MappingEditorModal`
  (the latter opts into a fourth Options tab via `showOptionsTab`).

  The strip is `CnTabs`/`CnTab` from the library. The pre-manifest modal
  used `bootstrap-vue`'s `<BTabs justified>`, which went away with the Vue 3
  migration (bootstrap-vue is Vue 2 only), and this component carried a
  hand-rolled `<button role="tab">` strip in the interim. CnTabs exists to
  close exactly that gap and implements the parts the hand-rolled version
  did not: `role="tabpanel"`, `aria-controls`/`aria-labelledby` wiring, a
  roving `tabindex`, and Left/Right/Home/End navigation.

  Note CnTab keeps inactive panels in the DOM (`hidden` + `display: none`)
  rather than destroying them, so all three tables — and their VueDraggable
  instances — stay mounted across tab switches. That is fine here: nothing
  in a panel fetches on mount.

  Each tab presents its own table of rows with Add / Edit / Delete
  controls. Editing happens through `EditMappingRuleDialog`, which renders
  the right inputs for the active rule kind:

    - mapping : target property + twig template (default value optional)
    - cast    : property + cast type (string|integer|float|boolean|array|jsonToArray|htmlDecode)
    - unset   : property name (single field)

  The component is presentational — every mutation re-emits the whole
  updated collection so the parent can persist it through `useObjectStore`
  in one call. We never mutate the props directly.

  Rule order matters for cascading transformations. Each row carries a
  drag handle (MDI drag-vertical) on the left and is `tabindex="0"` so
  ArrowUp/ArrowDown can move it within its tab. Reordering rebuilds the
  collection in the new order and emits it like any other mutation.

  Unset rules are only meaningful when `passThrough` is enabled on the
  parent mapping (otherwise there's nothing to remove). The Add button on
  the Unset tab is disabled in that case, mirroring the legacy behaviour.
-->
<template>
	<div class="cn-rules-editor">
		<CnTabs :aria-label="t('openconnector', 'Transformation rules')">
			<!-- Mapping rules tab -->
			<CnTab>
				<template #title>
					<span class="cn-rules-editor__tab-label">
						{{ t('openconnector', 'Mapping') }}
						<span class="cn-rules-editor__tab-count">{{
							mappingRowList.length
						}}</span>
					</span>
				</template>
				<div class="cn-rules-editor__panel">
					<p class="cn-rules-editor__help">
						{{
							t(
								'openconnector',
								'Each mapping rule maps a target property to a Twig template that produces its value from the input object. Drag the handle to change rule order — order matters for cascading transformations.',
							)
						}}
					</p>
					<table
						v-if="mappingRowList.length"
						class="cn-rules-editor__table">
						<thead>
							<tr>
								<th
									class="cn-rules-editor__col-handle"
									aria-hidden="true" />
								<th scope="col">
									{{ t('openconnector', 'Target property') }}
								</th>
								<th scope="col">
									{{ t('openconnector', 'Template') }}
								</th>
								<th scope="col" class="cn-rules-editor__col-actions">
									{{ t('openconnector', 'Actions') }}
								</th>
							</tr>
						</thead>
						<VueDraggable
							v-model="mappingDraft"
							tag="tbody"
							handle=".cn-rules-editor__drag-handle"
							:disabled="saving"
							:animation="150"
							ghost-class="cn-rules-editor__row--ghost"
							drag-class="cn-rules-editor__row--dragging"
							@end="onMappingReorder">
							<tr
								v-for="(row, index) in mappingDraft"
								:key="row.key"
								tabindex="0"
								class="cn-rules-editor__row"
								@keydown.up.prevent="moveRow('mapping', index, -1)"
								@keydown.down.prevent="moveRow('mapping', index, 1)">
								<td class="cn-rules-editor__col-handle">
									<button
										type="button"
										class="cn-rules-editor__drag-handle"
										:aria-label="
											t('openconnector', 'Drag to reorder')
										"
										:disabled="saving"
										tabindex="-1">
										<DragVerticalIcon :size="18" />
									</button>
								</td>
								<td class="cn-rules-editor__cell-key">
									{{ row.key }}
								</td>
								<td class="cn-rules-editor__cell-value">
									<code>{{ formatTemplate(row.value) }}</code>
								</td>
								<td class="cn-rules-editor__col-actions">
									<NcButton
										type="tertiary"
										:aria-label="t('openconnector', 'Edit rule')"
										:disabled="saving"
										@click="openEdit('mapping', row.key)">
										<template #icon>
											<PencilIcon :size="18" />
										</template>
									</NcButton>
									<NcButton
										type="tertiary"
										:aria-label="
											t('openconnector', 'Delete rule')
										"
										:disabled="saving"
										@click="deleteRule('mapping', row.key)">
										<template #icon>
											<DeleteIcon :size="18" />
										</template>
									</NcButton>
								</td>
							</tr>
						</VueDraggable>
					</table>
					<p v-else class="cn-rules-editor__empty">
						{{
							t(
								'openconnector',
								'No mapping rules yet. Add one to start shaping the output.',
							)
						}}
					</p>
					<div class="cn-rules-editor__footer">
						<NcButton
							type="primary"
							:disabled="saving"
							@click="openCreate('mapping')">
							<template #icon>
								<PlusIcon :size="20" />
							</template>
							{{ t('openconnector', 'Add mapping rule') }}
						</NcButton>
					</div>
				</div>
			</CnTab>

			<!-- Cast rules tab -->
			<CnTab>
				<template #title>
					<span class="cn-rules-editor__tab-label">
						{{ t('openconnector', 'Cast') }}
						<span class="cn-rules-editor__tab-count">{{
							castRowList.length
						}}</span>
					</span>
				</template>
				<div class="cn-rules-editor__panel">
					<p class="cn-rules-editor__help">
						{{
							t(
								'openconnector',
								'Cast rules coerce the value of a property to a specific JSON type after the mapping rules have run.',
							)
						}}
					</p>
					<table v-if="castRowList.length" class="cn-rules-editor__table">
						<thead>
							<tr>
								<th
									class="cn-rules-editor__col-handle"
									aria-hidden="true" />
								<th scope="col">
									{{ t('openconnector', 'Property') }}
								</th>
								<th scope="col">
									{{ t('openconnector', 'Cast type') }}
								</th>
								<th scope="col" class="cn-rules-editor__col-actions">
									{{ t('openconnector', 'Actions') }}
								</th>
							</tr>
						</thead>
						<VueDraggable
							v-model="castDraft"
							tag="tbody"
							handle=".cn-rules-editor__drag-handle"
							:disabled="saving"
							:animation="150"
							ghost-class="cn-rules-editor__row--ghost"
							drag-class="cn-rules-editor__row--dragging"
							@end="onCastReorder">
							<tr
								v-for="(row, index) in castDraft"
								:key="row.key"
								tabindex="0"
								class="cn-rules-editor__row"
								@keydown.up.prevent="moveRow('cast', index, -1)"
								@keydown.down.prevent="moveRow('cast', index, 1)">
								<td class="cn-rules-editor__col-handle">
									<button
										type="button"
										class="cn-rules-editor__drag-handle"
										:aria-label="
											t('openconnector', 'Drag to reorder')
										"
										:disabled="saving"
										tabindex="-1">
										<DragVerticalIcon :size="18" />
									</button>
								</td>
								<td class="cn-rules-editor__cell-key">
									{{ row.key }}
								</td>
								<td class="cn-rules-editor__cell-value">
									{{ row.value }}
								</td>
								<td class="cn-rules-editor__col-actions">
									<NcButton
										type="tertiary"
										:aria-label="t('openconnector', 'Edit rule')"
										:disabled="saving"
										@click="openEdit('cast', row.key)">
										<template #icon>
											<PencilIcon :size="18" />
										</template>
									</NcButton>
									<NcButton
										type="tertiary"
										:aria-label="
											t('openconnector', 'Delete rule')
										"
										:disabled="saving"
										@click="deleteRule('cast', row.key)">
										<template #icon>
											<DeleteIcon :size="18" />
										</template>
									</NcButton>
								</td>
							</tr>
						</VueDraggable>
					</table>
					<p v-else class="cn-rules-editor__empty">
						{{ t('openconnector', 'No cast rules yet.') }}
					</p>
					<div class="cn-rules-editor__footer">
						<NcButton
							type="primary"
							:disabled="saving"
							@click="openCreate('cast')">
							<template #icon>
								<PlusIcon :size="20" />
							</template>
							{{ t('openconnector', 'Add cast rule') }}
						</NcButton>
					</div>
				</div>
			</CnTab>

			<!-- Unset rules tab -->
			<CnTab>
				<template #title>
					<span class="cn-rules-editor__tab-label">
						{{ t('openconnector', 'Unset') }}
						<span class="cn-rules-editor__tab-count">{{
							unsetRules.length
						}}</span>
					</span>
				</template>
				<div class="cn-rules-editor__panel">
					<p class="cn-rules-editor__help">
						{{
							t(
								'openconnector',
								'Unset rules remove a property from the output object. They only apply when pass-through is enabled.',
							)
						}}
					</p>
					<table v-if="unsetDraft.length" class="cn-rules-editor__table">
						<thead>
							<tr>
								<th
									class="cn-rules-editor__col-handle"
									aria-hidden="true" />
								<th scope="col">
									{{ t('openconnector', 'Property') }}
								</th>
								<th scope="col" class="cn-rules-editor__col-actions">
									{{ t('openconnector', 'Actions') }}
								</th>
							</tr>
						</thead>
						<VueDraggable
							v-model="unsetDraft"
							tag="tbody"
							handle=".cn-rules-editor__drag-handle"
							:disabled="saving"
							:animation="150"
							ghost-class="cn-rules-editor__row--ghost"
							drag-class="cn-rules-editor__row--dragging"
							@end="onUnsetReorder">
							<tr
								v-for="(property, index) in unsetDraft"
								:key="property + '-' + index"
								tabindex="0"
								class="cn-rules-editor__row"
								@keydown.up.prevent="moveRow('unset', index, -1)"
								@keydown.down.prevent="moveRow('unset', index, 1)">
								<td class="cn-rules-editor__col-handle">
									<button
										type="button"
										class="cn-rules-editor__drag-handle"
										:aria-label="
											t('openconnector', 'Drag to reorder')
										"
										:disabled="saving"
										tabindex="-1">
										<DragVerticalIcon :size="18" />
									</button>
								</td>
								<td class="cn-rules-editor__cell-key">
									{{ property }}
								</td>
								<td class="cn-rules-editor__col-actions">
									<NcButton
										type="tertiary"
										:aria-label="t('openconnector', 'Edit rule')"
										:disabled="saving"
										@click="openEditUnset(property)">
										<template #icon>
											<PencilIcon :size="18" />
										</template>
									</NcButton>
									<NcButton
										type="tertiary"
										:aria-label="
											t('openconnector', 'Delete rule')
										"
										:disabled="saving"
										@click="deleteUnset(property)">
										<template #icon>
											<DeleteIcon :size="18" />
										</template>
									</NcButton>
								</td>
							</tr>
						</VueDraggable>
					</table>
					<p v-else class="cn-rules-editor__empty">
						{{ t('openconnector', 'No unset rules yet.') }}
					</p>
					<div class="cn-rules-editor__footer">
						<NcButton
							type="primary"
							:disabled="saving || !passThrough"
							@click="openCreate('unset')">
							<template #icon>
								<PlusIcon :size="20" />
							</template>
							{{ t('openconnector', 'Add unset rule') }}
						</NcButton>
						<p v-if="!passThrough" class="cn-rules-editor__warning">
							{{ passThroughHint }}
						</p>
					</div>
				</div>
			</CnTab>

			<!-- Options tab (opt-in via `showOptionsTab`) -->
			<CnTab v-if="showOptionsTab" :title="t('openconnector', 'Options')">
				<div class="cn-rules-editor__panel">
					<p class="cn-rules-editor__help">
						{{
							t(
								'openconnector',
								'Options change how the mapping treats properties it has no rule for.',
							)
						}}
					</p>
					<NcCheckboxRadioSwitch
						:model-value="passThrough"
						:disabled="saving"
						type="switch"
						@update:model-value="$emit('update-pass-through', $event)">
						{{ t('openconnector', 'Pass through') }}
					</NcCheckboxRadioSwitch>
					<p class="cn-rules-editor__help">
						{{
							t(
								'openconnector',
								'When enabled, properties from the input object are copied into the output unless an unset rule removes them. When disabled, the output contains only what the mapping rules produce.',
							)
						}}
					</p>
				</div>
			</CnTab>
		</CnTabs>

		<!-- Edit/Create dialog -->
		<EditMappingRuleDialog
			v-if="editing"
			:kind="editing.kind"
			:property="editing.property"
			:value="editing.value"
			:existing-keys="existingKeysFor(editing.kind, editing.property)"
			@cancel="editing = null"
			@submit="onSubmitDialog" />
	</div>
</template>

<script>
import { CnTab, CnTabs } from '@conduction/nextcloud-vue'
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import DragVerticalIcon from 'vue-material-design-icons/DragVertical.vue'
import { VueDraggable } from 'vue-draggable-plus'

import EditMappingRuleDialog from '../../dialogs/EditMappingRuleDialog.vue'

/**
 * Convert a keyed-rules object into an ordered array of `{ key, value }`
 * row records the drag-and-drop component can mutate in place.
 *
 * @param {object} obj Source keyed object.
 * @return {Array<{key: string, value: any}>} Ordered row list.
 */
function objectToRowList(obj) {
	if (!obj || typeof obj !== 'object') return []
	return Object.keys(obj).map((key) => ({ key, value: obj[key] }))
}

/**
 * Rebuild a keyed-rules object from an ordered row list. JS object key
 * iteration is insertion-ordered for string keys, so the returned object
 * serialises in the row list's order.
 *
 * @param {Array<{key: string, value: any}>} rows Ordered row list.
 * @return {object} Rebuilt keyed-rules object.
 */
function rowListToObject(rows) {
	const out = {}
	for (const row of rows) {
		if (row && typeof row.key === 'string') {
			out[row.key] = row.value
		}
	}
	return out
}

export default {
	name: 'MappingRulesEditor',

	components: {
		CnTab,
		CnTabs,
		NcButton,
		NcCheckboxRadioSwitch,
		PlusIcon,
		PencilIcon,
		DeleteIcon,
		DragVerticalIcon,
		VueDraggable,
		EditMappingRuleDialog,
	},

	props: {
		/** Mapping rules object: `{ targetProperty: twigTemplate }`. */
		mappingRules: {
			type: Object,
			default: () => ({}),
		},
		/** Cast rules object: `{ property: castType }`. */
		castRules: {
			type: Object,
			default: () => ({}),
		},
		/** Unset rules list: `[propertyName, …]`. */
		unsetRules: {
			type: Array,
			default: () => [],
		},
		/** Whether pass-through is enabled on the parent mapping. */
		passThrough: {
			type: Boolean,
			default: false,
		},
		/** Whether a persist is in flight. Disables the controls. */
		saving: {
			type: Boolean,
			default: false,
		},
		/**
		 * Show a fourth "Options" tab carrying the pass-through switch,
		 * restoring the tab the pre-manifest mapping modal had. Off by
		 * default so hosts that already surface pass-through elsewhere
		 * (MappingDetailPage's General card) do not end up with two
		 * switches for one field.
		 */
		showOptionsTab: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update-mapping', 'update-cast', 'update-unset', 'update-pass-through'],

	data() {
		return {
			/**
			 * Local draft copies of the three rule collections. Drag-and-drop
			 * mutates these arrays in place; we then commit a reconstructed
			 * collection back to the parent via the existing update events.
			 *
			 * Kept in sync with the props via the watcher below — parent
			 * persists are the source of truth.
			 */
			mappingDraft: objectToRowList(this.mappingRules),
			castDraft: objectToRowList(this.castRules),
			unsetDraft: [...this.unsetRules],
			/**
			 * @type {null|{
			 *   kind: 'mapping'|'cast'|'unset',
			 *   property: string|null,
			 *   value: any,
			 * }}
			 */
			editing: null,
		}
	},

	computed: {
		/**
		 * Where to point the user when unset rules are unavailable — the
		 * Options tab when this editor owns the switch, otherwise the host's
		 * own control above the editor.
		 *
		 * @return {string} Hint text for the disabled Add-unset button.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		passThroughHint() {
			return this.showOptionsTab
				? this.t(
						'openconnector',
						'Enable pass-through in the Options tab to add unset rules.',
					)
				: this.t(
						'openconnector',
						'Enable pass-through above to add unset rules.',
					)
		},
		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		mappingRowList() {
			return Object.keys(this.mappingRules).map((key) => ({
				key,
				value: this.mappingRules[key],
			}))
		},
		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		castRowList() {
			return Object.keys(this.castRules).map((key) => ({
				key,
				value: this.castRules[key],
			}))
		},
	},

	watch: {
		mappingRules: {
			/**
			 * Re-seed the local mapping draft when the parent persists, so the
			 * drag-and-drop row list reflects the newly saved rules.
			 *
			 * @param {object} next Mapping rules as persisted by the parent, `{ targetProperty: twigTemplate }`.
			 *
			 * @spec openspec/specs/mapping-editor-ui/spec.md
			 */
			handler(next) {
				this.mappingDraft = objectToRowList(next)
			},
			deep: true,
		},
		castRules: {
			/**
			 * Re-seed the local cast draft when the parent persists.
			 *
			 * @param {object} next Cast rules as persisted by the parent, `{ property: castType }`.
			 *
			 * @spec openspec/specs/mapping-editor-ui/spec.md
			 */
			handler(next) {
				this.castDraft = objectToRowList(next)
			},
			deep: true,
		},
		unsetRules: {
			/**
			 * Re-seed the local unset draft when the parent persists. Copied
			 * rather than aliased so drag-and-drop cannot mutate the prop.
			 *
			 * @param {Array<string>} next Property names to unset, as persisted by the parent.
			 *
			 * @spec openspec/specs/mapping-editor-ui/spec.md
			 */
			handler(next) {
				this.unsetDraft = [...next]
			},
			deep: true,
		},
	},

	methods: {
		/**
		 * Render a mapping-rule value as a single line of table text.
		 *
		 * @param {string|object|null} value Stored rule value — usually a Twig template string, but imported mappings may hold a nested object.
		 * @return {string} Display text: the string as-is, JSON for objects, empty for `null`.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		formatTemplate(value) {
			if (value == null) return ''
			if (typeof value === 'string') return value
			try {
				return JSON.stringify(value)
			} catch (_e) {
				return String(value)
			}
		},
		/**
		 * Collect the property names already taken in one rule collection, so
		 * the edit dialog can reject a duplicate key.
		 *
		 * @param {'mapping'|'cast'|'unset'} kind Rule collection to read the keys from.
		 * @param {string|null} currentProperty Property currently being edited; excluded from the result so keeping its own name is not reported as a collision. `null` when creating.
		 * @return {Array<string>} Property names that are unavailable.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		existingKeysFor(kind, currentProperty) {
			let keys = []
			if (kind === 'mapping') keys = Object.keys(this.mappingRules)
			else if (kind === 'cast') keys = Object.keys(this.castRules)
			else if (kind === 'unset') keys = [...this.unsetRules]
			if (currentProperty != null) {
				return keys.filter((k) => k !== currentProperty)
			}
			return keys
		},

		/**
		 * Open the rule dialog in create mode.
		 *
		 * @param {'mapping'|'cast'|'unset'} kind Collection the new rule belongs to; also picks the seed value (`'string'` for a cast, empty otherwise).
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		openCreate(kind) {
			this.editing = {
				kind,
				property: null,
				value: kind === 'cast' ? 'string' : '',
			}
		},
		/**
		 * Open the rule dialog on an existing mapping or cast rule, seeded
		 * with that rule's current value.
		 *
		 * @param {'mapping'|'cast'} kind Which collection holds the rule; unset rules use `openEditUnset`.
		 * @param {string} property Key of the rule to edit.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		openEdit(kind, property) {
			const source = kind === 'mapping' ? this.mappingRules : this.castRules
			this.editing = {
				kind,
				property,
				value: source[property] ?? (kind === 'cast' ? 'string' : ''),
			}
		},
		/**
		 * Open the rule dialog on an unset entry. An unset rule has no
		 * separate value, so the property name is used as both.
		 *
		 * @param {string} property Property name currently being unset.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		openEditUnset(property) {
			this.editing = {
				kind: 'unset',
				property,
				value: property,
			}
		},

		/**
		 * Route a dialog submit to the commit handler for its collection and
		 * close the dialog. The key being replaced comes from `editing`, so a
		 * renamed property is handled as a rename rather than an insert.
		 *
		 * @param {{kind: 'mapping'|'cast'|'unset', property: string, value: (string|object)}} payload Dialog result: target collection, the (possibly renamed) property key, and its new value.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		onSubmitDialog(payload) {
			const { kind, property, value } = payload
			if (kind === 'mapping') {
				this.commitMapping(this.editing.property, property, value)
			} else if (kind === 'cast') {
				this.commitCast(this.editing.property, property, value)
			} else if (kind === 'unset') {
				this.commitUnset(this.editing.property, property)
			}
			this.editing = null
		},

		/**
		 * Emit the full mapping-rules object with one rule created, updated or
		 * renamed. The parent owns persistence.
		 *
		 * @param {string|null} oldKey Key the rule was stored under before the edit; dropped when it differs from `newKey` (a rename). `null` when creating.
		 * @param {string} newKey Target property the rule is stored under.
		 * @param {string} newValue Twig template evaluated to produce the target property.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		commitMapping(oldKey, newKey, newValue) {
			const next = { ...this.mappingRules }
			if (oldKey && oldKey !== newKey) {
				delete next[oldKey]
			}
			next[newKey] = newValue
			this.$emit('update-mapping', next)
		},
		/**
		 * Emit the full cast-rules object with one rule created, updated or
		 * renamed. The parent owns persistence.
		 *
		 * @param {string|null} oldKey Property the cast was registered on before the edit; dropped when it differs from `newKey` (a rename). `null` when creating.
		 * @param {string} newKey Property the cast applies to.
		 * @param {string} newValue Cast type to coerce the property to (for example `string`, `int`, `bool`).
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		commitCast(oldKey, newKey, newValue) {
			const next = { ...this.castRules }
			if (oldKey && oldKey !== newKey) {
				delete next[oldKey]
			}
			next[newKey] = newValue
			this.$emit('update-cast', next)
		},
		/**
		 * Emit the full unset list with one entry added or renamed,
		 * deduplicated while preserving order.
		 *
		 * @param {string|null} oldProperty Entry being renamed; replaced in place so it keeps its position. `null` when adding, which appends.
		 * @param {string} newProperty Property name to unset.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		commitUnset(oldProperty, newProperty) {
			const next = [...this.unsetRules]
			if (oldProperty) {
				const idx = next.indexOf(oldProperty)
				if (idx >= 0) {
					next[idx] = newProperty
				} else {
					next.push(newProperty)
				}
			} else {
				next.push(newProperty)
			}
			// Deduplicate while preserving order
			const seen = new Set()
			const deduped = []
			for (const entry of next) {
				if (!seen.has(entry)) {
					seen.add(entry)
					deduped.push(entry)
				}
			}
			this.$emit('update-unset', deduped)
		},

		/**
		 * Remove one mapping or cast rule and emit the remaining collection.
		 *
		 * @param {'mapping'|'cast'} kind Collection to delete from; unset entries use `deleteUnset`.
		 * @param {string} key Property key of the rule to remove.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		deleteRule(kind, key) {
			if (kind === 'mapping') {
				const next = { ...this.mappingRules }
				delete next[key]
				this.$emit('update-mapping', next)
			} else if (kind === 'cast') {
				const next = { ...this.castRules }
				delete next[key]
				this.$emit('update-cast', next)
			}
		},
		/**
		 * Drop one entry from the unset list and emit the remainder.
		 *
		 * @param {string} property Property name to stop unsetting.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		deleteUnset(property) {
			const next = this.unsetRules.filter((entry) => entry !== property)
			this.$emit('update-unset', next)
		},

		/**
		 * Drag-end handler: emit a rebuilt object in the new order.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		onMappingReorder() {
			this.$emit('update-mapping', rowListToObject(this.mappingDraft))
		},
		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		onCastReorder() {
			this.$emit('update-cast', rowListToObject(this.castDraft))
		},
		/** @spec openspec/specs/mapping-editor-ui/spec.md */
		onUnsetReorder() {
			this.$emit('update-unset', [...this.unsetDraft])
		},

		/**
		 * Keyboard reorder. Swaps the focused row with its neighbour in the
		 * given direction and commits via the same reorder path the drag
		 * handler uses.
		 *
		 * @param {'mapping'|'cast'|'unset'} kind Which collection.
		 * @param {number} index Current row index.
		 * @param {number} direction -1 to move up, 1 to move down.
		 *
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		moveRow(kind, index, direction) {
			let list
			if (kind === 'mapping') list = this.mappingDraft
			else if (kind === 'cast') list = this.castDraft
			else list = this.unsetDraft
			const target = index + direction
			if (target < 0 || target >= list.length) return
			const next = [...list]
			const [moved] = next.splice(index, 1)
			next.splice(target, 0, moved)
			if (kind === 'mapping') {
				this.mappingDraft = next
				this.onMappingReorder()
			} else if (kind === 'cast') {
				this.castDraft = next
				this.onCastReorder()
			} else {
				this.unsetDraft = next
				this.onUnsetReorder()
			}
			// Restore focus on the new row position so successive arrow
			// presses keep moving the same logical row.
			this.$nextTick(() => {
				const rows = this.$el.querySelectorAll(
					'section.cn-rules-editor__panel .cn-rules-editor__row',
				)
				const row = rows[target]
				if (row && typeof row.focus === 'function') row.focus()
			})
		},
	},
}
</script>

<style scoped>
.cn-rules-editor {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

/* Rendered into CnTabs' nav button via the `#title` slot. Slot content is
   compiled in this component's scope, so these scoped rules still match. */
.cn-rules-editor__tab-label {
	display: inline-flex;
	gap: 6px;
	align-items: center;
}

.cn-rules-editor__tab-count {
	font-size: 12px;
	font-weight: 400;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
	padding: 0 6px;
	border-radius: 10px;
}

.cn-rules-editor__panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.cn-rules-editor__help {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.cn-rules-editor__table {
	width: 100%;
	border-collapse: collapse;
}

.cn-rules-editor__table th,
.cn-rules-editor__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: top;
}

.cn-rules-editor__table th {
	font-weight: 600;
	background: var(--color-background-hover);
}

.cn-rules-editor__row {
	transition:
		background-color 0.1s ease,
		box-shadow 0.1s ease;
	outline: none;
}

.cn-rules-editor__row:hover {
	background: var(--color-background-hover);
}

.cn-rules-editor__row:focus {
	background: var(--color-background-hover);
	box-shadow: inset 2px 0 0 var(--color-primary-element, var(--color-primary));
}

/* The row's hover/focus tint animates. Readers who have asked their OS for
   less motion get the same states instantly instead (WCAG 2.2 SC 2.3.3). */
@media (prefers-reduced-motion: reduce) {
	.cn-rules-editor__row {
		transition: none;
	}
}

.cn-rules-editor__row--ghost {
	opacity: 0.4;
	background: var(--color-primary-element-light, var(--color-background-dark));
}

.cn-rules-editor__row--dragging {
	background: var(--color-main-background);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.cn-rules-editor__col-handle {
	width: 32px;
	padding-right: 0;
	padding-left: 8px;
	vertical-align: middle;
}

.cn-rules-editor__drag-handle {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background: transparent;
	border: none;
	padding: 4px;
	cursor: grab;
	color: var(--color-text-maxcontrast);
	border-radius: var(--border-radius);
}

.cn-rules-editor__drag-handle:hover {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.cn-rules-editor__drag-handle:active {
	cursor: grabbing;
}

.cn-rules-editor__drag-handle:disabled {
	cursor: not-allowed;
	opacity: 0.4;
}

.cn-rules-editor__cell-key {
	font-weight: 500;
	word-break: break-word;
}

.cn-rules-editor__cell-value code {
	display: inline-block;
	word-break: break-all;
	white-space: pre-wrap;
	font-family: var(--font-face-monospace, monospace);
	font-size: 12px;
}

.cn-rules-editor__col-actions {
	width: 96px;
	text-align: right;
	white-space: nowrap;
}

.cn-rules-editor__col-actions .button-vue,
.cn-rules-editor__col-actions button {
	margin-left: 4px;
}

.cn-rules-editor__empty {
	margin: 0;
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
	font-style: italic;
}

.cn-rules-editor__footer {
	display: flex;
	flex-direction: column;
	gap: 4px;
	align-items: flex-start;
}

.cn-rules-editor__warning {
	margin: 0;
	font-size: 12px;
	color: var(--color-warning);
}
</style>

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  MappingRulesEditor — 3-tab table editor for the three rule collections
  on a Mapping object. Used by `MappingDetailPage`.

  Each tab presents its own table of rows with Add / Edit / Delete
  controls. Editing happens through `EditMappingRuleDialog`, which renders
  the right inputs for the active rule kind:

    - mapping : target property + twig template (default value optional)
    - cast    : property + cast type (string|integer|float|boolean|array|jsonToArray|htmlDecode)
    - unset   : property name (single field)

  The component is presentational — every mutation re-emits the whole
  updated collection so the parent can persist it through `useObjectStore`
  in one call. We never mutate the props directly.

  Unset rules are only meaningful when `passThrough` is enabled on the
  parent mapping (otherwise there's nothing to remove). The Add button on
  the Unset tab is disabled in that case, mirroring the legacy behaviour.
-->
<template>
	<div class="cn-rules-editor">
		<div class="cn-rules-editor__tabs" role="tablist">
			<button v-for="tab in tabs"
				:key="tab.id"
				type="button"
				role="tab"
				:aria-selected="activeTab === tab.id"
				:class="['cn-rules-editor__tab', { 'cn-rules-editor__tab--active': activeTab === tab.id }]"
				@click="activeTab = tab.id">
				{{ tab.label }}
				<span class="cn-rules-editor__tab-count">{{ tab.count }}</span>
			</button>
		</div>

		<!-- Mapping rules tab -->
		<section v-if="activeTab === 'mapping'" class="cn-rules-editor__panel">
			<p class="cn-rules-editor__help">
				{{ t('openconnector', 'Each mapping rule maps a target property to a Twig template that produces its value from the input object.') }}
			</p>
			<table v-if="mappingRowList.length" class="cn-rules-editor__table">
				<thead>
					<tr>
						<th>{{ t('openconnector', 'Target property') }}</th>
						<th>{{ t('openconnector', 'Template') }}</th>
						<th class="cn-rules-editor__col-actions">
							{{ t('openconnector', 'Actions') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in mappingRowList" :key="row.key">
						<td class="cn-rules-editor__cell-key">
							{{ row.key }}
						</td>
						<td class="cn-rules-editor__cell-value">
							<code>{{ formatTemplate(row.value) }}</code>
						</td>
						<td class="cn-rules-editor__col-actions">
							<NcButton type="tertiary"
								:aria-label="t('openconnector', 'Edit rule')"
								:disabled="saving"
								@click="openEdit('mapping', row.key)">
								<template #icon>
									<PencilIcon :size="18" />
								</template>
							</NcButton>
							<NcButton type="tertiary"
								:aria-label="t('openconnector', 'Delete rule')"
								:disabled="saving"
								@click="deleteRule('mapping', row.key)">
								<template #icon>
									<DeleteIcon :size="18" />
								</template>
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="cn-rules-editor__empty">
				{{ t('openconnector', 'No mapping rules yet. Add one to start shaping the output.') }}
			</p>
			<div class="cn-rules-editor__footer">
				<NcButton type="primary" :disabled="saving" @click="openCreate('mapping')">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					{{ t('openconnector', 'Add mapping rule') }}
				</NcButton>
			</div>
		</section>

		<!-- Cast rules tab -->
		<section v-else-if="activeTab === 'cast'" class="cn-rules-editor__panel">
			<p class="cn-rules-editor__help">
				{{ t('openconnector', 'Cast rules coerce the value of a property to a specific JSON type after the mapping rules have run.') }}
			</p>
			<table v-if="castRowList.length" class="cn-rules-editor__table">
				<thead>
					<tr>
						<th>{{ t('openconnector', 'Property') }}</th>
						<th>{{ t('openconnector', 'Cast type') }}</th>
						<th class="cn-rules-editor__col-actions">
							{{ t('openconnector', 'Actions') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in castRowList" :key="row.key">
						<td class="cn-rules-editor__cell-key">
							{{ row.key }}
						</td>
						<td class="cn-rules-editor__cell-value">
							{{ row.value }}
						</td>
						<td class="cn-rules-editor__col-actions">
							<NcButton type="tertiary"
								:aria-label="t('openconnector', 'Edit rule')"
								:disabled="saving"
								@click="openEdit('cast', row.key)">
								<template #icon>
									<PencilIcon :size="18" />
								</template>
							</NcButton>
							<NcButton type="tertiary"
								:aria-label="t('openconnector', 'Delete rule')"
								:disabled="saving"
								@click="deleteRule('cast', row.key)">
								<template #icon>
									<DeleteIcon :size="18" />
								</template>
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="cn-rules-editor__empty">
				{{ t('openconnector', 'No cast rules yet.') }}
			</p>
			<div class="cn-rules-editor__footer">
				<NcButton type="primary" :disabled="saving" @click="openCreate('cast')">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					{{ t('openconnector', 'Add cast rule') }}
				</NcButton>
			</div>
		</section>

		<!-- Unset rules tab -->
		<section v-else-if="activeTab === 'unset'" class="cn-rules-editor__panel">
			<p class="cn-rules-editor__help">
				{{ t('openconnector', 'Unset rules remove a property from the output object. They only apply when pass-through is enabled.') }}
			</p>
			<table v-if="unsetRules.length" class="cn-rules-editor__table">
				<thead>
					<tr>
						<th>{{ t('openconnector', 'Property') }}</th>
						<th class="cn-rules-editor__col-actions">
							{{ t('openconnector', 'Actions') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(property, index) in unsetRules" :key="property + '-' + index">
						<td class="cn-rules-editor__cell-key">
							{{ property }}
						</td>
						<td class="cn-rules-editor__col-actions">
							<NcButton type="tertiary"
								:aria-label="t('openconnector', 'Edit rule')"
								:disabled="saving"
								@click="openEditUnset(property)">
								<template #icon>
									<PencilIcon :size="18" />
								</template>
							</NcButton>
							<NcButton type="tertiary"
								:aria-label="t('openconnector', 'Delete rule')"
								:disabled="saving"
								@click="deleteUnset(property)">
								<template #icon>
									<DeleteIcon :size="18" />
								</template>
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="cn-rules-editor__empty">
				{{ t('openconnector', 'No unset rules yet.') }}
			</p>
			<div class="cn-rules-editor__footer">
				<NcButton type="primary"
					:disabled="saving || !passThrough"
					@click="openCreate('unset')">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					{{ t('openconnector', 'Add unset rule') }}
				</NcButton>
				<p v-if="!passThrough" class="cn-rules-editor__warning">
					{{ t('openconnector', 'Enable pass-through above to add unset rules.') }}
				</p>
			</div>
		</section>

		<!-- Edit/Create dialog -->
		<EditMappingRuleDialog v-if="editing"
			:kind="editing.kind"
			:property="editing.property"
			:value="editing.value"
			:existing-keys="existingKeysFor(editing.kind, editing.property)"
			@cancel="editing = null"
			@submit="onSubmitDialog" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'

import EditMappingRuleDialog from './EditMappingRuleDialog.vue'

export default {
	name: 'MappingRulesEditor',

	components: {
		NcButton,
		PlusIcon,
		PencilIcon,
		DeleteIcon,
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
	},

	data() {
		return {
			activeTab: 'mapping',
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
		tabs() {
			return [
				{
					id: 'mapping',
					label: this.t('openconnector', 'Mapping rules'),
					count: this.mappingRowList.length,
				},
				{
					id: 'cast',
					label: this.t('openconnector', 'Cast rules'),
					count: this.castRowList.length,
				},
				{
					id: 'unset',
					label: this.t('openconnector', 'Unset rules'),
					count: this.unsetRules.length,
				},
			]
		},
		mappingRowList() {
			return Object.keys(this.mappingRules).map((key) => ({
				key,
				value: this.mappingRules[key],
			}))
		},
		castRowList() {
			return Object.keys(this.castRules).map((key) => ({
				key,
				value: this.castRules[key],
			}))
		},
	},

	methods: {
		formatTemplate(value) {
			if (value == null) return ''
			if (typeof value === 'string') return value
			try {
				return JSON.stringify(value)
			} catch (_e) {
				return String(value)
			}
		},
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

		openCreate(kind) {
			this.editing = {
				kind,
				property: null,
				value: kind === 'cast' ? 'string' : '',
			}
		},
		openEdit(kind, property) {
			const source = kind === 'mapping' ? this.mappingRules : this.castRules
			this.editing = {
				kind,
				property,
				value: source[property] ?? (kind === 'cast' ? 'string' : ''),
			}
		},
		openEditUnset(property) {
			this.editing = {
				kind: 'unset',
				property,
				value: property,
			}
		},

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

		commitMapping(oldKey, newKey, newValue) {
			const next = { ...this.mappingRules }
			if (oldKey && oldKey !== newKey) {
				delete next[oldKey]
			}
			next[newKey] = newValue
			this.$emit('update-mapping', next)
		},
		commitCast(oldKey, newKey, newValue) {
			const next = { ...this.castRules }
			if (oldKey && oldKey !== newKey) {
				delete next[oldKey]
			}
			next[newKey] = newValue
			this.$emit('update-cast', next)
		},
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
		deleteUnset(property) {
			const next = this.unsetRules.filter((entry) => entry !== property)
			this.$emit('update-unset', next)
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

.cn-rules-editor__tabs {
	display: flex;
	gap: 4px;
	border-bottom: 1px solid var(--color-border);
}

.cn-rules-editor__tab {
	display: inline-flex;
	gap: 6px;
	align-items: center;
	background: transparent;
	border: none;
	border-bottom: 2px solid transparent;
	padding: 8px 12px;
	cursor: pointer;
	color: var(--color-main-text);
	font: inherit;
	font-weight: 500;
}

.cn-rules-editor__tab:hover {
	background: var(--color-background-hover);
}

.cn-rules-editor__tab--active {
	border-bottom-color: var(--color-primary-element, var(--color-primary));
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

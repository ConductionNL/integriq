<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<!--
  RulesTable: one reorderable rule table of MappingRulesEditor (mapping,
  cast or unset rules). Rows are drag handles plus a property, an optional
  value cell from the value slot, and edit and delete buttons. Arrow keys
  and drag and drop reorder; the table emits the new order and then reorder.

  @spec openspec/specs/mapping-editor-ui/spec.md
-->
<template>
	<table class="cn-rules-editor__table">
		<thead>
			<tr>
				<th class="cn-rules-editor__col-handle" aria-hidden="true" />
				<th scope="col">
					{{ propertyLabel }}
				</th>
				<th v-if="valueLabel" scope="col">
					{{ valueLabel }}
				</th>
				<th scope="col" class="cn-rules-editor__col-actions">
					{{ t('integriq', 'Actions') }}
				</th>
			</tr>
		</thead>
		<VueDraggable
			v-model="list"
			tag="tbody"
			handle=".cn-rules-editor__drag-handle"
			:disabled="saving"
			:animation="150"
			ghostClass="cn-rules-editor__row--ghost"
			dragClass="cn-rules-editor__row--dragging"
			@end="$emit('reorder')">
			<tr
				v-for="(row, index) in list"
				:key="keyOf(row, index)"
				tabindex="0"
				class="cn-rules-editor__row"
				@keydown.up.prevent="move(index, -1)"
				@keydown.down.prevent="move(index, 1)">
				<td class="cn-rules-editor__col-handle">
					<button
						type="button"
						class="cn-rules-editor__drag-handle"
						:aria-label="t('integriq', 'Drag to reorder')"
						:disabled="saving"
						tabindex="-1">
						<DragVerticalIcon :size="18" />
					</button>
				</td>
				<td class="cn-rules-editor__cell-key">
					{{ labelOf(row) }}
				</td>
				<td v-if="valueLabel" class="cn-rules-editor__cell-value">
					<slot name="value" :row="row" />
				</td>
				<td class="cn-rules-editor__col-actions">
					<NcButton
						variant="tertiary"
						:aria-label="t('integriq', 'Edit rule')"
						:disabled="saving"
						@click="$emit('edit', labelOf(row))">
						<template #icon>
							<PencilIcon :size="18" />
						</template>
					</NcButton>
					<NcButton
						variant="tertiary"
						:aria-label="t('integriq', 'Delete rule')"
						:disabled="saving"
						@click="$emit('delete', labelOf(row))">
						<template #icon>
							<DeleteIcon :size="18" />
						</template>
					</NcButton>
				</td>
			</tr>
		</VueDraggable>
	</table>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import { VueDraggable } from 'vue-draggable-plus'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import DragVerticalIcon from 'vue-material-design-icons/DragVertical.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'

export default {
	name: 'RulesTable',

	components: { DeleteIcon, DragVerticalIcon, NcButton, PencilIcon, VueDraggable },

	props: {
		/** The rows: `{ key, value }` records, or plain property names. */
		rows: { type: Array, required: true },
		/** The translated header of the property column. */
		propertyLabel: { type: String, required: true },
		/** The translated header of the value column; no value column when empty. */
		valueLabel: { type: String, default: '' },
		/** Whether a persist is in flight. Disables the controls. */
		saving: { type: Boolean, default: false },
	},

	emits: ['update:rows', 'reorder', 'edit', 'delete'],

	computed: {
		list: {
			/**
			 * @return {Array} The rows, for the drag and drop list.
			 * @spec openspec/specs/mapping-editor-ui/spec.md
			 */
			get: function () {
				return this.rows
			},

			/**
			 * @param {Array} next The rows in their dragged order.
			 * @spec openspec/specs/mapping-editor-ui/spec.md
			 */
			set: function (next) {
				this.$emit('update:rows', next)
			},
		},
	},

	methods: {
		t,

		/**
		 * The property a row stands for.
		 *
		 * @param {object|string} row A `{ key, value }` record or a property name.
		 * @return {string} The property.
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		labelOf(row) {
			return typeof row === 'string' ? row : row.key
		},

		/**
		 * The list key of a row; plain names may repeat, so they carry their index.
		 *
		 * @param {object|string} row A `{ key, value }` record or a property name.
		 * @param {number} index The row position.
		 * @return {string} The key.
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		keyOf(row, index) {
			return typeof row === 'string' ? row + '-' + index : row.key
		},

		/**
		 * Keyboard reorder: swap a row with its neighbour and emit the new
		 * order, then reorder, the same path a drag takes.
		 *
		 * @param {number} index Current row position.
		 * @param {number} direction -1 to move up, 1 to move down.
		 * @return {void}
		 * @spec openspec/specs/mapping-editor-ui/spec.md
		 */
		move(index, direction) {
			const target = index + direction
			if (target < 0 || target >= this.rows.length) return
			const next = [...this.rows]
			const [moved] = next.splice(index, 1)
			next.splice(target, 0, moved)
			this.$emit('update:rows', next)
			this.$emit('reorder')
		},
	},
}
</script>

<style scoped>
.cn-rules-editor__table {
	width: 100%;
	border-collapse: collapse;
}

.cn-rules-editor__table th,
.cn-rules-editor__table td {
	text-align: start;
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
	padding-inline: 8px 0;
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
	overflow-wrap: break-word;
}

.cn-rules-editor__col-actions {
	width: 96px;
	text-align: end;
	white-space: nowrap;
}

.cn-rules-editor__col-actions .button-vue,
.cn-rules-editor__col-actions button {
	margin-inline-start: 4px;
}
</style>

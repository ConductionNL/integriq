// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Shared row editing of the action forms that list properties as rows: edit
 * one field of a row, add an empty row, remove a row. Every change rebuilds
 * the row list and hands it to the component. The component provides `rows`
 * (computed), `emitRows(rows)` and `blankRow()`.
 *
 * @spec openspec/specs/rule-editor-ui/spec.md
 */
export default {
	methods: {
		/**
		 * Replace fields of one row and emit the new list.
		 *
		 * @param {number} index Zero-based position of the edited row in `rows`.
		 * @param {object} patch The fields to replace.
		 * @return {void}
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		patchRow(index, patch) {
			const rows = this.rows.slice()
			rows[index] = { ...rows[index], ...patch }
			this.emitRows(rows)
		},

		/**
		 * Update the dot path of one row as the user types.
		 *
		 * @param {number} index Zero-based position of the edited row in `rows`.
		 * @param {string} value The new dot path.
		 * @return {void}
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		onPropertyInput(index, value) {
			this.patchRow(index, { property: value })
		},

		/**
		 * Append an empty row.
		 *
		 * @return {void}
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		addRow() {
			const rows = this.rows.slice()
			rows.push(this.blankRow())
			this.emitRows(rows)
		},

		/**
		 * Drop one row from the list.
		 *
		 * @param {number} index Zero-based position of the row to remove.
		 * @return {void}
		 * @spec openspec/specs/rule-editor-ui/spec.md
		 */
		removeRow(index) {
			const rows = this.rows.slice()
			rows.splice(index, 1)
			this.emitRows(rows)
		},
	},
}

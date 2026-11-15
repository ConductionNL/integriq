// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Props of an editor that replaces CnIndexPage's `form-dialog` slot. The
 * manifest mounts the editor with `v-bind="slotProps"`, so these are the
 * whole contract with the host page.
 */
export const editorSlotScopeProps = {
	/** Whether CnIndexPage wants the form dialog open. */
	show: {
		type: Boolean,
		default: false,
	},

	/** The row being edited, or `null` in create mode. */
	item: {
		type: Object,
		default: null,
	},

	/** The effective JSON schema. Unused: the editors' fields are bespoke. */
	schema: {
		type: Object,
		default: null,
	},

	/**
	 * Persists the object through CnIndexPage's own save path and refreshes
	 * the list. Saving elsewhere would leave the index stale until a reload,
	 * because the built-in refresh only runs inside this handler, and would
	 * write to a different store than the one the list reads from.
	 */
	confirm: {
		type: Function,
		default: null,
	},

	/** Closes the form dialog on CnIndexPage. */
	close: {
		type: Function,
		default: null,
	},
}

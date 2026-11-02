// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Close relay of the dialogs that ModalHost opens: NcDialog reports its open
 * state, and a dialog that closes asks the host to close it. The component
 * declares the `close` event.
 *
 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
 */
export default {
	methods: {
		/**
		 * NcDialog open-state relay.
		 *
		 * @param {boolean} isOpen New open state from NcDialog.
		 * @return {void}
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
		 */
		onOpenChanged(isOpen) {
			if (!isOpen) {
				this.close()
			}
		},

		/**
		 * Ask the host to close the dialog.
		 *
		 * @return {void}
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
		 */
		close() {
			this.$emit('close')
		},
	},
}

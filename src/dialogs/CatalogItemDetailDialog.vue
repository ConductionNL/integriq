<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  CatalogItemDetailDialog — detail dialog for one Catalog item
  (connector-catalog-ui REQ-002), mounted once in ModalHost and opened via
  the modal bus from CatalogItemCard.

  On open it re-checks the item's LIVE status via
  GET /api/catalog/items/{id}/status (a stale materialised card must never
  offer an action that has already been taken), then offers the primary
  action per mechanism: "Enable" (flag-gated) or "Instantiate"
  (mock-seeded / always-available with a source template). The endpoint is
  gated server-side by ADR-023 `catalog.instantiate` + the source schema's
  data-layer admin lock — a 403/409 surfaces here as an error note.
-->
<template>
	<NcDialog
		:open="open"
		:name="dialogTitle"
		size="normal"
		data-testid="catalog-item-detail-dialog"
		@update:open="onOpenChanged">
		<div class="oc-catalog-detail">
			<div class="oc-catalog-detail__status-row">
				<span
					class="oc-catalog-detail__badge"
					:class="`oc-catalog-detail__badge--${liveStatusKey}`"
					data-testid="catalog-detail-status">
					{{ liveStatusLabel }}
				</span>
				<span v-if="statusLoading" class="oc-catalog-detail__checking">
					{{ t('integriq', 'Checking live status…') }}
				</span>
				<span
					v-else-if="mechanismLabel"
					class="oc-catalog-detail__mechanism">
					{{ mechanismLabel }}
				</span>
			</div>

			<p v-if="catalogItem.description" class="oc-catalog-detail__desc">
				{{ catalogItem.description }}
			</p>

			<div v-if="standards.length > 0" class="oc-catalog-detail__section">
				<h4>{{ t('integriq', 'Standards') }}</h4>
				<div class="oc-catalog-detail__chips">
					<span
						v-for="standard in standards"
						:key="standard"
						class="oc-catalog-detail__chip">
						{{ standard }}
					</span>
				</div>
			</div>

			<NcNoteCard v-if="errorMessage" type="error">
				{{ errorMessage }}
			</NcNoteCard>
			<NcNoteCard v-if="successMessage" type="success">
				{{ successMessage }}
			</NcNoteCard>

			<div class="oc-catalog-detail__actions">
				<NcButton variant="tertiary" @click="close">
					{{ t('integriq', 'Close') }}
				</NcButton>
				<NcButton
					v-if="showPrimaryAction"
					variant="primary"
					:disabled="actionRunning || statusLoading"
					data-testid="catalog-detail-primary-action"
					@click="runPrimaryAction">
					{{ primaryActionLabel }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'
import { useCatalogStore } from '../store/catalog.js'

export default {
	name: 'CatalogItemDetailDialog',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
	},

	props: {
		open: { type: Boolean, default: false },
		item: { type: Object, default: null },
	},

	emits: ['close'],

	data() {
		return {
			liveStatus: null,
			statusLoading: false,
			actionRunning: false,
			errorMessage: '',
			successMessage: '',
		}
	},

	computed: {
		/**
		 * The catalog store instance (Pinia Options API pattern).
		 *
		 * @return {object}
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
		 */
		catalogStore() {
			return useCatalogStore()
		},

		/**
		 * The catalog_item row this dialog describes.
		 *
		 * @return {object}
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
		 */
		catalogItem() {
			return this.item || {}
		},

		/**
		 * Dialog title — the item's display name.
		 *
		 * @return {string}
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
		 */
		dialogTitle() {
			return (
				this.catalogItem.name
				|| this.catalogItem.slug
				|| t('integriq', 'Catalog item')
			)
		},

		/**
		 * The item's OR object id, wherever the row carried it.
		 *
		 * @return {string}
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
		 */
		itemId() {
			const self = this.catalogItem['@self'] || {}
			return self.id || this.catalogItem.uuid || this.catalogItem.id || ''
		},

		/**
		 * Live status key — prefers the fresh per-open status re-check over
		 * the materialised card value (REQ-002).
		 *
		 * @return {string} 'available' | 'dormant'
		 * @spec openspec/specs/connector-catalog/spec.md#scenario-enable-action-flips-a-feature-flag-for-a-flag-gated-item
		 */
		liveStatusKey() {
			const status = this.liveStatus?.status || this.catalogItem.status
			return status === 'dormant' ? 'dormant' : 'available'
		},

		/**
		 * Translated live status label.
		 *
		 * @return {string}
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
		 */
		liveStatusLabel() {
			return this.liveStatusKey === 'dormant'
				? t('integriq', 'dormant')
				: t('integriq', 'available')
		},

		/**
		 * Which dormancy mechanism gates this item — from the live check
		 * when present, else the materialised value.
		 *
		 * @return {string}
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
		 */
		mechanism() {
			return (
				this.liveStatus?.mechanism
				|| this.catalogItem.mechanism
				|| 'always-available'
			)
		},

		/**
		 * Translated one-liner describing the mechanism.
		 *
		 * @return {string}
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
		 */
		mechanismLabel() {
			switch (this.mechanism) {
				case 'flag-gated':
					return t('integriq', 'Gated behind a feature flag')
				case 'mock-seeded':
					return t(
						'integriq',
						'Seeded source (mock mode until credentials are configured)',
					)
				default:
					return ''
			}
		},

		/**
		 * Standards list for the detail section.
		 *
		 * @return {Array<string>}
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
		 */
		standards() {
			return Array.isArray(this.catalogItem.standards)
				? this.catalogItem.standards
				: []
		},

		/**
		 * Show the primary action only while the item is dormant and there
		 * is something to act on (a flag or a source template).
		 *
		 * @return {boolean}
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
		 */
		showPrimaryAction() {
			if (this.liveStatusKey !== 'dormant') {
				return false
			}
			if (this.mechanism === 'flag-gated') {
				return Boolean(this.liveStatus?.flagKey || this.catalogItem.flagKey)
			}
			return Boolean(this.catalogItem.sourceTemplateSlug)
		},

		/**
		 * "Enable" for flag-gated items, "Instantiate" otherwise (REQ-002).
		 *
		 * @return {string}
		 * @spec openspec/specs/connector-catalog/spec.md#scenario-instantiate-action-creates-a-source-from-a-seeded-template
		 */
		primaryActionLabel() {
			return this.mechanism === 'flag-gated'
				? t('integriq', 'Enable')
				: t('integriq', 'Instantiate')
		},
	},

	watch: {
		/**
		 * Clears the previous run's messages and re-reads the item's install
		 * status each time the dialog opens, so a stale "enabled" result from
		 * a previous item cannot be shown against this one.
		 *
		 * @param {boolean} isOpen Whether the dialog is being shown.
		 * @return {void}
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
		 */
		open(isOpen) {
			if (isOpen) {
				this.errorMessage = ''
				this.successMessage = ''
				this.refreshStatus()
			}
		},
	},

	methods: {
		/**
		 * Live status re-check on dialog open (REQ-002: never offer an
		 * action from a stale materialised card).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
		 */
		async refreshStatus() {
			if (!this.itemId) {
				return
			}
			this.statusLoading = true
			try {
				this.liveStatus = await this.catalogStore.fetchStatus(this.itemId)
			} catch (err) {
				// Non-fatal: fall back to the materialised status.
				this.liveStatus = null
			} finally {
				this.statusLoading = false
			}
		},

		/**
		 * Dispatch the Enable/Instantiate action — one endpoint server-side,
		 * the controller branches on the item's mechanism (REQ-002).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/connector-catalog/spec.md#scenario-enable-action-flips-a-feature-flag-for-a-flag-gated-item
		 */
		async runPrimaryAction() {
			if (!this.itemId) {
				return
			}
			this.actionRunning = true
			this.errorMessage = ''
			this.successMessage = ''
			try {
				const result = await this.catalogStore.instantiate(this.itemId)
				this.liveStatus =
					this.catalogStore.statusById[this.itemId] || this.liveStatus
				this.successMessage =
					result?.type === 'source'
						? t(
								'integriq',
								'Source instantiated — find it on the Sources page',
							)
						: t('integriq', 'Feature enabled')
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				this.errorMessage =
					t('integriq', 'Action failed') + (detail ? `: ${detail}` : '')
			} finally {
				this.actionRunning = false
			}
		},

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
		 * Ask ModalHost to close the dialog.
		 *
		 * @return {void}
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
		 */
		close() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.oc-catalog-detail {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 12px 12px;
}

.oc-catalog-detail__status-row {
	display: flex;
	align-items: center;
	gap: 10px;
}

.oc-catalog-detail__badge {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	font-size: 0.85em;
}

.oc-catalog-detail__badge--available {
	background-color: var(--color-success);
	color: var(--color-primary-element-text);
}

.oc-catalog-detail__badge--dormant {
	background-color: var(--color-background-darker);
	color: var(--color-text-maxcontrast);
}

.oc-catalog-detail__checking,
.oc-catalog-detail__mechanism {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.oc-catalog-detail__desc {
	margin: 0;
	white-space: pre-line;
}

.oc-catalog-detail__section h4 {
	margin: 0 0 6px;
}

.oc-catalog-detail__chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.oc-catalog-detail__chip {
	padding: 1px 8px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-background-dark);
	font-size: 0.85em;
}

.oc-catalog-detail__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>

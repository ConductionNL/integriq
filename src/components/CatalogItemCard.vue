<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  CatalogItemCard — custom card for the Catalog index grid
  (`pages[].config.cardComponent: "CatalogItemCard"`, connector-catalog-ui).
  CnIndexPage mounts one per catalog_item row passing `{ item, object,
  schema, register, selected }` (the openbuild ApplicationCard contract).

  Shows name, kind pill, category, standards chips, and the status badge
  (`available` / `dormant`). Clicking the card asks the app shell (via the
  modal bus → ModalHost) to open CatalogItemDetailDialog for the item —
  the dialog lives in its own file per the modal-isolation gate, and
  mounting it once in ModalHost keeps it outside the card's lifecycle.
-->
<template>
	<div class="oc-catalog-card" data-testid="catalog-item-card">
		<div class="oc-catalog-card__inner"
			tabindex="0"
			role="button"
			:aria-label="t('openconnector', 'Open catalog item {name}', { name: displayName })"
			@click="openDetail"
			@keyup.enter="openDetail">
			<div class="oc-catalog-card__head">
				<h3 class="oc-catalog-card__title">
					{{ displayName }}
				</h3>
				<span class="oc-catalog-card__badge"
					:class="`oc-catalog-card__badge--${statusKey}`"
					data-testid="catalog-status-badge">
					{{ statusLabel }}
				</span>
			</div>
			<p v-if="catalogItem.description" class="oc-catalog-card__desc">
				{{ catalogItem.description }}
			</p>
			<div class="oc-catalog-card__meta">
				<span class="oc-catalog-card__chip oc-catalog-card__chip--kind">{{ kindLabel }}</span>
				<span v-if="catalogItem.category" class="oc-catalog-card__chip">{{ catalogItem.category }}</span>
				<span v-for="standard in standards"
					:key="standard"
					class="oc-catalog-card__chip oc-catalog-card__chip--muted">
					{{ standard }}
				</span>
			</div>
		</div>
	</div>
</template>

<script>
import { modalBus, EVENT_OPEN_CATALOG_ITEM_DETAIL } from '../handlers/modalBus.js'

export default {
	name: 'CatalogItemCard',

	props: {
		// CnIndexPage passes the row both as `item` and `object`.
		object: { type: Object, default: null },
		item: { type: Object, default: null },
		selected: { type: Boolean, default: false },
	},

	computed: {
		/**
		 * The catalog_item row, whichever prop CnIndexPage populated.
		 *
		 * @return {object}
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001
		 */
		catalogItem() {
			return this.object || this.item || {}
		},

		/**
		 * Display name with a translated fallback.
		 *
		 * @return {string}
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001
		 */
		displayName() {
			return this.catalogItem.name || this.catalogItem.slug || t('openconnector', 'Untitled catalog item')
		},

		/**
		 * Status key for the badge modifier class — `available` unless the
		 * materialised status says `dormant`.
		 *
		 * @return {string}
		 * @spec openspec/specs/connector-catalog/spec.md#scenario-status-badge-reflects-a-flag-gated-dormant-item
		 */
		statusKey() {
			return this.catalogItem.status === 'dormant' ? 'dormant' : 'available'
		},

		/**
		 * Translated status badge label.
		 *
		 * @return {string}
		 * @spec openspec/specs/connector-catalog/spec.md#scenario-status-badge-reflects-a-mock-seeded-available-item
		 */
		statusLabel() {
			return this.statusKey === 'dormant'
				? t('openconnector', 'dormant')
				: t('openconnector', 'available')
		},

		/**
		 * Translated kind pill label.
		 *
		 * @return {string}
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001
		 */
		kindLabel() {
			switch (this.catalogItem.kind) {
			case 'adapter':
				return t('openconnector', 'Adapter')
			case 'source-template':
				return t('openconnector', 'Source template')
			case 'configuration-template':
				return t('openconnector', 'Configuration template')
			default:
				return this.catalogItem.kind || ''
			}
		},

		/**
		 * Standards chip list (bounded so a long list can't blow up the card).
		 *
		 * @return {Array<string>}
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001
		 */
		standards() {
			const list = Array.isArray(this.catalogItem.standards) ? this.catalogItem.standards : []
			return list.slice(0, 4)
		},
	},

	methods: {
		/**
		 * Open the detail dialog for this item via the shared modal bus —
		 * ModalHost owns the dialog component (modal-isolation gate).
		 *
		 * @return {void}
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
		 */
		openDetail() {
			modalBus.emit(EVENT_OPEN_CATALOG_ITEM_DETAIL, { item: this.catalogItem })
		},
	},
}
</script>

<style scoped>
.oc-catalog-card__inner {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	cursor: pointer;
	height: 100%;
}

.oc-catalog-card__inner:hover,
.oc-catalog-card__inner:focus {
	background-color: var(--color-background-hover);
	outline: none;
	border-color: var(--color-primary-element);
}

.oc-catalog-card__head {
	display: flex;
	align-items: center;
	gap: 8px;
}

.oc-catalog-card__title {
	flex: 1;
	margin: 0;
	font-size: 1.05em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.oc-catalog-card__badge {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	font-size: 0.8em;
	white-space: nowrap;
}

.oc-catalog-card__badge--available {
	background-color: var(--color-success);
	color: var(--color-primary-element-text);
}

.oc-catalog-card__badge--dormant {
	background-color: var(--color-background-darker);
	color: var(--color-text-maxcontrast);
}

.oc-catalog-card__desc {
	margin: 0;
	color: var(--color-text-maxcontrast);
	display: -webkit-box;
	-webkit-line-clamp: 3;
	line-clamp: 3;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

.oc-catalog-card__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: auto;
}

.oc-catalog-card__chip {
	padding: 1px 8px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-background-dark);
	font-size: 0.8em;
	white-space: nowrap;
}

.oc-catalog-card__chip--kind {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.oc-catalog-card__chip--muted {
	color: var(--color-text-maxcontrast);
}
</style>

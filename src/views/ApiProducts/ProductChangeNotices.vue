<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  ProductChangeNotices: who was told what about this API product, and when.
  One row per product_change_notice, written when the product is deprecated,
  given or moved a sunset date, versioned or retired, and fourteen days
  before a subscription ends. Read from OpenRegister's object API.

  @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-subscribers-are-told-when-a-product-they-use-changes-req-devp-004
-->
<template>
	<section class="productChangeNotices" data-testid="api-product-change-notices">
		<h3>{{ t('integriq', 'Change notices') }}</h3>
		<p class="productChangeNotices__hint">
			{{ t('integriq', 'Subscribers are told by notification and mail when this product is deprecated, gets a sunset date, a new version or is retired.') }}
		</p>
		<NcLoadingIcon v-if="loading" :size="24" />
		<table v-else-if="notices.length" class="productChangeNotices__table">
			<thead>
				<tr>
					<th scope="col">{{ t('integriq', 'Sent') }}</th>
					<th scope="col">{{ t('integriq', 'Recipient') }}</th>
					<th scope="col">{{ t('integriq', 'Kind') }}</th>
					<th scope="col">{{ t('integriq', 'Message') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="notice in notices" :key="notice.id || notice.uuid">
					<td>{{ notice.sentAt || '—' }}</td>
					<td>{{ notice.recipientUserId }}</td>
					<td>{{ kindLabel(notice.kind) }}</td>
					<td>{{ notice.message }}</td>
				</tr>
			</tbody>
		</table>
		<p v-else class="productChangeNotices__hint">
			{{ t('integriq', 'No notices sent yet.') }}
		</p>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'
import { noticeKindLabel, noticesOf } from './productChangeNotices.js'

export default {
	name: 'ProductChangeNotices',

	components: { NcLoadingIcon },

	props: {
		/** The api_product's id. */
		productId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			notices: [],
			loading: false,
		}
	},

	watch: {
		productId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * @param {string} kind The notice kind.
		 * @return {string} Its label.
		 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-subscribers-are-told-when-a-product-they-use-changes-req-devp-004
		 */
		kindLabel(kind) {
			return noticeKindLabel(kind)
		},

		/**
		 * Read this product's notices, newest first.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-subscribers-are-told-when-a-product-they-use-changes-req-devp-004
		 */
		async load() {
			this.loading = true
			try {
				const res = await axios.get(
					generateUrl('/apps/openregister/api/objects/integriq/product_change_notice'),
					{ params: { product: this.productId, _limit: 100 } },
				)
				this.notices = noticesOf(res.data)
			} catch {
				this.notices = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.productChangeNotices__hint {
	color: var(--color-text-maxcontrast);
}

.productChangeNotices__table {
	width: 100%;
	border-collapse: collapse;
}

.productChangeNotices__table th,
.productChangeNotices__table td {
	text-align: start;
	padding: 6px 10px;
	border-bottom: 1px solid var(--color-border);
}
</style>

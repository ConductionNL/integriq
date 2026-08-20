<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  ApiProductDetail — a single api_product's detail (manifest `type: custom`,
  `component: ApiProductDetail`, route `/products/:id`): endpoint picker,
  named-tier editor, gateway analytics panel, and pending subscriptions with
  approve/reject actions. A generic OR CRUD detail page can't express any of
  these, so this mirrors ApprovalDetail's shape (plain axios calls, no store,
  no inline NcModal — everything happens on the page body).

  Backend contract: the product itself is a plain OR object
  (GET/PATCH /api/objects/openconnector/api_product/{id}); Endpoints are
  listed from /api/objects/openconnector/endpoint (mirrors
  AddEndpointRuleModal's endpoint-picker pattern); subscriptions are listed
  from /api/objects/openconnector/api_product_subscription filtered by
  product; approve/reject/analytics are the bespoke, two-layer-authorized
  ProductSubscriptionsController routes.

  @spec openspec/specs/api-product-gateway/spec.md#requirement-api-products-management-ui-req-apg-002
  @spec openspec/specs/api-product-gateway/spec.md#requirement-gateway-analytics-per-api-product-req-apg-007
-->
<template>
	<div class="apiProductDetail">
		<NcButton variant="tertiary" class="apiProductDetail__back" @click="goBack">
			{{ t('openconnector', 'Back to API Products') }}
		</NcButton>

		<NcLoadingIcon v-if="loading" :size="32" class="apiProductDetail__loading" />

		<div v-else-if="product" class="apiProductDetail__body">
			<div class="apiProductDetail__meta">
				<h2>{{ product.name }}</h2>
				<span
					class="apiProductDetail__badge"
					:class="`apiProductDetail__badge--${product.status}`">
					{{ product.status }}
				</span>
			</div>
			<p
				v-if="product.status === 'deprecated'"
				class="apiProductDetail__sunsetNotice">
				{{
					t('openconnector', 'Deprecated — sunset {date}', {
						date: product.sunsetDate || '—',
					})
				}}
			</p>

			<!-- Endpoint picker -->
			<section class="apiProductDetail__section">
				<h3>{{ t('openconnector', 'Endpoints') }}</h3>
				<ul class="apiProductDetail__list">
					<li v-for="id in productEndpoints" :key="id">
						{{ endpointLabel(id) }}
						<NcButton
							variant="tertiary"
							:aria-label="t('openconnector', 'Remove endpoint')"
							@click="removeEndpoint(id)">
							{{ t('openconnector', 'Remove') }}
						</NcButton>
					</li>
					<li
						v-if="productEndpoints.length === 0"
						class="apiProductDetail__empty">
						{{ t('openconnector', 'No endpoints attached yet.') }}
					</li>
				</ul>
				<label :for="'apiProductDetail-endpoint-select-' + uid">
					{{ t('openconnector', 'Add endpoints') }}
				</label>
				<NcSelect
					:id="'apiProductDetail-endpoint-select-' + uid"
					v-model="selectedEndpoints"
					:inputId="'apiProductDetail-endpoint-select-input-' + uid"
					:inputLabel="t('openconnector', 'Add endpoints')"
					:aria-label-combobox="t('openconnector', 'Add endpoints')"
					:options="availableEndpointOptions"
					:loading="loadingEndpoints"
					:multiple="true"
					:clearable="true"
					:placeholder="
						t('openconnector', 'Pick one or more endpoints')
					" />
				<NcButton
					variant="primary"
					:disabled="selectedEndpoints.length === 0 || saving"
					@click="addSelectedEndpoints">
					{{ t('openconnector', 'Add') }}
				</NcButton>
			</section>

			<!-- Tier editor -->
			<section class="apiProductDetail__section">
				<h3>{{ t('openconnector', 'Tiers') }}</h3>
				<table class="apiProductDetail__table">
					<thead>
						<tr>
							<th scope="col">{{ t('openconnector', 'Name') }}</th>
							<th scope="col">
								{{ t('openconnector', 'Requests / window') }}
							</th>
							<th scope="col">
								{{ t('openconnector', 'Window (s)') }}
							</th>
							<th scope="col">
								{{ t('openconnector', 'Requires approval') }}
							</th>
							<th scope="col">
								{{ t('openconnector', 'Approver group') }}
							</th>
							<th />
						</tr>
					</thead>
					<tbody>
						<tr v-for="(tier, name) in productTiers" :key="name">
							<td>{{ name }}</td>
							<td>
								{{
									(tier.rateLimit
										&& tier.rateLimit.requestsPerWindow)
									|| '—'
								}}
							</td>
							<td>
								{{
									(tier.rateLimit && tier.rateLimit.windowSeconds)
									|| '—'
								}}
							</td>
							<td>
								{{
									tier.requiresApproval
										? t('openconnector', 'Yes')
										: t('openconnector', 'No')
								}}
							</td>
							<td>{{ tier.approverGroup || '—' }}</td>
							<td>
								<NcButton
									variant="tertiary"
									:aria-label="t('openconnector', 'Remove tier')"
									@click="removeTier(name)">
									{{ t('openconnector', 'Remove') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>

				<form class="apiProductDetail__tierForm" @submit.prevent="addTier">
					<label :for="'apiProductDetail-tier-name-' + uid">{{
						t('openconnector', 'Tier name')
					}}</label>
					<input
						:id="'apiProductDetail-tier-name-' + uid"
						v-model="newTier.name"
						type="text" />

					<label :for="'apiProductDetail-tier-rpw-' + uid">{{
						t('openconnector', 'Requests / window')
					}}</label>
					<input
						:id="'apiProductDetail-tier-rpw-' + uid"
						v-model.number="newTier.requestsPerWindow"
						type="number"
						min="1" />

					<label :for="'apiProductDetail-tier-window-' + uid">{{
						t('openconnector', 'Window (seconds)')
					}}</label>
					<input
						:id="'apiProductDetail-tier-window-' + uid"
						v-model.number="newTier.windowSeconds"
						type="number"
						min="1" />

					<label :for="'apiProductDetail-tier-approval-' + uid">
						<input
							:id="'apiProductDetail-tier-approval-' + uid"
							v-model="newTier.requiresApproval"
							type="checkbox" />
						{{ t('openconnector', 'Requires approval') }}
					</label>

					<label :for="'apiProductDetail-tier-group-' + uid">{{
						t('openconnector', 'Approver group')
					}}</label>
					<input
						:id="'apiProductDetail-tier-group-' + uid"
						v-model="newTier.approverGroup"
						type="text" />

					<NcButton
						variant="primary"
						type="submit"
						:disabled="!newTier.name || saving">
						{{ t('openconnector', 'Add tier') }}
					</NcButton>
				</form>
			</section>

			<!-- Analytics panel -->
			<section class="apiProductDetail__section">
				<h3>{{ t('openconnector', 'Analytics') }}</h3>
				<NcLoadingIcon v-if="loadingAnalytics" :size="24" />
				<dl
					v-else-if="analytics"
					class="apiProductDetail__fields"
					data-testid="analytics-summary">
					<dt>{{ t('openconnector', 'Requests') }}</dt>
					<dd>{{ analytics.requestCount }}</dd>
					<dt>{{ t('openconnector', 'Error rate') }}</dt>
					<dd>{{ formatPercent(analytics.errorRate) }}</dd>
					<dt>{{ t('openconnector', 'p50 latency') }}</dt>
					<dd>
						{{ formatMs(analytics.latency && analytics.latency.p50) }}
					</dd>
					<dt>{{ t('openconnector', 'p95 latency') }}</dt>
					<dd>
						{{ formatMs(analytics.latency && analytics.latency.p95) }}
					</dd>
					<dt>{{ t('openconnector', 'p99 latency') }}</dt>
					<dd>
						{{ formatMs(analytics.latency && analytics.latency.p99) }}
					</dd>
				</dl>
			</section>

			<!-- Subscriptions -->
			<section class="apiProductDetail__section">
				<h3>{{ t('openconnector', 'Subscriptions') }}</h3>
				<ul class="apiProductDetail__list">
					<li v-for="sub in subscriptions" :key="sub.id || sub.uuid">
						{{ sub.consumer }} — {{ sub.tier }} — {{ sub.status }}
						<template v-if="sub.status === 'pending_approval'">
							<NcButton
								variant="primary"
								:disabled="busy"
								@click="approveSubscription(sub)">
								{{ t('openconnector', 'Approve') }}
							</NcButton>
							<NcButton
								variant="error"
								:disabled="busy"
								@click="rejectSubscription(sub)">
								{{ t('openconnector', 'Reject') }}
							</NcButton>
						</template>
					</li>
					<li
						v-if="subscriptions.length === 0"
						class="apiProductDetail__empty">
						{{ t('openconnector', 'No subscriptions yet.') }}
					</li>
				</ul>
			</section>
		</div>

		<NcEmptyContent
			v-else
			:name="t('openconnector', 'API product not found')"
			:description="t('openconnector', 'It may have been removed.')">
			<template #icon>
				<AlertCircleOutline :size="48" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'

let uidCounter = 0

export default {
	name: 'ApiProductDetail',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		AlertCircleOutline,
	},

	data() {
		return {
			uid: ++uidCounter,
			product: null,
			loading: false,
			saving: false,
			busy: false,
			endpoints: [],
			loadingEndpoints: false,
			selectedEndpoints: [],
			analytics: null,
			loadingAnalytics: false,
			subscriptions: [],
			newTier: {
				name: '',
				requestsPerWindow: null,
				windowSeconds: null,
				requiresApproval: false,
				approverGroup: '',
			},
		}
	},

	computed: {
		/** @spec openspec/specs/api-product-gateway/spec.md#requirement-api-products-management-ui-req-apg-002 */
		productId() {
			return this.$route?.params?.id
		},

		/** @spec openspec/specs/api-product-gateway/spec.md#requirement-api-product-groups-endpoints-into-a-named-versioned-bundle-req-apg-001 */
		productEndpoints() {
			return Array.isArray(this.product?.endpoints)
				? this.product.endpoints
				: []
		},

		/** @spec openspec/specs/api-product-gateway/spec.md#requirement-api-product-groups-endpoints-into-a-named-versioned-bundle-req-apg-001 */
		productTiers() {
			return this.product?.tiers && typeof this.product.tiers === 'object'
				? this.product.tiers
				: {}
		},

		/** @spec openspec/specs/api-product-gateway/spec.md#requirement-api-products-management-ui-req-apg-002 */
		availableEndpointOptions() {
			const attached = new Set(this.productEndpoints)
			return this.endpoints
				.filter((e) => !attached.has(String(e.id || e.uuid)))
				.map((e) => ({
					id: String(e.id || e.uuid),
					label: e.name || e.endpoint || e.id,
				}))
		},
	},

	/**
	 * Loads the product and its four panels. Each load is independent so one
	 * failing panel (analytics is admin-only, for instance) leaves the rest of
	 * the page usable rather than blanking the whole detail view.
	 *
	 * @return {void}
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-api-products-management-ui-req-apg-002
	 */
	mounted() {
		this.load()
		this.loadEndpoints()
		this.loadAnalytics()
		this.loadSubscriptions()
	},

	methods: {
		t,
		/** @spec openspec/specs/api-product-gateway/spec.md#requirement-api-products-management-ui-req-apg-002 */
		goBack() {
			this.$router.push('/products')
		},

		/**
		 * Resolve an attached endpoint reference to a human-readable label.
		 *
		 * @param {string|number} id Endpoint id or uuid as stored on the product's `endpoints` array.
		 * @return {string|number} The endpoint's name or path, falling back to the raw id when it is not in the loaded list.
		 *
		 * @spec openspec/specs/api-product-gateway/spec.md#requirement-api-products-management-ui-req-apg-002
		 */
		endpointLabel(id) {
			const match = this.endpoints.find(
				(e) => String(e.id || e.uuid) === String(id),
			)
			return match ? match.name || match.endpoint || id : id
		},

		/**
		 * Render an analytics ratio as a percentage.
		 *
		 * @param {number|undefined} value Ratio in the 0–1 range (the error rate); missing when analytics failed to load.
		 * @return {string} Percentage with two decimals, or an em dash when there is no number.
		 *
		 * @spec openspec/specs/api-product-gateway/spec.md#requirement-api-products-management-ui-req-apg-002
		 */
		formatPercent(value) {
			if (typeof value !== 'number') return '—'
			return `${(value * 100).toFixed(2)}%`
		},

		/**
		 * Render a latency percentile as whole milliseconds.
		 *
		 * @param {number|undefined} value Latency in milliseconds (p50/p95/p99); missing when analytics carry no latency block.
		 * @return {string} Rounded value suffixed with `ms`, or an em dash when there is no number.
		 *
		 * @spec openspec/specs/api-product-gateway/spec.md#requirement-gateway-analytics-per-api-product-req-apg-007
		 */
		formatMs(value) {
			if (typeof value !== 'number') return '—'
			return `${Math.round(value)} ms`
		},

		/** @spec openspec/specs/api-product-gateway/spec.md#requirement-api-product-groups-endpoints-into-a-named-versioned-bundle-req-apg-001 */
		async load() {
			this.loading = true
			try {
				const res = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/openconnector/api_product/${this.productId}`,
					),
				)
				this.product = res.data
			} catch (err) {
				this.product = null
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/specs/api-product-gateway/spec.md#requirement-api-products-management-ui-req-apg-002 */
		async loadEndpoints() {
			this.loadingEndpoints = true
			try {
				const res = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/openconnector/endpoint',
					),
					// `_limit`, not `limit` — an unprefixed param is a PROPERTY
					// FILTER in OpenRegister and silently returns `total: 0`
					// under HTTP 200. See FlowDetailPage.fetchPickerOptions().
					{ params: { _limit: 500 } },
				)
				const data = res.data
				this.endpoints = Array.isArray(data?.results)
					? data.results
					: Array.isArray(data)
						? data
						: []
			} catch (err) {
				this.endpoints = []
			} finally {
				this.loadingEndpoints = false
			}
		},

		/** @spec openspec/specs/api-product-gateway/spec.md#requirement-gateway-analytics-per-api-product-req-apg-007 */
		async loadAnalytics() {
			this.loadingAnalytics = true
			try {
				const res = await axios.get(
					generateUrl(
						`/apps/openconnector/api/products/${this.productId}/analytics`,
					),
				)
				this.analytics = res.data
			} catch (err) {
				this.analytics = null
			} finally {
				this.loadingAnalytics = false
			}
		},

		/** @spec openspec/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004 */
		async loadSubscriptions() {
			try {
				const res = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/openconnector/api_product_subscription',
					),
					// `product` IS meant to be a property filter; `_limit` is a
					// CONTROL param and needs the underscore. Written as `limit`
					// it became a second property filter and this list was
					// always empty. See FlowDetailPage.fetchPickerOptions().
					{ params: { product: this.productId, _limit: 100 } },
				)
				const data = res.data
				this.subscriptions = Array.isArray(data?.results)
					? data.results
					: Array.isArray(data)
						? data
						: []
			} catch (err) {
				this.subscriptions = []
			}
		},

		/**
		 * PATCH a single property of the api_product object in OpenRegister,
		 * then reload so the view reflects what was actually stored. Failures
		 * surface as a toast; the object is left untouched.
		 *
		 * @param {string} field Property name on the api_product object, for example `endpoints` or `tiers`.
		 * @param {(Array|object|string|number|boolean)} value Replacement value for that property — an array of endpoint ids, the tiers object, or a scalar.
		 * @return {Promise<void>} Resolves once the PATCH and the reload have settled.
		 *
		 * @spec openspec/specs/api-product-gateway/spec.md#requirement-api-product-groups-endpoints-into-a-named-versioned-bundle-req-apg-001
		 */
		async saveProductField(field, value) {
			this.saving = true
			try {
				await axios.patch(
					generateUrl(
						`/apps/openregister/api/objects/openconnector/api_product/${this.productId}`,
					),
					{ [field]: value },
				)
				await this.load()
			} catch (err) {
				const detail = err?.response?.data?.message || err?.message || ''
				showError(
					t('openconnector', 'Failed to update API product')
						+ (detail ? `: ${detail}` : ''),
				)
			} finally {
				this.saving = false
			}
		},

		/** @spec openspec/specs/api-product-gateway/spec.md#requirement-api-products-management-ui-req-apg-002 */
		async addSelectedEndpoints() {
			const merged = Array.from(
				new Set([
					...this.productEndpoints,
					...this.selectedEndpoints.map((e) => e.id),
				]),
			)
			await this.saveProductField('endpoints', merged)
			this.selectedEndpoints = []
			showSuccess(t('openconnector', 'Endpoint(s) added'))
		},

		/**
		 * Detach one endpoint from the product by persisting the remaining ids.
		 *
		 * @param {string|number} id Endpoint id or uuid to remove; compared as a string because the stored ids are mixed-type.
		 * @return {Promise<void>} Resolves once the product has been saved and reloaded.
		 *
		 * @spec openspec/specs/api-product-gateway/spec.md#requirement-api-products-management-ui-req-apg-002
		 */
		async removeEndpoint(id) {
			const remaining = this.productEndpoints.filter(
				(e) => String(e) !== String(id),
			)
			await this.saveProductField('endpoints', remaining)
		},

		/** @spec openspec/specs/api-product-gateway/spec.md#requirement-api-products-management-ui-req-apg-002 */
		async addTier() {
			if (!this.newTier.name) return
			const tiers = { ...this.productTiers }
			tiers[this.newTier.name] = {
				rateLimit: {
					requestsPerWindow: this.newTier.requestsPerWindow || undefined,
					windowSeconds: this.newTier.windowSeconds || undefined,
				},

				requiresApproval: !!this.newTier.requiresApproval,
				approverGroup: this.newTier.approverGroup || undefined,
			}
			await this.saveProductField('tiers', tiers)
			this.newTier = {
				name: '',
				requestsPerWindow: null,
				windowSeconds: null,
				requiresApproval: false,
				approverGroup: '',
			}
			showSuccess(t('openconnector', 'Tier added'))
		},

		/**
		 * Delete one subscription tier and persist the remaining tiers.
		 *
		 * @param {string} name Tier name — the key under which it is stored in the product's `tiers` object.
		 * @return {Promise<void>} Resolves once the product has been saved and reloaded.
		 *
		 * @spec openspec/specs/api-product-gateway/spec.md#requirement-api-products-management-ui-req-apg-002
		 */
		async removeTier(name) {
			const tiers = { ...this.productTiers }
			delete tiers[name]
			await this.saveProductField('tiers', tiers)
		},

		/**
		 * Approve a pending subscription through the HITL approval gate and
		 * refresh the list so its status changes.
		 *
		 * @param {{id: (string|number|undefined), uuid: (string|undefined), consumer: string, tier: string, status: string}} sub Subscription row from the list; its `id` (or `uuid`) identifies the approval.
		 * @return {Promise<void>} Resolves once the approval call and the reload have settled.
		 *
		 * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004
		 */
		async approveSubscription(sub) {
			this.busy = true
			try {
				await axios.post(
					generateUrl(
						`/apps/openconnector/api/products/subscriptions/${sub.id || sub.uuid}/approve`,
					),
				)
				showSuccess(t('openconnector', 'Subscription approved'))
				await this.loadSubscriptions()
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				showError(
					t('openconnector', 'Approve failed')
						+ (detail ? `: ${detail}` : ''),
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Reject a pending subscription through the HITL approval gate,
		 * recording a fixed comment, and refresh the list.
		 *
		 * @param {{id: (string|number|undefined), uuid: (string|undefined), consumer: string, tier: string, status: string}} sub Subscription row from the list; its `id` (or `uuid`) identifies the approval.
		 * @return {Promise<void>} Resolves once the rejection call and the reload have settled.
		 *
		 * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004
		 */
		async rejectSubscription(sub) {
			this.busy = true
			try {
				await axios.post(
					generateUrl(
						`/apps/openconnector/api/products/subscriptions/${sub.id || sub.uuid}/reject`,
					),
					{ comment: t('openconnector', 'Rejected from API Products UI') },
				)
				showSuccess(t('openconnector', 'Subscription rejected'))
				await this.loadSubscriptions()
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				showError(
					t('openconnector', 'Reject failed')
						+ (detail ? `: ${detail}` : ''),
				)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.apiProductDetail {
	padding: 20px;
	max-width: 900px;
}

.apiProductDetail__back {
	margin-bottom: 16px;
}

.apiProductDetail__meta {
	display: flex;
	gap: 12px;
	align-items: center;
	margin-bottom: 8px;
}

.apiProductDetail__badge {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	font-weight: 600;
}

.apiProductDetail__badge--deprecated {
	background: var(--color-warning, #e9a13b);
	color: var(--color-primary-text);
}

.apiProductDetail__badge--active {
	background: var(--color-success, #46ba61);
	color: var(--color-primary-text);
}

.apiProductDetail__sunsetNotice {
	color: var(--color-warning, #e9a13b);
	margin-bottom: 16px;
}

.apiProductDetail__section {
	margin-bottom: 28px;
}

.apiProductDetail__list {
	list-style: none;
	padding: 0;
	margin: 0 0 12px;
}

.apiProductDetail__list li {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}

.apiProductDetail__empty {
	color: var(--color-text-maxcontrast);
}

.apiProductDetail__table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 12px;
}

.apiProductDetail__table th,
.apiProductDetail__table td {
	text-align: left;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.apiProductDetail__tierForm {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 16px;
	align-items: center;
}

.apiProductDetail__tierForm input[type='text'],
.apiProductDetail__tierForm input[type='number'] {
	padding: 6px 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.apiProductDetail__fields {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 6px 16px;
}

.apiProductDetail__fields dt {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.apiProductDetail__loading {
	margin: 32px auto;
}
</style>

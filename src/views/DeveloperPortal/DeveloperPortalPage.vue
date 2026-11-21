<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  DeveloperPortalPage: the developer portal (manifest `type: custom`,
  `component: DeveloperPortalPage`).

  Why a custom page rather than a CnIndexPage: a developer holds no rights on
  integriq's register, so the page cannot read OpenRegister's object API. It
  reads the portal routes instead (GET /api/portal/products and
  /api/portal/applications), which check the developer group and return only
  public products and the developer's own applications; requesting access
  posts to /api/portal/applications/{id}/subscriptions, which opens the
  product's approval. Each application's API keys are managed by
  PortalApplicationKeys over /api/portal/applications/{id}/keys.

  @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-finds-the-published-api-products-req-devp-001
  @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-requests-access-for-their-own-application-req-devp-002
-->
<template>
	<div class="developerPortal" data-testid="developer-portal">
		<h2>{{ t('integriq', 'Developer portal') }}</h2>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="refusal"
			data-testid="developer-portal-refused"
			:name="t('integriq', 'Developer portal')"
			:description="refusal">
			<template #icon>
				<LockOutline :size="48" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<section class="developerPortal__section" data-testid="developer-portal-products">
				<h3>{{ t('integriq', 'API products') }}</h3>
				<p v-if="!products.length" class="developerPortal__hint">
					{{ t('integriq', 'No API products are published yet.') }}
				</p>
				<ul class="developerPortal__cards">
					<li
						v-for="product in products"
						:key="product.id"
						class="developerPortal__card"
						data-testid="developer-portal-product">
						<h4>{{ product.name }} <span class="developerPortal__muted">{{ product.version }}</span></h4>
						<p v-if="product.status === 'deprecated'" class="developerPortal__warning">
							{{ deprecationText(product) }}
						</p>
						<p>{{ product.description }}</p>
						<p class="developerPortal__muted">
							{{ t('integriq', 'Tiers') }}: {{ tierText(product) }}
						</p>
						<a
							v-if="product.openApiUrl"
							:href="product.openApiUrl"
							target="_blank"
							rel="noopener noreferrer">
							{{ t('integriq', 'Read the API description of {name}', { name: product.name }) }}
						</a>
					</li>
				</ul>
			</section>

			<section class="developerPortal__section" data-testid="developer-portal-applications">
				<h3>{{ t('integriq', 'Your applications') }}</h3>
				<div class="developerPortal__row">
					<NcTextField
						v-model="newName"
						data-testid="developer-portal-new-application"
						:label="t('integriq', 'Application name')"
						@keyup.enter="createApplication" />
					<NcButton
						variant="primary"
						data-testid="developer-portal-create-application"
						:disabled="busy || !newName.trim()"
						@click="createApplication">
						{{ t('integriq', 'Create application') }}
					</NcButton>
				</div>

				<p v-if="!applications.length" class="developerPortal__hint">
					{{ t('integriq', 'You have no applications yet. Create one to request access to an API product.') }}
				</p>

				<div
					v-for="application in applications"
					:key="application.id"
					class="developerPortal__card"
					data-testid="developer-portal-application">
					<h4>{{ application.name }}</h4>
					<ul class="developerPortal__subscriptions">
						<li v-for="subscription in application.subscriptions" :key="subscription.id">
							{{ productName(subscription.product) }} — {{ subscription.tier }} —
							{{ statusLabel(subscription.status) }}
							<span v-if="subscription.expiresAt" class="developerPortal__muted">
								({{ t('integriq', 'ends {date}', { date: subscription.expiresAt }) }})
							</span>
						</li>
					</ul>
					<div class="developerPortal__row">
						<NcSelect
							v-model="requests[application.id].product"
							:inputLabel="t('integriq', 'API product')"
							:options="productOptions"
							label="label" />
						<NcSelect
							v-model="requests[application.id].tier"
							:inputLabel="t('integriq', 'Tier')"
							:options="tierOptions(requests[application.id].product)" />
						<NcButton
							data-testid="developer-portal-request-access"
							:disabled="busy || !requests[application.id].product"
							@click="requestAccess(application)">
							{{ t('integriq', 'Request access') }}
						</NcButton>
					</div>
					<PortalApplicationKeys :applicationId="application.id" />
				</div>
			</section>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import PortalApplicationKeys from './PortalApplicationKeys.vue'
import { productOptionsOf, requestOutcome, subscriptionStatusLabel, tierNamesOf } from './portal.js'

const BASE = '/apps/integriq/api/portal'

export default {
	name: 'DeveloperPortalPage',

	components: { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect, NcTextField, LockOutline, PortalApplicationKeys },

	data() {
		return {
			loading: true,
			busy: false,
			refusal: '',
			products: [],
			applications: [],
			requests: {},
			newName: '',
		}
	},

	computed: {
		/**
		 * @return {Array<object>} The products as select options.
		 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-requests-access-for-their-own-application-req-devp-002
		 */
		productOptions() {
			return productOptionsOf(this.products)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * @param {object|null} option The chosen product option.
		 * @return {Array<string>} Its tier names.
		 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-requests-access-for-their-own-application-req-devp-002
		 */
		tierOptions(option) {
			return tierNamesOf(this.products.find((product) => product.id === option?.id))
		},

		/**
		 * @param {object} product A product.
		 * @return {string} Its tiers, approval-gated ones marked.
		 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-finds-the-published-api-products-req-devp-001
		 */
		tierText(product) {
			return (product.tiers || [])
				.map((tier) => (tier.requiresApproval ? t('integriq', '{tier} (needs approval)', { tier: tier.name }) : tier.name))
				.join(', ')
		},

		/**
		 * @param {object} product A deprecated product.
		 * @return {string} The deprecation line.
		 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-finds-the-published-api-products-req-devp-001
		 */
		deprecationText(product) {
			if (product.sunsetDate) {
				return t('integriq', 'Deprecated: retired on {date}.', { date: String(product.sunsetDate).slice(0, 10) })
			}
			return t('integriq', 'Deprecated.')
		},

		/**
		 * @param {string} id A product id.
		 * @return {string} Its name, or the id.
		 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-requests-access-for-their-own-application-req-devp-002
		 */
		productName(id) {
			return this.products.find((product) => product.id === id)?.name || id
		},

		/**
		 * @param {string} status A subscription status.
		 * @return {string} Its label.
		 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-requests-access-for-their-own-application-req-devp-002
		 */
		statusLabel(status) {
			return subscriptionStatusLabel(status)
		},

		/**
		 * Read the products and the developer's applications.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-finds-the-published-api-products-req-devp-001
		 */
		async load() {
			this.loading = true
			try {
				const [products, applications] = await Promise.all([
					axios.get(generateUrl(`${BASE}/products`)),
					axios.get(generateUrl(`${BASE}/applications`)),
				])
				this.products = products.data?.results || []
				this.setApplications(applications.data?.results || [])
				this.refusal = ''
			} catch (e) {
				this.refusal = e?.response?.data?.error || t('integriq', 'The developer portal could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {Array<object>} applications The applications.
		 * @return {void}
		 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-requests-access-for-their-own-application-req-devp-002
		 */
		setApplications(applications) {
			const requests = {}
			for (const application of applications) {
				requests[application.id] = this.requests[application.id] || { product: null, tier: '' }
			}
			this.requests = requests
			this.applications = applications
		},

		/**
		 * Create an application the developer owns.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-requests-access-for-their-own-application-req-devp-002
		 */
		async createApplication() {
			const name = this.newName.trim()
			if (!name) {
				return
			}
			this.busy = true
			try {
				const { data } = await axios.post(generateUrl(`${BASE}/applications`), { name })
				this.setApplications([...this.applications, data])
				this.newName = ''
				showSuccess(t('integriq', 'Application created.'))
			} catch (e) {
				showError(e?.response?.data?.error || t('integriq', 'The application could not be created.'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Request access for an application to the chosen product and tier.
		 *
		 * @param {object} application The application.
		 * @return {Promise<void>}
		 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-requests-access-for-their-own-application-req-devp-002
		 */
		async requestAccess(application) {
			const request = this.requests[application.id]
			this.busy = true
			try {
				const response = await axios.post(
					generateUrl(`${BASE}/applications/${encodeURIComponent(application.id)}/subscriptions`),
					{ productId: request.product?.id, tier: request.tier || '' },
				)
				showSuccess(requestOutcome(response.status))
				await this.load()
			} catch (e) {
				showError(e?.response?.data?.error || t('integriq', 'The access request could not be saved.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.developerPortal {
	padding: calc(var(--default-grid-baseline) * 5);
}

.developerPortal__section {
	margin-bottom: calc(var(--default-grid-baseline) * 6);
}

.developerPortal__cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: calc(var(--default-grid-baseline) * 3);
	padding: 0;
	list-style: none;
}

.developerPortal__card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 3);
	margin-bottom: calc(var(--default-grid-baseline) * 3);
}

.developerPortal__row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}

.developerPortal__muted,
.developerPortal__hint {
	color: var(--color-text-maxcontrast);
}

.developerPortal__warning {
	color: var(--color-warning-text);
}
</style>

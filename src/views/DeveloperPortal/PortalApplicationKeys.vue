<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  PortalApplicationKeys: the API keys of one of the developer's own
  applications, on the developer portal page.

  It reads and writes the portal key routes
  (/api/portal/applications/{id}/keys), which check the developer group and
  that the developer owns the application. A generated key is in the create
  response only, so it is shown here once, until the panel is reloaded or
  another key is generated; the list shows each key by its label and first
  characters, with when it was last used.

  @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
-->
<template>
	<div class="portalKeys" data-testid="developer-portal-keys">
		<h5>{{ t('integriq', 'API keys') }}</h5>

		<p v-if="loadError" class="portalKeys__error" role="alert">
			{{ loadError }}
		</p>
		<p v-else-if="!loading && !apiKeys.length" class="portalKeys__muted">
			{{ t('integriq', 'This application has no API keys yet.') }}
		</p>

		<ul class="portalKeys__list">
			<li
				v-for="key in apiKeys"
				:key="key.id"
				class="portalKeys__item"
				data-testid="developer-portal-key">
				<span>
					<strong>{{ key.label }}</strong>
					<code>{{ key.keyPrefix }}…</code>
					<span class="portalKeys__muted">{{ usedText(key) }}</span>
					<span v-if="key.expired" class="portalKeys__muted">{{ t('integriq', 'expired') }}</span>
				</span>
				<NcButton
					variant="tertiary"
					data-testid="developer-portal-revoke-key"
					:disabled="busy"
					@click="revoke(key)">
					{{ t('integriq', 'Revoke') }}
				</NcButton>
			</li>
		</ul>

		<div
			v-if="newKey"
			class="portalKeys__new"
			data-testid="developer-portal-new-key">
			<p>
				{{ t('integriq', 'This is the only time the key is shown. Copy it now and store it in your system.') }}
			</p>
			<code class="portalKeys__key">{{ newKey }}</code>
		</div>

		<div class="portalKeys__row">
			<NcTextField
				v-model="label"
				data-testid="developer-portal-key-label"
				:label="t('integriq', 'Key label')"
				@keyup.enter="generate" />
			<NcButton
				data-testid="developer-portal-generate-key"
				:disabled="busy || !label.trim()"
				@click="generate">
				{{ t('integriq', 'Generate key') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcTextField } from '@nextcloud/vue'

/** The credential type of an API key (ConsumerCredentials::TYPE_API_KEY). */
const API_KEY = 'apiKey'

export default {
	name: 'PortalApplicationKeys',

	components: { NcButton, NcTextField },

	props: {
		/** The application's id. */
		applicationId: { type: String, required: true },
	},

	data() {
		return {
			credentials: [],
			loading: false,
			loadError: '',
			busy: false,
			label: '',
			newKey: '',
		}
	},

	computed: {
		/**
		 * The application's API keys; a pinned client certificate is the
		 * administrator's and is not listed here.
		 *
		 * @return {object[]} the keys
		 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
		 */
		apiKeys() {
			return this.credentials.filter((credential) => credential?.type === API_KEY)
		},

		/**
		 * The application's key routes.
		 *
		 * @return {string} the URL
		 * @spec exclude URL composition, presentation only
		 */
		keysUrl() {
			return generateUrl('/apps/integriq/api/portal/applications/{id}/keys', { id: this.applicationId })
		},
	},

	/**
	 * Load the application's keys.
	 *
	 * @return {void}
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
	 */
	created() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the application's credentials. A failure says so in the panel.
		 *
		 * @return {Promise<void>} resolves once read
		 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
		 */
		async load() {
			this.loading = true
			try {
				const response = await axios.get(this.keysUrl)
				this.credentials = Array.isArray(response?.data?.results) ? response.data.results : []
				this.loadError = ''
			} catch (e) {
				this.credentials = []
				this.loadError = e?.response?.data?.error || t('integriq', 'The keys of this application could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Generate a key and show it once.
		 *
		 * @return {Promise<void>} resolves once generated
		 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
		 */
		async generate() {
			const label = this.label.trim()
			if (!label || this.busy) return
			this.busy = true
			try {
				const response = await axios.post(this.keysUrl, { label })
				this.newKey = String(response?.data?.key ?? '')
				if (response?.data?.credential) {
					this.credentials = [...this.credentials, response.data.credential]
				}
				this.label = ''
			} catch (e) {
				showError(e?.response?.data?.error || t('integriq', 'The key could not be generated.'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Revoke one key. Calls with it are refused from then on.
		 *
		 * @param {object} key the key to revoke
		 * @return {Promise<void>} resolves once revoked
		 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
		 */
		async revoke(key) {
			if (this.busy) return
			this.busy = true
			try {
				await axios.delete(this.keysUrl + '/' + encodeURIComponent(key.id))
				this.credentials = this.credentials.filter((credential) => credential.id !== key.id)
				showSuccess(t('integriq', 'Key {label} is revoked.', { label: key.label }))
			} catch (e) {
				showError(e?.response?.data?.error || t('integriq', 'The key could not be revoked.'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * When a key was last used, or that it never was.
		 *
		 * @param {object} key the key
		 * @return {string} the text
		 * @spec exclude presentation text
		 */
		usedText(key) {
			return key.lastUsedAt
				? t('integriq', 'last used {date}', { date: key.lastUsedAt })
				: t('integriq', 'never used')
		},
	},
}
</script>

<style scoped>
.portalKeys {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-top: 8px;
}

.portalKeys__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.portalKeys__item {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.portalKeys__row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
}

.portalKeys__muted {
	color: var(--color-text-maxcontrast);
}

.portalKeys__error {
	color: var(--color-error-text, var(--color-error));
}

.portalKeys__new {
	padding: 8px;
	border: 1px solid var(--color-warning, var(--color-border));
	border-radius: var(--border-radius);
}

.portalKeys__key {
	overflow-wrap: anywhere;
	user-select: all;
}
</style>

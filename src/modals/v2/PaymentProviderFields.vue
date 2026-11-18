<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  PaymentProviderFields: the provider step of a `payment` source, shown by
  SourceFormFields when the source type is payment (messaging-payments-review
  design D3).

  The provider is picked from the bindings GET /api/payments/providers lists,
  never from a list written here. The key is the brokered credential the
  source form's broker block already picks. "Check provider" posts the draft
  configuration to POST /api/payments/providers/check, which runs the
  binding's read-only probe and answers whether the key is accepted and
  whether it is a test or a live key. The key itself is never shown: the
  answer does not carry it.

  Keys of the configuration this component does not know are kept.
-->
<template>
	<div class="cn-payment-provider-fields">
		<h3 class="cn-payment-provider-fields__heading">
			{{ t('integriq', 'Payment provider') }}
		</h3>

		<NcSelect
			inputId="cn-payment-provider"
			:inputLabel="t('integriq', 'Payment provider')"
			:modelValue="selectedProvider"
			:options="providerOptions"
			:loading="providersLoading"
			:clearable="false"
			:placeholder="t('integriq', 'Select a payment provider')"
			@update:modelValue="onProviderPick" />
		<p class="cn-payment-provider-fields__helper">
			{{ providerHelp }}
		</p>
		<p
			v-if="unknownProvider"
			class="cn-payment-provider-fields__note cn-payment-provider-fields__note--warn">
			{{
				t(
					'integriq',
					'"{provider}" is not a payment provider this instance has. Payments on this source are refused until you pick one.',
					{ provider: providerId },
				)
			}}
		</p>

		<div class="cn-payment-provider-fields__check">
			<NcButton
				variant="secondary"
				:disabled="checking"
				@click="checkProvider">
				{{ t('integriq', 'Check provider') }}
			</NcButton>
			<p
				class="cn-payment-provider-fields__result"
				:class="resultClass"
				role="status"
				aria-live="polite">
				{{ resultText }}
			</p>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcSelect } from '@nextcloud/vue'

export default {
	name: 'PaymentProviderFields',

	components: {
		NcButton,
		NcSelect,
	},

	props: {
		/** The source's `configuration` object. */
		configuration: { type: Object, default: () => ({}) },
	},

	emits: ['update:configuration'],

	data() {
		return {
			/** The bindings this instance carries, from the server. */
			providers: [],
			providersLoading: false,
			checking: false,
			/** The last check answer, or null before a check. */
			result: null,
		}
	},

	computed: {
		/**
		 * The provider the configuration names; absent means the log binding.
		 *
		 * @return {string} The provider id.
		 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-an-unknown-provider-value-is-refused-not-run-as-the-stub-req-prv-004
		 */
		providerId() {
			const provider = this.configuration?.provider
			return provider ? String(provider) : 'log'
		},

		/**
		 * The bindings as picker options.
		 *
		 * @return {object[]} The options.
		 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payment-source-moves-to-a-live-provider-with-a-checked-key-req-prv-003
		 */
		providerOptions() {
			return this.providers.map((provider) => ({
				id: provider.id,
				label: provider.label || provider.id,
				needsKey: provider.needsKey === true,
			}))
		},

		/**
		 * Whether the configured provider is one this instance does not carry.
		 *
		 * @return {boolean} True once the list loaded and the value is not on it.
		 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-an-unknown-provider-value-is-refused-not-run-as-the-stub-req-prv-004
		 */
		unknownProvider() {
			return (
				!this.providersLoading
				&& this.providerOptions.length > 0
				&& !this.providerOptions.some((option) => option.id === this.providerId)
			)
		},

		/**
		 * The option for the configured provider, shown as itself when unknown.
		 *
		 * @return {object} The selected option.
		 * @spec exclude select-value projection, presentation only
		 */
		selectedProvider() {
			return (
				this.providerOptions.find((option) => option.id === this.providerId) ?? {
					id: this.providerId,
					label: this.providerId,
					needsKey: false,
				}
			)
		},

		/**
		 * What the picked provider needs.
		 *
		 * @return {string} The helper text.
		 * @spec exclude presentation-only helper text
		 */
		providerHelp() {
			if (this.selectedProvider.needsKey) {
				return t(
					'integriq',
					'This provider charges real accounts with a key held by the credential broker. Turn on the brokered credential above, pick the key, then check the provider.',
				)
			}
			return t(
				'integriq',
				'The log provider charges nobody and needs no key. Pick a live provider to take real payments.',
			)
		},

		/**
		 * The last check answer, as one sentence.
		 *
		 * @return {string} The text, empty before a check.
		 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payment-source-moves-to-a-live-provider-with-a-checked-key-req-prv-003
		 */
		resultText() {
			if (this.checking) {
				return t('integriq', 'Checking the provider…')
			}
			if (!this.result) {
				return ''
			}
			if (!this.result.ok) {
				return t('integriq', 'The provider refused: {reason}', {
					reason: this.result.message || '',
				})
			}
			if (this.result.mode === 'live') {
				return t('integriq', 'The key is accepted. It is a live key: payments are real.')
			}
			if (this.result.mode === 'test') {
				return t('integriq', 'The key is accepted. It is a test key: payments are not real.')
			}
			return t('integriq', 'The log provider is ready. It charges nobody.')
		},

		/**
		 * The class of the result line.
		 *
		 * @return {string} The modifier class.
		 * @spec exclude presentation-only class
		 */
		resultClass() {
			if (!this.result || this.checking) return ''
			return this.result.ok
				? 'cn-payment-provider-fields__result--ok'
				: 'cn-payment-provider-fields__result--refused'
		},
	},

	/**
	 * Load the bindings so the picker resolves the current value.
	 *
	 * @return {void}
	 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payment-source-moves-to-a-live-provider-with-a-checked-key-req-prv-003
	 */
	created() {
		this.fetchProviders()
	},

	methods: {
		t,

		/**
		 * Write the picked provider; an earlier check no longer applies.
		 *
		 * @param {object|null} option The picked option.
		 * @return {void}
		 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payment-source-moves-to-a-live-provider-with-a-checked-key-req-prv-003
		 */
		onProviderPick(option) {
			if (!option?.id) return
			this.result = null
			this.$emit('update:configuration', {
				...(this.configuration ?? {}),
				provider: String(option.id),
			})
		},

		/**
		 * Ask the instance which payment bindings it carries. Soft-fails to an empty list.
		 *
		 * @return {Promise<void>} Resolves once loaded.
		 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payment-source-moves-to-a-live-provider-with-a-checked-key-req-prv-003
		 */
		async fetchProviders() {
			this.providersLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/integriq/api/payments/providers'),
				)
				const providers = response?.data?.providers
				this.providers = Array.isArray(providers) ? providers : []
			} catch (err) {
				this.providers = []
				// eslint-disable-next-line no-console
				console.warn('[PaymentProviderFields] provider fetch failed', err)
			} finally {
				this.providersLoading = false
			}
		},

		/**
		 * Check the draft configuration with the binding's read-only probe.
		 *
		 * @return {Promise<void>} Resolves once the answer is shown.
		 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payment-source-moves-to-a-live-provider-with-a-checked-key-req-prv-003
		 */
		async checkProvider() {
			this.checking = true
			try {
				const response = await axios.post(
					generateUrl('/apps/integriq/api/payments/providers/check'),
					{ configuration: { ...(this.configuration ?? {}), provider: this.providerId } },
				)
				this.result = response?.data ?? null
			} catch (error) {
				this.result = {
					ok: false,
					message: error?.response?.data?.message || error?.message || '',
				}
			} finally {
				this.checking = false
			}
		},
	},
}
</script>

<style scoped>
.cn-payment-provider-fields {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, var(--border-radius));
}

.cn-payment-provider-fields__heading {
	margin: 0;
	font-size: 1em;
	font-weight: bold;
}

.cn-payment-provider-fields__helper,
.cn-payment-provider-fields__note {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.cn-payment-provider-fields__note--warn {
	color: var(--color-warning-text, var(--color-error));
}

.cn-payment-provider-fields__check {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.cn-payment-provider-fields__result {
	margin: 0;
}

.cn-payment-provider-fields__result--ok {
	color: var(--color-success-text, var(--color-main-text));
}

.cn-payment-provider-fields__result--refused {
	color: var(--color-error-text, var(--color-error));
}
</style>

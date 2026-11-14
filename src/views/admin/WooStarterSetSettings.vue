<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="integriq-admin__section" data-testid="admin-woo-starter-set">
		<h3>{{ t('integriq', 'Woo starter set') }}</h3>
		<p class="integriq-admin__hint">
			{{
				t(
					'integriq',
					'Create one document type in the catalogue for every Woo information category that does not have one yet. Existing types are not changed.',
				)
			}}
		</p>

		<NcButton
			variant="primary"
			:disabled="running"
			data-testid="admin-woo-starter-set-run"
			@click="run">
			{{ running ? t('integriq', 'Creating the document types…') : t('integriq', 'Create the Woo starter set') }}
		</NcButton>

		<p v-if="result" class="integriq-admin__hint" role="status" data-testid="admin-woo-starter-set-result">
			{{
				t('integriq', 'Created: {created}. Already present: {skipped}.', {
					created: result.created,
					skipped: result.skipped,
				})
			}}
		</p>

		<div v-if="error" class="integriq-admin__action-error" role="alert">
			{{ error }}
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'

/**
 * Admin button that runs the Woo starter set and shows how many document
 * types it created and how many categories already had one.
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-a-woo-starter-set-gives-one-type-per-woo-information-category-req-zcs-003
 */
export default {
	name: 'WooStarterSetSettings',

	components: {
		NcButton,
	},

	data() {
		return {
			running: false,
			result: null,
			error: '',
		}
	},

	methods: {
		/**
		 * Run the starter set once and show its counts or why it refused.
		 *
		 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-a-woo-starter-set-gives-one-type-per-woo-information-category-req-zcs-003
		 */
		async run() {
			this.running = true
			this.result = null
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl('/apps/integriq/api/catalogi/woo-starter-set'),
				)
				this.result = {
					created: Number(data.created ?? 0),
					skipped: Number(data.skipped ?? 0),
				}
			} catch (failure) {
				this.error = this.refusal(failure?.response?.data?.code)
			} finally {
				this.running = false
			}
		},

		/**
		 * The message for a refusal code.
		 *
		 * @param {string|undefined} code The problem code the server answered.
		 * @return {string} The translated message.
		 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-a-woo-starter-set-gives-one-type-per-woo-information-category-req-zcs-003
		 */
		refusal(code) {
			if (code === 'tooi-scheme-missing') {
				return this.t('integriq', 'The list of Woo information categories cannot be read. Nothing was created.')
			}
			if (code === 'catalogue-not-configured') {
				return this.t('integriq', 'No catalogue is configured yet. Set one up first.')
			}
			return this.t('integriq', 'The Woo starter set could not be created.')
		},
	},
}
</script>

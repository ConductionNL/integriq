<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  LtiPlatformDetails: the six values a tool's administrator enters at the
  vendor, on the LTI tool page, each with a copy action.

  Mounted as a body widget on LtiToolDetail and reads the loaded tool off
  `cnSectionContext`, like SourceRunSummaryWidget. The values come from
  ltiPlatformDetails#show, so every URL is one this instance answers on and
  the issuer is the one the launch signs with.

  @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-an-administrator-can-give-a-tool-the-platform-details-it-needs-req-ltil-004
-->
<template>
	<section class="ltiPlatformDetails" data-testid="lti-platform-details">
		<h3>{{ t('integriq', 'Platform details for the tool') }}</h3>
		<p class="ltiPlatformDetails__intro">
			{{
				t(
					'integriq',
					"Enter these values in the tool's platform registration at the vendor.",
				)
			}}
		</p>

		<p v-if="loading">
			{{ t('integriq', 'Reading the platform details') }}
		</p>
		<p v-else-if="error" class="ltiPlatformDetails__error" role="alert">
			{{ error }}
		</p>
		<dl v-else class="ltiPlatformDetails__list">
			<div
				v-for="row in rows"
				:key="row.key"
				class="ltiPlatformDetails__row"
				data-testid="lti-platform-detail"
				:data-key="row.key">
				<dt>{{ row.label }}</dt>
				<dd>
					<span v-if="row.empty" class="ltiPlatformDetails__empty">
						{{ row.empty }}
					</span>
					<code v-else class="ltiPlatformDetails__value">{{
						row.text
					}}</code>
					<NcButton
						variant="tertiary"
						:aria-label="
							t('integriq', 'Copy {label}', { label: row.label })
						"
						@click="copy(row)">
						{{
							copied === row.key
								? t('integriq', 'Copied')
								: t('integriq', 'Copy')
						}}
					</NcButton>
				</dd>
			</div>
		</dl>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'LtiPlatformDetails',

	components: { NcButton },

	inject: {
		cnSectionContext: {
			from: 'cnSectionContext',
			default: () => ({ value: { objectId: null, object: null } }),
		},
	},

	data() {
		return {
			loading: false,
			error: '',
			details: null,
			copied: '',
		}
	},

	computed: {
		/**
		 * The tool registration's id for the endpoint URL.
		 *
		 * @return {string}
		 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-an-administrator-can-give-a-tool-the-platform-details-it-needs-req-ltil-004
		 */
		objectId() {
			const ctx = this.cnSectionContext
			const value = ctx && ctx.value !== undefined ? ctx.value : ctx
			const object = (value && value.object) || {}
			return String(
				(value && value.objectId)
					|| object['@self']?.id
					|| object.uuid
					|| object.id
					|| '',
			)
		},

		/**
		 * The six rows, in the order a vendor form asks for them.
		 *
		 * @return {Array<{key: string, label: string, text: string, empty: string}>}
		 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-an-administrator-can-give-a-tool-the-platform-details-it-needs-req-ltil-004
		 */
		rows() {
			const details = this.details || {}
			const deploymentIds = Array.isArray(details.deploymentIds)
				? details.deploymentIds
				: []
			return [
				{
					key: 'issuer',
					label: t('integriq', 'Issuer'),
					text: details.issuer || '',
				},
				{
					key: 'clientId',
					label: t('integriq', 'Client ID'),
					text: details.clientId || '',
				},
				{
					key: 'deploymentIds',
					label: t('integriq', 'Deployment IDs'),
					text: deploymentIds.join('\n'),
					empty:
						deploymentIds.length === 0
							? t(
									'integriq',
									'No deployment yet. Add one to place this tool.',
								)
							: '',
				},
				{
					key: 'authorizationUrl',
					label: t('integriq', 'Authorization URL'),
					text: details.authorizationUrl || '',
				},
				{
					key: 'tokenUrl',
					label: t('integriq', 'Token URL'),
					text: details.tokenUrl || '',
				},
				{
					key: 'keySetUrl',
					label: t('integriq', 'Key set URL'),
					text: details.keySetUrl || '',
				},
			].map((row) => ({ empty: '', ...row }))
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/**
			 * Read the details once the tool is known.
			 *
			 * @return {void}
			 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-an-administrator-can-give-a-tool-the-platform-details-it-needs-req-ltil-004
			 */
			handler() {
				if (this.objectId) {
					this.load()
				}
			},
		},
	},

	methods: {
		t,

		/**
		 * Ask this instance for the tool's platform details.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-an-administrator-can-give-a-tool-the-platform-details-it-needs-req-ltil-004
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/integriq/api/lti/tools/${encodeURIComponent(this.objectId)}/platform-details`,
					),
				)
				this.details = data || {}
			} catch (e) {
				this.details = null
				this.error =
					e?.response?.data?.error
					|| t('integriq', 'The platform details could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Copy one row's exact value.
		 *
		 * @param {{key: string, text: string}} row the row
		 * @return {Promise<void>}
		 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-an-administrator-can-give-a-tool-the-platform-details-it-needs-req-ltil-004
		 */
		async copy(row) {
			try {
				await navigator.clipboard.writeText(row.text)
				this.copied = row.key
			} catch {
				this.copied = ''
			}
		},
	},
}
</script>

<style scoped>
.ltiPlatformDetails__intro {
	color: var(--color-text-maxcontrast);
}

.ltiPlatformDetails__list {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 2);
}

.ltiPlatformDetails__row dd {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	margin: 0;
}

.ltiPlatformDetails__row dt {
	font-weight: bold;
}

.ltiPlatformDetails__value {
	white-space: pre-wrap;
	word-break: break-all;
}

.ltiPlatformDetails__empty {
	color: var(--color-text-maxcontrast);
}

.ltiPlatformDetails__error {
	color: var(--color-error-text);
}
</style>

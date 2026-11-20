<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  CloudEventReceiverUrl: the URL an outside system sends its CloudEvents to
  (cloudevents-receiver task 1.4, REQ-017), with a copy action.

  Each source has its own receiver, `POST /api/cloudevents/sources/{source id}`,
  checked by that source's webhook connection. On a source page the manifest
  passes `sourceId: "@objectId"`, and the widget shows that source's exact URL.
  Without a source id (the endpoint page) it shows the URL pattern and says
  where the exact URL is found.

  @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
-->
<template>
	<section class="cloudEventReceiverUrl" data-testid="cloudevent-receiver-url">
		<h3>{{ t('integriq', 'CloudEvents receiver') }}</h3>
		<p class="cloudEventReceiverUrl__intro">
			{{
				hasSource
					? t(
							'integriq',
							'An outside system sends CloudEvents for this source to this URL, signed with the source\'s webhook connection.',
						)
					: t(
							'integriq',
							'Each source has its own receiver URL for CloudEvents. Open the source to copy its exact URL.',
						)
			}}
		</p>
		<div class="cloudEventReceiverUrl__row">
			<code class="cloudEventReceiverUrl__value" data-testid="cloudevent-receiver-url-value">{{ url }}</code>
			<NcButton
				v-if="hasSource"
				variant="tertiary"
				:aria-label="t('integriq', 'Copy the receiver URL')"
				@click="copy">
				{{ copied ? t('integriq', 'Copied') : t('integriq', 'Copy') }}
			</NcButton>
		</div>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'

/**
 * The receiver route without its `{source}` segment, as appinfo/routes.php declares it.
 */
const RECEIVER_BASE = '/apps/integriq/api/cloudevents/sources/'

export default {
	name: 'CloudEventReceiverUrl',

	components: { NcButton },

	props: {
		/** The source whose receiver URL to show; empty shows the pattern. */
		sourceId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			copied: false,
		}
	},

	computed: {
		/**
		 * Whether a source is known, so its exact URL can be shown.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
		 */
		hasSource() {
			return /^[A-Za-z0-9-]+$/.test(this.sourceId || '')
		},

		/**
		 * The absolute receiver URL of the source, or the pattern without one.
		 *
		 * @return {string}
		 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
		 */
		url() {
			const path = this.hasSource
				? generateUrl(RECEIVER_BASE + encodeURIComponent(this.sourceId.toLowerCase()))
				: generateUrl(RECEIVER_BASE) + '{' + t('integriq', 'source id') + '}'
			return window.location.origin + path
		},
	},

	watch: {
		/**
		 * Forget a previous copy when another source is shown.
		 *
		 * @return {void}
		 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
		 */
		sourceId() {
			this.copied = false
		},
	},

	methods: {
		t,

		/**
		 * Copy the exact receiver URL.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
		 */
		async copy() {
			try {
				await navigator.clipboard.writeText(this.url)
				this.copied = true
			} catch {
				this.copied = false
			}
		},
	},
}
</script>

<style scoped>
.cloudEventReceiverUrl__intro {
	color: var(--color-text-maxcontrast);
}

.cloudEventReceiverUrl__row {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.cloudEventReceiverUrl__value {
	word-break: break-all;
}
</style>

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  SourceHostKeyPanel: the host key of an SFTP source, on the source page.

  Mounted as a body widget on SourceDetail and reads the loaded source off
  `cnSectionContext`, like DocumentGenerationSourcePanel. It renders nothing
  for any other kind of source. "Check the host key" asks the server for the
  fingerprint it presents (GET /api/sources/{id}/host-key; no credential is
  sent). Until the administrator ticks that they checked it with the partner,
  it cannot be pinned; pinning (POST) saves it only when the server still
  presents that same key, and says so when it does not.

  @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
-->
<template>
	<section
		v-if="isSftp"
		class="hostKey"
		data-testid="source-host-key-panel">
		<h3>{{ t('integriq', 'Host key') }}</h3>

		<p v-if="pinned" data-testid="source-host-key-pinned">
			{{ t('integriq', 'Pinned fingerprint') }}: <code>{{ pinned }}</code>
		</p>
		<p v-else data-testid="source-host-key-unpinned">
			{{
				t(
					'integriq',
					'No host key is pinned yet, so this source does not connect. Check the key the server presents, compare it with the fingerprint the partner gave you, and pin it.',
				)
			}}
		</p>

		<div>
			<NcButton
				:disabled="checking || !objectId"
				data-testid="source-host-key-check"
				@click="check">
				{{ t('integriq', 'Check the host key') }}
			</NcButton>
		</div>

		<template v-if="presented">
			<p data-testid="source-host-key-presented">
				{{ t('integriq', 'The server presents') }}: <code>{{ presented }}</code>
			</p>
			<p v-if="matches" data-testid="source-host-key-matches">
				{{ t('integriq', 'This is the pinned key.') }}
			</p>
			<template v-else>
				<p v-if="pinned" class="hostKey__warning" role="alert">
					{{
						t(
							'integriq',
							'This is not the pinned key, so every connection is refused. Pin it only when the partner confirms they changed their key.',
						)
					}}
				</p>
				<NcCheckboxRadioSwitch
					:modelValue="confirmed"
					data-testid="source-host-key-confirm"
					@update:modelValue="(value) => (confirmed = value === true)">
					{{ t('integriq', 'I checked this fingerprint with the partner') }}
				</NcCheckboxRadioSwitch>
				<div>
					<NcButton
						variant="primary"
						:disabled="!confirmed || pinning"
						data-testid="source-host-key-pin"
						@click="pin">
						{{ t('integriq', 'Pin this host key') }}
					</NcButton>
				</div>
			</template>
		</template>

		<p v-if="error" class="hostKey__error" role="alert">
			{{ error }}
		</p>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'

export default {
	name: 'SourceHostKeyPanel',

	components: { NcButton, NcCheckboxRadioSwitch },

	inject: {
		cnSectionContext: {
			from: 'cnSectionContext',
			default: () => ({ value: { objectId: null, object: null } }),
		},
	},

	data() {
		return {
			checking: false,
			pinning: false,
			presented: '',
			pinnedNow: '',
			confirmed: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The loaded source object.
		 *
		 * @return {object} the source
		 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
		 */
		source() {
			const ctx = this.cnSectionContext
			const value = ctx && ctx.value !== undefined ? ctx.value : ctx
			return (value && value.object) || {}
		},

		/**
		 * The source's id for the endpoint URLs.
		 *
		 * @return {string} the id, or ''
		 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
		 */
		objectId() {
			const ctx = this.cnSectionContext
			const value = ctx && ctx.value !== undefined ? ctx.value : ctx
			return String(
				(value && value.objectId)
					|| this.source['@self']?.id
					|| this.source.uuid
					|| this.source.id
					|| '',
			)
		},

		/**
		 * Whether this is an SFTP source, the only kind with a host key.
		 *
		 * @return {boolean} true for type sftp
		 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
		 */
		isSftp() {
			return String(this.source.type ?? '').toLowerCase() === 'sftp'
		},

		/**
		 * The pinned fingerprint: the one just pinned, else the stored one.
		 *
		 * @return {string} the fingerprint, or ''
		 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
		 */
		pinned() {
			return this.pinnedNow || String(this.source.configuration?.hostKeyFingerprint ?? '')
		},

		/**
		 * Whether the presented key is the pinned one, as the server compared them.
		 *
		 * @return {boolean} true when they match
		 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
		 */
		matches() {
			return this.presented !== '' && this.presented === this.pinnedNow
		},
	},

	methods: {
		t,

		/**
		 * Ask the server which host key it presents.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
		 */
		async check() {
			this.checking = true
			this.error = ''
			this.confirmed = false
			try {
				const { data } = await axios.get(this.url())
				this.presented = String(data?.presented ?? '')
				this.pinnedNow = data?.matches === true ? this.presented : String(data?.pinned ?? '')
			} catch (e) {
				this.presented = ''
				this.error = e?.response?.data?.error || t('integriq', 'The host key could not be read.')
			} finally {
				this.checking = false
			}
		},

		/**
		 * Pin the presented key; the server refuses it when it presents another key by now.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
		 */
		async pin() {
			this.pinning = true
			this.error = ''
			try {
				const { data } = await axios.post(this.url(), { fingerprint: this.presented })
				this.pinnedNow = String(data?.pinned ?? this.presented)
				this.confirmed = false
			} catch (e) {
				this.error = e?.response?.data?.error || t('integriq', 'The host key was not pinned.')
			} finally {
				this.pinning = false
			}
		},

		/**
		 * The host key endpoint of this source.
		 *
		 * @return {string} the URL
		 * @spec exclude trivial URL builder
		 */
		url() {
			return generateUrl(`/apps/integriq/api/sources/${encodeURIComponent(this.objectId)}/host-key`)
		},
	},
}
</script>

<style scoped>
.hostKey {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.hostKey code {
	overflow-wrap: anywhere;
}

.hostKey__warning,
.hostKey__error {
	color: var(--color-error-text);
}
</style>

<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="integriq-admin__section" data-testid="admin-berichtenbox">
		<h3>{{ t('integriq', 'MijnOverheid Berichtenbox') }}</h3>
		<p class="integriq-admin__hint">
			{{
				t(
					'integriq',
					"Letters go to the citizen's Berichtenbox through your ebMS adapter. Before each letter, integriq asks Logius whether the citizen takes letters from you. Logius gives you the CPA values when your connection is set up.",
				)
			}}
		</p>

		<p v-if="loading" class="integriq-admin__hint">
			{{ t('integriq', 'Loading the Berichtenbox settings…') }}
		</p>

		<template v-else>
			<p class="integriq-admin__hint" data-testid="admin-berichtenbox-live">
				{{
					live
						? t('integriq', 'Live: letters are sent to Logius.')
						: t(
								'integriq',
								'Not live: every letter is simulated until the Berichtenbox is enabled in the connector catalog.',
							)
				}}
			</p>

			<NcTextField
				v-for="field in fields"
				:key="field.key"
				v-model="form[field.key]"
				:label="field.label"
				:helperText="fieldErrors[field.key] || field.help"
				:error="!!fieldErrors[field.key]"
				:data-testid="'admin-berichtenbox-' + field.key" />

			<h4>{{ t('integriq', 'BerichtType per letter category') }}</h4>
			<NcTextField
				v-for="category in categories"
				:key="category.key"
				v-model="berichtTypes[category.key]"
				:label="category.label"
				:helperText="
					t(
						'integriq',
						'At most 8 characters, as made in the Leveranciersportaal',
					)
				"
				:data-testid="'admin-berichtenbox-type-' + category.key" />
			<p
				v-if="fieldErrors.berichtTypes"
				class="integriq-admin__action-error"
				role="alert">
				{{ fieldErrors.berichtTypes }}
			</p>

			<h4>{{ t('integriq', 'PKIoverheid certificate') }}</h4>
			<p
				class="integriq-admin__hint"
				data-testid="admin-berichtenbox-certificate-state">
				{{ certificateState }}
			</p>
			<label for="berichtenbox-certificate">{{
				t('integriq', 'Certificate (PEM)')
			}}</label>
			<textarea
				id="berichtenbox-certificate"
				v-model="certificate.certificatePem"
				class="integriq-admin__dso-pki-textarea"
				data-testid="admin-berichtenbox-certificate" />
			<label for="berichtenbox-key">{{
				t('integriq', 'Private key (PEM)')
			}}</label>
			<textarea
				id="berichtenbox-key"
				v-model="certificate.privateKeyPem"
				class="integriq-admin__dso-pki-textarea"
				data-testid="admin-berichtenbox-key" />
			<NcPasswordField
				v-model="certificate.passphrase"
				:label="t('integriq', 'Key passphrase (optional)')" />
			<label for="berichtenbox-ca">{{
				t(
					'integriq',
					"PKIoverheid CA chain that signed Logius' server certificate (PEM, optional)",
				)
			}}</label>
			<textarea
				id="berichtenbox-ca"
				v-model="certificate.caBundlePem"
				class="integriq-admin__dso-pki-textarea"
				data-testid="admin-berichtenbox-ca" />
			<p
				v-if="fieldErrors.certificate"
				class="integriq-admin__action-error"
				role="alert">
				{{ fieldErrors.certificate }}
			</p>

			<NcPasswordField
				v-model="adapterToken"
				:label="t('integriq', 'ebMS adapter token (optional)')"
				:placeholder="
					adapterTokenConfigured
						? t('integriq', 'Configured (leave blank to keep unchanged)')
						: t('integriq', 'Not configured')
				" />

			<div class="integriq-admin__matrix-actions">
				<NcButton
					variant="primary"
					:disabled="saving"
					data-testid="admin-berichtenbox-save"
					@click="save">
					{{ saving ? t('integriq', 'Saving…') : t('integriq', 'Save') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcPasswordField, NcTextField } from '@nextcloud/vue'

/**
 * The slug of the one Berichtenbox source an organisation connects with (D1).
 */
const SLUG = 'berichtenbox'

/**
 * Admin editor for the MijnOverheid Berichtenbox source: the sender OIN, the
 * CPA values, the ebMS adapter, the BerichtType per category and the
 * PKIoverheid certificate. The certificate and key are sent once and stored
 * encrypted; the page never gets them back, only their subject and expiry.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-transport-certificate-is-held-encrypted-and-named-by-reference-req-dpa-004
 */
export default {
	name: 'BerichtenboxSettings',

	components: {
		NcButton,
		NcPasswordField,
		NcTextField,
	},

	data() {
		return {
			loading: true,
			saving: false,
			live: false,
			source: null,
			form: {
				senderOin: '',
				adapterUrl: '',
				cpaId: '',
				fromPartyId: '',
				toPartyId: '',
				service: '',
				wusEndpoint: '',
			},

			berichtTypes: {
				besluit: '',
				'case-update': '',
				statutory: '',
				service: '',
			},

			certificate: {
				certificatePem: '',
				privateKeyPem: '',
				passphrase: '',
				caBundlePem: '',
			},

			adapterToken: '',
			adapterTokenConfigured: false,
			fieldErrors: {},
		}
	},

	computed: {
		/** @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-live-binding-speaks-the-interface-logius-publishes-req-dpa-008 */
		fields() {
			return [
				{
					key: 'senderOin',
					label: this.t('integriq', 'Sender OIN'),
					help: this.t(
						'integriq',
						'20 digits, the same as in the certificate',
					),
				},
				{
					key: 'adapterUrl',
					label: this.t('integriq', 'ebMS adapter URL'),
					help: this.t(
						'integriq',
						'For ebms-admin, ending in /service/rest/v19/ebms',
					),
				},
				{ key: 'cpaId', label: this.t('integriq', 'CPA id'), help: '' },
				{
					key: 'fromPartyId',
					label: this.t('integriq', 'Your party id'),
					help: this.t('integriq', 'Leave empty to use the sender OIN'),
				},
				{
					key: 'toPartyId',
					label: this.t('integriq', 'Logius party id'),
					help: '',
				},
				{
					key: 'service',
					label: this.t('integriq', 'CPA service'),
					help: '',
				},
				{
					key: 'wusEndpoint',
					label: this.t('integriq', 'Subscription check endpoint'),
					help: this.t(
						'integriq',
						'The ValidateAbonnementen URL, starting with https://',
					),
				},
			]
		},

		/** @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-letter-is-built-to-the-official-schema-and-its-limits-req-dpa-010 */
		categories() {
			return [
				{ key: 'besluit', label: this.t('integriq', 'Besluit') },
				{ key: 'case-update', label: this.t('integriq', 'Case update') },
				{ key: 'statutory', label: this.t('integriq', 'Statutory notice') },
				{ key: 'service', label: this.t('integriq', 'Service message') },
			]
		},

		/** @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-transport-certificate-is-held-encrypted-and-named-by-reference-req-dpa-004 */
		certificateState() {
			const certificate = (this.source && this.source.certificate) || {
				configured: false,
			}
			if (!certificate.configured) {
				return this.t(
					'integriq',
					'No certificate stored: every letter is refused.',
				)
			}
			if (!certificate.usable) {
				return this.t(
					'integriq',
					'The stored certificate cannot be used: {error}',
					{ error: certificate.error },
				)
			}
			return this.t(
				'integriq',
				'Stored: {subject}, OIN {oin}, valid until {date}. Paste a new one to replace it.',
				{
					subject: certificate.subject,
					oin: certificate.serialNumber || '-',
					date: (certificate.validTo || '').slice(0, 10),
				},
			)
		},
	},

	/** @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-transport-certificate-is-held-encrypted-and-named-by-reference-req-dpa-004 */
	async mounted() {
		try {
			const { data } = await axios.get(
				generateUrl('/apps/integriq/api/admin/berichtenbox/{slug}', {
					slug: SLUG,
				}),
			)
			this.apply(data)
		} catch (e) {
			if (e.response && e.response.status === 404) {
				this.live = !!(e.response.data && e.response.data.live)
			} else {
				showError(
					this.t('integriq', 'Failed to load the Berichtenbox settings.'),
				)
			}
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * Show the source as the server describes it.
		 *
		 * @param {object} data The response.
		 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-transport-certificate-is-held-encrypted-and-named-by-reference-req-dpa-004
		 */
		apply(data) {
			this.live = !!data.live
			this.source = data.source
			if (!data.source) {
				return
			}
			for (const key of Object.keys(this.form)) {
				this.form[key] = data.source[key] || ''
			}
			this.berichtTypes = {
				...this.berichtTypes,
				...(data.source.berichtTypes || {}),
			}
			this.adapterTokenConfigured = !!data.source.adapterToken
		},

		/** @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-transport-certificate-is-held-encrypted-and-named-by-reference-req-dpa-004 */
		async save() {
			this.saving = true
			this.fieldErrors = {}
			const payload = { ...this.form, berichtTypes: this.berichtTypes }
			if (this.certificate.certificatePem.trim() !== '') {
				payload.certificate = { ...this.certificate }
			}
			if (this.adapterToken !== '') {
				payload.adapterToken = this.adapterToken
			}
			try {
				const { data } = await axios.put(
					generateUrl('/apps/integriq/api/admin/berichtenbox/{slug}', {
						slug: SLUG,
					}),
					payload,
				)
				this.apply(data)
				this.certificate = {
					certificatePem: '',
					privateKeyPem: '',
					passphrase: '',
					caBundlePem: '',
				}
				this.adapterToken = ''
				showSuccess(this.t('integriq', 'Berichtenbox settings saved.'))
				for (const warning of data.warnings || []) {
					showWarning(warning)
				}
			} catch (e) {
				const body = (e.response && e.response.data) || {}
				this.fieldErrors = body.fieldErrors || {}
				showError(
					Array.isArray(body.errors)
						? body.errors.join(' ')
						: this.t(
								'integriq',
								'Failed to save the Berichtenbox settings.',
							),
				)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

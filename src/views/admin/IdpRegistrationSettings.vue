<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="integriq-admin__section" data-testid="admin-idp-registrations">
		<h3>{{ t('integriq', 'Authenticatie') }}</h3>
		<p class="integriq-admin__hint">
			{{ t('integriq', 'Each organisation logs residents and businesses in through DigiD, eHerkenning or eIDAS with its own registration at a broker or identity provider.') }}
		</p>

		<NcNoteCard
			v-if="!loading && !keepiqAvailable"
			type="warning"
			data-testid="idp-registrations-keepiq-missing">
			{{ t('integriq', 'Identity provider keys live in Keepiq. No registration can log anyone in until Keepiq is enabled.') }}
		</NcNoteCard>
		<NcNoteCard
			v-if="!loading && !brokerEnabled"
			type="warning"
			data-testid="idp-registrations-flag-off">
			{{ t('integriq', 'The login broker is switched off. No registration can log anyone in until it is switched on.') }}
		</NcNoteCard>

		<p v-if="loading" class="integriq-admin__hint">
			{{ t('integriq', 'Loading the registrations…') }}
		</p>
		<p v-else-if="loadError" class="integriq-admin__action-error" role="alert">
			{{ loadError }}
		</p>

		<NcEmptyContent
			v-else-if="registrations.length === 0"
			:name="t('integriq', 'No registrations yet')"
			:description="t('integriq', 'A registration connects one organisation to one identity provider: where its metadata lives, how its attributes map and which credentials sign and decrypt.')"
			data-testid="idp-registrations-empty">
			<template #action>
				<NcButton variant="primary" data-testid="idp-registrations-add-empty" @click="add">
					{{ t('integriq', 'Add registration') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<template v-else>
			<table class="idp-registrations" data-testid="idp-registrations-table">
				<thead>
					<tr>
						<th scope="col">{{ t('integriq', 'Organisation') }}</th>
						<th scope="col">{{ t('integriq', 'Provider') }}</th>
						<th scope="col">{{ t('integriq', 'Protocol') }}</th>
						<th scope="col">{{ t('integriq', 'Status') }}</th>
						<th scope="col">{{ t('integriq', 'Metadata URL') }}</th>
						<th scope="col">{{ t('integriq', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in registrations" :key="row.id" :data-testid="`idp-registration-row-${row.id}`">
						<td>{{ organisationName(row) }}</td>
						<td>{{ labelOf(providers, row.provider) }}</td>
						<td>{{ labelOf(kinds, row.kind) }}</td>
						<td>
							<span data-testid="idp-registration-status">{{ statusText(row.status, row.reasons) }}</span>
							<ul v-if="results[row.id]" class="idp-registrations__checks" data-testid="idp-registration-checks">
								<li
									v-for="check in results[row.id]"
									:key="check.check"
									:class="check.passed ? 'idp-registrations__passed' : 'idp-registrations__failed'">
									{{ check.passed
										? t('integriq', '{check}: passed', { check: checkLabel(check.check) })
										: t('integriq', '{check}: failed', { check: checkLabel(check.check) }) }}
								</li>
							</ul>
						</td>
						<td class="idp-registrations__url">
							<code>{{ row.metadataUrl }}</code>
							<NcButton
								variant="tertiary"
								:aria-label="t('integriq', 'Copy metadata URL')"
								data-testid="idp-registration-copy"
								@click="copy(row.metadataUrl)">
								{{ t('integriq', 'Copy') }}
							</NcButton>
						</td>
						<td class="idp-registrations__actions">
							<NcButton :disabled="busy === row.id" data-testid="idp-registration-test" @click="test(row)">
								{{ t('integriq', 'Test') }}
							</NcButton>
							<NcButton :disabled="busy === row.id" data-testid="idp-registration-toggle" @click="toggle(row)">
								{{ row.enabled ? t('integriq', 'Disable') : t('integriq', 'Enable') }}
							</NcButton>
							<NcButton :disabled="busy === row.id" data-testid="idp-registration-edit" @click="edit(row)">
								{{ t('integriq', 'Edit') }}
							</NcButton>
							<NcButton
								:href="row.metadataUrl"
								target="_blank"
								data-testid="idp-registration-download">
								{{ t('integriq', 'Download metadata') }}
							</NcButton>
							<NcButton
								variant="error"
								:disabled="busy === row.id"
								data-testid="idp-registration-delete"
								@click="deleting = row">
								{{ t('integriq', 'Delete') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<div class="integriq-admin__matrix-actions">
				<NcButton variant="primary" data-testid="idp-registrations-add" @click="add">
					{{ t('integriq', 'Add registration') }}
				</NcButton>
			</div>
		</template>

		<IdpRegistrationModal
			:open="editing !== null"
			:initial="editing"
			:organisations="organisations"
			:credentials="credentials"
			:credentialsLoading="credentialsLoading"
			:saving="saving"
			:error="saveError"
			@close="editing = null"
			@save="save" />

		<IdpRegistrationDeleteDialog
			:open="deleting !== null"
			:organisation="deleting ? organisationName(deleting) : ''"
			:provider="deleting ? labelOf(providers, deleting.provider) : ''"
			:busy="busy !== null"
			@close="deleting = null"
			@confirm="remove" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcNoteCard } from '@nextcloud/vue'
import IdpRegistrationDeleteDialog from '../../dialogs/IdpRegistrationDeleteDialog.vue'
import IdpRegistrationModal from '../../modals/IdpRegistrationModal.vue'
import {
	checkLabel,
	credentialOptions,
	CREDENTIALS_URL,
	draftFromObject,
	emptyDraft,
	kindOptions,
	labelOf,
	objectUrl,
	organisationOptions,
	ORGANISATIONS_URL,
	OVERVIEW_URL,
	payloadFromDraft,
	providerOptions,
	REGISTRATIONS_URL,
	statusText,
	testUrl,
} from './idpRegistration.js'

/**
 * The Authenticatie section: every identity provider registration with its
 * readiness, and add, edit, test, enable, download and delete. The
 * registrations are OpenRegister objects; integriq only adds the readiness
 * overview and the Test checks, neither of which returns a secret.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
 */
export default {
	name: 'IdpRegistrationSettings',

	components: {
		IdpRegistrationDeleteDialog,
		IdpRegistrationModal,
		NcButton,
		NcEmptyContent,
		NcNoteCard,
	},

	data() {
		return {
			loading: true,
			loadError: '',
			brokerEnabled: true,
			keepiqAvailable: true,
			registrations: [],
			organisations: [],
			credentials: [],
			credentialsLoading: true,
			results: {},
			busy: null,
			editing: null,
			saving: false,
			saveError: '',
			deleting: null,
			providers: providerOptions(),
			kinds: kindOptions(),
		}
	},

	/** @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008 */
	async mounted() {
		await Promise.all([this.load(), this.loadOptions()])
	},

	methods: {
		checkLabel,
		t,
		labelOf,
		statusText,

		/**
		 * Read the overview: the flag, Keepiq and every registration's readiness.
		 *
		 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
		 */
		async load() {
			this.loadError = ''
			try {
				const { data } = await axios.get(generateUrl(OVERVIEW_URL))
				this.brokerEnabled = data.brokerEnabled === true
				this.keepiqAvailable = data.keepiqAvailable === true
				this.registrations = Array.isArray(data.registrations) ? data.registrations : []
			} catch {
				this.loadError = this.t('integriq', 'Failed to load the registrations.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Read the organisations and the credentials the pickers offer. A
		 * failure leaves a picker empty; it does not block the table.
		 *
		 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
		 */
		async loadOptions() {
			const [organisations, credentials] = await Promise.allSettled([
				axios.get(generateUrl(ORGANISATIONS_URL)),
				axios.get(generateUrl(CREDENTIALS_URL)),
			])
			this.organisations = organisations.status === 'fulfilled' ? organisationOptions(organisations.value.data) : []
			this.credentials = credentials.status === 'fulfilled' ? credentialOptions(credentials.value.data) : []
			this.credentialsLoading = false
		},

		/**
		 * The organisation's name, or its id when it is not in the list.
		 *
		 * @param {object} row The registration row.
		 * @return {string} The name.
		 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
		 */
		organisationName(row) {
			const id = row.organisation || ''
			const name = labelOf(this.organisations, id)
			return row.label ? `${name} (${row.label})` : name
		},

		/** @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008 */
		add() {
			this.saveError = ''
			this.editing = emptyDraft()
		},

		/**
		 * Open the modal on the stored registration.
		 *
		 * @param {object} row The registration row.
		 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
		 */
		async edit(row) {
			this.busy = row.id
			try {
				const { data } = await axios.get(generateUrl(objectUrl(row.id)))
				this.saveError = ''
				this.editing = draftFromObject(data)
			} catch {
				showError(this.t('integriq', 'Failed to load the registration.'))
			} finally {
				this.busy = null
			}
		},

		/**
		 * Store the modal's payload: a new object, or the edited one.
		 *
		 * @param {{id: string|null, payload: object}} event The modal's save.
		 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
		 */
		async save({ id, payload }) {
			this.saving = true
			this.saveError = ''
			try {
				if (id) {
					await axios.put(generateUrl(objectUrl(id)), payload)
				} else {
					await axios.post(generateUrl(REGISTRATIONS_URL), payload)
				}
				this.editing = null
				showSuccess(this.t('integriq', 'Registration saved.'))
				await this.load()
			} catch (e) {
				const body = (e.response && e.response.data) || {}
				this.saveError = body.message || body.error || this.t('integriq', 'Failed to save the registration.')
			} finally {
				this.saving = false
			}
		},

		/**
		 * Switch a registration on or off, keeping everything else as stored.
		 *
		 * @param {object} row The registration row.
		 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
		 */
		async toggle(row) {
			this.busy = row.id
			try {
				const { data } = await axios.get(generateUrl(objectUrl(row.id)))
				const draft = draftFromObject(data)
				draft.enabled = !row.enabled
				await axios.put(generateUrl(objectUrl(row.id)), payloadFromDraft(draft))
				await this.load()
			} catch {
				showError(this.t('integriq', 'Failed to change the registration.'))
			} finally {
				this.busy = null
			}
		},

		/**
		 * Run the Test checks and show each one as passed or failed.
		 *
		 * @param {object} row The registration row.
		 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
		 */
		async test(row) {
			this.busy = row.id
			try {
				const { data } = await axios.post(generateUrl(testUrl(row.id)))
				this.results = { ...this.results, [row.id]: Array.isArray(data.checks) ? data.checks : [] }
				await this.load()
			} catch {
				showError(this.t('integriq', 'Failed to test the registration.'))
			} finally {
				this.busy = null
			}
		},

		/**
		 * Delete the registration the dialog confirmed.
		 *
		 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
		 */
		async remove() {
			const row = this.deleting
			this.busy = row.id
			try {
				await axios.delete(generateUrl(objectUrl(row.id)))
				this.deleting = null
				showSuccess(this.t('integriq', 'Registration deleted.'))
				await this.load()
			} catch {
				showError(this.t('integriq', 'Failed to delete the registration.'))
			} finally {
				this.busy = null
			}
		},

		/**
		 * Copy a metadata URL to the clipboard.
		 *
		 * @param {string} url The URL.
		 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
		 */
		async copy(url) {
			try {
				await navigator.clipboard.writeText(url)
				showSuccess(this.t('integriq', 'Metadata URL copied.'))
			} catch {
				showError(this.t('integriq', 'Copying failed.'))
			}
		},
	},
}
</script>

<style scoped>
.idp-registrations {
	width: 100%;
	border-collapse: collapse;
}

.idp-registrations th,
.idp-registrations td {
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
}

.idp-registrations__url code {
	word-break: break-all;
}

.idp-registrations__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.idp-registrations__checks {
	margin-top: var(--default-grid-baseline);
}

.idp-registrations__passed {
	color: var(--color-success-text);
}

.idp-registrations__failed {
	color: var(--color-error-text);
}
</style>

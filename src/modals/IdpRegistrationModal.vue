<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  IdpRegistrationModal: add or edit one identity provider registration from
  the Authenticatie section on the admin settings page. Lives in its own file
  under src/modals/ per the modal-isolation gate.

  The modal shows the fields of the chosen kind only (SAML: metadata URL and
  EntityID; OIDC: issuer, client id and client authentication). Credentials
  are picked by reference from the OpenRegister credentials integriq may use;
  no field accepts or shows a secret value. The parent stores the payload.

  An attribute provider (Yivi) is reached over an OIDC bridge only: its
  protocol is fixed to OIDC, and instead of the pseudonymisation, subject and
  assurance mappings it shows the list of attributes a service may ask for.

  @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
  @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
-->
<template>
	<NcModal
		v-if="open"
		labelId="idp-registration-modal-title"
		size="large"
		data-testid="idp-registration-modal"
		@close="$emit('close')">
		<form class="idp-registration" @submit.prevent="save">
			<h2 id="idp-registration-modal-title">
				{{ draft.id ? t('integriq', 'Edit registration') : t('integriq', 'Add registration') }}
			</h2>

			<NcSelect
				:modelValue="pick(organisations, draft.organisation)"
				data-testid="idp-registration-organisation"
				:inputLabel="t('integriq', 'Organisation')"
				:options="organisations"
				:clearable="false"
				@update:modelValue="(option) => set('organisation', option)" />

			<NcSelect
				:modelValue="pick(providers, draft.provider)"
				data-testid="idp-registration-provider"
				:inputLabel="t('integriq', 'Provider')"
				:options="providers"
				:clearable="false"
				@update:modelValue="(option) => set('provider', option)" />

			<NcSelect
				:modelValue="pick(kinds, draft.kind)"
				data-testid="idp-registration-kind"
				:inputLabel="t('integriq', 'Protocol')"
				:options="kinds"
				:clearable="false"
				:disabled="attributeLogin"
				@update:modelValue="(option) => set('kind', option)" />
			<p v-if="attributeLogin" class="idp-registration__hint" data-testid="idp-registration-kind-fixed">
				{{ t('integriq', 'Yivi is reached through an OIDC bridge, so the protocol is always OIDC Relying Party.') }}
			</p>

			<NcTextField
				v-model="draft.label"
				data-testid="idp-registration-label"
				:label="t('integriq', 'Label')" />

			<template v-if="draft.kind === 'saml-sp'">
				<NcTextField
					v-model="draft.idpMetadataUrl"
					data-testid="idp-registration-metadata-url"
					:label="t('integriq', 'Identity provider metadata URL')" />
				<NcTextField
					v-model="draft.spEntityId"
					data-testid="idp-registration-entity-id"
					:label="t('integriq', 'Our EntityID')"
					:helperText="t('integriq', 'Leave empty to use our metadata URL for this organisation and provider.')" />
			</template>

			<template v-else>
				<NcTextField
					v-model="draft.issuer"
					data-testid="idp-registration-issuer"
					:label="t('integriq', 'Issuer')" />
				<NcTextField
					v-model="draft.clientId"
					data-testid="idp-registration-client-id"
					:label="t('integriq', 'Client id')" />
				<NcSelect
					:modelValue="pick(clientAuths, draft.clientAuth)"
					data-testid="idp-registration-client-auth"
					:inputLabel="t('integriq', 'Client authentication')"
					:options="clientAuths"
					:clearable="false"
					@update:modelValue="(option) => set('clientAuth', option)" />
			</template>

			<NcSelect
				v-if="!attributeLogin"
				:modelValue="pick(pseudonymisations, draft.pseudonymisation)"
				data-testid="idp-registration-pseudonymisation"
				:inputLabel="t('integriq', 'Pseudonymisation')"
				:options="pseudonymisations"
				:clearable="false"
				@update:modelValue="(option) => set('pseudonymisation', option)" />

			<template v-if="attributeLogin">
				<h3>{{ t('integriq', 'Attributes') }}</h3>
				<p class="idp-registration__hint">
					{{ t('integriq', 'The Yivi attributes a service of this organisation may ask for, and the claim name the bridge returns for each.') }}
				</p>
				<div
					v-for="(line, index) in draft.attributes"
					:key="index"
					class="idp-registration__line"
					:data-testid="`idp-registration-attribute-${index}`">
					<NcTextField
						v-model="line.id"
						:label="t('integriq', 'Attribute id')" />
					<NcTextField
						v-model="line.claim"
						:label="t('integriq', 'Claim name')" />
					<NcTextField
						v-model="line.label"
						:label="t('integriq', 'Label')" />
					<NcCheckboxRadioSwitch v-model="line.carriesBsn">
						{{ t('integriq', 'Carries a BSN') }}
					</NcCheckboxRadioSwitch>
					<NcButton
						variant="tertiary"
						:aria-label="t('integriq', 'Remove attribute')"
						@click="draft.attributes.splice(index, 1)">
						{{ t('integriq', 'Remove') }}
					</NcButton>
				</div>
				<NcButton
					data-testid="idp-registration-add-attribute"
					@click="draft.attributes.push({ id: '', claim: '', label: '', carriesBsn: false })">
					{{ t('integriq', 'Add attribute') }}
				</NcButton>
			</template>

			<h3>{{ t('integriq', 'Credentials') }}</h3>
			<p class="idp-registration__hint">
				{{ t('integriq', 'Pick the OpenRegister credential for each role. The keys stay in Keepiq; this form never shows or accepts a secret.') }}
			</p>
			<NcSelect
				v-for="role in roles"
				:key="role"
				:modelValue="pick(credentials, draft.credentials[role])"
				:data-testid="`idp-registration-credential-${role}`"
				:inputLabel="roleLabel(role)"
				:options="credentials"
				:loading="credentialsLoading"
				@update:modelValue="(option) => setCredential(role, option)" />
			<p v-if="!credentialsLoading && credentials.length === 0" class="idp-registration__hint">
				{{ t('integriq', 'No credential allows integriq yet. Create one in OpenRegister first.') }}
			</p>

			<template v-if="!attributeLogin">
				<h3>{{ t('integriq', 'Subject mapping') }}</h3>
				<p class="idp-registration__hint">
					{{ t('integriq', 'The attribute or claim name the identity provider uses for each field. Leave a field empty when it does not send it.') }}
				</p>
				<NcTextField
					v-for="field in subjectFields"
					:key="field"
					v-model="draft.subjectMapping[field]"
					:data-testid="`idp-registration-subject-${field}`"
					:label="subjectLabel(field)" />

				<h3>{{ t('integriq', 'Assurance mapping') }}</h3>
				<p class="idp-registration__hint">
					{{ t('integriq', 'The level spelling the identity provider sends, and the level it means.') }}
				</p>
				<div
					v-for="(line, index) in draft.assuranceMapping"
					:key="index"
					class="idp-registration__line">
					<NcTextField
						v-model="line.sent"
						:label="t('integriq', 'Level as sent')" />
					<NcTextField
						v-model="line.level"
						:label="t('integriq', 'Level it means')" />
					<NcButton
						variant="tertiary"
						:aria-label="t('integriq', 'Remove level')"
						@click="draft.assuranceMapping.splice(index, 1)">
						{{ t('integriq', 'Remove') }}
					</NcButton>
				</div>
				<NcButton
					data-testid="idp-registration-add-level"
					@click="draft.assuranceMapping.push({ sent: '', level: '' })">
					{{ t('integriq', 'Add level') }}
				</NcButton>
			</template>

			<NcCheckboxRadioSwitch
				v-model="draft.enabled"
				type="switch"
				data-testid="idp-registration-enabled">
				{{ t('integriq', 'Enabled') }}
			</NcCheckboxRadioSwitch>

			<NcNoteCard
				v-if="shownProblems.length > 0"
				type="error"
				data-testid="idp-registration-problems">
				<ul>
					<li v-for="problem in shownProblems" :key="problem">
						{{ problem }}
					</li>
				</ul>
			</NcNoteCard>

			<div class="idp-registration__actions">
				<NcButton @click="$emit('close')">
					{{ t('integriq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					type="submit"
					:disabled="saving"
					data-testid="idp-registration-save">
					{{ saving ? t('integriq', 'Saving…') : t('integriq', 'Save') }}
				</NcButton>
			</div>
		</form>
	</NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcModal,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import {
	clientAuthOptions,
	draftProblems,
	emptyDraft,
	fixedForProvider,
	isAttributeProvider,
	kindOptions,
	offeredRoles,
	payloadFromDraft,
	providerOptions,
	pseudonymisationOptions,
	roleLabel,
	SUBJECT_FIELDS,
} from '../views/admin/idpRegistration.js'

/**
 * The add and edit form of one registration.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
 */
export default {
	name: 'IdpRegistrationModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		/** The draft to edit; a copy is edited, so cancelling changes nothing. */
		initial: {
			type: Object,
			default: null,
		},

		organisations: {
			type: Array,
			default: () => [],
		},

		credentials: {
			type: Array,
			default: () => [],
		},

		credentialsLoading: {
			type: Boolean,
			default: false,
		},

		saving: {
			type: Boolean,
			default: false,
		},

		/** The server's refusal of the last save, if any. */
		error: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'save'],

	data() {
		return {
			draft: emptyDraft(),
			problems: [],
			providers: providerOptions(),
			kinds: kindOptions(),
			clientAuths: clientAuthOptions(),
			pseudonymisations: pseudonymisationOptions(),
			subjectFields: SUBJECT_FIELDS,
		}
	},

	computed: {
		/**
		 * Whether the chosen provider discloses attributes (Yivi).
		 *
		 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
		 */
		attributeLogin() {
			return isAttributeProvider(this.draft.provider)
		},

		/**
		 * The credential roles the chosen kind and modes use.
		 *
		 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
		 */
		roles() {
			return offeredRoles(this.draft)
		},

		/**
		 * The local problems, then the server's refusal.
		 *
		 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
		 */
		shownProblems() {
			return this.error ? [...this.problems, this.error] : this.problems
		},
	},

	watch: {
		open: {
			immediate: true,
			/**
			 * Start from a fresh copy each time the modal opens.
			 *
			 * @param {boolean} open Whether the modal is open.
			 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
			 */
			handler(open) {
				if (open) {
					this.draft = JSON.parse(JSON.stringify(this.initial || emptyDraft()))
					this.problems = []
				}
			},
		},
	},

	methods: {
		roleLabel,
		t,

		/**
		 * The option with an id, or null.
		 *
		 * @param {Array<{id: string}>} options The options.
		 * @param {string} id The id.
		 * @return {object|null} The option.
		 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
		 */
		pick(options, id) {
			return options.find((option) => option.id === id) || null
		},

		/**
		 * Store a picked option's id on the draft, then what its provider fixes.
		 *
		 * @param {string} key The draft key.
		 * @param {object|null} option The picked option.
		 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
		 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
		 */
		set(key, option) {
			this.draft[key] = option ? option.id : ''
			fixedForProvider(this.draft)
		},

		/**
		 * Store a picked credential id for a role.
		 *
		 * @param {string} role The role.
		 * @param {object|null} option The picked credential.
		 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
		 */
		setCredential(role, option) {
			this.draft.credentials[role] = option ? option.id : ''
		},

		/**
		 * The label of a subject field.
		 *
		 * @param {string} field The field.
		 * @return {string} The label.
		 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
		 */
		subjectLabel(field) {
			const labels = {
				bsn: this.t('integriq', 'BSN'),
				pseudonym: this.t('integriq', 'Pseudonym'),
				kvk: this.t('integriq', 'KvK number'),
				rsin: this.t('integriq', 'RSIN'),
				branch: this.t('integriq', 'Branch number'),
				eidasPersonIdentifier: this.t('integriq', 'eIDAS person identifier'),
			}
			return labels[field] || field
		},

		/**
		 * Check the draft and hand its payload to the parent.
		 *
		 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
		 */
		save() {
			this.problems = draftProblems(this.draft)
			if (this.problems.length > 0) {
				return
			}
			this.$emit('save', { id: this.draft.id, payload: payloadFromDraft(this.draft) })
		},
	},
}
</script>

<style scoped>
.idp-registration {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 5);
}

.idp-registration__hint {
	color: var(--color-text-maxcontrast);
}

.idp-registration__line {
	display: flex;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}

.idp-registration__actions {
	display: flex;
	justify-content: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>

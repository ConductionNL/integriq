<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  SharePointPublicationSetupDialog — "Route to publications" from the
  SharePoint card in the Store (connectors-sharepoint-publication-intake
  REQ-SPPI-001). Mounted once in ModalHost, opened over the modal bus.

  Four choices, each read live: a brokered credential (OpenRegister's
  credential broker), a site found by search, a library and folder (Graph,
  through GET /api/sharepoint/lookup), and the target catalogue (OpenRegister).
  Saving posts them to POST /api/sharepoint/publication-intake, which imports
  one source, mapping, rule and synchronization; nothing secret is stored.
-->
<template>
	<NcDialog
		:open="open"
		:name="t('integriq', 'Route SharePoint documents to publications')"
		size="normal"
		data-testid="sharepoint-publication-setup-dialog"
		@update:open="onOpenChanged">
		<div class="sp-setup">
			<p class="sp-setup__intro">
				{{ t('integriq', 'Each subfolder of the folder you pick becomes a draft publication with its files attached. Nothing is published until an editor sets a publication date.') }}
			</p>

			<NcSelect
				v-model="credential"
				inputId="sp-setup-credential"
				:inputLabel="t('integriq', 'Brokered credential')"
				:options="credentialOptions"
				:loading="credentialsLoading"
				:placeholder="t('integriq', 'Select a brokered credential')"
				data-testid="sp-setup-credential"
				@update:modelValue="onCredentialPicked" />
			<p class="sp-setup__helper">
				{{ t('integriq', 'Background runs call SharePoint as the owner of this credential.') }}
			</p>

			<div class="sp-setup__row">
				<NcTextField
					v-model="siteSearch"
					:label="t('integriq', 'Search for a site')"
					:disabled="!credential"
					data-testid="sp-setup-site-search"
					@keyup.enter="searchSites" />
				<NcButton
					:disabled="!credential || siteSearch.trim() === '' || busy"
					data-testid="sp-setup-site-search-button"
					@click="searchSites">
					{{ t('integriq', 'Search') }}
				</NcButton>
			</div>

			<NcSelect
				v-if="siteOptions.length > 0"
				v-model="site"
				inputId="sp-setup-site"
				:inputLabel="t('integriq', 'Site')"
				:options="siteOptions"
				data-testid="sp-setup-site"
				@update:modelValue="onSitePicked" />

			<NcSelect
				v-if="driveOptions.length > 0"
				v-model="drive"
				inputId="sp-setup-drive"
				:inputLabel="t('integriq', 'Document library')"
				:options="driveOptions"
				data-testid="sp-setup-drive"
				@update:modelValue="onDrivePicked" />

			<template v-if="drive">
				<NcSelect
					v-model="folder"
					inputId="sp-setup-folder"
					:inputLabel="t('integriq', 'Folder whose subfolders become publications')"
					:options="folderOptions"
					:placeholder="t('integriq', 'The library root')"
					:clearable="true"
					data-testid="sp-setup-folder" />
				<NcButton
					v-if="folder"
					variant="tertiary"
					:disabled="busy"
					data-testid="sp-setup-folder-open"
					@click="openFolder">
					{{ t('integriq', 'Show its subfolders') }}
				</NcButton>
			</template>

			<div class="sp-setup__row">
				<NcTextField
					v-model="register"
					:label="t('integriq', 'Target register')"
					data-testid="sp-setup-register"
					@update:modelValue="loadCatalogs" />
				<NcTextField
					v-model="schema"
					:label="t('integriq', 'Target schema')"
					data-testid="sp-setup-schema" />
			</div>

			<NcSelect
				v-if="catalogOptions.length > 0"
				v-model="catalog"
				inputId="sp-setup-catalog"
				:inputLabel="t('integriq', 'Catalogue')"
				:options="catalogOptions"
				data-testid="sp-setup-catalog" />
			<NcTextField
				v-else
				v-model="catalog"
				:label="t('integriq', 'Catalogue id')"
				data-testid="sp-setup-catalog-id" />

			<NcCheckboxRadioSwitch
				v-model="requiresApproval"
				type="switch"
				data-testid="sp-setup-approval">
				{{ t('integriq', 'Review before import') }}
			</NcCheckboxRadioSwitch>

			<NcNoteCard v-if="errorMessage" type="error" data-testid="sp-setup-error">
				{{ errorMessage }}
			</NcNoteCard>
			<NcNoteCard v-if="successMessage" type="success" data-testid="sp-setup-success">
				{{ successMessage }}
			</NcNoteCard>

			<div class="sp-setup__actions">
				<NcButton variant="tertiary" @click="close">
					{{ t('integriq', 'Close') }}
				</NcButton>
				<NcButton
					v-if="synchronizationId"
					:disabled="busy"
					data-testid="sp-setup-run"
					@click="runNow">
					{{ t('integriq', 'Run now') }}
				</NcButton>
				<NcButton
					v-else
					variant="primary"
					:disabled="!saveable || busy"
					data-testid="sp-setup-save"
					@click="save">
					{{ t('integriq', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import closableDialog from '../mixins/closableDialog.js'
import { extractCredentialResults, mapCredentialOptions } from '../modals/v2/sourceCredentialRef.js'
import {
	canSave,
	createdSynchronizationId,
	INTAKE_URL,
	intakeBody,
	LOOKUP_URL,
	lookupFailureText,
	lookupOptions,
	lookupParams,
} from './sharePointSetup.js'

/**
 * The initial choices.
 *
 * @return {object}
 */
function initialState() {
	return {
		credential: null,
		siteSearch: '',
		site: null,
		drive: null,
		folder: null,
		register: 'publication',
		schema: 'publication',
		catalog: '',
		requiresApproval: false,
		credentialOptions: [],
		siteOptions: [],
		driveOptions: [],
		folderOptions: [],
		catalogOptions: [],
		credentialsLoading: false,
		busy: false,
		errorMessage: '',
		successMessage: '',
		synchronizationId: null,
	}
}

export default {
	name: 'SharePointPublicationSetupDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	mixins: [closableDialog],

	props: {
		open: { type: Boolean, default: false },
	},

	emits: ['close'],

	data() {
		return initialState()
	},

	computed: {
		/**
		 * Whether every choice the save needs is made.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
		 */
		saveable() {
			return canSave(this.$data)
		},
	},

	watch: {
		/**
		 * Every opening starts from scratch and reloads the credentials and catalogues.
		 *
		 * @param {boolean} isOpen Whether the dialog is open.
		 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
		 */
		open(isOpen) {
			if (!isOpen) {
				return
			}
			Object.assign(this.$data, initialState())
			this.loadCredentials()
			this.loadCatalogs()
		},
	},

	methods: {
		/**
		 * The brokered credentials the signed-in administrator can pick.
		 *
		 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
		 */
		async loadCredentials() {
			this.credentialsLoading = true
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/credentials'))
				this.credentialOptions = mapCredentialOptions(extractCredentialResults(response.data))
			} catch {
				this.credentialOptions = []
				this.errorMessage = t('integriq', 'The credential broker is not available.')
			} finally {
				this.credentialsLoading = false
			}
		},

		/**
		 * The catalogues of the target register, when OpenRegister lists them; else the id is typed.
		 *
		 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
		 */
		async loadCatalogs() {
			const register = String(this.register || '').trim()
			this.catalogOptions = []
			if (register === '') {
				return
			}
			try {
				const response = await axios.get(generateUrl(`/apps/openregister/api/objects/${encodeURIComponent(register)}/catalog`))
				const rows = Array.isArray(response.data?.results) ? response.data.results : []
				this.catalogOptions = rows
					.map((row) => ({ id: String(row.id || row.uuid || row['@self']?.id || ''), label: String(row.title || row.name || row.id || '') }))
					.filter((option) => option.id !== '')
			} catch {
				this.catalogOptions = []
			}
		},

		/**
		 * One Graph lookup through the picked credential.
		 *
		 * @param {string} kind sites, drives or children.
		 * @param {object} params The lookup's parameters.
		 * @return {Promise<object|null>} The answer, or null after showing why it failed.
		 */
		async lookup(kind, params) {
			this.busy = true
			this.errorMessage = ''
			try {
				const response = await axios.get(generateUrl(LOOKUP_URL), {
					params: lookupParams(this.credential?.id || '', kind, params),
				})
				return response.data
			} catch (error) {
				this.errorMessage = lookupFailureText(error)
				return null
			} finally {
				this.busy = false
			}
		},

		/**
		 * A new credential clears everything read through the previous one.
		 *
		 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
		 */
		onCredentialPicked() {
			this.site = null
			this.drive = null
			this.folder = null
			this.siteOptions = []
			this.driveOptions = []
			this.folderOptions = []
		},

		/**
		 * Sites whose name matches the search.
		 *
		 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#scenario-graph-refuses-the-site-search
		 */
		async searchSites() {
			if (!this.credential || this.siteSearch.trim() === '') {
				return
			}
			this.site = null
			this.drive = null
			this.folder = null
			this.driveOptions = []
			this.folderOptions = []
			const data = await this.lookup('sites', { search: this.siteSearch })
			this.siteOptions = data ? lookupOptions(data) : []
		},

		/**
		 * The libraries of the picked site.
		 *
		 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
		 */
		async onSitePicked() {
			this.drive = null
			this.folder = null
			this.driveOptions = []
			this.folderOptions = []
			if (!this.site) {
				return
			}
			const data = await this.lookup('drives', { siteId: this.site.id })
			this.driveOptions = data ? lookupOptions(data) : []
		},

		/**
		 * The folders at the root of the picked library.
		 *
		 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
		 */
		async onDrivePicked() {
			this.folder = null
			this.folderOptions = []
			if (!this.drive) {
				return
			}
			const data = await this.lookup('children', { driveId: this.drive.id })
			this.folderOptions = data ? lookupOptions(data, true) : []
		},

		/**
		 * The subfolders of the picked folder, to pick a deeper one.
		 *
		 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
		 */
		async openFolder() {
			if (!this.drive || !this.folder) {
				return
			}
			const parent = this.folder
			const data = await this.lookup('children', { driveId: this.drive.id, itemId: parent.id })
			if (data) {
				this.folderOptions = [parent, ...lookupOptions(data, true)]
			}
		},

		/**
		 * Save the setup: one source, mapping, rule and synchronization.
		 *
		 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#scenario-a-woo-coordinators-library-is-connected
		 */
		async save() {
			this.busy = true
			this.errorMessage = ''
			try {
				const response = await axios.post(generateUrl(INTAKE_URL), intakeBody(this.$data))
				this.synchronizationId = createdSynchronizationId(response.data)
				this.successMessage = t('integriq', 'Saved. A source, a mapping, a rule and a synchronization were created.')
			} catch (error) {
				const message = error?.response?.data?.message || error?.message || ''
				this.errorMessage = t('integriq', 'Saving failed') + (message ? `: ${message}` : '')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Run the new synchronization once now.
		 *
		 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
		 */
		async runNow() {
			this.busy = true
			this.errorMessage = ''
			try {
				await axios.post(generateUrl(`/apps/integriq/api/synchronizations/${encodeURIComponent(this.synchronizationId)}/run`))
				this.successMessage = this.requiresApproval
					? t('integriq', 'The run started and waits for approval before it writes.')
					: t('integriq', 'The run started. Draft publications appear in the catalogue as it goes.')
			} catch {
				this.errorMessage = t('integriq', 'The run could not be started.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.sp-setup {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 2);
}

.sp-setup__intro,
.sp-setup__helper {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.sp-setup__row {
	display: flex;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}

.sp-setup__actions {
	display: flex;
	justify-content: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>

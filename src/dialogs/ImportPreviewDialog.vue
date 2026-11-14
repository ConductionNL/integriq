<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  ImportPreviewDialog — configuration import with preview + confirmation
  (connector-catalog-ui REQ-007/REQ-008/REQ-009), mounted once in ModalHost
  and opened via the Import configuration header action of the Catalog page.

  Flow:
    1. pick a JSON file (an earlier export, REQ-001-shaped OAS document)
    2. POST /api/configurations/import/preview → render creates / updates /
       collisions / unresolvedReferences / credentialsNeedingReentry
    3. unresolved references BLOCK confirmation until the operator ticks
       the explicit acknowledgement (REQ-007 blocking-warning decision)
    4. POST /api/configurations/import with confirmed:true (REQ-008)
    5. post-import summary re-lists credentialsNeedingReentry with a link
       to the Sources page for re-entry (REQ-009)
-->
<template>
	<NcDialog
		:open="open"
		:name="t('integriq', 'Import configuration')"
		size="large"
		data-testid="import-preview-dialog"
		@update:open="onOpenChanged">
		<div class="oc-import-dialog">
			<!-- Step 1: file pick -->
			<div v-if="step === 'pick'" class="oc-import-dialog__step">
				<p>
					{{
						t(
							'integriq',
							'Select an exported configuration document (JSON). The import is previewed first — nothing is written until you confirm.',
						)
					}}
				</p>
				<input
					ref="fileInput"
					type="file"
					accept="application/json,.json"
					:aria-label="t('integriq', 'Configuration document')"
					data-testid="import-file-input"
					@change="onFileChosen" />
				<NcNoteCard v-if="errorMessage" type="error">
					{{ errorMessage }}
				</NcNoteCard>
			</div>

			<!-- Step 2: preview -->
			<div v-else-if="step === 'preview'" class="oc-import-dialog__step">
				<h4>{{ t('integriq', 'Import preview') }}</h4>

				<NcNoteCard
					v-for="entry in preview.deprecations || []"
					:key="`dep-${entry.type}-${entry.slug}`"
					type="warning"
					data-testid="preview-deprecated">
					{{ deprecationText(entry) }}
				</NcNoteCard>

				<div class="oc-import-dialog__buckets">
					<PreviewBucket
						block="oc-import-dialog"
						:title="t('integriq', 'Will be created')"
						:entries="preview.creates"
						keyPrefix="c"
						data-testid="preview-creates" />
					<PreviewBucket
						block="oc-import-dialog"
						:title="t('integriq', 'Will be updated')"
						:entries="preview.updates"
						keyPrefix="u"
						data-testid="preview-updates" />
				</div>

				<NcNoteCard
					v-if="preview.collisions.length > 0"
					type="warning"
					data-testid="preview-collisions">
					<strong>{{ t('integriq', 'Slug collisions') }}</strong>
					<ul>
						<li
							v-for="entry in preview.collisions"
							:key="`x-${entry.type}-${entry.slug}`">
							<code>{{ entry.type }}</code> {{ entry.slug }} —
							{{ entry.reason }}
						</li>
					</ul>
				</NcNoteCard>

				<NcNoteCard
					v-if="preview.unresolvedReferences.length > 0"
					type="error"
					data-testid="preview-unresolved">
					<strong>{{ t('integriq', 'Unresolved references') }}</strong>
					<p>
						{{
							t(
								'integriq',
								'These slug references do not resolve in this environment and will be imported verbatim — the referencing entity will be broken until fixed manually.',
							)
						}}
					</p>
					<ul>
						<li
							v-for="(entry, idx) in preview.unresolvedReferences"
							:key="`r-${idx}`">
							<code>{{ entry.type }}</code> {{ entry.slug }} →
							{{ entry.field }} = "{{ entry.value }}"
						</li>
					</ul>
					<NcCheckboxRadioSwitch
						v-model="unresolvedAcknowledged"
						data-testid="unresolved-ack">
						{{
							t(
								'integriq',
								'I understand these references will stay dangling and want to import anyway',
							)
						}}
					</NcCheckboxRadioSwitch>
				</NcNoteCard>

				<NcNoteCard
					v-if="preview.credentialsNeedingReentry.length > 0"
					type="info"
					data-testid="preview-credentials">
					<strong>{{
						t('integriq', 'Credentials need re-entry after import')
					}}</strong>
					<ul>
						<li
							v-for="entry in preview.credentialsNeedingReentry"
							:key="`k-${entry.slug}`">
							{{ entry.slug }}: {{ entry.fields.join(', ') }}
						</li>
					</ul>
				</NcNoteCard>

				<NcNoteCard v-if="errorMessage" type="error">
					{{ errorMessage }}
				</NcNoteCard>
			</div>

			<!-- Step 3: post-import summary -->
			<div v-else-if="step === 'done'" class="oc-import-dialog__step">
				<NcNoteCard type="success" data-testid="import-success">
					{{ t('integriq', 'Import completed') }}
				</NcNoteCard>
				<NcNoteCard
					v-if="preview.credentialsNeedingReentry.length > 0"
					type="warning"
					data-testid="import-credentials-summary">
					<strong>{{
						t('integriq', 'Re-enter credentials for these sources')
					}}</strong>
					<p>
						{{
							t(
								'integriq',
								'Exports always strip credentials — open each imported source and re-enter them.',
							)
						}}
					</p>
					<ul>
						<li
							v-for="entry in preview.credentialsNeedingReentry"
							:key="`d-${entry.slug}`">
							{{ entry.slug }}: {{ entry.fields.join(', ') }}
						</li>
					</ul>
					<NcButton variant="secondary" @click="goToSources">
						{{ t('integriq', 'Open Sources') }}
					</NcButton>
				</NcNoteCard>
			</div>

			<div class="oc-import-dialog__actions">
				<NcButton variant="tertiary" @click="close">
					{{
						step === 'done'
							? t('integriq', 'Close')
							: t('integriq', 'Cancel')
					}}
				</NcButton>
				<NcButton
					v-if="step === 'preview'"
					variant="primary"
					:disabled="importRunning || confirmBlocked"
					data-testid="confirm-import"
					@click="confirmImport">
					{{ t('integriq', 'Confirm import') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
} from '@nextcloud/vue'
import PreviewBucket from '../components/PreviewBucket.vue'
import { withDetail } from '../errorDetail.js'
import { getRouter } from '../handlers/routerRef.js'
import closableDialog from '../mixins/closableDialog.js'

/** What parseChosen yields for a file that is not JSON. */
const INVALID = Symbol('invalid')

const EMPTY_PREVIEW = {
	creates: [],
	updates: [],
	collisions: [],
	unresolvedReferences: [],
	credentialsNeedingReentry: [],
}

export default {
	name: 'ImportPreviewDialog',

	components: {
		PreviewBucket,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
	},

	mixins: [closableDialog],

	props: {
		open: { type: Boolean, default: false },
	},

	emits: ['close'],

	data() {
		return {
			step: 'pick',
			document: null,
			preview: { ...EMPTY_PREVIEW },
			unresolvedAcknowledged: false,
			importRunning: false,
			errorMessage: '',
		}
	},

	computed: {
		/**
		 * Confirmation is blocked while unresolved references exist and the
		 * operator has not explicitly acknowledged them (REQ-007's blocking
		 * warning decision, proposal.md Open Questions).
		 *
		 * @return {boolean}
		 * @spec openspec/specs/configuration-export-import/spec.md#scenario-preview-surfaces-an-unresolvable-slug-reference-as-a-blocking-warning
		 */
		confirmBlocked() {
			return (
				this.preview.unresolvedReferences.length > 0
				&& !this.unresolvedAcknowledged
			)
		},
	},

	watch: {
		/**
		 * Resets on open. This clears `unresolvedAcknowledged` along with
		 * everything else — without that, an operator who acknowledged
		 * unresolved references for a previous import would find the confirm
		 * button already unblocked for the next one.
		 *
		 * @param {boolean} isOpen Whether the dialog is being shown.
		 * @return {void}
		 * @spec openspec/specs/configuration-export-import/spec.md#scenario-preview-surfaces-an-unresolvable-slug-reference-as-a-blocking-warning
		 */
		open(isOpen) {
			if (isOpen) {
				this.reset()
			}
		},
	},

	methods: {
		/**
		 * The warning for one deprecated entity: what it is and what replaces it.
		 *
		 * @param {object} entry A preview deprecation `{ type, slug, replacedBy, reason }`.
		 * @return {string} The translated text.
		 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#scenario-an-administrator-is-steered-to-the-new-set
		 */
		deprecationText(entry) {
			const text = entry.replacedBy
				? t('integriq', 'The {type} "{slug}" is deprecated. Use "{replacedBy}" instead.', { type: entry.type, slug: entry.slug, replacedBy: entry.replacedBy })
				: t('integriq', 'The {type} "{slug}" is deprecated.', { type: entry.type, slug: entry.slug })
			return entry.reason ? `${text} ${entry.reason}` : text
		},

		/**
		 * Reset the dialog to the file-pick step.
		 *
		 * @return {void}
		 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-007--preview-an-import-before-writing-anything
		 */
		reset() {
			this.step = 'pick'
			this.document = null
			this.preview = { ...EMPTY_PREVIEW }
			this.unresolvedAcknowledged = false
			this.importRunning = false
			this.errorMessage = ''
		},

		/**
		 * Parse the chosen file and fetch the non-mutating preview (REQ-007).
		 *
		 * @param {Event} event Native file-input change event.
		 * @return {Promise<void>}
		 * @spec openspec/specs/configuration-export-import/spec.md#scenario-preview-classifies-creates-updates-and-collisions
		 */
		async onFileChosen(event) {
			this.errorMessage = ''
			const file = event.target?.files?.[0]
			if (!file) {
				return
			}
			const parsed = await this.parseChosen(file)
			if (parsed === INVALID) {
				return
			}
			this.document = parsed
			await this.requestPreview(parsed)
		},

		/**
		 * Read a chosen file as JSON; invalid JSON says so and yields INVALID.
		 *
		 * @param {File} file The chosen file.
		 * @return {Promise<unknown>} The parsed document, or INVALID.
		 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-007--preview-an-import-before-writing-anything
		 */
		async parseChosen(file) {
			try {
				return JSON.parse(await file.text())
			} catch (err) {
				this.errorMessage = t(
					'integriq',
					'The selected file is not valid JSON',
				)
				return INVALID
			}
		},

		/**
		 * Ask the server what importing a document would do, and show it.
		 *
		 * @param {unknown} document The parsed configuration document.
		 * @return {Promise<void>}
		 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-007--preview-an-import-before-writing-anything
		 */
		async requestPreview(document) {
			try {
				const url = generateUrl(
					'/apps/integriq/api/configurations/import/preview',
				)
				const { data } = await axios.post(url, { document })
				this.preview = { ...EMPTY_PREVIEW, ...data }
				this.unresolvedAcknowledged = false
				this.step = 'preview'
			} catch (err) {
				this.errorMessage = withDetail(t('integriq', 'Preview failed'), err)
			}
		},

		/**
		 * Show what the import did on the done step.
		 *
		 * @param {object|null} data The import result.
		 * @return {void}
		 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-008--import-requires-explicit-confirmation-after-preview
		 */
		showImported(data) {
			this.preview = { ...EMPTY_PREVIEW, ...data }
			this.step = 'done'
		},

		/**
		 * POST the confirmed import of the chosen document.
		 *
		 * @return {Promise<object>} What the import did.
		 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-008--import-requires-explicit-confirmation-after-preview
		 */
		async postImport() {
			const url = generateUrl('/apps/integriq/api/configurations/import')
			const { data } = await axios.post(url, {
				document: this.document,
				confirmed: true,
			})
			return data
		},

		/**
		 * Confirmed import (REQ-008): sends confirmed:true after the operator
		 * has seen (and where needed acknowledged) the preview.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/configuration-export-import/spec.md#scenario-confirmed-import-proceeds-and-reuses-the-existing-import-pipeline-unchanged
		 */
		async confirmImport() {
			if (!this.document || this.confirmBlocked) {
				return
			}
			this.importRunning = true
			this.errorMessage = ''
			try {
				this.showImported(await this.postImport())
			} catch (err) {
				this.errorMessage = withDetail(t('integriq', 'Import failed'), err)
			} finally {
				this.importRunning = false
			}
		},

		/**
		 * Navigate to the Sources index so the operator can re-enter the
		 * stripped credentials (REQ-009).
		 *
		 * @return {void}
		 * @spec openspec/specs/configuration-export-import/spec.md#scenario-a-newly-created-source-from-import-is-flagged-for-credential-re-entry
		 */
		goToSources() {
			const router = getRouter()
			if (router) {
				router.push({ name: 'Sources' }).catch(() => {})
			}
			this.close()
		},
	},
}
</script>

<style scoped>
.oc-import-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 12px 12px;
}

.oc-import-dialog__step {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.oc-import-dialog__buckets {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
}

.oc-import-dialog__bucket :deep(h5) {
	margin: 0 0 4px;
}

.oc-import-dialog__bucket :deep(ul) {
	margin: 0;
	padding-inline-start: 18px;
	max-height: 180px;
	overflow-y: auto;
}

.oc-import-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>

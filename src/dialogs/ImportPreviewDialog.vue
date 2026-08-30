<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  ImportPreviewDialog — configuration import with preview + confirmation
  (connector-catalog-ui REQ-007/REQ-008/REQ-009), mounted once in ModalHost
  and opened via the Catalog page's "Import configuration" header action.

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

				<div class="oc-import-dialog__buckets">
					<div
						class="oc-import-dialog__bucket"
						data-testid="preview-creates">
						<h5>
							{{ t('integriq', 'Will be created') }} ({{
								preview.creates.length
							}})
						</h5>
						<ul>
							<li
								v-for="entry in preview.creates"
								:key="`c-${entry.type}-${entry.slug}`">
								<code>{{ entry.type }}</code> {{ entry.slug }}
							</li>
						</ul>
					</div>
					<div
						class="oc-import-dialog__bucket"
						data-testid="preview-updates">
						<h5>
							{{ t('integriq', 'Will be updated') }} ({{
								preview.updates.length
							}})
						</h5>
						<ul>
							<li
								v-for="entry in preview.updates"
								:key="`u-${entry.type}-${entry.slug}`">
								<code>{{ entry.type }}</code> {{ entry.slug }}
							</li>
						</ul>
					</div>
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
import { getRouter } from '../handlers/routerRef.js'

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
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
	},

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
			let parsed
			try {
				parsed = JSON.parse(await file.text())
			} catch (err) {
				this.errorMessage = t(
					'integriq',
					'The selected file is not valid JSON',
				)
				return
			}
			this.document = parsed
			try {
				const url = generateUrl(
					'/apps/integriq/api/configurations/import/preview',
				)
				const { data } = await axios.post(url, { document: parsed })
				this.preview = { ...EMPTY_PREVIEW, ...data }
				this.unresolvedAcknowledged = false
				this.step = 'preview'
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				this.errorMessage =
					t('integriq', 'Preview failed') + (detail ? `: ${detail}` : '')
			}
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
				const url = generateUrl('/apps/integriq/api/configurations/import')
				const { data } = await axios.post(url, {
					document: this.document,
					confirmed: true,
				})
				this.preview = { ...EMPTY_PREVIEW, ...data }
				this.step = 'done'
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				this.errorMessage =
					t('integriq', 'Import failed') + (detail ? `: ${detail}` : '')
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

		/**
		 * NcDialog open-state relay.
		 *
		 * @param {boolean} isOpen New open state from NcDialog.
		 * @return {void}
		 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-007--preview-an-import-before-writing-anything
		 */
		onOpenChanged(isOpen) {
			if (!isOpen) {
				this.close()
			}
		},

		/**
		 * Ask ModalHost to close the dialog.
		 *
		 * @return {void}
		 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-007--preview-an-import-before-writing-anything
		 */
		close() {
			this.$emit('close')
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

.oc-import-dialog__bucket h5 {
	margin: 0 0 4px;
}

.oc-import-dialog__bucket ul {
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

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  PromotePreviewModal — environments-and-promotion promote flow: pick a
  configuration group and a target environment, review the merged diff
  preview (the target's own creates/updates/collisions classification PLUS
  the locally-scanned credentialRefsNeedingRebind bucket), optionally supply
  a rebind (credentialId/credentialName reference — NEVER a plaintext
  secret) for each flagged Source, then confirm. Mounted once in ModalHost
  and opened via the Environments page's "Promote configuration" header
  action (src/handlers/actionHandlers.js#openPromotionHandler).

  Flow:
    1. pick a configuration group (OR-native /api/configurations, same
       source ExportConfigurationDialog uses) and a target environment
       (/api/environments)
    2. POST /api/promotions/preview → render creates / updates / collisions
       / credentialRefsNeedingRebind (Confirm stays disabled until this
       preview has loaded — test-plan TC-18)
    3. an operator may type a replacement credentialId/credentialName for
       any flagged entry; unrebound entries are sent verbatim, never
       dropped (REQ-004)
    4. POST /api/promotions with confirmed:true
-->
<template>
	<NcModal
		v-if="open"
		label-id="promotePreviewModal"
		size="large"
		data-testid="promote-preview-modal"
		@close="onClose">
		<div class="oc-promote-modal">
			<h2>{{ t('openconnector', 'Promote configuration') }}</h2>

			<!-- Step 1: pick configuration group + target environment -->
			<div v-if="step === 'select'" class="oc-promote-modal__step">
				<NcSelect
					:model-value="selectedConfig"
					:options="configOptions"
					:loading="loadingConfigs"
					:input-label="t('openconnector', 'Configuration group')"
					:placeholder="t('openconnector', 'Select a configuration group')"
					label="label"
					data-testid="promote-configuration-select"
					@update:model-value="onSelectConfig" />

				<NcSelect
					:model-value="selectedEnvironment"
					:options="environmentOptions"
					:loading="loadingEnvironments"
					:input-label="t('openconnector', 'Target environment')"
					:placeholder="t('openconnector', 'Select a target environment')"
					label="label"
					data-testid="promote-target-environment-select"
					@update:model-value="onSelectEnvironment" />

				<NcNoteCard v-if="errorMessage" type="error">
					{{ errorMessage }}
				</NcNoteCard>

				<div class="oc-promote-modal__actions">
					<NcButton type="tertiary" @click="close">
						{{ t('openconnector', 'Cancel') }}
					</NcButton>
					<NcButton
						type="primary"
						:disabled="!canPreview || previewing"
						data-testid="promote-run-preview"
						@click="runPreview">
						<template #icon>
							<NcLoadingIcon v-if="previewing" :size="20" />
						</template>
						{{ t('openconnector', 'Preview') }}
					</NcButton>
				</div>
			</div>

			<!-- Step 2: preview + credential rebind + confirm -->
			<div v-else class="oc-promote-modal__step">
				<p>
					{{
						t('openconnector', 'Promoting {config} to {environment}.', {
							config: selectedConfig.label,
							environment: selectedEnvironment.label,
						})
					}}
				</p>

				<div class="oc-promote-modal__buckets">
					<div
						class="oc-promote-modal__bucket"
						data-testid="promote-preview-creates">
						<h5>
							{{ t('openconnector', 'Will be created') }} ({{
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
						class="oc-promote-modal__bucket"
						data-testid="promote-preview-updates">
						<h5>
							{{ t('openconnector', 'Will be updated') }} ({{
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
					<div
						class="oc-promote-modal__bucket"
						data-testid="promote-preview-collisions">
						<h5>
							{{ t('openconnector', 'Collisions') }} ({{
								preview.collisions.length
							}})
						</h5>
						<ul>
							<li
								v-for="entry in preview.collisions"
								:key="`co-${entry.type}-${entry.slug}`">
								<code>{{ entry.type }}</code> {{ entry.slug }}
							</li>
						</ul>
					</div>
				</div>

				<div
					v-if="preview.credentialRefsNeedingRebind.length"
					class="oc-promote-modal__rebind"
					data-testid="promote-preview-rebind">
					<h5>
						{{
							t(
								'openconnector',
								'Credentials needing a target-environment rebind',
							)
						}}
						({{ preview.credentialRefsNeedingRebind.length }})
					</h5>
					<p>
						{{
							t(
								'openconnector',
								'These Sources authenticate via a credential reference from THIS environment. Supply the equivalent credential name valid on the target — the reference is rewritten, never a secret.',
							)
						}}
					</p>
					<div
						v-for="entry in preview.credentialRefsNeedingRebind"
						:key="`rb-${entry.slug}-${entry.field}`"
						class="oc-promote-modal__rebind-row">
						<label :for="`rebind-${entry.slug}`">
							{{ entry.slug }} <code>{{ entry.field }}</code>
						</label>
						<input
							:id="`rebind-${entry.slug}`"
							v-model="credentialBindings[bindingKey(entry)]"
							type="text"
							:placeholder="
								t(
									'openconnector',
									'Target credential name (optional — leave blank to send as-is)',
								)
							"
							:data-testid="`promote-rebind-input-${entry.slug}`" />
					</div>
				</div>

				<NcNoteCard v-if="errorMessage" type="error">
					{{ errorMessage }}
				</NcNoteCard>

				<div class="oc-promote-modal__actions">
					<NcButton type="tertiary" @click="backToSelect">
						{{ t('openconnector', 'Back') }}
					</NcButton>
					<NcButton
						type="primary"
						:disabled="confirming"
						data-testid="promote-confirm"
						@click="confirmPromotion">
						<template #icon>
							<NcLoadingIcon v-if="confirming" :size="20" />
						</template>
						{{ t('openconnector', 'Confirm promotion') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess } from '@nextcloud/dialogs'
import {
	NcModal,
	NcButton,
	NcSelect,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

export default {
	name: 'PromotePreviewModal',

	components: {
		NcModal,
		NcButton,
		NcSelect,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** Whether the modal is mounted/visible. */
		open: { type: Boolean, default: false },
	},

	emits: ['close'],

	data() {
		return {
			step: 'select',
			configOptions: [],
			selectedConfig: null,
			loadingConfigs: false,
			environmentOptions: [],
			selectedEnvironment: null,
			loadingEnvironments: false,
			previewing: false,
			confirming: false,
			preview: null,
			credentialBindings: {},
			errorMessage: '',
		}
	},

	computed: {
		/** @spec openspec/specs/environments-and-promotion/spec.md#requirement-diff-preview-merges-the-targets-existing-preview-response-with-a-credential-rebind-classification-req-003 */
		canPreview() {
			return !!this.selectedConfig && !!this.selectedEnvironment
		},
	},

	watch: {
		/**
		 * Initialises the promote flow each time the modal opens: state is
		 * reset first, so a previous run's preview or half-entered rebindings
		 * can never be confirmed against a newly chosen target.
		 *
		 * @param {boolean} isOpen Whether the modal is being shown.
		 * @return {void}
		 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-diff-preview-merges-the-targets-existing-preview-response-with-a-credential-rebind-classification-req-003
		 */
		open(isOpen) {
			if (isOpen) {
				this.resetState()
				this.fetchConfigurations()
				this.fetchEnvironments()
			}
		},
	},

	methods: {
		/**
		 * Reset all step/selection/preview state on open.
		 *
		 * @return {void}
		 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-diff-preview-merges-the-targets-existing-preview-response-with-a-credential-rebind-classification-req-003
		 */
		resetState() {
			this.step = 'select'
			this.selectedConfig = null
			this.selectedEnvironment = null
			this.preview = null
			this.credentialBindings = {}
			this.errorMessage = ''
		},

		/**
		 * List configuration groups from OR's native configurations endpoint
		 * (same source ExportConfigurationDialog uses).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-promotion-exports-locally-unchanged-and-dispatches-to-the-targets-existing-import-endpoints-req-002
		 */
		async fetchConfigurations() {
			this.loadingConfigs = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/configurations'),
				)
				const rows = data?.results || []
				this.configOptions = rows.map((row) => ({
					id: row.uuid || row.id,
					label: row.title || row.name || row.uuid || String(row.id),
				}))
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				this.errorMessage =
					t('openconnector', 'Could not load configuration groups')
					+ (detail ? `: ${detail}` : '')
			} finally {
				this.loadingConfigs = false
			}
		},

		/**
		 * List registered environments.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-named-environments-are-openregister-objects-that-wrap-an-existing-source-for-connectivity-req-001
		 */
		async fetchEnvironments() {
			this.loadingEnvironments = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/openconnector/api/environments'),
				)
				const rows = data?.results || []
				this.environmentOptions = rows.map((row) => ({
					id: row.slug,
					label: row.name || row.slug,
				}))
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				this.errorMessage =
					t('openconnector', 'Could not load environments')
					+ (detail ? `: ${detail}` : '')
			} finally {
				this.loadingEnvironments = false
			}
		},

		/**
		 * Picks the configuration group to promote. A cleared select stores
		 * null rather than an empty option, so `canPreview` blocks the preview
		 * instead of posting an undefined `configurationId`.
		 *
		 * @param {object|null} option The chosen configuration option.
		 * @return {void}
		 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-promotion-exports-locally-unchanged-and-dispatches-to-the-targets-existing-import-endpoints-req-002
		 */
		onSelectConfig(option) {
			this.selectedConfig = option || null
		},

		/**
		 * Picks the target environment. Same null-on-clear rule as
		 * {@link onSelectConfig} — promoting into an unset target is the one
		 * mistake this modal must not make possible.
		 *
		 * @param {object|null} option The chosen environment option.
		 * @return {void}
		 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-named-environments-are-openregister-objects-that-wrap-an-existing-source-for-connectivity-req-001
		 */
		onSelectEnvironment(option) {
			this.selectedEnvironment = option || null
		},

		/**
		 * The stable key used to index a flagged entry's rebind input.
		 *
		 * @param {{slug: string, field: string}} entry One `credentialRefsNeedingRebind[]` entry.
		 * @return {string} The binding key.
		 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-credentialref-placeholders-are-re-bound-per-target-environment-never-resolved-to-a-secret-req-004
		 */
		bindingKey(entry) {
			return `${entry.slug}|${entry.field}`
		},

		/**
		 * Build the `credentialBindings[]` request array from the operator's
		 * rebind inputs — reference strings only, never a resolved secret.
		 *
		 * @return {Array<object>} The bindings to send.
		 * @spec openspec/specs/environments-and-promotion/spec.md#scenario-an-operator-supplied-rebinding-replaces-the-reference-before-the-target-ever-sees-the-original
		 */
		buildCredentialBindings() {
			if (
				!this.preview
				|| !Array.isArray(this.preview.credentialRefsNeedingRebind)
			) {
				return []
			}
			return this.preview.credentialRefsNeedingRebind
				.map((entry) => {
					const value = (
						this.credentialBindings[this.bindingKey(entry)] || ''
					).trim()
					if (!value) {
						return null
					}
					return {
						sourceSlug: entry.slug,
						field: entry.field,
						credentialName: value,
					}
				})
				.filter(Boolean)
		},

		/**
		 * Fetch the merged diff preview (REQ-003) and advance to step 2.
		 *
		 * Sends an EMPTY `credentialBindings` array deliberately: the preview
		 * exists to discover which refs need rebinding, so sending the
		 * operator's half-entered values here would pre-empt the very
		 * classification being asked for.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-diff-preview-merges-the-targets-existing-preview-response-with-a-credential-rebind-classification-req-003
		 */
		async runPreview() {
			if (!this.canPreview) {
				return
			}
			this.previewing = true
			this.errorMessage = ''
			try {
				const url = generateUrl('/apps/openconnector/api/promotions/preview')
				const { data } = await axios.post(url, {
					configurationId: this.selectedConfig.id,
					targetEnvironmentSlug: this.selectedEnvironment.id,
					credentialBindings: [],
				})
				this.preview = {
					creates: data.creates || [],
					updates: data.updates || [],
					collisions: data.collisions || [],
					credentialRefsNeedingRebind:
						data.credentialRefsNeedingRebind || [],
				}
				this.step = 'preview'
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				this.errorMessage =
					t('openconnector', 'Preview failed')
					+ (detail ? `: ${detail}` : '')
			} finally {
				this.previewing = false
			}
		},

		/**
		 * Confirm the promotion (REQ-005: `confirmed: true`).
		 *
		 * `confirmed: true` is sent only from this handler, which is only
		 * reachable from the preview step — so a promotion cannot be issued by
		 * anyone who has not been shown the diff first.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-promotion-requires-explicit-confirmation-and-the-same-action-matrix-authorization-as-export-import-req-005
		 */
		async confirmPromotion() {
			this.confirming = true
			this.errorMessage = ''
			try {
				const url = generateUrl('/apps/openconnector/api/promotions')
				await axios.post(url, {
					configurationId: this.selectedConfig.id,
					targetEnvironmentSlug: this.selectedEnvironment.id,
					credentialBindings: this.buildCredentialBindings(),
					confirmed: true,
				})
				showSuccess(
					t('openconnector', 'Configuration promoted to {environment}.', {
						environment: this.selectedEnvironment.label,
					}),
				)
				this.close()
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				this.errorMessage =
					t('openconnector', 'Promotion failed')
					+ (detail ? `: ${detail}` : '')
			} finally {
				this.confirming = false
			}
		},

		/**
		 * Return to step 1 without losing the current selection.
		 *
		 * The preview is discarded on the way back, so returning and changing
		 * the target cannot leave a stale diff on screen to be confirmed
		 * against the new one.
		 *
		 * @return {void}
		 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-diff-preview-merges-the-targets-existing-preview-response-with-a-credential-rebind-classification-req-003
		 */
		backToSelect() {
			this.step = 'select'
			this.preview = null
		},

		/**
		 * Dismissal from the modal chrome. Abandoning at the preview step is
		 * the negative path of REQ-005's explicit-confirmation rule: closing is
		 * never a confirmation, and nothing is dispatched here.
		 *
		 * @return {void}
		 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-promotion-requires-explicit-confirmation-and-the-same-action-matrix-authorization-as-export-import-req-005
		 */
		onClose() {
			this.close()
		},

		/**
		 * Ask ModalHost to close the modal.
		 *
		 * @return {void}
		 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-promotion-requires-explicit-confirmation-and-the-same-action-matrix-authorization-as-export-import-req-005
		 */
		close() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.oc-promote-modal {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 20px;
	min-width: 640px;
	max-width: 900px;
}

.oc-promote-modal__step {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.oc-promote-modal__buckets {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: 12px;
}

.oc-promote-modal__bucket ul {
	max-height: 180px;
	overflow: auto;
	margin: 0;
	padding-left: 16px;
}

.oc-promote-modal__rebind {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.oc-promote-modal__rebind-row {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.oc-promote-modal__rebind-row input {
	width: 100%;
}

.oc-promote-modal__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>

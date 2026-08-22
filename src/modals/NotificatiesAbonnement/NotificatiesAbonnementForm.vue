<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  NotificatiesAbonnementForm — create/edit modal for a ZGW Notificaties API
  abonnement.

  Lives in its own file under src/modals/ per the modal-isolation gate.
  Submits to the dedicated NotificatiesSubscriberController endpoints (NOT
  the generic OR object CRUD) because create/update also register/update the
  abonnement against the remote Notificaties API server-side.

  The kanalen field is a taggable, multi NcSelect (ZGW kanaal names are
  target-deployment-specific, not a fixed enum) with an explicit
  `input-label`, per ADR-004 / hydra-gate-nc-input-labels — REQ-008.

  @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008
-->
<template>
	<NcModal
		v-if="open"
		labelId="notificaties-abonnement-form"
		data-testid="notificaties-abonnement-form-modal"
		@close="$emit('close')">
		<div class="abonnementForm">
			<h2>
				{{
					isEdit
						? t('integriq', 'Edit abonnement')
						: t('integriq', 'Add abonnement')
				}}
			</h2>

			<NcTextField
				v-model="model.name"
				:label="t('integriq', 'Name') + ' *'"
				:helperText="errors.name"
				:error="!!errors.name" />

			<div class="abonnementForm__field">
				<label for="notificaties-abonnement-source">
					{{ t('integriq', 'Source') }} *
				</label>
				<NcSelect
					inputId="notificaties-abonnement-source"
					:inputLabel="t('integriq', 'Source')"
					:aria-label-combobox="t('integriq', 'Source')"
					:modelValue="selectedSource"
					:options="sourceOptions"
					:loading="sourcesLoading"
					:clearable="false"
					:placeholder="
						t('integriq', 'Select the Notificaties API source')
					"
					@update:modelValue="onSourcePick" />
				<span class="abonnementForm__helper">
					{{
						t(
							'integriq',
							'The Integriq Source describing the remote Notificaties API (location, auth).',
						)
					}}
				</span>
			</div>

			<div class="abonnementForm__field">
				<label for="notificaties-abonnement-kanalen">
					{{ t('integriq', 'Kanalen') }} *
				</label>
				<NcSelect
					inputId="notificaties-abonnement-kanalen"
					:inputLabel="t('integriq', 'Kanalen')"
					:aria-label-combobox="t('integriq', 'Kanalen')"
					:modelValue="kanaalNames"
					:options="kanaalNames"
					:taggable="true"
					:multiple="true"
					:clearable="true"
					:placeholder="
						t(
							'integriq',
							'Type a kanaal name and press enter (e.g. zaken)',
						)
					"
					@update:modelValue="onKanalenChange">
					<template #no-options>
						{{ t('integriq', 'Type to add a kanaal name') }}
					</template>
				</NcSelect>
				<span
					v-if="errors.kanalen"
					class="abonnementForm__helper abonnementForm__helper--error">
					{{ errors.kanalen }}
				</span>
			</div>

			<NcTextField
				v-model="model.authHeaderName"
				:label="t('integriq', 'Auth header name')"
				:helperText="
					t(
						'integriq',
						'Header the remote Notificaties Routeer Component echoes the abonnement secret back on. Default: Authorization.',
					)
				" />

			<NcTextField
				v-model="model.authScheme"
				:label="t('integriq', 'Auth scheme prefix')"
				:helperText="
					t(
						'integriq',
						'Optional prefix stripped before comparison (e.g. \'Bearer \'). Leave empty for a bare token.',
					)
				" />

			<div class="abonnementForm__actions">
				<NcButton variant="primary" :disabled="busy" @click="save">
					{{ t('integriq', 'Save') }}
				</NcButton>
				<NcButton :disabled="busy" @click="$emit('close')">
					{{ t('integriq', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcModal, NcSelect, NcTextField } from '@nextcloud/vue'

export default {
	name: 'NotificatiesAbonnementForm',

	components: {
		NcModal,
		NcButton,
		NcTextField,
		NcSelect,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		/** The abonnement being edited, or null when creating. */
		abonnement: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			model: this.emptyModel(),
			kanaalNames: [],
			sourceOptions: [],
			sourcesLoading: false,
			busy: false,
			errors: {},
		}
	},

	computed: {
		/**
		 * Whether the form is editing an existing abonnement rather than adding one.
		 *
		 * @return {boolean} True when an abonnement id is present.
		 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008
		 */
		isEdit() {
			return !!(
				this.abonnement
				&& (this.abonnement.id || this.abonnement.uuid)
			)
		},

		/**
		 * The `NcSelect` model for the Source. Falls back to a synthetic
		 * `{id, label: id}` when the stored sourceId is not in the fetched
		 * option set, so an abonnement bound to a Source the picker cannot
		 * currently see still shows its binding instead of appearing unset —
		 * which would invite an operator to silently rebind it on save.
		 *
		 * @return {object|null}
		 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008
		 */
		selectedSource() {
			const id = this.model.sourceId
			if (!id) {
				return null
			}
			return (
				this.sourceOptions.find((option) => option.id === id) ?? {
					id,
					label: id,
				}
			)
		},
	},

	watch: {
		open: {
			immediate: true,
			/**
			 * Re-seeds the form from the `abonnement` prop each time the modal
			 * opens, so a previous edit's values can never be saved onto a
			 * different abonnement. Sources are fetched only once.
			 *
			 * @param {boolean} next Whether the modal is being shown.
			 * @return {void}
			 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008
			 */
			handler(next) {
				if (next) {
					this.resetFromProp()
					if (this.sourceOptions.length === 0) {
						this.fetchSources()
					}
				}
			},
		},
	},

	methods: {
		t,
		/**
		 * A fresh, empty form model.
		 *
		 * @return {object}
		 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008
		 */
		emptyModel() {
			return {
				name: '',
				sourceId: '',
				authHeaderName: 'Authorization',
				authScheme: '',
			}
		},

		/**
		 * (Re)populate the form from the `abonnement` prop, or reset to empty
		 * when creating.
		 *
		 * `kanalen` is flattened to bare names for the taggable select and
		 * re-wrapped on save; entries without a `naam` are dropped rather than
		 * carried through as blanks.
		 *
		 * @return {void}
		 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008
		 */
		resetFromProp() {
			this.errors = {}
			if (this.abonnement) {
				this.model = {
					name: this.abonnement.name || '',
					sourceId: this.abonnement.sourceId || '',
					authHeaderName:
						this.abonnement.authHeaderName || 'Authorization',

					authScheme: this.abonnement.authScheme || '',
				}
				const kanalen = Array.isArray(this.abonnement.kanalen)
					? this.abonnement.kanalen
					: []
				this.kanaalNames = kanalen.map((k) => k.naam).filter(Boolean)
			} else {
				this.model = this.emptyModel()
				this.kanaalNames = []
			}
		},

		/**
		 * Fetch the available Sources for the picker.
		 *
		 * A failure empties the option list rather than throwing: the picker
		 * degrades to the synthetic option from {@link selectedSource}, so an
		 * existing binding stays visible even when Sources cannot be listed.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008
		 */
		async fetchSources() {
			this.sourcesLoading = true
			try {
				const response = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/openconnector/source',
					),
					// `_limit`, not `limit` — an unprefixed param is a PROPERTY
					// FILTER in OpenRegister and silently returns `total: 0`
					// under HTTP 200. See FlowDetailPage.fetchPickerOptions().
					{ params: { _limit: 500 } },
				)
				const data = response.data
				const list = Array.isArray(data?.results)
					? data.results
					: Array.isArray(data)
						? data
						: []
				this.sourceOptions = list.map((source) => ({
					id: String(source.id || source.uuid),
					label: source.name || source.id,
				}))
			} catch (err) {
				this.sourceOptions = []
			} finally {
				this.sourcesLoading = false
			}
		},

		/**
		 * Handle a Source pick.
		 *
		 * @param {object} option The picked NcSelect option.
		 * @return {void}
		 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008
		 */
		onSourcePick(option) {
			this.model.sourceId = option?.id || ''
		},

		/**
		 * Handle a kanalen taggable-multi-select change.
		 *
		 * @param {Array<string>} value The updated list of kanaal names.
		 * @return {void}
		 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008
		 */
		onKanalenChange(value) {
			this.kanaalNames = Array.isArray(value) ? value : []
		},

		/**
		 * Validate + submit the create/update request.
		 *
		 * Both client-side rules run before the early return, so an operator
		 * sees every problem at once rather than one per attempt. `kanalen` is
		 * required here because REQ-006 makes a publish with no kanaal a
		 * configuration error rather than a transient failure — catching it at
		 * registration is the earliest honest point.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnement-registration-update-and-deletion-against-the-remote-api-req-001
		 */
		async save() {
			this.errors = {}
			if (!this.model.name) {
				this.errors = {
					...this.errors,
					name: t('integriq', 'Name is required'),
				}
			}
			if (this.kanaalNames.length === 0) {
				this.errors = {
					...this.errors,
					kanalen: t('integriq', 'At least one kanaal is required'),
				}
			}
			if (Object.keys(this.errors).length > 0) {
				return
			}

			const payload = {
				name: this.model.name,
				sourceId: this.model.sourceId,
				kanalen: this.kanaalNames.map((naam) => ({ naam, filters: {} })),
				authHeaderName: this.model.authHeaderName || 'Authorization',
				authScheme: this.model.authScheme || '',
			}

			this.busy = true
			try {
				if (this.isEdit) {
					const id = this.abonnement.id || this.abonnement.uuid
					await axios.put(
						generateUrl(
							`/apps/integriq/api/notificaties/abonnementen/${id}`,
						),
						payload,
					)
					showSuccess(t('integriq', 'Abonnement updated'))
				} else {
					await axios.post(
						generateUrl(
							'/apps/integriq/api/notificaties/abonnementen',
						),
						payload,
					)
					showSuccess(t('integriq', 'Abonnement created'))
				}
				this.$emit('saved')
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				showError(
					t('integriq', 'Failed to save abonnement')
						+ (detail ? `: ${detail}` : ''),
				)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.abonnementForm {
	padding: 20px;
	min-width: 420px;
	max-width: 520px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.abonnementForm__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.abonnementForm__helper {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.abonnementForm__helper--error {
	color: var(--color-error);
}

.abonnementForm__actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}
</style>

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
	<NcModal v-if="open"
		label-id="notificaties-abonnement-form"
		data-testid="notificaties-abonnement-form-modal"
		@close="$emit('close')">
		<div class="abonnementForm">
			<h2>{{ isEdit ? t('openconnector', 'Edit abonnement') : t('openconnector', 'Add abonnement') }}</h2>

			<NcTextField :label="t('openconnector', 'Name') + ' *'"
				v-model="model.name"
				:helper-text="errors.name"
				:error="!!errors.name" />

			<div class="abonnementForm__field">
				<label for="notificaties-abonnement-source">
					{{ t('openconnector', 'Source') }} *
				</label>
				<NcSelect input-id="notificaties-abonnement-source"
					:input-label="t('openconnector', 'Source')"
					:aria-label-combobox="t('openconnector', 'Source')"
					:value="selectedSource"
					:options="sourceOptions"
					:loading="sourcesLoading"
					:clearable="false"
					:placeholder="t('openconnector', 'Select the Notificaties API source')"
					@input="onSourcePick" />
				<span class="abonnementForm__helper">
					{{ t('openconnector', 'The openconnector Source describing the remote Notificaties API (location, auth).') }}
				</span>
			</div>

			<div class="abonnementForm__field">
				<label for="notificaties-abonnement-kanalen">
					{{ t('openconnector', 'Kanalen') }} *
				</label>
				<NcSelect input-id="notificaties-abonnement-kanalen"
					:input-label="t('openconnector', 'Kanalen')"
					:aria-label-combobox="t('openconnector', 'Kanalen')"
					:value="kanaalNames"
					:options="kanaalNames"
					:taggable="true"
					:multiple="true"
					:clearable="true"
					:placeholder="t('openconnector', 'Type a kanaal name and press enter (e.g. zaken)')"
					@input="onKanalenChange">
					<template #no-options>
						{{ t('openconnector', 'Type to add a kanaal name') }}
					</template>
				</NcSelect>
				<span v-if="errors.kanalen" class="abonnementForm__helper abonnementForm__helper--error">
					{{ errors.kanalen }}
				</span>
			</div>

			<NcTextField :label="t('openconnector', 'Auth header name')"
				v-model="model.authHeaderName"
				:helper-text="t('openconnector', 'Header the remote Notificaties Routeer Component echoes the abonnement secret back on. Default: Authorization.')" />

			<NcTextField :label="t('openconnector', 'Auth scheme prefix')"
				v-model="model.authScheme"
				:helper-text="t('openconnector', 'Optional prefix stripped before comparison (e.g. \'Bearer \'). Leave empty for a bare token.')" />

			<div class="abonnementForm__actions">
				<NcButton type="primary" :disabled="busy" @click="save">
					{{ t('openconnector', 'Save') }}
				</NcButton>
				<NcButton :disabled="busy" @click="$emit('close')">
					{{ t('openconnector', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import {
	NcModal,
	NcButton,
	NcTextField,
	NcSelect,
} from '@nextcloud/vue'

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
		isEdit() {
			return !!(this.abonnement && (this.abonnement.id || this.abonnement.uuid))
		},
		selectedSource() {
			const id = this.model.sourceId
			if (!id) {
				return null
			}
			return this.sourceOptions.find((option) => option.id === id) ?? { id, label: id }
		},
	},

	watch: {
		open: {
			immediate: true,
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
		 * @return {object}
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
		 */
		resetFromProp() {
			this.errors = {}
			if (this.abonnement) {
				this.model = {
					name: this.abonnement.name || '',
					sourceId: this.abonnement.sourceId || '',
					authHeaderName: this.abonnement.authHeaderName || 'Authorization',
					authScheme: this.abonnement.authScheme || '',
				}
				const kanalen = Array.isArray(this.abonnement.kanalen) ? this.abonnement.kanalen : []
				this.kanaalNames = kanalen.map((k) => k.naam).filter(Boolean)
			} else {
				this.model = this.emptyModel()
				this.kanaalNames = []
			}
		},

		/**
		 * Fetch the available Sources for the picker.
		 */
		async fetchSources() {
			this.sourcesLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/objects/openconnector/source'),
					{ params: { limit: 500 } },
				)
				const data = response.data
				const list = Array.isArray(data?.results) ? data.results : (Array.isArray(data) ? data : [])
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
		 * @param {object} option The picked NcSelect option.
		 */
		onSourcePick(option) {
			this.model.sourceId = option?.id || ''
		},

		/**
		 * Handle a kanalen taggable-multi-select change.
		 * @param {Array<string>} value The updated list of kanaal names.
		 */
		onKanalenChange(value) {
			this.kanaalNames = Array.isArray(value) ? value : []
		},

		/**
		 * Validate + submit the create/update request.
		 */
		async save() {
			this.errors = {}
			if (!this.model.name) {
				this.errors = { ...this.errors, name: t('openconnector', 'Name is required') }
			}
			if (this.kanaalNames.length === 0) {
				this.errors = { ...this.errors, kanalen: t('openconnector', 'At least one kanaal is required') }
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
					await axios.put(generateUrl(`/apps/openconnector/api/notificaties/abonnementen/${id}`), payload)
					showSuccess(t('openconnector', 'Abonnement updated'))
				} else {
					await axios.post(generateUrl('/apps/openconnector/api/notificaties/abonnementen'), payload)
					showSuccess(t('openconnector', 'Abonnement created'))
				}
				this.$emit('saved')
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				showError(t('openconnector', 'Failed to save abonnement') + (detail ? `: ${detail}` : ''))
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

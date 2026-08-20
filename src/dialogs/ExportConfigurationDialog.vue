<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  ExportConfigurationDialog — export a configuration group to a redacted
  JSON file from the UI (connector-catalog-ui REQ-006), mounted once in
  ModalHost and opened via the Catalog page's "Export configuration"
  header action.

  Configuration groups are OpenRegister-native rows (lib/Db/Configuration
  in OR, served by /apps/openregister/api/configurations — NOT an object
  register/schema), so the picker lists them from that endpoint and the
  download hits openconnector's POST /api/configurations/{id}/export,
  which wraps the existing ConfigurationService::exportConfiguration()
  (slug translation + REQ-005 credential redaction, unchanged).
-->
<template>
	<NcDialog
		:open="open"
		:name="t('openconnector', 'Export configuration')"
		size="normal"
		data-testid="export-configuration-dialog"
		@update:open="onOpenChanged">
		<div class="oc-export-dialog">
			<p>
				{{
					t(
						'openconnector',
						'Download a configuration group as a slug-referenced JSON document. Credentials are always stripped from the export.',
					)
				}}
			</p>

			<NcSelect
				:modelValue="selected"
				:options="options"
				:loading="loading"
				:inputLabel="t('openconnector', 'Configuration group')"
				:placeholder="t('openconnector', 'Select a configuration group')"
				label="label"
				data-testid="export-configuration-select"
				@update:modelValue="onSelect" />

			<NcNoteCard v-if="options.length === 0 && !loading" type="info">
				{{
					t(
						'openconnector',
						'No configuration groups found. Assign entities to a configuration first.',
					)
				}}
			</NcNoteCard>

			<NcNoteCard v-if="errorMessage" type="error">
				{{ errorMessage }}
			</NcNoteCard>

			<div class="oc-export-dialog__actions">
				<NcButton variant="tertiary" @click="close">
					{{ t('openconnector', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="!selected || exporting"
					data-testid="export-configuration-confirm"
					@click="runExport">
					{{ t('openconnector', 'Export') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard, NcSelect } from '@nextcloud/vue'

export default {
	name: 'ExportConfigurationDialog',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
	},

	props: {
		open: { type: Boolean, default: false },
	},

	emits: ['close'],

	data() {
		return {
			options: [],
			selected: null,
			loading: false,
			exporting: false,
			errorMessage: '',
		}
	},

	watch: {
		/**
		 * Resets the selection and re-lists configurations on open. Clearing
		 * `selected` matters: a retained selection from a previous open could
		 * be exported without the operator having chosen it in this session.
		 *
		 * @param {boolean} isOpen Whether the dialog is being shown.
		 * @return {void}
		 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-006--export-a-configuration-from-the-ui
		 */
		open(isOpen) {
			if (isOpen) {
				this.errorMessage = ''
				this.selected = null
				this.fetchConfigurations()
			}
		},
	},

	methods: {
		/**
		 * List configuration groups from OR's native configurations endpoint.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-006--export-a-configuration-from-the-ui
		 */
		async fetchConfigurations() {
			this.loading = true
			try {
				const url = generateUrl('/apps/openregister/api/configurations')
				const { data } = await axios.get(url)
				const rows = data?.results || []
				this.options = rows.map((row) => ({
					id: row.uuid || row.id,
					label: row.title || row.name || row.uuid || String(row.id),
				}))
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				this.errorMessage =
					t('openconnector', 'Could not load configuration groups')
					+ (detail ? `: ${detail}` : '')
			} finally {
				this.loading = false
			}
		},

		/**
		 * NcSelect selection relay.
		 *
		 * @param {object|null} option The chosen option.
		 * @return {void}
		 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-006--export-a-configuration-from-the-ui
		 */
		onSelect(option) {
			this.selected = option || null
		},

		/**
		 * Download the redacted export document for the selected group
		 * (REQ-006 — the endpoint is gated by the ADR-023
		 * `configuration.export` action).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/configuration-export-import/spec.md#scenario-exporting-a-configuration-from-the-ui-produces-a-redacted-downloadable-file
		 */
		async runExport() {
			if (!this.selected) {
				return
			}
			this.exporting = true
			this.errorMessage = ''
			try {
				const url = generateUrl(
					`/apps/openconnector/api/configurations/${this.selected.id}/export`,
				)
				const { data } = await axios.post(url)
				const blob = new Blob([JSON.stringify(data, null, 2)], {
					type: 'application/json',
				})
				const link = document.createElement('a')
				link.href = URL.createObjectURL(blob)
				link.download = `configuration-${this.selected.id}.json`
				document.body.appendChild(link)
				link.click()
				document.body.removeChild(link)
				URL.revokeObjectURL(link.href)
				showSuccess(t('openconnector', 'Configuration exported'))
				this.close()
			} catch (err) {
				const detail = err?.response?.data?.error || err?.message || ''
				this.errorMessage =
					t('openconnector', 'Export failed')
					+ (detail ? `: ${detail}` : '')
			} finally {
				this.exporting = false
			}
		},

		/**
		 * NcDialog open-state relay.
		 *
		 * @param {boolean} isOpen New open state from NcDialog.
		 * @return {void}
		 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-006--export-a-configuration-from-the-ui
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
		 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-006--export-a-configuration-from-the-ui
		 */
		close() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.oc-export-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 12px 12px;
}

.oc-export-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  ImportMailFileModal: import a saved Outlook message (.msg) or a .eml file
  into the mail intake, under a chosen mailbox source.

  Posts the file to `POST /api/mail-intake/import`, the same path a polled
  mailbox takes, so the message is parsed, checked for a case reference and
  offered to the app that opens cases. A file that does not parse gets the
  server's reason, shown in the dialog.

  @spec openspec/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
-->
<template>
	<NcModal
		v-if="open"
		labelId="import-mail-file-title"
		data-testid="import-mail-file-modal"
		@close="$emit('close')">
		<div class="importMailFile">
			<h2 id="import-mail-file-title">
				{{ t('integriq', 'Import file (.eml or .msg)') }}
			</h2>

			<NcSelect
				:modelValue="selectedMailbox"
				:options="mailboxOptions"
				:inputLabel="t('integriq', 'Mailbox')"
				label="label"
				:clearable="false"
				:disabled="busy"
				@update:modelValue="sourceId = $event ? $event.id : ''" />
			<p v-if="mailboxes.length === 0" class="importMailFile__hint">
				{{ t('integriq', 'There is no mailbox source yet. Add one under Sources first.') }}
			</p>

			<div class="importMailFile__file">
				<label for="import-mail-file-input">{{ t('integriq', 'Message file') }}</label>
				<input
					id="import-mail-file-input"
					type="file"
					accept=".eml,.msg,message/rfc822,application/vnd.ms-outlook"
					:disabled="busy"
					data-testid="import-mail-file-input"
					@change="onFilePicked">
			</div>

			<p v-if="error" class="importMailFile__error" role="alert" data-testid="import-mail-file-error">
				{{ error }}
			</p>
			<p v-if="result" role="status" data-testid="import-mail-file-result">
				{{ result }}
			</p>

			<div class="importMailFile__actions">
				<NcButton
					variant="primary"
					:disabled="!canImport"
					data-testid="import-mail-file-send"
					@click="onImport">
					{{ t('integriq', 'Import') }}
				</NcButton>
				<NcButton @click="$emit('close')">
					{{ t('integriq', 'Close') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcModal, NcSelect } from '@nextcloud/vue'
import { importMailFile, refusalOf } from './v2/intakeRuleApi.js'

export default {
	name: 'ImportMailFileModal',

	components: { NcButton, NcModal, NcSelect },

	props: {
		/** Whether the dialog is open. */
		open: {
			type: Boolean,
			default: false,
		},

		/** The mailbox sources a file can be imported under. */
		mailboxes: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['close', 'imported'],

	data() {
		return {
			sourceId: '',
			file: null,
			busy: false,
			error: '',
			result: '',
		}
	},

	computed: {
		/**
		 * The mailboxes as select options.
		 *
		 * @return {Array<{id: string, label: string}>}
		 */
		mailboxOptions() {
			return this.mailboxes.map((mailbox) => ({ id: String(mailbox.id), label: String(mailbox.name || mailbox.id) }))
		},

		/**
		 * The picked mailbox's option.
		 *
		 * @return {object|null}
		 */
		selectedMailbox() {
			return this.mailboxOptions.find((option) => option.id === this.sourceId) || null
		},

		/**
		 * Whether a mailbox and a file are picked.
		 *
		 * @return {boolean}
		 */
		canImport() {
			return !this.busy && this.sourceId !== '' && this.file !== null
		},
	},

	watch: {
		open: {
			immediate: true,
			handler(value) {
				if (value) {
					this.reset()
				}
			},
		},
	},

	methods: {
		t,

		/**
		 * Start clean, with the only mailbox picked when there is one.
		 *
		 * @return {void}
		 */
		reset() {
			this.sourceId = this.mailboxes.length === 1 ? String(this.mailboxes[0].id) : ''
			this.file = null
			this.error = ''
			this.result = ''
		},

		/**
		 * Keep the picked file.
		 *
		 * @param {Event} event The change event.
		 * @return {void}
		 */
		onFilePicked(event) {
			this.file = event?.target?.files?.[0] || null
			this.error = ''
			this.result = ''
		},

		/**
		 * Import the file and say what became of it.
		 *
		 * @return {Promise<boolean>} Whether it was imported.
		 * @spec openspec/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
		 */
		async onImport() {
			if (!this.canImport) {
				return false
			}
			this.busy = true
			this.error = ''
			this.result = ''
			try {
				const data = await importMailFile(this.sourceId, this.file)
				const subject = data?.message?.subject || this.file.name
				this.result = t('integriq', 'Imported "{subject}".', { subject })
				if (data?.warning) {
					this.result += ' ' + data.warning
				}
				this.$emit('imported', data)
				return true
			} catch (error) {
				this.error = refusalOf(error, t('integriq', 'The file could not be imported.'))
				return false
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.importMailFile {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 4);
}

.importMailFile h2 {
	margin: 0;
}

.importMailFile__file {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.importMailFile__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.importMailFile__error {
	color: var(--color-error-text, var(--color-error));
}

.importMailFile__actions {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	justify-content: flex-end;
}
</style>

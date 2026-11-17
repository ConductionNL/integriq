<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  BodyCaptureWindowDialog: "Open investigation window" on the source detail
  page (outbound-call-log-investigation-window-page-and-allowlist, Task 6).

  Asks for a number of hours and a reason, and opens the window through
  `POST /api/sources/{id}/body-capture` (REQ-OCD-008). The server bounds the
  hours and the reason; its refusal is shown as is. Emits `opened` with the
  server's answer, and `close`.

  @spec openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-the-source-page-shows-the-window-and-says-when-no-body-was-stored-req-ocd-011
-->
<template>
	<NcDialog
		:open="open"
		:name="t('integriq', 'Open investigation window')"
		size="normal"
		data-testid="body-capture-dialog"
		@update:open="onOpenChanged">
		<div class="body-capture-dialog">
			<p>
				{{
					t(
						'integriq',
						'While the window is open, the request and response bodies of calls to this source are stored in the call log.',
					)
				}}
			</p>

			<NcTextField
				v-model="hours"
				type="number"
				min="1"
				:label="t('integriq', 'Hours')"
				data-testid="body-capture-hours" />

			<NcTextField
				v-model="reason"
				:label="t('integriq', 'Reason')"
				data-testid="body-capture-reason" />

			<NcNoteCard v-if="error" type="error" data-testid="body-capture-error">
				{{ error }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton :disabled="busy" @click="close">
				{{ t('integriq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || !canSubmit"
				data-testid="body-capture-submit"
				@click="submit">
				{{ t('integriq', 'Open window') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard, NcTextField } from '@nextcloud/vue'

export default {
	name: 'BodyCaptureWindowDialog',

	components: { NcButton, NcDialog, NcNoteCard, NcTextField },

	props: {
		/** Whether the dialog is shown. */
		open: {
			type: Boolean,
			default: false,
		},

		/** The source's object id. */
		sourceId: {
			type: String,
			required: true,
		},
	},

	emits: ['opened', 'close'],

	data() {
		return {
			hours: '24',
			reason: '',
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Whether hours and a reason are filled in.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-the-source-page-shows-the-window-and-says-when-no-body-was-stored-req-ocd-011
		 */
		canSubmit() {
			return Number(this.hours) >= 1 && this.reason.trim() !== ''
		},
	},

	methods: {
		t,

		/**
		 * Open the window on the server, and hand its answer to the parent.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-the-source-page-shows-the-window-and-says-when-no-body-was-stored-req-ocd-011
		 */
		async submit() {
			this.busy = true
			this.error = ''
			try {
				const res = await axios.post(
					generateUrl(`/apps/integriq/api/sources/${this.sourceId}/body-capture`),
					{ hours: Number(this.hours), reason: this.reason.trim() },
				)
				this.$emit('opened', res.data)
			} catch (err) {
				this.error = err?.response?.data?.error
					|| t('integriq', 'The investigation window could not be opened.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Close the dialog without opening a window.
		 *
		 * @spec openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-the-source-page-shows-the-window-and-says-when-no-body-was-stored-req-ocd-011
		 */
		close() {
			this.$emit('close')
		},

		/**
		 * NcDialog's own close (Escape, the cross) closes it too.
		 *
		 * @param {boolean} value The requested open state.
		 * @spec openspec/changes/outbound-call-log-investigation-window-page-and-allowlist/specs/outbound-call-log/spec.md#requirement-the-source-page-shows-the-window-and-says-when-no-body-was-stored-req-ocd-011
		 */
		onOpenChanged(value) {
			if (value === false) {
				this.close()
			}
		},
	},
}
</script>

<style scoped>
.body-capture-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
}
</style>

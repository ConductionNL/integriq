<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  IntakeMessageActionDialog: the case reference for "Assign to case", or the
  text for "Reply", on one message in the intake inbox.

  Opened by IntakeMessageRowActions. It only collects the value: `submit`
  carries it to the row actions, which post it to the route and close the
  dialog when it went through. `sending` keeps it open and its buttons off
  while that runs.

  @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
-->
<template>
	<NcDialog
		v-if="kind !== ''"
		:name="kind === 'assign' ? t('integriq', 'Assign to case') : t('integriq', 'Reply')"
		:noClose="sending"
		data-testid="intake-message-action-dialog"
		@closing="onClose">
		<NcTextField
			v-if="kind === 'assign'"
			v-model="value"
			:label="t('integriq', 'Case reference')"
			data-testid="intake-message-case-ref" />
		<NcTextArea
			v-else
			v-model="value"
			:label="t('integriq', 'Your reply')"
			data-testid="intake-message-reply-text" />
		<template #actions>
			<NcButton :disabled="sending" @click="onClose">
				{{ t('integriq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="sending || !canSubmit"
				data-testid="intake-message-action-submit"
				@click="onSubmit">
				{{ kind === 'assign' ? t('integriq', 'Assign') : t('integriq', 'Send') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcTextArea, NcTextField } from '@nextcloud/vue'

export default {
	name: 'IntakeMessageActionDialog',
	components: { NcButton, NcDialog, NcTextArea, NcTextField },
	props: {
		/** `assign`, `reply`, or '' when closed. */
		kind: {
			type: String,
			default: '',
		},

		/** Whether the row actions are posting the value. */
		sending: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['submit', 'close'],

	data() {
		return {
			value: '',
		}
	},

	computed: {
		/**
		 * Whether there is something to send.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
		 */
		canSubmit() {
			return this.value.trim() !== ''
		},
	},

	watch: {
		/**
		 * Start empty whenever the dialog opens for another action.
		 *
		 * @return {void}
		 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
		 */
		kind() {
			this.value = ''
		},
	},

	methods: {
		t,

		/**
		 * Hand the value to the row actions.
		 *
		 * @return {void}
		 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
		 */
		onSubmit() {
			if (this.canSubmit && !this.sending) {
				this.$emit('submit', this.value)
			}
		},

		/**
		 * Ask to close, unless the value is being sent.
		 *
		 * @return {void}
		 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
		 */
		onClose() {
			if (!this.sending) {
				this.$emit('close')
			}
		},
	},
}
</script>
